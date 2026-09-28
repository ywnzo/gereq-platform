# iDoklad (`idoklad`)

iDoklad integrates GEREQ with the Czech online accounting service **iDoklad** (api.idoklad.cz v3, OAuth2 client‑credentials). The module keeps a mapping table between local entities (customers, service orders, …) and remote iDoklad objects (contacts, invoices, credit notes, cash vouchers, …), pushes customers as iDoklad contacts, lets other modules create accounting documents for a linked partner through the `idoklad` capability (used by [CarService](carservice.md) `IdokladFinishHandler`), browses remote document lists with PDF download, and caches iDoklad code lists (countries, currencies, numeric sequences, payment options, cash registers) in settings. It is part of the ECS package and used by accounting/back‑office staff.

## At a glance

| Item | Value |
| --- | --- |
| Module id | `idoklad` |
| Version | `1.99.0` (`modules/iDoklad/module.php`, `iDokladModule::version()`; `README.md` says v1.2.0) |
| Directory | `modules/iDoklad/` |
| Class | `Modules\iDoklad\iDokladModule` |
| Requires | `core ^1.0` |
| Optional | `customers ^1.0`, `orders ^1.0`, `currency ^1.1` |
| Capabilities provided | `idoklad` → `Application/iDokladService` |
| Capabilities consumed | `currency` (`CurrencyBuilder::syncIDokladIds()`, currency id lookup) – guarded |
| Groups / packages | `ecs` (`module.php`); `config/module_groups.php` group **ECS** lists `idoklad` (optional member) |
| Permissions | `idoklad.view` (`perm.idoklad.view`), `idoklad.manage` (`perm.idoklad.manage`), `idoklad.sync` (`perm.idoklad.sync`) – group `perm.group.idoklad` |

## Domain model

| Entity | Table | Key fields | Relations |
| --- | --- | --- | --- |
| `Domain/iDokladLink.php` | `idoklad_links` (unique `uniq_idoklad_links_entity` on `entity_type, entity_id, document_type`; index `idx_idoklad_links_external` on `idoklad_id`) | `id: string(36)` · `entity_type: string(64)` · `entity_id: string(36)` · `document_type: string(32)` (default `contact`) · `idoklad_id: string(64)` · `meta_json: ?text` (JSON: company, email, synced_at, …) · `created_at`, `updated_at` | polymorphic `(entity_type, entity_id)` → local record; `idoklad_id` → remote iDoklad object |

Known `entity_type` / `document_type` combinations in code: `customer`/`contact` (this module, `iDokladCustomerController`), `carservice_order`/`invoice` (CarService `IdokladFinishHandler`).

Document type catalogue (`iDokladService::documentTypes()`, not persisted):

| id | type | key | resource | group | pdf | writable |
| --- | --- | --- | --- | --- | --- | --- |
| 0 | `IssuedInvoice` | `issued_invoice` | `IssuedInvoices` | invoices | yes | yes |
| 1 | `ProformaInvoice` | `proforma_invoice` | `ProformaInvoices` | invoices | yes | yes |
| 2 | `CashVoucher` | `cash_voucher` | `CashVouchers` | cash | yes | yes |
| 3 | `CreditNote` | `credit_note` | `CreditNotes` | returns | yes | yes |
| 4 | `BankStatement` | `bank_statement` | `BankStatements` | bank | no | no |
| 5 | `ReceivedInvoice` | `received_invoice` | `ReceivedInvoices` | expenses | yes | yes |
| 6 | `SalesReceipt` | `sales_receipt` | `SalesReceipts` | income | yes | yes |
| 7 | `SalesOrder` | `sales_order` | `SalesOrders` | orders | no | no |
| 9 | `IssuedTaxDocument` | `issued_tax_document` | `IssuedTaxDocuments` | invoices | yes | no |
| 11 | `ReceivedReceipt` | `received_receipt` | `ReceivedReceipts` | expenses | no | no |

Labels `idoklad.document_type.<key>`.

## Admin UI

Menu (`boot()`):

| Id | Label | Icon | Route | Permission | Order | Placement |
| --- | --- | --- | --- | --- | --- | --- |
| `idoklad.documents` | `nav.idoklad.documents` | `receipt` | `admin.idoklad.documents` | `idoklad.view` | 56 | parent `carservice` |
| `idoklad` | `nav.idoklad` | `receipt` | `admin.idoklad.settings` | `idoklad.view` | 78 | group `Integrace` |

Command palette: `idoklad.documents` (`/admin/integrations/idoklad/documents`, keywords `idoklad, faktura, účetnictví, invoice, doklady, dobropis, pokladna`), `idoklad.settings` (`/admin/integrations/idoklad`).

Routes (`register()`, prefix `/admin/integrations/idoklad`, middleware `auth`):

| Method | Path | Route name | Controller::action | Permission |
| --- | --- | --- | --- | --- |
| GET | `/documents` | `admin.idoklad.documents` | `iDokladDocumentController::index` (`?type=issued_invoice&q=&page=`) | `idoklad.view` |
| GET | `/documents/{type}/{id}` | `admin.idoklad.documents.show` | `iDokladDocumentController::show` | `idoklad.view` |
| GET | `/documents/{type}/{id}/pdf` | `admin.idoklad.documents.pdf` | `iDokladDocumentController::pdf` (streams report PDF; 404 for types without `pdf`) | `idoklad.view` |
| GET | `` | `admin.idoklad.settings` | `iDokladSettingsController::edit` | `idoklad.view` |
| POST | `` | `admin.idoklad.settings.update` | `iDokladSettingsController::update` (keys `idoklad.enabled`, `idoklad.client_id`, `idoklad.client_secret`) | `idoklad.manage` |
| POST | `/sync` | `admin.idoklad.sync` | `iDokladSettingsController::sync` (`list` = one list name or `all`) | `idoklad.sync` |
| POST | `/customers/{id}/sync` | `admin.idoklad.customers.sync` | `iDokladCustomerController::sync` (push customer → contact, upsert link) | `idoklad.manage` |
| POST | `/customers/{id}/attach` | `admin.idoklad.customers.attach` | `iDokladCustomerController::attach` (`idoklad_id` of existing contact, verified via `getContact`) | `idoklad.manage` |
| POST | `/customers/{id}/unlink` | `admin.idoklad.customers.unlink` | `iDokladCustomerController::unlink` | `idoklad.manage` |

Screens (`Resources/views/`): `documents/index.html.twig` (type tabs from the catalogue, remote search and pagination, statuses `idoklad.status.*`), `documents/show.html.twig` (remote document detail with PDF button when `canPdf`), `settings.html.twig` (enable flag, client id/secret, connection state, list sync buttons with cached counts `idoklad.lists.*`), `partials/customer_partner.html.twig` (section injected into the customer detail: linked partner, remote contact preview, sync/attach/unlink forms).

Audit log: `idoklad.settings.updated`, `idoklad.list.synced`, `idoklad.customer.attached`, `idoklad.customer.unlinked`, plus the sync entry written in `iDokladCustomerController::sync` (see `Http/*Controller.php`).

## Public / REST API

None. The module does not register `/api/v1` routes; access to iDoklad data is available only through the admin UI and the `idoklad` capability. See `../architecture/api.md`.

## Events, hooks & webhooks

- Platform events: none registered.
- Hooks **added**: filter `customers.show.sections` (section `idoklad.partner`, order 90, requires `idoklad.view`; fetches remote contact when enabled) and action `customers.deleting` (`HookPriority::EARLY`, removes all `iDokladLink` rows with `entity_type = customer`). Both only when `Modules\Customers\Domain\Customer` exists.
- Queue jobs, scheduled tasks, publishables, webhooks: none. All API calls are synchronous from requests.
- Diagnostics: every API call is logged through `Gereq\Debug\FullServiceLogger` (`idoklad.api.request`, `idoklad.api.token_failed`, `idoklad.api.pdf.request`, …).

## Dashboard widgets

| Widget id | Type | Class | Shows | Permission | Order |
| --- | --- | --- | --- | --- | --- |
| `idoklad.shortcut` | shortcut | `Application/Widgets/iDokladShortcutWidget.php` | Link to iDoklad documents (hint `admin.dashboard.hint.idoklad`) | `idoklad.view` | 78 |
| `idoklad.links.stats` | metric | `Application/Widgets/iDokladLinksStatsWidget.php` | Number of `idoklad_links` (hint per type / `hint_empty`) | `idoklad.view` | 62 |
| `idoklad.links.recent` | panel | `Application/Widgets/iDokladRecentLinksWidget.php` | Most recently created/updated links with entity type and document type subtitle | `idoklad.view` | 34 |

See `../architecture/dashboard-widgets.md`.

## Services & extension points

| Class | Responsibility |
| --- | --- |
| `Application/HttpJsonClient.php` | Thin cURL JSON client (`request(method, url, headers, body, query)`), user agent `GEREQ-Platform/1.0 (+idoklad)`. |
| `Application/iDokladApiClient.php` | Low‑level iDoklad v3 client. Token endpoint `https://identity.idoklad.cz/server/connect/token` (`grant_type=client_credentials`, `scope=idoklad_api`), access token cached in `AppCache` under `idoklad.access_token` (expires −30 s). `isConfigured()`, `isEnabled()`. Typed wrappers: code lists (`getListCountries/Currencies/PaymentOptions/CashRegisters`, `getNumericSequences`, `getNumericSequenceInfo`), contacts (`setContact`, `getContact`), documents (`set/get/getList` for `Invoice`, `CreditNote`, `CashVoucher`, `ProformaInvoice`, `ReceivedInvoice`, `SalesReceipt`; read‑only `BankStatement`, `SalesOrder`, `IssuedTaxDocument`, `ReceivedReceipt`), `listResource()`, `getReport()`, `getReportPdf()` (binary), generic `api(method, path, query, body)` returning `{isSucceed, code, message, data, items, totalItems, totalPages}`. |
| `Application/iDokladService.php` | Capability facade / mapping layer: `documentTypes()`, `resolveDocumentType()`, `listDocuments(type, page, pageSize, search)`, `setContact(userData{first_name, last_name, company, phone, email, ico, dic, street, city, zip, country}, iDokladId?)` → iDoklad contact payload (`Firstname`, `Surname`, `CompanyName`, `IdentificationNumber`, `VatIdentificationNumber`, `CountryId`, …), `getContact()`, `setDocument(documentData, partnerId, iDokladId?)` dispatching by type key to normalisers (`normalizeIssuedDocument`, `normalizeCreditNote`, `normalizeCashVoucher`, `normalizeReceivedInvoice`, `normalizeSalesReceipt`; `baseDocumentPayload()` fills `PartnerId`, `CurrencyId`, `PaymentOptionId`, `NumericSequenceId`, dates, `Items` via `mapInvoiceItems()/mapCashItems()`), `getDocument()`, `getDocumentReport()`, `downloadDocumentPdf()`, link registry (`findLink`, `rememberLink(entityType, entityId, iDokladId, documentType, meta)`, `forgetLink`), resolvers (`resolveCountryId`, `resolveCurrencyId` – via [Currency](currency.md) `CurrencyBuilder` when available, else cached list; `resolveDefaultPaymentOptionId`, `resolveDefaultNumericSequence(documentType)`), `syncList(name)` / `cachedList(name)` for `countries`, `currencies`, `numericSequences`, `paymentOptions`, `cashRegisters` stored in setting `idoklad.lists`; syncing `currencies` also calls `CurrencyBuilder::syncIDokladIds()`. |

Extension points: the `idoklad` capability (`iDokladService`) and the `iDokladLink` registry with free‑form `entity_type`/`document_type`. Consumers: [CarService](carservice.md) `Application/IdokladFinishHandler.php` and `FinishWizardService.php` (issue invoice for a finished service order using the customer's `contact` link, storing a `carservice_order`/`invoice` link). Customer address data is read from [Customers](customers.md) `Customer` / `CustomerAddress` (guarded `class_exists`).

## CLI commands

None.

## Configuration & translations

Settings (`register()`, all non‑public):

| Key | Type | Default | Label |
| --- | --- | --- | --- |
| `idoklad.enabled` | boolean | `false` | `idoklad.settings.enabled` |
| `idoklad.client_id` | string | `` | `idoklad.settings.client_id` |
| `idoklad.client_secret` | encrypted | `` | `idoklad.settings.client_secret` |
| `idoklad.lists` | json | `[]` | `idoklad.settings.lists` (cache of code lists, written by `syncList()`) |

No env vars; endpoints are constants in `iDokladApiClient` (`https://api.idoklad.cz/v3`, identity server). Token cache uses the core `AppCache`.

Translations: `Resources/translations/messages.{cs,en}.php` (115 EN keys) – prefixes `idoklad.customer.*`, `idoklad.documents.*`, `idoklad.settings.*`, `idoklad.document_type.*`, `idoklad.widget.*`, `idoklad.error.*`, `idoklad.lists.*`, `idoklad.list.*`, `idoklad.sync.*`, `idoklad.status.*`, `nav.idoklad*`, `palette.idoklad.*`, `perm.idoklad.*`, `perm.group.idoklad`, `module.idoklad.description`, `admin.dashboard.hint.idoklad`.

## Database migrations

| File | Purpose |
| --- | --- |
| `Database/Migrations/2026_09_05_000001_idoklad.php` | No‑op marker; SchemaTool creates `idoklad_links` from the entity. |

## Notes

- Single entity module; all remote data (documents, contacts) is read live from iDoklad on every page view – there is no local cache of documents, and the document list/detail pages fail with flash/404 when the API is disabled or unreachable.
- `optional` declares `orders ^1.0` but no code in the module references the Orders module; the only document producer is CarService.
- The customer detail hook performs a remote `getContact()` call on each customer page render when a link exists and the integration is enabled.
- Access token is cached process‑wide via `AppCache`; there is no refresh‑token flow (client credentials are re‑requested when expired).
- `README.md` version (1.2.0) lags `module.php` (1.3.0).
