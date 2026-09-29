# Vyhledávače / Search engines (`searchengines`)

The SearchEngines module gives admin users a live price-comparison search across public Czech comparison engines and e-shops — Heureka, Seznam Zboží (zbozi.cz), Hledejceny, Autodíly Jama, Autodílypro, AutoKelly and Mojekolo. It scrapes the public sites (cURL with a browser User-Agent, or a Python `curl_cffi` helper for Heureka's Cloudflare-protected pages), normalises prices through the [Currency](currency.md) module (net of VAT, converted to the display currency) and renders them in an Alpine.js search screen that streams results per source. It is a tooling module for bike-service (EBS) and car-service (ECS) operators; [PartsCatalog](partscatalog.md) and [CarService](carservice.md) reuse its capability for OEM part price lookups. It stores nothing in the database.

## At a glance

| Item | Value |
| --- | --- |
| Module id | `searchengines` |
| Version | `1.99.0` (`modules/SearchEngines/module.php`, `SearchEnginesModule::version()`) |
| Directory | `modules/SearchEngines/` |
| Requires | `core ^1.0`, `currency ^1.3` |
| Optional | none |
| Capabilities provided | `searchengines` (capability object `SearchEnginesProvider`) |
| Capabilities consumed | `Gereq\Currency\Application\CurrencyBuilder` (hard dependency in `SearchController`) |
| Groups / packages | manifest `groups: ['ebs', 'ecs']`; `config/module_groups.php` lists it in **`ebs`** (not required); `ecs` membership comes only from the manifest merge |
| Permissions | `searchengines.view` (`perm.searchengines.view`), `searchengines.manage` (`perm.searchengines.manage`) — group `perm.group.searchengines` |
| API scopes | none |
| Author | GEREQ |

Manifest name is Czech (`Vyhledávače`), icon `search`, description key `module.searchengines.description`.

## Domain model

None. The module has no `Domain/` directory, no entities and no tables. All data is fetched on demand from remote sites.

Result shapes returned by drivers (plain arrays):

- **Search item** (`SearchEnginesProvider::search()` → `items[]`): `id` (prefixed by source, e.g. `seznam-123`), `name`, `url`, `min_price`, `max_price` (Seznam only), `currency` (`CZK`), `shop`, `shop_count`, `source`, `image`, `description`, `brand`, `parameters[]`, `rating` (0–5), `rating_count`, `availability`, `category_root`, `category_parent`. Individual drivers fill a subset; see each `Application/Drivers/*Client.php`.
- **Offer** (`offers()` → `offers[]`, Heureka + Seznam only): `shop`, `price`, `currency`, `availability`, `shipping`, `free_shipping`, `url`.
- Both are decorated by `SearchController` with `money` (`CurrencyBuilder::make()` result), `price_label` (localised string) and `unit_price` (net amount).

## Admin UI

### Menu (`SearchEnginesModule::boot()`)

| Menu id | Label key | Icon | Route | Permission | Order / group |
| --- | --- | --- | --- | --- | --- |
| `searchengines` | `nav.searchengines` | `search` | `admin.searchengines.index` | `searchengines.view` | 36, group `Nástroje` |

Command palette: `searchengines.index` (`palette.searchengines.index`, `/admin/searchengines`, keywords `vyhledavac`, `heureka`, `seznam`, `zbozi`, `hledejceny`, `jama`, `autodilypro`, `autokelly`, `autodily`, `mojekolo`, `search`).

### Routes (group `/admin/searchengines`, middleware `auth`)

| Method | Path | Route name | Controller::action | Permission |
| --- | --- | --- | --- | --- |
| GET | `/admin/searchengines` | `admin.searchengines.index` | `SearchController::index` | `searchengines.view` |
| GET | `/admin/searchengines/search` | `admin.searchengines.search` | `SearchController::search` (JSON) | `searchengines.view` |
| GET | `/admin/searchengines/offers` | `admin.searchengines.offers` | `SearchController::offers` (JSON) | `searchengines.view` |

Controller: `modules/SearchEngines/Http/SearchController.php`.

### Screens

- **Search page** (`Resources/views/index.html.twig`): query input `q`, source selector `source` (`all` or one engine id), list of engines with availability badge (`searchengines.unavailable` for Heureka without Python). When `q` is present the page passes `pending` (list of source ids to query) to the Alpine component `seSearch` (`Resources/assets/js/seSearch.ts`), which calls `admin.searchengines.search` once per source, reveals rows progressively (42 ms stagger, respects reduced motion) and shows `price_label`. Hovering a row uses `seOffersHover` (`Resources/assets/js/seOffersHover.ts`) to load per-shop offers from `admin.searchengines.offers` into a popover (`partials/shop_cell.html.twig`). Both Alpine components are registered by the core bundle in `resources/js/ui/index.ts`; styles in `Resources/assets/css/admin.css`.

### JSON endpoints (admin session, not the REST API)

`GET /admin/searchengines/search?q=&source=&page=1&limit=20`

- `limit` clamped 1–80, `page >= 1` (only Heureka paginates).
- Response: `{ ok, source, items[], error, has_more, page, display_currency }`. Errors are translation keys (`searchengines.error.query_required`, `request_failed`, `invalid`, `no_results`, `unknown_source`, `heureka_unavailable`).

`GET /admin/searchengines/offers?source=heureka|seznam&url=<product url>`

- Response: `{ ok, source, offers[], error, display_currency }`.

`SearchController::decorateItems()` / `decorateOffers()` parse the raw price with `CurrencyBuilder::parse()`, strip VAT via `withoutVat()` and build the display money object with `make($net, $sourceCurrency)`.

### Settings

None. There are no settings pages or keys owned by this module ([CarService](carservice.md) has a market-engine selector that lists `SearchEnginesProvider::engines()`, stored in CarService settings).

## Public / REST API

None. No `/api/v1/*` or `/api/public/*` routes are registered.

## Events, hooks & webhooks

None. No platform events, hooks, queue jobs, scheduled tasks or publishables.

## Dashboard widgets

None. The module has no `Application/Widgets/` directory and registers no dashboard widgets.

## Services & extension points

| Class | Responsibility |
| --- | --- |
| `Modules\SearchEngines\Application\SearchEnginesProvider` | Facade / capability `searchengines`. `engines()` returns metadata list (`id`, `name`, `region`, `available`, `paginated`); `availableSourceIds()`, `isAvailable()`, `search(source, query, limit = 20, page = 1)` (dispatches by `match`), `searchUrl(source, query)`, `offers(source, productUrl)` (Heureka, Seznam), `searchLinks(query)` (deep links for every engine). Drivers are constructor-injectable for tests but default to `new`. |
| `Application/Drivers/HeurekaClient` | Runs `bin/heureka_search.py` via `shell_exec()` (`python3`/`python`, requires `curl_cffi` — checked with `import curl_cffi`); `configured()` gates availability. Supports search pagination (`page`) and `--offers <url>`. Handles Cloudflare (`cloudflare_challenge` error from script). |
| `Application/Drivers/SeznamZboziClient` | Scrapes `https://www.zbozi.cz/hledej/?q=` (Next.js `__NEXT_DATA__` JSON); prices in haléře / 100; `offers()` reads `props.pageProps.data.offers.items`. |
| `Application/Drivers/HledejcenyClient` | Scrapes `https://www.hledejceny.cz/hledat?search=`. |
| `Application/Drivers/JamaClient` | Scrapes `https://www.autodily-jama.cz/hledani/?search=`. |
| `Application/Drivers/AutodilyProClient` | Scrapes `https://www.autodilypro.cz/kategoria/vyhladavanie?q=`. |
| `Application/Drivers/AutoKellyClient` | Warms cookies on `https://www.autokelly.cz/` (cookie jar `sys_get_temp_dir()/gereq-autokelly-cookies.txt`), then POSTs JSON to `/Search2/Product/Data` and fetches retail prices; normalises OEM codes (`HttpFetch::normalizePartQuery`, `oemSearchText()` → `OEM <code>`). |
| `Application/Drivers/MojekoloClient` | Scrapes `https://www.mojekolo.cz/search/?search=`. |
| `Application/HttpFetch` | Minimal cURL wrapper: `get()`, `postJson()` (Chrome UA, optional cookie file, timeout), `normalizePartQuery()` (extracts catalog number from "Name brand 1.800.204" / "OEM …"), `parseCzPrice()` (handles `3 490 Kč`, `3.490,00`, `97,50`, …). |

Comparator driver contract: there is no interface; each `*Client` exposes `search(string $query, int $limit): array{ok, items, error, has_more}` and `public static searchUrl(string $query): string`, and optionally `offers(string $productUrl): array{ok, offers, error}`. Adding an engine means adding a client and extending the `match` arms plus `engines()` in `SearchEnginesProvider`.

Consumers in other modules (guarded by `ModuleRegistry::isEnabled('searchengines')` + `CapabilityRegistry::has('searchengines')` + `instanceof SearchEnginesProvider`):

- `modules/PartsCatalog/Http/CatalogController.php` — OEM market lookup panel; also requires the user to have `searchengines.view` and reuses routes `admin.searchengines.search` / `admin.searchengines.offers` and translation keys `searchengines.*`.
- `modules/CarService/Http/SettingsController.php` — `marketEngineOptions()` for selecting engines in CarService settings.

## CLI commands

None (the module contains a Python helper `bin/heureka_search.py`, invoked internally, not a `gereq` console command).

## Configuration & translations

- Settings keys: none.
- Environment variables: none. Runtime requirements: PHP cURL extension; for Heureka a `python3`/`python` binary with `curl_cffi` installed on the PATH of the PHP process (otherwise Heureka is reported as unavailable). AutoKelly writes a cookie jar to the system temp directory.
- Translations: `Resources/translations/messages.cs.php`, `messages.en.php`; prefixes `module.searchengines.description`, `nav.searchengines`, `palette.searchengines.index`, `perm.searchengines.*`, `perm.group.searchengines`, `searchengines.*` (`searchengines.col.*`, `searchengines.offers.*`, `searchengines.error.*`, `searchengines.source.all`).

## Database migrations

None (no `Database/Migrations/` directory).

## Notes

- All data comes from HTML/JSON scraping of third-party sites; any markup change upstream breaks the corresponding driver silently (`searchengines.error.invalid` / `no_results`). Nothing is cached, so every search triggers live requests to every selected source.
- `searchengines.manage` is registered but not used by any route or view; only `searchengines.view` is checked.
- Heureka availability check (`pythonAvailable()`) executes `python -c "import curl_cffi"` on every `engines()` call and is not memoised; `SearchController::index()` calls `engines()` and `availableSourceIds()` (which calls `engines()` again), so the process spawn happens repeatedly per request.
- Prices are displayed net of VAT (`CurrencyBuilder::withoutVat()`), which is a deliberate choice for B2B parts purchasing; the raw gross price from the site is overwritten in `min_price`/`price`.
- The Seznam `has_more` flag is `true` when the source page listed more products than `limit`, but the driver does not support paging, so the UI cannot fetch the remainder.
- The Python helper's failure output (`curl_cffi_missing`, `cloudflare_challenge`, `http_<code>`) is passed through as `error` and is not a translation key.
