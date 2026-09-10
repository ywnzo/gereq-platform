# Documents (`documents`)

Documents is the platform's generic file/attachment store for the admin: users upload files (PDF, images, Office…) through `Gereq\File\FileManager`, give them a title and optionally **link** them to records of other modules (`customer`, `deal`, `fleet_vehicle`, `carservice_order`, `bikeservice_order`) through polymorphic `DocumentLink` rows. The module also ships a reusable **PDF generation pipeline** (`Application/Pdf/*`, Twig → Dompdf) used by [CarService](carservice.md), [BikeService](bikeservice.md) and [iDoklad](idoklad.md) to persist generated or downloaded PDFs as documents. Used by back‑office staff; other modules consume it through the `documents` capability.

## At a glance

| Item | Value |
| --- | --- |
| Module id | `documents` |
| Version | `1.4.0` (`modules/Documents/module.php`, `DocumentsModule::version()`; `README.md` still says v1.3.4) |
| Directory | `modules/Documents/` |
| Class | `Modules\Documents\DocumentsModule` |
| Requires | `core ^1.0` |
| Optional | none declared; runtime‑guarded integrations with `customers`, `crm`, `fleet`, `carservice`, `bikeservice` (`ModuleManager::isEnabled()` + `class_exists`) |
| Capabilities provided | `documents` → `Application/DocumentsProvider` |
| Capabilities consumed | `carservice` (only to decide whether vehicle/order filters are shown) |
| Groups / packages | `module.php`: `ecs`; `config/module_groups.php`: listed in `ecs`, `ebs`, `realestates` (not required in any) |
| Permissions | `documents.view` (`perm.documents.view`), `documents.manage` (`perm.documents.manage`) – group `perm.group.documents` |

## Domain model

| Entity | Table | Key fields | Relations |
| --- | --- | --- | --- |
| `Domain/Document.php` | `documents` | `id: string(36)` UUID v7 · `title: string(190)` · `path: string(255)` (relative path in `FileManager` filesystem) · `original_name: string(255)` · `mime: string(120)` · `size: int` · `uploaded_by: ?string(36)` (admin user id) · `created_at`. Non‑ORM form‑only props `entityType`, `entityId`. Helpers `isImage()`, `isPdf()`, `fileIcon()`. | `uploaded_by` → core user id |
| `Domain/DocumentLink.php` | `document_links` (index `idx_doc_link_entity` on `entity_type, entity_id`) | `id: string(36)` · `document_id: string(36)` · `entity_type: string(80)` · `entity_id: string(36)` · `created_at` | `document_id` → `Document.id`; `(entity_type, entity_id)` → polymorphic target: `customer` ([Customers](customers.md)), `deal` ([CRM](crm.md)), `fleet_vehicle` ([Fleet](fleet.md)), `carservice_order` ([CarService](carservice.md)), `bikeservice_order` ([BikeService](bikeservice.md)); other modules may write arbitrary types via `PdfPersistOptions` |

`Document` has `#[FormEntity('documents.save')]` + `#[FormGenerator(exclude: [id, path, originalName, mime, size, uploadedBy, createdAt], cols: 1)]`; only `title` is a `#[FormField]` (`documents.field.title`).

Domain events (`Domain/Events/`): `AbstractDocumentEvent` implements `Gereq\Event\PlatformEvent` (envelope version `1`, actor defaults to `{type: system}`); concrete `DocumentUploaded` (`document.uploaded`), `DocumentLinked` (`document.linked`), `DocumentDeleted` (`document.deleted`), each `make(id, data, actor)` with resource `{type: 'document', id}`.

Statuses/enums: none.

## Admin UI

Menu (`boot()`):

| Id | Label | Icon | Route | Permission | Order | Notes |
| --- | --- | --- | --- | --- | --- | --- |
| `documents` | `nav.documents` | `file` | `admin.documents.index` | `documents.view` | 35 | group `Hlavní` |
| `documents.upload` | `nav.documents.upload` | – | `admin.documents.upload` | `documents.manage` | 36 | parent `documents`, hidden |

Command palette: `documents.index` (`palette.documents.index`, `/admin/documents`, keywords `dokumenty, soubory, documents, files`), `documents.upload` (`palette.documents.upload`, `/admin/documents/upload`, icon `plus`, group `Akce`, permission `documents.manage`).

Routes (`register()`, middleware `auth`, controller `Http/DocumentController.php`):

| Method | Path | Route name | Action | Permission |
| --- | --- | --- | --- | --- |
| GET | `/admin/documents` | `admin.documents.index` | `index` | `documents.view` |
| GET | `/admin/documents/upload` | `admin.documents.upload` | `create` | `documents.manage` |
| GET | `/admin/documents/entity-lookup` | `admin.documents.entity_lookup` | `entityLookup` (JSON) | `documents.manage` |
| POST | `/admin/documents` | `admin.documents.store` | `store` | `documents.manage` |
| POST | `/admin/documents/bulk` | `admin.documents.bulk` | `bulk` | `documents.manage` |
| GET | `/admin/documents/{id}` | `admin.documents.show` | `show` | `documents.view` |
| GET | `/admin/documents/{id}/file` | `admin.documents.file` | `file` (inline `BinaryFileResponse`, ETag/Last‑Modified, `private, max-age=300`, ASCII‑safe filename) | `documents.view` |
| POST | `/admin/documents/{id}/link` | `admin.documents.link` | `link` | `documents.manage` |
| POST | `/admin/documents/{id}/delete` | `admin.documents.destroy` | `destroy` | `documents.manage` |

Screens (`Resources/views/`):

- **List** `index.html.twig` – `ListQuery` (sortable `title|mime|size|createdAt`, default `createdAt desc`), search over `title, originalName, mime`, filter `mime` (exact or `image/*` prefix form), and – when the `carservice` capability plus `CarService\Domain\ServiceOrder` and `Fleet\Domain\Vehicle` exist – `vehicle` (license plate) and `order` (service order) filters implemented through `document_links` with `entity_type = carservice_order`. Bulk action bar (`action=delete`, requires `confirmed`).
- **Upload form** `form.html.twig` – title + file + optional entity link (`partials/entity_link_fields.html.twig`; type select fed by `linkableEntityTypes()`, record picker uses `entity-lookup` JSON endpoint `?type=&q=&limit=` → `{data: [{value,label,description,search}], meta}`). Query params `entity_type`, `entity_id`, `entity_label` pre‑fill the link.
- **Detail** `show.html.twig` – preview (image/PDF), metadata, list of links, "Link to entity" form.
- **Partial** `partials/customer_documents.html.twig` – injected into the customer detail page via hook (see below). `partials/document_list.html.twig` – reusable list.
- **PDF layouts** `pdf/base.html.twig`, `pdf/simple.html.twig` (keys `documents.pdf.label`, `documents.pdf.value`) – base templates for `PdfRenderer`.

Settings pages / keys: none.

## Public / REST API

Registered via `$context->api()->module('documents', …)`; scopes `documents.read`, `documents.write`. Controller `Http/Api/DocumentApiController.php`.

| Method | Path | Scope | Action | Notes |
| --- | --- | --- | --- | --- |
| GET | `/api/v1/documents` | `documents.read` | `index` | `ApiQuery`: `search` over `title, originalName`; sortable `title|createdAt|size|mime` (default `createdAt`); filters `mime` (exact), `entity_type` + `entity_id` (both required, `EXISTS` on `document_links`); paginated `ApiResponse::collection` with `meta` |
| POST | `/api/v1/documents` | `documents.write` | `store` | multipart: `file` (required), `title`, `entity_type`, `entity_id` (ruleset `documents.save`); 201; dispatches `DocumentUploaded` (+ `DocumentLinked`). Error codes `validation_error`, `upload_failed`. Entity type is **not** validated against the allowed list here |
| GET | `/api/v1/documents/{id}` | `documents.read` | `show` | serialized document + `links[]` (`id, entity_type, entity_id, created_at`) |
| DELETE | `/api/v1/documents/{id}` | `documents.write` | `destroy` | removes links + document row, dispatches `DocumentDeleted`; returns `{id, deleted: true}` |

Serializer (`serialize()` / admin `publicDocument()`): `id, title, path, original_name, mime, size, uploaded_by, created_at`. No file download endpoint exists in the API (use admin `admin.documents.file`).

`/api/public/*`: none. See `../architecture/api.md`.

## Events, hooks & webhooks

Platform events (`registerPlatformEvents()`, all `webhookEnabled(true)`, Czech descriptions):

| Event | Dispatched from | Payload |
| --- | --- | --- |
| `document.uploaded` | admin `store`, API `store` | serialized document |
| `document.linked` | admin `store`/`link` (`attachLink()`), API `store` | serialized document + `entity_type`, `entity_id`, `link_id` |
| `document.deleted` | admin `destroy`/`bulk`, API `destroy` | serialized document (pre‑delete) |

Events are dispatched as `PlatformEvent` objects (`$events->dispatch(DocumentUploaded::make(...))`), not string emits. Audit log (admin only): `document.uploaded`, `document.linked`, `document.deleted`.

Hooks added (`registerHooks()` in `boot()`):

| Hook | Kind | Behaviour |
| --- | --- | --- |
| `customers.show.sections` | filter (`HookPriority::NORMAL`) | Appends section `{id: documents, label: documents.partial.title, order: 40, html}` with the last 20 documents linked to the customer, only if the user `can('documents.view')`. |
| `customers.deleting` | action (`HookPriority::EARLY`) | Removes all `DocumentLink` rows with `entity_type = customer` for the deleted customer (documents themselves are kept). |

Note `DocumentsModule` imports `Modules\Customers\Domain\Customer` unguarded for the closure type hints.

Validation rulesets: `documents.save` (`title` required ≤190, `entity_type` ≤80, `entity_id` ≤36), `documents.link` (both required), `documents.bulk` (`action in:delete`, `confirmed`). Queue jobs, scheduled tasks, publishables: none.

## Dashboard widgets

| Widget id | Type | Class | Shows | Permission | Order |
| --- | --- | --- | --- | --- | --- |
| `documents.shortcut` | shortcut | `Application/Widgets/DocumentsShortcutWidget.php` | Link to documents index (hint `admin.dashboard.hint.documents`) | `documents.view` | 35 |
| `documents.stats` | metric | `Application/Widgets/DocumentStatsWidget.php` | `COUNT(documents)`; hint = total bytes (`formatBytes()`) and uploads in last 30 days | `documents.view` | 52 |
| `documents.recent` | panel (`list_panel.html.twig`) | `Application/Widgets/RecentDocumentsWidget.php` | Last 8 documents with icon (image/pdf/file), size, upload day/time, link to detail | `documents.view` | 24 |

See `../architecture/dashboard-widgets.md`.

## Services & extension points

| Class | Responsibility |
| --- | --- |
| `Application/DocumentsProvider.php` | Capability service: `find(id)`, `search(query, limit)` (title/originalName via `self_repair_search`), `forEntity(entityType, entityId, limit)` (documents linked to a record, newest first). Used by CarService/BikeService order controllers (`capabilities()->has('documents')`), `CarService\Application\IdokladFinishHandler`, and the [Ai](ai.md) tools `DocumentsSearchTool`, `DocumentsReadTextTool`, `ListingsGetTool`, `ListingsSearchTool`. |
| `Application/Pdf/PdfDocumentType.php` | **Interface** other modules implement: `type()`, `view()` (Twig), `data()`, `title()`, `suggestedFilename()`. |
| `Application/Pdf/ArrayPdfDocument.php` | Ad‑hoc `PdfDocumentType` built from constructor args. |
| `Application/Pdf/PdfRenderer.php` | `renderHtml()`, `htmlToBinary()`, `render()`, `renderDocument()`; uses `Dompdf\Dompdf` when installed (`isRemoteEnabled` from options, paper/orientation), otherwise falls back to returning HTML (`text/html`, ext `html`). Unit‑tested in `tests/Unit/PdfRendererTest.php`. |
| `Application/Pdf/PdfRenderOptions.php` | `paper` (A4), `orientation` (portrait), `remoteEnabled` (false); factory `a4()`. |
| `Application/Pdf/PdfRenderResult.php` | `binary`, `mime`, `extension`, `isPdf()`. |
| `Application/Pdf/PdfPersistOptions.php` | `directory` (relative under uploads), `entityType`/`entityId`, `uploadedBy`, `filename`, `extraLinks[]`. |
| `Application/Pdf/PdfDocumentGenerator.php` | Orchestrator: `render()`, `generate(type, persist, options)` → writes file via `FileManager::filesystem()->write()` with a random suffix (`uniqueBasename`), creates `Document` + de‑duplicated `DocumentLink`s; `storeBinary()` for already‑finished PDFs (e.g. downloaded from iDoklad). Consumers: `CarService\Application\ServiceDocumentService`, `BikeService\Application\ServiceDocumentService`, `iDoklad\Http\iDokladDocumentController`. |

File storage itself is core `Gereq\File\FileManager` (`store(UploadedFile, user)`, `absolutePath(path)`, `filesystem()`).

## CLI commands

None.

## Configuration & translations

Settings/env: none defined by the module. PDF output depends on the optional Composer package `dompdf/dompdf` (checked with `class_exists`).

Translations: `Resources/translations/messages.{cs,en}.php` – prefixes `documents.*` (`bulk`, `error`, `entity`, `filter`, `field`, `flash`, `link`, `links`, `partial`, `pdf`, `widget`), `nav.documents*`, `palette.documents.*`, `perm.documents.*`, `perm.group.documents`, `module.documents.description`. Widget hint `admin.dashboard.hint.documents` and `carservice.status.*` (used in order filter labels) come from other bundles.

## Database migrations

| File | Purpose |
| --- | --- |
| `Database/Migrations/2026_01_01_000001_init.php` | Empty `up()`/`down()` placeholder – the `documents` and `document_links` tables are not created by a module migration (schema is expected from core/Doctrine tooling). |

## Notes

- The API `store` accepts any `entity_type`, while the admin UI restricts links to `linkableEntityTypes()`; the API also does not write audit log entries.
- Deleting a document removes the DB rows but **does not delete the file** from the `FileManager` filesystem (both admin and API paths).
- `customers.deleting` hook removes links only for `entity_type = customer`; links to deleted deals/vehicles/orders of other modules are not cleaned up here.
- `file()` computes `isNotModified()` but returns the same response either way (no‑op branch).
- `Document::fileIcon()` returns `file` for every non‑PDF type (the `text/`/`html` branch is redundant).
- `PdfRenderer` silently degrades to HTML output when Dompdf is missing; `PdfRenderResult::extension` becomes `html` and consumers store an `.html` "PDF".
- Filter option lists for plates/orders load up to 500 vehicles and 500 orders on each list render when CarService is enabled.
- `README.md` version (1.3.4) is out of sync with `module.php` (1.4.0).
