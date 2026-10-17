# Customer Uploads (`customer_uploads`)

Customer Uploads lets anonymous storefront visitors (the Vue/React shop frontend) upload image files – typically product photos or attachments for a configurable product – before an order exists, and later link those files to an order number. Uploads are scoped to a short‑lived, HMAC‑signed **upload session token**, stored on local disk under `storage/uploads/customer`, and purged after a retention period by a CLI command. There is no admin CRUD UI; administrators only see a dashboard metric and three settings. The module is a runtime dependency of [Shop](shop.md) (`modules/Shop/module.php` requires `customer_uploads ^1.0`).

## At a glance

| Item | Value |
| --- | --- |
| Module id | `customer_uploads` |
| Version | `2.0.0` (`modules/Shop/Submodules/CustomerUploads/module.php`, `CustomerUploadsModule::version()`) |
| Directory | `modules/Shop/Submodules/CustomerUploads/` |
| Class | `Modules\Shop\Submodules\CustomerUploads\CustomerUploadsModule` |
| Requires | `core ^1.0` |
| Optional | none |
| Capabilities provided | `customer_uploads` (registered in `register()` with `CustomerUploadService` as the capability service) |
| Capabilities consumed | none |
| Groups / packages | none in `module.php`; not listed in `config/module_groups.php`. `ModuleInspector` treats it as a *runtime module* (`customeruploads`/`customer_uploads`) and `src/Admin/Http/SettingsController.php` renders its settings under the `customer_uploads` settings group (label `settings.group.customer_uploads` = "Uploads"). |
| Permissions | `uploads.view` (`perm.uploads.view`), `uploads.manage` (`perm.uploads.manage`) – group `perm.group.uploads` |

See `../architecture/module-system.md` for the manifest/`register()`/`boot()` lifecycle.

## Domain model

Single entity, `modules/Shop/Submodules/CustomerUploads/Domain/CustomerUpload.php`.

| Entity | Table | Key fields | Relations |
| --- | --- | --- | --- |
| `CustomerUpload` | `customer_uploads` | `id: string(36)` UUID v7 PK · `session_hash: string(64)` (SHA‑256 of the session secret, indexed `idx_customer_uploads_session`) · `product_id: ?string(36)` (indexed `idx_customer_uploads_product`) · `order_number: ?string(64)` (indexed `idx_customer_uploads_order`) · `original_name: string(255)` · `stored_name: string(255)` (generated `YmdHis-<16 hex>.<ext>`) · `mime: string(120)` · `size: int` · `promotional_consent: bool` (default false) · `meta_json: ?text` (JSON, exposed via `meta()`/`setMeta()`) · `created_at: datetime_immutable` | Soft references only: `product_id` → [Products](products.md) product id, `order_number` → [Orders](orders.md) order number. No Doctrine associations. |

Statuses/enums: none. Allowed MIME types are a service constant: `image/jpeg`, `image/png`, `image/webp` (`CustomerUploadService::ALLOWED`).

## Admin UI

Menu items: none (the module registers nothing in `boot()` besides the dashboard widget).

Admin routes: none.

Settings page: the three settings below appear on the platform settings screen in the "Uploads" group (`settings.group.customer_uploads`), rendered by `src/Admin/Http/SettingsController.php`.

| Setting key | Type | Default | Label key |
| --- | --- | --- | --- |
| `uploads.max_bytes` | integer | `26214400` (25 MB) | `uploads.settings.max_bytes` |
| `uploads.session_ttl_seconds` | integer | `86400` | `uploads.settings.session_ttl` |
| `uploads.retention_days` | integer | `7` | `uploads.settings.retention_days` |

## Public / REST API

The module does **not** use `$context->api()->module(...)`; all routes are registered directly on the router in `CustomerUploadsModule::register()`, so they carry no API middleware, scopes or permission checks. Authorization is the upload session token only.

### Session token

`CustomerUploadService::createSession()` returns `{token, expires_at, max_bytes, allowed_mimes}`. The token is `base64(json{t, exp}) . "." . hmac_sha256(payload, key)`; the key is `app.key` from config, falling back to setting `uploads.signing_key` (default `gereq-uploads`). TTL is `uploads.session_ttl_seconds` clamped to ≥300 s. `verifySession()` returns `sha256(t)` which becomes the record's `session_hash`.

The token is read from header `X-Upload-Session`, POST field `session_token`, or query `?session_token=` (`requireSession()` in both controllers).

### `/api/v1/uploads` (`Http/Api/UploadApiController.php`)

| Method | Path | Route name | Action | Auth |
| --- | --- | --- | --- | --- |
| GET | `/api/v1/uploads/session` | `api.uploads.session` | `session` – issue a token | none |
| POST | `/api/v1/uploads/files` | `api.uploads.store` | `store` – multipart `file`, optional `product_id`; 201 with serialized upload | session token |
| POST | `/api/v1/uploads/consent` | `api.uploads.consent` | `consent` – body `id`, `consent` (bool, default true); sets `promotional_consent` | session token, must own upload |
| POST | `/api/v1/uploads/link` | `api.uploads.link` | `link` – body `order_number`, `upload_ids[]`; returns `{linked, order_number}` | session token, only uploads with same `session_hash` are linked |
| GET | `/api/v1/uploads/files/{id}` | `api.uploads.file` | `file` – streams the binary (`BinaryFileResponse`, inline) | **none** (no session check on this variant) |

### `/api/public/store/uploads` (`Http/Public/PublicStoreUploadController.php`)

CORS‑enabled variant for the storefront (`Gereq\Http\PublicCors::apply`, methods `GET, POST, OPTIONS`).

| Method | Path | Route name | Action |
| --- | --- | --- | --- |
| OPTIONS | `/api/public/store/uploads/session` | `api.public.store.uploads.session.options` | CORS preflight (204) |
| OPTIONS | `/api/public/store/uploads/files` | `api.public.store.uploads.files.options` | CORS preflight |
| OPTIONS | `/api/public/store/uploads/files/{id}` | `api.public.store.uploads.file.options` | CORS preflight |
| GET | `/api/public/store/uploads/session` | `api.public.store.uploads.session` | issue token |
| POST | `/api/public/store/uploads/files` | `api.public.store.uploads.store` | upload (same validation as v1) |
| GET | `/api/public/store/uploads/files/{id}` | `api.public.store.uploads.file` | stream file – **requires** session token matching `session_hash` |

Error codes (`ApiResponse::error`): `unauthorized` (401), `validation_error` (422) with messages `uploads.error.invalid_mime`, `uploads.error.too_large`, `uploads.error.not_image`, `Missing file`; `RuntimeException('uploads.error.storage')` on disk failures.

### Serializer (`CustomerUploadService::serialize()`)

`id`, `product_id`, `order_number`, `original_name`, `mime`, `size`, `promotional_consent`, `url` (always `/api/public/store/uploads/files/{id}`), `created_at` (ATOM).

Filters: none (no list endpoint). See `../architecture/api.md` for the general API conventions and `../frontend/public-frontends.md` for storefront usage.

## Events, hooks & webhooks

None. No `EventDefinition`, hooks, queue jobs, automation tasks or publishables are registered.

## Dashboard widgets

| Widget id | Type | Class | Shows | Permission | Order |
| --- | --- | --- | --- | --- | --- |
| `customer_uploads.stats` | metric | `Application/Widgets/CustomerUploadsStatsWidget.php` | Total `COUNT(customer_uploads)`; label `uploads.widget.title`, hint `uploads.widget.hint`; template `admin/dashboard/widgets/metric.html.twig` | `uploads.view` | 55 |

Registered in `boot()`. See `../architecture/dashboard-widgets.md`.

## Services & extension points

| Class | Responsibility |
| --- | --- |
| `Application/CustomerUploadService.php` | Whole business logic: `createSession()`/`verifySession()` (HMAC token), `store()` (MIME whitelist, size ≥1 and ≤ `maxBytes()` where max is clamped to ≥100 KB, `getimagesize()` check, move/rename/copy to `storage/uploads/customer`), `linkToOrder()`, `setConsent()`, `absolutePath()`, `serialize()`, `purgeExpired(days, dryRun)`. Constructor takes `EntityManagerInterface`, `Settings`, `Config\Repository`, `string $basePath`. |

Extension points:

- Capability `customer_uploads` resolves to `CustomerUploadService`, so other modules (e.g. [Shop](shop.md) checkout) can call `linkToOrder()` without a hard class dependency.
- No interfaces/contracts, no hooks.

## CLI commands

| Command | Class | Purpose |
| --- | --- | --- |
| `uploads:purge [--days=N] [--dry-run]` | `Console/PurgeUploadsCommand.php` | Deletes uploads (file + row) older than `--days` or setting `uploads.retention_days`; prints `PURGE/DRY-RUN — candidates N, deleted N`. Registered centrally in `src/Console/ConsoleKernel.php`. |

## Configuration & translations

Settings (defined in `register()`): `uploads.max_bytes` (25 MB), `uploads.session_ttl_seconds` (86400), `uploads.retention_days` (7). Read‑only fallback setting (not defined, only read): `uploads.signing_key` (default `gereq-uploads`), used only when config `app.key` is empty.

Env/config: `app.key` (token signing); base path injected as constructor argument (`storage/uploads/customer` is derived from it).

Translations: `Resources/translations/messages.{cs,en}.php` – prefixes `uploads.widget.*`, `uploads.settings.*` (+ `.help`), `module.customer_uploads.description`. Permission labels `perm.uploads.*`/`perm.group.uploads` and settings group label `settings.group.customer_uploads` live in the core `resources/translations/messages.*.php`. Error message keys `uploads.error.*` are thrown as exception messages but have **no translation entries** in the module.

## Database migrations

None. The module has no `Database/Migrations/` directory; the `customer_uploads` table is expected to be created by Doctrine schema tooling / core migrations. (Observation: `README.md` only contains the module name and version.)

## Notes

- `/api/v1/uploads/files/{id}` serves any upload by UUID without a session check, whereas the `/api/public/store/...` variant requires a matching session. UUIDv7 ids are hard to guess but not secret.
- The v1 routes are plain router routes, not `ApiRegistry` routes, so they are not covered by API scopes/tokens described in `../architecture/api.md`.
- `linkToOrder()` silently skips ids that do not exist or belong to another session; response only reports the count.
- Retention purge is manual (CLI) – no scheduled task registers `uploads:purge`.
- `PurgeUploadsCommand` mixes Czech in option/description strings (`'Jen spočítat'`, `retention %d dní`).
- Only images are accepted (`getimagesize` gate), despite the entity docblock mentioning generic "attachments".
