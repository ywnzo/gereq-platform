# Product Discounts (`product_discounts`)

Catalog-level sales for the [Products](products.md) module: a discount is either a **percentage** or a **fixed amount**, is scoped to a single **product** or a whole **category**, can be time-boxed (`starts_at`/`ends_at`) and optionally conditioned on variant stock (`stock ≤ N` / `stock ≥ N`). Discounts are managed by shop administrators from the *Discounts* tab on the product detail and category form; they are consumed by the public store catalog API (`Products`) and by the cart ([Shop](shop.md)) so that the struck-through original price and the effective price are consistent everywhere.

## At a glance

| | |
|---|---|
| Module id | `product_discounts` |
| Version | `1.99.0` (`modules/ProductDiscounts/module.php`, `ProductDiscountsModule::version()`) |
| Directory | `modules/ProductDiscounts/` |
| Requires | `core ^1.0`, `products ^1.0`, `currency ^1.1`; optional: none |
| Capabilities | provides `product_discounts` (payload: `ProductDiscountsProvider`); consumed by `Products` (`ProductController`, `CategoryController`, `PublicStoreCatalogController`) and `Shop` (`CartService`) via `class_exists` guards |
| Groups / packages | none in `module.php`; not listed in `config/module_groups.php` |
| Permissions | `product_discounts.view` (View product discounts), `product_discounts.manage` (Manage product discounts) — group `perm.group.product_discounts` |
| Menu | none — "UI lives in product / category tabs" (`boot()` comment); dashboard widgets only |

## Domain model

| Entity | Table | Key fields | Enum values | Relations |
|---|---|---|---|---|
| `Domain/ProductDiscount` | `product_discounts` (indexes `idx_product_discounts_scope (scope_type, scope_id)`, `idx_product_discounts_active_window (is_active, starts_at, ends_at)`) | `id: string(36)` UUIDv7 PK; `product_id: ?string(36)` legacy mirror of `scope_id` when scope is product; `scope_type: string(16)` default `product`; `scope_id: string(36)`; `kind: string(16)`; `value: decimal(14,2)` (percent or amount, normalised to 2 dp); `description: ?string(190)`; `condition_type: string(32)` default `none`; `condition_value: ?int`; `starts_at`, `ends_at: ?datetime_immutable`; `is_active: bool` (property `active`); `created_at`, `updated_at` | `KINDS = percent, amount` (`fixed` is normalised to `amount`); `SCOPES = product, category`; `CONDITIONS = none, stock_lte, stock_gte` | `scope_id` → `products.id` or `product_categories.id` (by id, no FK); `product_id` → `products.id` |

Domain helpers: `isCurrentlyActive(?now)` (active flag + time window), `matchesStockCondition(?int $stockQty)` (match on `condition_type`; unknown types → `false`), factories `forProduct()` / `forCategory()`. The entity is annotated with `#[FormEntity('product_discounts.save')]` and `#[FormGenerator(...)]` (2 columns; excludes id/scope/timestamps) so the admin form is generated from `#[FormField]` attributes (kind select, value, condition select, condition value, description, starts/ends `datetime-local`, active switch).

### Resolution algorithm (`ProductDiscountsProvider`)

1. Collect product-scoped discounts for the product; keep those that are currently active and match the stock condition.
2. If any match → pick the one with the **largest saving** on a probe base price of 100 (`pickBest()`).
3. Otherwise repeat for all category-scoped discounts of the product's categories (categories loaded from `Product::categories()` unless passed in).
4. `applyCatalogDiscount(base, kind, value)`: percent → `max(0, round(base*(1-value/100), 2))`; amount → `max(0, round(base-value, 2))`. Returns `{price, original_price|null}`; `original_price` is only set when the price actually changed.

`pricesForProducts()` is the batch variant (one query per scope type) used by the public catalog.

## Admin UI

No menu item or palette command. Routes are registered in `ProductDiscountsModule::register()` (all `auth` middleware):

| Method | Path | Route name | Controller::action | Permission |
|---|---|---|---|---|
| GET | `/admin/product-discounts` | `admin.product_discounts.index` | `ProductDiscountController::index` → redirect to `admin.products.index` | `product_discounts.view` |
| GET | `/admin/product-discounts/create` | `admin.product_discounts.create` → redirect | `ProductDiscountController::create` | `product_discounts.manage` |
| POST | `/admin/product-discounts` | `admin.product_discounts.store` → redirect | `ProductDiscountController::store` | `product_discounts.manage` |
| GET | `/admin/product-discounts/{id}/edit` | `admin.product_discounts.edit` → redirect to product show / category edit `#discounts` | `ProductDiscountController::edit` | `product_discounts.manage` |
| POST | `/admin/product-discounts/{id}` | `admin.product_discounts.update` (same as edit) | `ProductDiscountController::update` | `product_discounts.manage` |
| POST | `/admin/product-discounts/{id}/delete` | `admin.product_discounts.destroy` | `ProductDiscountController::destroy` → `destroyOne` | `product_discounts.manage` |
| POST | `/admin/product-discounts/{id}/remove` | `admin.product_discounts.remove` | `ProductDiscountController::destroyOne` | `product_discounts.manage` |
| POST | `/admin/products/{productId}/discount` | `admin.products.discount.upsert` | `ProductDiscountController::upsertForProduct` (creates a **new** discount row) | `product_discounts.manage` |
| POST | `/admin/products/{productId}/discount/delete` | `admin.products.discount.destroy` | `ProductDiscountController::destroyForProduct` (deletes **all** product-scoped discounts) | `product_discounts.manage` |
| POST | `/admin/products/categories/{categoryId}/discounts` | `admin.products.categories.discounts.store` | `ProductDiscountController::storeForCategory` | `product_discounts.manage` |

Screens: the module ships `Resources/views/_panel.html.twig` (embedded as the *Discounts* tab in `modules/Products/Resources/views/products/show.html.twig` and `categories/form.html.twig`), plus legacy `index.html.twig` / `form.html.twig` (no longer routed — the standalone controller actions redirect). Validation ruleset `product_discounts.save` (`kind` required ≤16, `value` required, `description` ≤190, `condition_type` ≤32, `scope_type` ≤16, `scope_id`/`product_id` ≤36). Form binding: `$context->forms()->entity('product_discounts.save', ProductDiscount::class)`.

Settings pages / setting keys: none.

## Public / REST API

Registered via `$context->api()->module('product-discounts', …)`; known scopes `product_discounts.read`, `product_discounts.write`. See [API](../architecture/api.md).

| Method | Path | Scope | Controller::action |
|---|---|---|---|
| GET | `/api/v1/product-discounts/` | `product_discounts.read` | `Http/Api/ProductDiscountApiController::index` — all discounts ordered by `updated_at` desc |
| GET | `/api/v1/product-discounts/product/{productId}` | `product_discounts.read` | `showForProduct` — primary (unconditional) product discount, or `{product_id, active:false}`; 404 when product missing |
| PUT | `/api/v1/product-discounts/product/{productId}` | `product_discounts.write` | `upsertForProduct` — validates `product_discounts.save`, upserts the primary discount (`ProductDiscountsProvider::upsert()`); accepts form or JSON body |
| DELETE | `/api/v1/product-discounts/product/{productId}` | `product_discounts.write` | `destroyForProduct` — deletes all product-scoped discounts |

Serializer (`ProductDiscountsProvider::serialize()`): `id, product_id, scope_type, scope_id, kind, value, description, condition_type, condition_value, starts_at (ATOM), ends_at (ATOM), active, currently_active`. Filters: none. Public storefront exposure happens through the Products public catalog API (`price`, `original_price`, `discount` fields) — see [Products](products.md).

## Events, hooks & webhooks

| Event | Registered by | Webhook |
|---|---|---|
| `product_discount.saved` | `ProductDiscountsModule::registerPlatformEvents()` (`EventDefinition`, description "Produktová sleva uložena") | enabled |
| `product_discount.deleted` | same (description "Produktová sleva smazána") | enabled |

Emitted by `ProductDiscountController` via `EventBus::emit(name, ['type' => 'product_discount', 'id' => …], serialized payload)` on upsert/store/destroy actions. The REST API controller does **not** emit events. Audit log entries: `product_discounts.upserted`, `product_discounts.created`, `product_discounts.deleted`. No hooks, queue jobs, scheduled tasks or publishables. See [hooks, events & webhooks](../architecture/hooks-events-webhooks.md).

## Dashboard widgets

| Widget id | Type | Class | Shows | Permission | Order |
|---|---|---|---|---|---|
| `product_discounts.shortcut` | shortcut | `Application/Widgets/ProductDiscountsShortcutWidget` | Link to `admin.product_discounts.index` (→ products list), icon `tag`, hint `admin.dashboard.hint.product_discounts` | `product_discounts.view` | 33 |
| `product_discounts.stats` | metric | `Application/Widgets/ProductDiscountStatsWidget` | Count of discounts active now (flag + window), hint: how many end within 7 days | `product_discounts.view` | 54 |

## Services & extension points

| Class | Responsibility |
|---|---|
| `Application/ProductDiscountsProvider` | Capability payload. Query helpers `findForProduct()` (primary unconditional discount, fallback first), `listForProduct()`, `listForCategory()`, `listForScope()`, `listForScopes()` (batch); pricing `priceForProduct()`, `pricesForProducts()`, `resolveBestDiscount()`, `applyCatalogDiscount()`; mutations `create()`, `upsert()` (conditional discounts always create a new row, unconditional ones update the existing primary), `update()`, `delete()`, `deleteForProduct()`; `serialize()`. |
| `Http/ProductDiscountController` | Admin actions embedded in product/category screens. |
| `Http/Api/ProductDiscountApiController` | REST API. |

Consumers (all guarded by `$capabilities->has('product_discounts') && class_exists(ProductDiscountsProvider::class)`):

* `modules/Products/Http/ProductController.php` — passes `productDiscount`, `productDiscounts`, `hasProductDiscounts` to the product detail view.
* `modules/Products/Http/CategoryController.php` — `categoryDiscounts` for the category form.
* `modules/Products/Http/Public/PublicStoreCatalogController.php` — applies `pricesForProducts()` / `priceForProduct()` to public listing and detail payloads.
* `modules/Shop/Application/CartService.php` — applies the resolved discount to cart lines.

## CLI commands

None.

## Configuration & translations

Settings / env vars: none.

Translations: `Resources/translations/messages.cs.php`, `messages.en.php`. Prefixes: `module.product_discounts.description`, `nav.product_discounts*`, `palette.product_discounts.index`, `perm.product_discounts.*`, `perm.group.product_discounts`, `product_discounts.*` (`field.*`, `scope.*`, `kind.*`, `condition.*`, `error.*`, `flash.*`, `panel.*`, `category.*`, `widget.*`, status labels `active|inactive|scheduled|expired`), `admin.dashboard.hint.product_discounts`. Stylesheet: `Resources/assets/css/admin.css`.

## Database migrations

| File | Purpose |
|---|---|
| `Database/Migrations/2026_09_05_000001_product_discounts.php` | Creates `product_discounts` (id, product_id, kind, value, description, starts_at, ends_at, is_active, timestamps) with unique index `uniq_product_discounts_product` on `product_id` — the original one-discount-per-product model. |
| `Database/Migrations/2026_09_05_000002_discount_scope_conditions.php` | Adds `scope_type`, `scope_id`, `condition_type`, `condition_value`; makes `product_id` nullable; drops the unique index and adds `idx_product_discounts_scope`. `afterUp()` backfills `scope_*` from `product_id` and attempts `ALTER COLUMN scope_id SET NOT NULL` (ignored on drivers without support, e.g. SQLite). |

## Notes

* The admin `index/create/store` routes are stubs that redirect to the products list; the module intentionally has no standalone listing (comment in `boot()`), yet `Resources/views/index.html.twig` and `form.html.twig` are still present.
* `upsertForProduct` in the admin controller always **creates** a new row (`create()`), whereas the REST `PUT` uses `upsert()` which updates the primary unconditional discount — the two paths behave differently for repeated saves.
* `ProductDiscountApiController::upsertForProduct` ignores `condition_type` / `condition_value` from the body (only kind, value, description, dates, active are forwarded).
* `pickBest()` compares savings on a fixed probe base of 100, so for products priced far from 100 an `amount` discount may be chosen over a `percent` one (or vice versa) even if the other yields a bigger saving on the real price.
* Platform event descriptions in `registerPlatformEvents()` are Czech literals rather than translation keys.
* Related: [Products](products.md), [Shop](shop.md), [Currency](currency.md).
