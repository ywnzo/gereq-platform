# MoneyS3 (`moneys3`)

Integration module for the **Money S3** accounting system (Seyfor). It wraps the Money S3 *S3 API* (OAuth2 password grant + GraphQL) in a thin client, lets administrators store credentials in platform settings, and synchronises three code lists — agendas, centres and numerical series — into a JSON setting so that other modules (e.g. [Orders](orders.md), [CarService](carservice.md)) can export issued invoices and cash vouchers to a chosen agenda. It is an *integration-style* module: no own database tables, no public API, one settings screen under **Integrations**.

## At a glance

| | |
|---|---|
| Module id | `moneys3` |
| Version | `1.99.0` (`modules/MoneyS3/module.php`, `MoneyS3Module::version()`) |
| Directory | `modules/MoneyS3/` |
| Requires | `core ^1.0`; optional: `orders ^1.0` |
| Capabilities | provides `moneys3` (registered with `MoneyS3Service` instance as the capability payload); consumes none |
| Groups / packages | none in `module.php`; not listed in `config/module_groups.php`. Listed as an *optional* dependency of `carservice` (`modules/CarService/module.php`). |
| Permissions | `moneys3.view` (View Money S3), `moneys3.manage` (Manage Money S3), `moneys3.sync` (Sync Money S3) — group `perm.group.moneys3` |
| Menu group | `Integrace` (menu id `moneys3`, order 79, icon `database`) |

## Domain model

None. The module has no Doctrine entities. All persisted state lives in platform settings (`Gereq\Setting\Settings`):

| Setting key | Type | Content |
|---|---|---|
| `moneys3.cache` | JSON | `{ "agendas": [...], "centres": [...], "numericalSeries": [...] }` — raw item arrays returned by the GraphQL API, written by `MoneyS3Service::storeCache()` |

The OAuth access token is cached in `Gereq\Cache\AppCache` under key `moneys3.access_token` (`MoneyS3Client::CACHE_KEY`) with `expires_at`; it is refreshed when fewer than 60 s remain.

## Admin UI

### Menu & command palette (`MoneyS3Module::boot()`)

| Kind | Id | Label key | Icon | Route / URL | Permission |
|---|---|---|---|---|---|
| Menu item | `moneys3` | `nav.moneys3` ("Money S3") | `database` | `admin.moneys3.settings` | `moneys3.view` (group `Integrace`, order 79) |
| Palette command | `moneys3.settings` | `palette.moneys3.settings` | `database` | `/admin/integrations/moneys3` | `moneys3.view` (keywords: moneys3, money, účetnictví, agenda, graphql) |

### Routes (`MoneyS3Module::register()`, group `/admin/integrations/moneys3`, middleware `auth`)

| Method | Path | Route name | Controller::action | Permission |
|---|---|---|---|---|
| GET | `/admin/integrations/moneys3` | `admin.moneys3.settings` | `MoneyS3SettingsController::edit` | `moneys3.view` |
| POST | `/admin/integrations/moneys3` | `admin.moneys3.settings.update` | `MoneyS3SettingsController::update` | `moneys3.manage` |
| POST | `/admin/integrations/moneys3/sync` | `admin.moneys3.sync` | `MoneyS3SettingsController::sync` | `moneys3.sync` |

### Screens

* **Settings** (`Resources/views/settings.html.twig`) — one page with an *API* section (enable switch, API URL, App ID, Client ID, Client Secret, Username, Password, default agenda GUID) and a *Agendas and series* section showing cached counts for agendas / centres / numerical series with a "Synchronize" button per list. The page also displays a status line: `moneys3.status.enabled`, `moneys3.status.configured` (credentials set but switch off) or `moneys3.status.missing`.
* `update()` iterates `MoneyS3SettingsController::KEYS`, converts form field names (`moneys3__api_url` → `moneys3.api_url`), stores booleans as `'1'`-checks and **skips empty secret fields** (type `encrypted` or key containing `secret`/`password`) so existing secrets are not wiped. Audit event: `moneys3.settings.updated`.
* `sync()` reads `list` from the POST body (`agendas` | `centres` | `numericalSeries`), calls the matching `MoneyS3Service::sync*()` and flashes `moneys3.sync.success` with `%list%`/`%count%` placeholders. Audit event: `moneys3.list.synced`. Unknown list → `moneys3.error.unknown_list`; missing default agenda → `moneys3.error.no_agenda`.

## Public / REST API

None. The module registers no `$context->api()` module and no public routes.

## Events, hooks & webhooks

* Platform events: none registered.
* Hooks: none.
* Queue jobs / scheduled tasks: none (synchronisation is manual via the settings screen).
* Publishables: none.
* Audit log entries: `moneys3.settings.updated` (subject `settings/moneys3`), `moneys3.list.synced` (subject `moneys3/<list>`).

## Dashboard widgets

Both widgets are registered in `MoneyS3Module::boot()`.

| Widget id | Type | Class | Shows | Permission | Order |
|---|---|---|---|---|---|
| `moneys3.shortcut` | shortcut | `Application/Widgets/MoneyS3ShortcutWidget` | Link to `admin.moneys3.settings`, icon `database`, hint `admin.dashboard.hint.moneys3` ("Export to Money S3") | `moneys3.view` | 79 |
| `moneys3.cache.stats` | metric | `Application/Widgets/MoneyS3CacheStatsWidget` | Number of cached agendas as the value; hint `moneys3.widget.stats.hint` with centres/series counts, or `moneys3.widget.stats.disabled` when the integration is off | `moneys3.view` | 64 |

Templates: `admin/dashboard/widgets/shortcut.html.twig`, `admin/dashboard/widgets/metric.html.twig`. See [Dashboard widgets](../architecture/dashboard-widgets.md).

## Services & extension points

| Class | Responsibility |
|---|---|
| `Application/MoneyS3Client` | Low-level S3 API client. `fetchAccessToken()` POSTs to `<api_url>/connect/token?AppId=…` with HTTP Basic (`client_id:client_secret`) and `grant_type=password`, `scope=s3api`; `sendRequest()` POSTs GraphQL to `<api_url>/graphql` with `Authorization: Bearer`, optional custom headers (the agenda is selected via header `agendaid`). Uses cURL when available, falls back to `file_get_contents`. Timeouts: connect 10 s, total 45 s. Exposes `getError()` with the last error message. Static helpers `buildQuery()`, `buildMutation()` generate GraphQL from PHP arrays (`where` conditions as `[field, op, value]` triples). |
| `Application/MoneyS3Service` | Application facade and the `moneys3` capability payload. `isEnabled()`, `defaultAgendaId()`, `getAgendas()`, `getCentres()`, `getNumericalSeries()`, `setIssuedInvoice(array $data)` (mutation `createIssuedInvoice`, XML transfer definition `_FP+FV`), `setCashVoucher(array $data)` (mutation `createCashVoucher`, definition `_PD`), `syncAgendas()/syncCentres()/syncNumericalSeries()` returning `{ok, count|message}`, and `cached(string $name)` reading from the `moneys3.cache` setting. |
| `Http/MoneyS3SettingsController` | Settings screen, save and sync actions (see above). |

### GraphQL operations used (`MoneyS3Client`)

| Method | Operation | Notes |
|---|---|---|
| `getAgendas()` | `{ agendas { items { name guid } } }` | no agenda header |
| `getCentres($agendaId)` | `{ centres { items { id name shortCut } } }` | header `agendaid` |
| `getNumericalSeries($agendaId)` | `{ numericalSeries { items { name number year prefix numberOfPlaces isInvoicesIssued } } }` | header `agendaid` |
| `getIssuedInvoices($agendaId, ?$documentNumber)` | `issuedInvoices(where: { documentNumber: { eq: … } })` | items with lines, centre, contact fields |
| `setIssuedInvoice($agendaId, $data)` | `mutation { createIssuedInvoice(issuedInvoice: {...}, definitionXMLTransfer: { shortCut: "_FP+FV" }) { guid isSuccess } }` | |
| `getCashVoucher($agendaId, ?$documentNumber)` | `cashVouchers(where: …)` | |
| `setCashVoucher($agendaId, $data)` | `mutation { createCashVoucher(cashVoucher: {...}, definitionXMLTransfer: { shortCut: "_PD" }) { guid isSuccess } }` | |

### Integration with other modules

Consumers obtain the service through the capability registry: `$capabilities->has('moneys3')` → `$capabilities->get('moneys3')` returns the `MoneyS3Service`. `module.php` lists `orders` as optional and [CarService](carservice.md) lists `moneys3` as optional, but at the time of writing no module in `modules/` references `Modules\MoneyS3\…` directly (only `modules/CarService/module.php` names the id). See [module system](../architecture/module-system.md) for the capability mechanism.

## CLI commands

None.

## Configuration & translations

### Settings (`MoneyS3Module::register()`, all module `moneys3`, not public)

| Key | Type | Default | Env fallback (`MoneyS3Client::setting()`) |
|---|---|---|---|
| `moneys3.enabled` | boolean | `false` | — |
| `moneys3.api_url` | string | `''` | `MONEYS3_API_URL` |
| `moneys3.app_id` | string | `''` | `MONEYS3_APP_ID` |
| `moneys3.client_id` | string | `''` | `MONEYS3_CLIENT_ID` |
| `moneys3.client_secret` | encrypted | `''` | `MONEYS3_CLIENT_SECRET` |
| `moneys3.username` | string | `''` | `MONEYS3_CLIENT_USERNAME` |
| `moneys3.password` | encrypted | `''` | `MONEYS3_CLIENT_PASSWORD` |
| `moneys3.default_agenda_id` | string | `''` | — |
| `moneys3.cache` | json | `[]` | — (written by sync) |

Settings take precedence; an empty setting falls back to the env variable. `isConfigured()` requires API URL, App ID, Client ID, Client Secret, Username and Password to be non-empty; `isEnabled()` additionally requires `moneys3.enabled = true`.

### Translations

`Resources/translations/messages.cs.php`, `messages.en.php`. Key prefixes: `module.moneys3.*`, `nav.moneys3`, `palette.moneys3.*`, `perm.moneys3.*`, `perm.group.moneys3`, `admin.dashboard.hint.moneys3`, `moneys3.settings.*`, `moneys3.status.*`, `moneys3.list.*`, `moneys3.action.*`, `moneys3.sync.*`, `moneys3.error.*`, `moneys3.widget.*`. Twig namespace: `@moneys3`.

## Database migrations

| File | Purpose |
|---|---|
| `Database/Migrations/2026_09_05_000001_moneys3.php` | Intentionally empty (`up()` contains only a comment) — the module stores its cache in the `moneys3.cache` setting and owns no tables. |

## Notes

* The `README.md` in the module still says `v1.0.1` while `module.php` / `MoneyS3Module::version()` are `1.1.0`.
* `MoneyS3Client` docblock: "inspirace athena _rpsCzech/MoneyS3" — the query/mutation builders are ported from that project.
* `sendRequest()` returns the decoded JSON even when the GraphQL response contains `errors`; callers must check `getError()` (the service's `sync*()` helpers only treat a `null` response as failure, so a GraphQL-level error with an empty `items` list is reported as a successful sync of 0 items).
* Error strings thrown from the HTTP layer are in Czech (`'Nelze inicializovat cURL.'`, `'HTTP požadavek selhal'`).
* No actual invoice/cash-voucher export flow exists in the module itself — `setIssuedInvoice()` / `setCashVoucher()` are public building blocks for other modules; nothing calls them yet.
* Related: [Orders](orders.md) (invoices that would be exported), [CarService](carservice.md), [iDoklad](idoklad.md) (the alternative accounting integration).
