# Feeds (`feeds`)

Feeds generates product feeds for price comparison and advertising platforms. Administrators define **feed definitions** (code, driver, output format, JSON config); the module then renders active [Products](products.md) with their [ProductPrice](products.md) into Google Merchant RSS XML, Heureka `<SHOP>` XML or a raw JSON dump ("custom" driver), served on demand through the REST API (`GET /api/v1/feeds/{code}`) or previewed in the admin. Used by e‑commerce administrators; it has a hard runtime dependency on the Products module entities even though `module.php` does not declare it.

## At a glance

| Item | Value |
| --- | --- |
| Module id | `feeds` |
| Version | `1.99.0` (`modules/Feeds/module.php`, `FeedsModule::version()`; `README.md` says v1.0.2) |
| Directory | `modules/Feeds/` |
| Class | `Modules\Feeds\FeedsModule` |
| Requires | `core ^1.0` (Products entities `Modules\Products\Domain\Product`, `ProductPrice` are imported unguarded by `FeedsProvider`) |
| Optional | none |
| Capabilities provided | `feeds` → `Application/FeedsProvider` |
| Capabilities consumed | none |
| Groups / packages | none in `module.php`; not listed in `config/module_groups.php` |
| Permissions | `feeds.view` (`perm.feeds.view`), `feeds.manage` (`perm.feeds.manage`) – group `perm.group.feeds` |

## Domain model

| Entity | Table | Key fields | Relations |
| --- | --- | --- | --- |
| `Domain/FeedDefinition.php` | `feed_definitions` (unique `uniq_feed_definitions_code`, index `idx_feed_definitions_active`) | `id: string(36)` UUID v7 · `code: string(64)` lower‑cased/trimmed, unique · `name: string(190)` · `driver: string(32)` default `google_merchant` (invalid values normalised to default) · `format: string(16)` default `xml` (invalid → `xml`) · `is_active: bool` default true · `config: ?json` (empty array stored as NULL) · `created_at`, `updated_at` (touched by every setter) | none (products are read live) |

Enums (class constants): `FeedDefinition::DRIVERS = ['google_merchant', 'heureka', 'custom']`, `FeedDefinition::FORMATS = ['xml', 'tsv', 'csv']`.

Form metadata: `#[FormEntity('feeds.save')]`, `#[FormGenerator(exclude: [id, config, createdAt, updatedAt], cols: 1)]`; `driver` select uses `choicesFrom: 'DRIVERS'` with label prefix `feeds.driver.`; `format` select has inline choices XML/TSV/CSV; `active` is a `switch`.

Interface `Domain/FeedDriverInterface.php`: `key(): string`, `render(array $config, list<array> $products): string`, `contentType(): string`.

## Admin UI

Menu (`boot()`):

| Id | Label | Icon | Route | Permission | Order | Notes |
| --- | --- | --- | --- | --- | --- | --- |
| `feeds` | `nav.feeds` | `rss` | `admin.feeds.index` | `feeds.view` | 38 | group `Obchod` |
| `feeds.create` | `nav.feeds.create` | – | `admin.feeds.create` | `feeds.manage` | 39 | parent `feeds`, hidden |

Command palette: `feeds.index` (`palette.feeds.index`, `/admin/feeds`, icon `rss`, keywords `feed, heureka, google, merchant, xml`).

Routes (`register()`, middleware `auth`, controller `Http/FeedController.php`):

| Method | Path | Route name | Action | Permission |
| --- | --- | --- | --- | --- |
| GET | `/admin/feeds` | `admin.feeds.index` | `index` | `feeds.view` |
| GET | `/admin/feeds/create` | `admin.feeds.create` | `create` | `feeds.manage` |
| POST | `/admin/feeds` | `admin.feeds.store` | `store` | `feeds.manage` |
| GET | `/admin/feeds/{id}/edit` | `admin.feeds.edit` | `edit` | `feeds.manage` |
| POST | `/admin/feeds/{id}` | `admin.feeds.update` | `update` | `feeds.manage` |
| GET | `/admin/feeds/{id}/preview` | `admin.feeds.preview` | `preview` | `feeds.view` |
| POST | `/admin/feeds/{id}/delete` | `admin.feeds.destroy` | `destroy` | `feeds.manage` |

Screens (`Resources/views/`): `index.html.twig` (list ordered by `code`, `?q=` search over `code`/`name`), `form.html.twig` (create/edit; `config_json` textarea pre‑filled with `{"currency": "CZK", "base_url": ""}` on create; invalid JSON is silently stored as NULL), `preview.html.twig` (renders generated body and content type inline; 422 `feeds.error.generate_failed` if the feed is inactive or the driver is unknown).

Settings pages / keys: none.

## Public / REST API

`$context->api()->module('feeds', …)`; scopes `feeds.read`, `feeds.write`. Controller `Http/Api/FeedApiController.php`.

| Method | Path | Scope | Action | Notes |
| --- | --- | --- | --- | --- |
| GET | `/api/v1/feeds` | `feeds.read` | `index` | `ApiQuery`: search `code, name`; filters `driver`, `active` (boolean); sortable `code|name|createdAt` (default `code`); paginated collection |
| POST | `/api/v1/feeds` | `feeds.write` | `store` | body (form or JSON) `code`, `name`, `driver`, `format`, `config` (object) or `config_json` (string), `active` (default true); 422 `validation_error` `code: taken` |
| GET | `/api/v1/feeds/definitions/{id}` | `feeds.read` | `show` | serialized definition |
| PATCH | `/api/v1/feeds/definitions/{id}` | `feeds.write` | `update` | same body as store |
| DELETE | `/api/v1/feeds/definitions/{id}` | `feeds.write` | `destroy` | `{id, deleted: true}` |
| GET | `/api/v1/feeds/{code}` | `feeds.read` | `generate` | **Raw feed body** with the driver's `Content-Type` (not a JSON envelope); 404 when the code is unknown/inactive |

Serializer: `id, code, name, driver, format, active, config, created_at, updated_at`.

`/api/public/*`: none – the generated feed is only exposed through the scoped API route, so comparison engines need an API token (see `../architecture/api.md`).

## Events, hooks & webhooks

Platform events (`webhookEnabled(true)`, Czech descriptions), emitted with `EventBus::emit()` and subject `{type: feed_definition, id}`:

| Event | Emitted from | Payload |
| --- | --- | --- |
| `feed.created` | admin `store`, API `store` | serialized definition |
| `feed.updated` | admin `update`, API `update` | serialized definition |
| `feed.deleted` | admin `destroy`, API `destroy` | serialized definition (pre‑delete) |

Audit log (admin only): `feeds.created`, `feeds.updated`, `feeds.deleted`. Validation ruleset: `feeds.save` (`code` 1–64, `name` 2–190, `driver` ≤32, `format` ≤16, `config_json` ≤100000, `active`). Hooks, queue jobs, scheduled tasks, publishables: none.

## Dashboard widgets

| Widget id | Type | Class | Shows | Permission | Order |
| --- | --- | --- | --- | --- | --- |
| `feeds.shortcut` | shortcut | `Application/Widgets/FeedsShortcutWidget.php` | Link to feeds index (hint `admin.dashboard.hint.feeds`) | `feeds.view` | 38 |
| `feeds.stats` | metric | `Application/Widgets/FeedStatsWidget.php` | Count of active feeds; hint = total + last `updated_at` (`feeds.widget.stats.hint`/`hint_empty`) | `feeds.view` | 58 |
| `feeds.list` | panel (`list_panel.html.twig`) | `Application/Widgets/FeedsListWidget.php` | Up to 8 feeds (active first, then `updatedAt DESC`) with driver label, format, code, active badge, link to preview | `feeds.view` | 38 |

See `../architecture/dashboard-widgets.md`.

## Services & extension points

| Class | Responsibility |
| --- | --- |
| `Application/FeedsProvider.php` | Capability service. Holds the driver map keyed by `key()`; `find(id)`, `findByCode(code)`, `generate(code)` → `{content_type, body}` or `null` (inactive/unknown driver). `collectProducts(config)` loads `Product` rows with `status = 'active'` ordered by name, limit `config.limit` (default 5000) and maps them to a flat row: `id, sku, title/name, description, link/url` (`{base_url}/p/{sku}`), `image_link/imgurl` (always empty), `price` (`"<amount> <currency>"`), `price_vat`, `availability` (`in_stock`), `brand/manufacturer` (`config.default_brand`). `priceFor()` picks `ProductPrice` for `config.currency` (default `CZK`) or the first price found. |
| `Application/Drivers/GoogleMerchantDriver.php` | key `google_merchant`; `application/xml`; RSS 2.0 with `xmlns:g` – channel `title/link/description` from config, items `g:id, g:title, g:description, g:link, g:image_link, g:price, g:availability, g:brand`. |
| `Application/Drivers/HeurekaDriver.php` | key `heureka`; `application/xml`; `<SHOP><SHOPITEM>` with `ITEM_ID, PRODUCTNAME, DESCRIPTION, URL, IMGURL, PRICE_VAT, DELIVERY_DATE` (from `availability`), `MANUFACTURER`. |
| `Application/Drivers/CustomDriver.php` | key `custom`; `application/json`; pretty‑printed `{config, products}`. |

Extension: implement `FeedDriverInterface` – but the driver set is hard‑wired in `FeedsProvider::__construct()` (three concrete classes), so new drivers require code changes; there is no registry hook. Recognised `config` keys: `currency`, `base_url`, `limit`, `default_brand`, `title`, `link`, `description`.

## CLI commands

None.

## Configuration & translations

Settings/env: none. All per‑feed configuration lives in `FeedDefinition.config` JSON.

Translations: `Resources/translations/messages.{cs,en}.php` – prefixes `feeds.*` (`field`, `driver`, `error`, `flash`, `action`, `widget`), `nav.feeds*`, `palette.feeds.index`, `perm.feeds.*`, `perm.group.feeds`, `module.feeds.description`. Hint `admin.dashboard.hint.feeds` is expected from core.

## Database migrations

| File | Purpose |
| --- | --- |
| `Database/Migrations/2026_01_01_000001_feeds.php` | Creates `feed_definitions` (id, code, name, driver default `google_merchant`, format default `xml`, is_active, config JSON, timestamps; unique `code`, index `is_active`). `down()` drops it. |

## Notes

- `format` (`xml`/`tsv`/`csv`) is stored and shown but **ignored** by all drivers – output format is dictated by the driver alone; TSV/CSV are never produced.
- `image_link`/`imgurl` are always empty: product images from [Media](media.md)/Products are not resolved.
- `availability` is hard‑coded to `in_stock` (Heureka expects a delivery‑days number in `DELIVERY_DATE`, so the value `in_stock` is semantically wrong for that driver).
- Product `link` assumes a storefront URL scheme `{base_url}/p/{sku}`.
- Feed generation is synchronous and uncached; every request re‑queries up to 5000 products plus one or two `ProductPrice` lookups per product (N+1).
- `FeedsProvider` imports Products entities without `class_exists` guards; enabling Feeds without Products will fatal on generation.
- Event descriptions are Czech literals; `README.md` version (1.0.2) is out of sync with `module.php` (1.1.1).
