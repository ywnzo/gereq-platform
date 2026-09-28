# Warehouse (`warehouse`)

Warehouse manages physical stock: a list of warehouses, per-warehouse stock levels for [Products](products.md), and an append-only ledger of stock movements (receipt, issue, transfer, adjustment). It exposes the `inventory` capability so other modules can read quantities, injects a stock section into the product detail page, provides an authenticated REST API for warehouses / stock / movements, and emits `stock.movement_created` / `stock.low` events that can be forwarded by [Webhooks](webhooks.md). [Shop](shop.md) checkout uses its `StockService` to decrement stock for ordered items.

## At a glance

| Item | Value |
| --- | --- |
| Module id | `warehouse` |
| Version | `1.99.0` (`modules/Warehouse/module.php`, `WarehouseModule::version()`) |
| Directory | `modules/Warehouse/` |
| Requires | `core ^1.0`, `products ^1.0` |
| Optional | none |
| Capabilities provided | `inventory` → `Application\InventoryProvider` (`provides: ['inventory']`) |
| Capabilities consumed | none (hard dependency on `Modules\Products\Domain\Product`) |
| Groups / packages | none in `module.php`; not listed in `config/module_groups.php` |
| Permissions | `warehouse.view` (`perm.warehouse.view`), `warehouse.manage` (`perm.warehouse.manage`), `warehouse.stock.manage` (`perm.warehouse.stock.manage`) — group `perm.group.warehouse` |
| API scopes | `warehouse.read`, `warehouse.write` |
| Author | GEREQ |

## Domain model

All entities live in `modules/Warehouse/Domain/`; relations are by id strings without Doctrine associations or foreign keys.

```mermaid
erDiagram
    WAREHOUSE ||--o{ STOCK_ITEM : "warehouse_id"
    WAREHOUSE ||--o{ STOCK_MOVEMENT : "warehouse_id / target_warehouse_id"
    PRODUCT ||--o{ STOCK_ITEM : "product_id"
    PRODUCT ||--o{ STOCK_MOVEMENT : "product_id"
    WAREHOUSE { string id PK; string code UK; string name; bool is_active }
    STOCK_ITEM { string id PK; string warehouse_id; string product_id; decimal qty }
    STOCK_MOVEMENT { string id PK; string warehouse_id; string product_id; string type; decimal qty; string target_warehouse_id; string created_by }
```

### `Warehouse` → table `warehouses`

Unique `uniq_warehouses_code (code)`. Annotated `#[FormEntity('warehouse.save')]` / `#[FormGenerator(exclude: id, createdAt, updatedAt; cols: 1)]` so the admin form is generated from `#[FormField]` attributes.

| Field | Column / type | Notes |
| --- | --- | --- |
| `id` | string(36) PK | UUID v7 |
| `code` | string(64) | upper-cased and trimmed in constructor / `setCode()`; form field `warehouse.field.code`, required, order 10 |
| `name` | string(190) | form `warehouse.field.name`, required, order 20 |
| `active` | `is_active` boolean, default `true` | form `active` switch `warehouse.field.active`, order 30 |
| `createdAt`, `updatedAt` | datetime | `touch()` on every setter |

### `StockItem` → table `warehouse_stock_items`

Unique `uniq_stock_wh_product (warehouse_id, product_id)`, index `idx_stock_product (product_id)`. One row per warehouse × product.

| Field | Column / type | Notes |
| --- | --- | --- |
| `id` | string(36) PK | UUID v7 |
| `warehouseId` | `warehouse_id` string(36) | → `warehouses.id` |
| `productId` | `product_id` string(36) | → `products.id` |
| `qty` | decimal(18,4) stored as string | normalised with `number_format(…, 4)`; `addQty(delta)` uses float arithmetic |
| `updatedAt` | `updated_at` datetime | |

`LOW_THRESHOLD = 5.0`; `isLow(threshold = null)` is `qty < threshold`.

### `StockMovement` → table `warehouse_stock_movements`

Indexes on `warehouse_id`, `product_id`, `created_at`. Immutable ledger entry.

| Field | Column / type | Notes |
| --- | --- | --- |
| `id` | string(36) PK | UUID v7 |
| `warehouseId`, `productId` | string(36) | source warehouse / product |
| `type` | string(16) | one of `TYPES = in, out, transfer, adjust`; constructor throws `InvalidArgumentException` (Czech message) otherwise |
| `qty` | decimal(18,4) | always stored as `abs()` value |
| `targetWarehouseId` | `target_warehouse_id` string(36) nullable | only for `transfer` |
| `note` | text nullable | |
| `createdBy` | `created_by` string(36) nullable | user id |
| `createdAt` | `created_at` datetime | |

`typeLabel()` returns translation key `warehouse.movement.type.<type>`.

Movement semantics (`Application\StockService::createMovement()`): `in` adds qty to the source item; `out` subtracts (fails with `warehouse.error.insufficient_stock` if `qty < amount`); `transfer` subtracts from source and adds to target (target required, must differ from source and exist); `adjust` sets the source qty to the given absolute value (may be 0). Stock items are created on demand with qty 0.

## Admin UI

### Menu (`WarehouseModule::boot()`)

| Menu id | Label key | Icon | Route | Permission | Order / parent |
| --- | --- | --- | --- | --- | --- |
| `warehouse` | `nav.warehouse` | `warehouse` | `admin.warehouse.index` | `warehouse.view` | 35, group `Obchod` |
| `warehouse.stock` | `nav.warehouse.stock` | — | `admin.warehouse.stock.index` | `warehouse.view` | 36, parent `warehouse` |
| `warehouse.create` | `nav.warehouse.create` | — | `admin.warehouse.create` | `warehouse.manage` | 37, parent `warehouse`, hidden |

Command palette: `warehouse.index` (`palette.warehouse.index`, `/admin/warehouse`, keywords `sklad`, `warehouse`, `stock`, `zásoby`) and `warehouse.stock` (`palette.warehouse.stock`, `/admin/warehouse/stock`, keywords `stock`, `pohyb`, `zásoby`); both need `warehouse.view`.

### Routes (group `/admin/warehouse`, middleware `auth`)

| Method | Path | Route name | Controller::action | Permission |
| --- | --- | --- | --- | --- |
| GET | `/admin/warehouse` | `admin.warehouse.index` | `WarehouseController::index` | `warehouse.view` |
| GET | `/admin/warehouse/create` | `admin.warehouse.create` | `WarehouseController::create` | `warehouse.manage` |
| POST | `/admin/warehouse` | `admin.warehouse.store` | `WarehouseController::store` | `warehouse.manage` |
| GET | `/admin/warehouse/stock` | `admin.warehouse.stock.index` | `StockController::index` | `warehouse.view` |
| GET | `/admin/warehouse/stock/movements/create` | `admin.warehouse.stock.movement.create` | `StockController::createMovement` | `warehouse.stock.manage` |
| POST | `/admin/warehouse/stock/movements` | `admin.warehouse.stock.movement.store` | `StockController::storeMovement` | `warehouse.stock.manage` |
| GET | `/admin/warehouse/{id}/edit` | `admin.warehouse.edit` | `WarehouseController::edit` | `warehouse.manage` |
| POST | `/admin/warehouse/{id}` | `admin.warehouse.update` | `WarehouseController::update` | `warehouse.manage` |
| POST | `/admin/warehouse/{id}/delete` | `admin.warehouse.destroy` | `WarehouseController::destroy` | `warehouse.manage` |

Controllers: `modules/Warehouse/Http/WarehouseController.php`, `modules/Warehouse/Http/StockController.php`.

### Screens

- **Warehouses** (`Resources/views/warehouses/index.html.twig`): list ordered by code with `?q=` search over `code`/`name` (`self_repair_search()`), active/inactive badge, edit / delete (`warehouse.confirm_delete`).
- **Warehouse form** (`warehouses/form.html.twig`): generated from the entity (`warehouse.save` ruleset: `code required|min:1|max:64`, `name required|min:2|max:190`, `active nullable`). Store/update check code uniqueness (`warehouse.error.code_taken`), audit `warehouse.created` / `warehouse.updated` (with old values) / `warehouse.deleted`, flashes `warehouse.flash.*`.
- **Stock** (`stock/index.html.twig`): `ListQuery` paginated (100/page), sortable by `updatedAt` / `qty`, filters `warehouse`, `product`; shows warehouse code, product SKU/name, qty with `warehouse.stock.low` badge; select options limited to active warehouses and the first 100 products by name.
- **New movement** (`stock/movement_form.html.twig`): warehouse, product, type (`in|out|transfer|adjust`), qty, target warehouse, note (`warehouse.movement.save` ruleset). Errors from `StockService` are translated flash messages; success audits `warehouse.movement.created` and redirects to the stock list.
- **Product detail section**: filter `products.show.sections` adds section `warehouse.stock` (label `warehouse.stock.title`, order 40) rendering `partials/product_stock.html.twig` with the product's `StockItem`s per warehouse; only for users with `warehouse.view`.

### Settings

None.

## Public / REST API

Registered through `ApiModule` in `WarehouseModule::registerApi()`; all endpoints require an API token with the given scope (see `../architecture/api.md`).

| Method | Path | Scope | Controller::action | Notes |
| --- | --- | --- | --- | --- |
| GET | `/api/v1/warehouses` | `warehouse.read` | `Http\Api\WarehouseApiController::index` | `ApiQuery`: search `code`/`name`, filter `active`, sort `code|name|createdAt` (default `code`) |
| POST | `/api/v1/warehouses` | `warehouse.write` | `WarehouseApiController::store` | ruleset `warehouse.save`; 422 `validation_error` "Code taken" on duplicate code |
| GET | `/api/v1/warehouses/{id}` | `warehouse.read` | `WarehouseApiController::show` | |
| PATCH | `/api/v1/warehouses/{id}` | `warehouse.write` | `WarehouseApiController::update` | |
| DELETE | `/api/v1/warehouses/{id}` | `warehouse.write` | `WarehouseApiController::destroy` | returns `{id, deleted: true}`; no check for existing stock |
| GET | `/api/v1/stock` | `warehouse.read` | `Http\Api\StockApiController::index` | filters `warehouse_id`, `product_id`; sort `qty|updatedAt` |
| GET | `/api/v1/movements` | `warehouse.read` | `StockApiController::movements` | filters `warehouse_id`, `product_id`, `type`; sort `createdAt` |
| POST | `/api/v1/movements` | `warehouse.write` | `StockApiController::storeMovement` | ruleset `warehouse.movement.save`; 422 `insufficient_stock` / `validation_error`; 201 with movement |

Serializers (`Application\StockService`):

- `serializeWarehouse`: `id, code, name, active, created_at, updated_at`
- `serializeStock`: `id, warehouse_id, product_id, qty, is_low, updated_at`
- `serializeMovement`: `id, warehouse_id, product_id, type, qty, target_warehouse_id, note, created_by, created_at`

Public (`/api/public/*`) endpoints: None.

## Events, hooks & webhooks

Platform events (`registerPlatformEvents()`, both `webhookEnabled(true)`, no explicit version):

| Event | Description (as registered) | Emitted from | Subject / payload |
| --- | --- | --- | --- |
| `stock.movement_created` | "Pohyb skladu vytvořen" | `StockService::createMovement()` | subject `{type: stock_movement, id}`, payload = `serializeMovement()` |
| `stock.low` | "Nízký stav zásob" | `StockService::createMovement()` for every affected item with `isLow()` | subject `{type: stock_item, id}`, payload `warehouse_id, product_id, qty, threshold` |

Hooks: adds filter `products.show.sections` (priority `HookPriority::NORMAL`) — see Admin UI. Exposes no hooks of its own.

Audit log entries: `warehouse.created`, `warehouse.updated`, `warehouse.deleted`, `warehouse.movement.created` (admin controllers only; API writes are not audited).

Queue jobs, scheduled tasks, publishables: None. See `../architecture/hooks-events-webhooks.md`.

## Dashboard widgets

| Widget id | Type | Class | Shows | Permission | Order |
| --- | --- | --- | --- | --- | --- |
| `warehouse.shortcut` | shortcut | `Application/Widgets/WarehouseShortcutWidget.php` | Link to warehouses (`nav.warehouse`, hint `admin.dashboard.hint.warehouse`) | `warehouse.view` | 35 |
| `warehouse.low_stock.stats` | metric | `LowStockStatsWidget.php` | Count of stock items with `qty < 5`; hint with tracked items and active warehouses (`warehouse.widget.stats.hint`) | `warehouse.view` | 58 |
| `warehouse.low_stock` | panel (list) | `LowStockItemsWidget.php` | Up to 8 low-stock items, lowest qty first, with product / warehouse and `Out of stock` (qty ≤ 0, danger) or `Low stock` (warn) badge; link to stock list | `warehouse.view` | 32 |

Registered in `boot()`; see `../architecture/dashboard-widgets.md`.

## Services & extension points

| Class | Responsibility |
| --- | --- |
| `Application\InventoryProvider` | Capability `inventory`. `qtyForProduct(productId, ?warehouseId)` — `SUM(qty)` as 4-decimal string; `stockForProduct(productId)` — list of `StockItem` ordered by warehouse. Read-only. |
| `Application\StockService` | Constructor-injected (`EntityManagerInterface`, `EventBus`). `createMovement(warehouseId, productId, type, qty, ?target, ?note, ?createdBy)` — validates warehouse and `Product` existence, applies the movement, persists, flushes, emits events; `serializeMovement()`, `serializeStock()`, `serializeWarehouse()`. |

Consumers:

- [Shop](shop.md) `CheckoutService::decrementStock()` resolves `StockService` from the container (guarded by `class_exists` + `container->has`) and creates `out` movements per cart item against an active warehouse, note `Shop checkout cart <id>`.
- [Products](products.md) product detail page displays the stock section via the `products.show.sections` filter.
- Other modules may call `capabilities()->get('inventory')` to obtain `InventoryProvider` (see `../architecture/module-system.md`).

## CLI commands

None.

## Configuration & translations

- Settings keys: none. The low-stock threshold is the constant `StockItem::LOW_THRESHOLD = 5.0`.
- Environment variables: none.
- Validation rulesets: `warehouse.save`, `warehouse.movement.save` (`warehouse_id required|max:36`, `product_id required|max:36`, `type required|in:in,out,transfer,adjust`, `qty required`, `target_warehouse_id nullable|max:36`, `note nullable|max:20000`).
- Forms: `forms()->entity('warehouse.save', Warehouse::class)`.
- Translations: `Resources/translations/messages.cs.php`, `messages.en.php`; prefixes `module.warehouse.description`, `nav.warehouse*`, `palette.warehouse.*`, `perm.warehouse.*`, `perm.group.warehouse`, `warehouse.*` (`warehouse.field.*`, `warehouse.stock.*`, `warehouse.movement.*`, `warehouse.flash.*`, `warehouse.error.*`, `warehouse.widget.*`). `admin.dashboard.hint.warehouse` lives in core translations.

## Database migrations

| File | Purpose |
| --- | --- |
| `Database/Migrations/2026_01_01_000001_warehouse.php` | Empty `up()`/`down()` placeholder; tables `warehouses`, `warehouse_stock_items`, `warehouse_stock_movements` are created from entity metadata by `SchemaTool`. |

## Notes

- Movements are applied and flushed without an explicit transaction or row locking; concurrent `out`/`transfer` movements can overdraw stock. Quantities are decimals in the DB but manipulated with PHP floats in `StockItem::addQty()`.
- `adjust` records the movement `qty` as the absolute target quantity, not the delta, so the ledger cannot be replayed to reconstruct balances without special-casing `adjust`.
- Deleting a warehouse (admin or API) does not check for or remove its `StockItem`/`StockMovement` rows; orphaned stock remains counted in `InventoryProvider::qtyForProduct()`.
- Low-stock events fire on every movement while an item stays below the threshold (no de-duplication), and there is no notification besides the event.
- Product option lists in the admin forms are capped at the first 100 products by name; larger catalogues cannot pick other products from the movement form.
- Event descriptions and the `StockMovement` constructor exception message are Czech literals rather than translation keys.
- The module has `products` as a hard requirement (`use Modules\Products\Domain\Product`); the `class_exists(Product::class)` guard in `StockController::productOptions()` is redundant.
- `README.md` says v1.0.3 while the manifest is 1.1.0.
