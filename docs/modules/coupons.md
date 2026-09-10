# Coupons (`coupons`)

The Coupons module manages **discount coupons** for the shop: a unique code with a `percent` or `fixed` discount, optional currency, minimum order subtotal, usage cap, validity window and active flag. Shop administrators maintain coupons in the admin (menu group *Obchod*); the [Shop](shop.md) checkout consumes the `coupons` capability (`CouponsProvider::validateForCart()`) to compute the discount and increments `used_count` after an order is placed. External systems can validate a code via the REST API.

See also: [Bundles](bundles.md), [Shop](shop.md), [Orders](orders.md), [module system](../architecture/module-system.md), [dashboard widgets](../architecture/dashboard-widgets.md), [REST API](../architecture/api.md).

## At a glance

| Item | Value |
| --- | --- |
| Module id | `coupons` |
| Version | `1.1.0` (`modules/Coupons/module.php`, `CouponsModule::version()`) |
| Directory | `modules/Coupons/` |
| Class | `Modules\Coupons\CouponsModule` |
| Requires | `core ^1.0` (no optional deps) |
| Capabilities provided | `coupons` → `Modules\Coupons\Application\CouponsProvider` |
| Capabilities consumed | None |
| Groups / packages | Not listed in `config/module_groups.php`; `module.php` has no `groups` key (standalone module) |
| Permissions | `coupons.view` (`perm.coupons.view`), `coupons.manage` (`perm.coupons.manage`) — group `perm.group.coupons` |
| API scopes | `coupons.read`, `coupons.write` |
| Icon | `tag` |

## Domain model

Single entity in `modules/Coupons/Domain/Coupon.php`.

| Entity | Table | Key fields | Enum / status values | Relations |
| --- | --- | --- | --- | --- |
| `Coupon` | `coupons` (unique `uniq_coupons_code` on `code`) | `id`: string(36) UUIDv7 PK; `code`: string(64), upper-cased & trimmed; `name`: string(190); `type`: string(16) default `percent`; `value`: decimal(14,2) default `0.00`; `currency`: string(3) nullable (hint: only for fixed discounts); `min_order`: decimal(14,2) nullable; `max_uses`: int nullable; `used_count`: int default 0 (never negative); `starts_at`, `ends_at`: datetime nullable; `is_active`: bool default true; `created_at`, `updated_at` | `TYPES = ['percent', 'fixed']` (unknown value normalises to `percent`) | None (referenced by Shop orders through `Order.couponCode`, a plain string) |

The entity carries `#[FormEntity('coupons.save')]` and `#[FormGenerator(exclude: ['id','usedCount','createdAt','updatedAt'], cols: 2)]`; fields are labelled `coupons.field.*`, type select uses `coupons.type.` label prefix, date fields render as `datetime-local`, `active` as a switch. Mutators: `incrementUsedCount()`, `setUsedCount()` (clamped to ≥0), `setMinOrder()` normalising to 2 decimals.

## Admin UI

### Menu items (`CouponsModule::boot()`)

| Menu id | Label key | Icon | Route | Permission | Order / group |
| --- | --- | --- | --- | --- | --- |
| `coupons` | `nav.coupons` | `tag` | `admin.coupons.index` | `coupons.view` | 34, group `Obchod` |
| `coupons.create` | `nav.coupons.create` | — | `admin.coupons.create` | `coupons.manage` | 35, parent `coupons`, hidden |

Command palette: `coupons.index` (`palette.coupons.index`, url `/admin/coupons`, permission `coupons.view`, keywords `kupón`, `coupon`, `sleva`, `discount`, `promo`).

### Admin routes (`/admin/coupons`, middleware `auth`)

| Method | Path | Route name | Controller::action | Permission |
| --- | --- | --- | --- | --- |
| GET | `/admin/coupons` | `admin.coupons.index` | `CouponController::index` | `coupons.view` |
| GET | `/admin/coupons/create` | `admin.coupons.create` | `CouponController::create` | `coupons.manage` |
| POST | `/admin/coupons` | `admin.coupons.store` | `CouponController::store` | `coupons.manage` |
| GET | `/admin/coupons/{id}/edit` | `admin.coupons.edit` | `CouponController::edit` | `coupons.manage` |
| POST | `/admin/coupons/{id}` | `admin.coupons.update` | `CouponController::update` | `coupons.manage` |
| POST | `/admin/coupons/{id}/delete` | `admin.coupons.destroy` | `CouponController::destroy` | `coupons.manage` |

Controller: `modules/Coupons/Http/CouponController.php`.

### Screens

- **List** (`Resources/views/index.html.twig`): coupons with search `q`, type/value, uses (`coupons.field.uses`), validity, active badge, delete confirm `coupons.confirm_delete`.
- **Form** (`Resources/views/form.html.twig`): generated from the entity; validates ruleset `coupons.save`, rejects duplicate code with 422 (`coupons.error.code_taken`), writes audit log (`coupons.created` / `coupons.updated` / `coupons.deleted`, entity type `coupon`), emits platform events, flashes `coupons.flash.*`, clears the remembered form (`forgetForm('coupons.save')`).
- No settings page; no settings keys.

Validation ruleset `coupons.save`: `code required|min:1|max:64`, `name required|min:2|max:190`, `type required|max:16`, `value required`, `currency nullable|max:3`, `min_order`, `max_uses`, `starts_at`, `ends_at`, `active` (all `nullable`). Bound with `forms()->entity('coupons.save', Coupon::class)`.

## Public / REST API

Registered via `$context->api()->module('coupons', …)` → `/api/v1/coupons`. Controller `modules/Coupons/Http/Api/CouponApiController.php`.

| Method | Path | Scope | Action |
| --- | --- | --- | --- |
| GET | `/api/v1/coupons/` | `coupons.read` | `index` — paginated (`ApiQuery`: sortable `code`, `name`, `createdAt`, `endsAt`; filterable `type`, `active`; `search` over `code`/`name`) |
| POST | `/api/v1/coupons/` | `coupons.write` | `store` (ruleset `coupons.save`; 201) |
| POST | `/api/v1/coupons/validate` | `coupons.read` | `validateCoupon` — body `{code, subtotal}`; returns `{code, ok, discount, message}` with HTTP 200 when valid, 422 otherwise |
| GET | `/api/v1/coupons/{id}` | `coupons.read` | `show` |
| PATCH | `/api/v1/coupons/{id}` | `coupons.write` | `update` |
| DELETE | `/api/v1/coupons/{id}` | `coupons.write` | `destroy` → `{id, deleted: true}` |

Serializer fields: `id`, `code`, `name`, `type`, `value`, `currency`, `min_order`, `max_uses`, `used_count`, `starts_at`, `ends_at`, `active`, `created_at`, `updated_at` (ATOM strings). Duplicate code → `422 validation_error {code: "taken"}`. Dates are parsed with `new \DateTimeImmutable($raw)`; unparsable values silently become `null`.

No `/api/public/*` endpoints.

## Events, hooks & webhooks

Platform events (all `webhookEnabled(true)`, module `coupons`):

| Event | Description (as registered) | Emitted from |
| --- | --- | --- |
| `coupon.created` | Kupón vytvořen | `CouponController::store`, `CouponApiController::store` |
| `coupon.updated` | Kupón upraven | `CouponController::update`, `CouponApiController::update` |
| `coupon.deleted` | Kupón smazán | `CouponController::destroy`, `CouponApiController::destroy` |

Payload: full serialised coupon; subject `['type' => 'coupon', 'id' => …]`.

Hooks, queue jobs, scheduled tasks, publishables: none. Audit log: `coupons.created`, `coupons.updated`, `coupons.deleted` (admin controller only).

## Dashboard widgets

Registered in `boot()` (`modules/Coupons/Application/Widgets/`):

| Widget id | Type | Class | Shows | Permission | Order |
| --- | --- | --- | --- | --- | --- |
| `coupons.shortcut` | shortcut | `CouponsShortcutWidget` | Link to `admin.coupons.index`, icon `tag` | `coupons.view` | 34 |
| `coupons.stats` | metric | `CouponStatsWidget` | Number of currently valid active coupons (active, within `starts_at`/`ends_at`); hint: coupons ending within 7 days and total `used_count` sum (`coupons.widget.stats.hint`) | `coupons.view` | 48 |
| `coupons.expiring` | panel (list) | `ExpiringCouponsWidget` | Active coupons with a future `ends_at`, soonest first (limited list); each row links to the edit form, shows value, usage `used/max` badge (tone `warn` when ending within `SOON_DAYS`) | `coupons.view` | 26 |

Templates: `shortcut.html.twig`, `metric.html.twig`, `list_panel.html.twig` under `admin/dashboard/widgets/`.

## Services & extension points

| Class | Responsibility |
| --- | --- |
| `Modules\Coupons\Application\CouponsProvider` | Capability `coupons`. `find(id)`, `findByCode(code)` (upper-cased), `validateForCart(code, subtotal)` → `{ok, discount, message}`. Checks in order: existence, `active`, `starts_at`/`ends_at` window, `max_uses` vs `used_count`, `min_order`; discount = `subtotal * value/100` (percent) or `value` (fixed), capped at the subtotal, formatted to 2 decimals. Messages are hard-coded English strings. |

Integration:

- Registered as capability `coupons` in `register()`.
- [Shop](shop.md) `CheckoutService` resolves the coupon during checkout and, guarded by `class_exists(CouponsProvider::class) && $container->has(...)`, increments `used_count` after order creation (`modules/Shop/Application/CheckoutService.php`).
- No interfaces are exposed for other modules to implement.

## CLI commands

None.

## Configuration & translations

- Settings keys: none. Environment variables: none.
- Translations: `modules/Coupons/Resources/translations/messages.cs.php`, `messages.en.php`. Prefixes: `module.coupons.*`, `nav.coupons*`, `palette.coupons.*`, `perm.coupons.*`, `perm.group.coupons`, `coupons.*` (`coupons.field.*`, `coupons.type.*`, `coupons.error.*`, `coupons.flash.*`, `coupons.widget.stats.*`, `coupons.widget.expiring.*`).

## Database migrations

| File | Purpose |
| --- | --- |
| `Database/Migrations/2026_01_01_000001_coupons.php` | Creates `coupons` (PK `id`, unique `uniq_coupons_code`, index `idx_coupons_active` on `is_active`); idempotent (`hasTable` guard). `down()` drops the table. |

## Notes

- `currency` on a coupon is informational only — `validateForCart()` does not compare the coupon currency with the cart currency.
- Validation messages returned by `validateForCart()` are untranslated English literals, and they flow to the API `validate` endpoint as-is.
- `used_count` is only incremented by the Shop module; the admin UI does not expose editing it (`usedCount` is excluded from the generated form) and there is no reset action.
- Monetary values are decimal strings in the DB but pass through `(float)` casts before `number_format`.
- Coupon deletion is a hard delete; orders keep only the `coupon_code` string.
