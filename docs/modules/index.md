# Modules

GEREQ ships business functionality as self-contained modules under `modules/<Dir>/`. Each module is its own git repository (`git.gereq.cz/gereq/module-<id>`) pinned as a submodule, carries a `module.php` manifest, a `<Dir>Module.php` class with `register()`/`boot()`, and owns its Doctrine entities, admin UI, API, events, dashboard widgets and migrations. Modules never touch another module's tables; they integrate through capabilities, providers and guarded `class_exists()` lookups. See [Module system](../architecture/module-system.md) for the lifecycle and [Creating a module](../development/creating-a-module.md) for the workflow.

Versions below are read from `module.php` at the time of writing; the authoritative value is always the manifest.

## Packages

Packages (`config/module_groups.php`, surface `packages`) bundle modules into a product. The current deployment is the **RealEstates** package.

| Package | Modules | Required |
|---|---|---|
| `ecs` — Car service | customers, fleet, carservice, media, documents, crm, calendar, idoklad | customers, fleet, carservice |
| `ebs` — Bike service | customers, bikeservice, searchengines, media, documents, calendar | customers, bikeservice |
| `realestates` — Real estate agency | currency, customers, media, cms, listings, crm, calendar, viewings, documents, reviews, emailing, newsletter, engage, webplus | currency, media, cms, listings |

Third-party adapters (payments, shipping, AI providers) live in `integrations/` and are grouped as plug-ins — see [Integrations](../integrations/index.md).

## Platform / GEREQ

| Module | Version | Requires | Purpose |
|---|---|---|---|
| [GEREQ](gereq.md) | 1.0.0 | — | Ticketing desk + Security form protection (developer-only) |

## Real estate

| Module | Version | Requires | Purpose |
|---|---|---|---|
| [Listings](listings.md) | 1.3.2 | currency | Property listings, brokers, inquiries, public catalog API |
| [Viewings](viewings.md) | 1.1.2 | listings | Property viewing requests and scheduling |
| [Portals](portals.md) | 1.4.0 | listings | Export of listings to real-estate portals |
| [WebPlus](webplus.md) | 1.5.1 | — | SEO/OG/GEO meta, sitemap, health checks, Google Products feed |
| [Cms](cms.md) | 1.10.0 | — | Page builder and content for small public sites |
| [Reviews](reviews.md) | 1.5.0 | — | Reviews with moderation and replies |

## CRM & customers

| Module | Version | Requires | Purpose |
|---|---|---|---|
| [Customers](customers.md) | 1.6.4 | — | Customer and company contact records (CRM base) |
| [CRM](crm.md) | 1.5.1 | customers, currency | Deals pipeline, activities, workflows |
| [Calendar](calendar.md) | 1.3.1 | crm | Calendar events linked to CRM and external calendars |
| [Projects](projects.md) | 1.1.0 | customers | Projects and tasks |
| [Documents](documents.md) | 1.4.0 | — | Documents attached to any entity |
| [Media](media.md) | 1.3.0 | — | Media asset library |
| [CustomerUploads](customer_uploads.md) | 1.1.1 | — | Session-based public uploads with retention |
| [ESign](esign.md) | 1.2.1 | — | Simple electronic signatures (eIDAS SES) |

## Commerce

| Module | Version | Requires | Purpose |
|---|---|---|---|
| [Products](products.md) | 1.8.4 | currency | Catalog, categories, prices, public catalog API |
| [ProductDiscounts](product_discounts.md) | 1.2.0 | products, currency | Percentage/amount discounts on catalog items |
| [Bundles](bundles.md) | 1.1.0 | — | Product bundles / sets |
| [Coupons](coupons.md) | 1.1.0 | — | Discount coupons |
| [Loyalty](loyalty.md) | 1.1.0 | — | Loyalty tiers and points |
| [Shop](shop.md) | 1.6.1 | products, currency | Carts, shipping methods, checkout |
| [Orders](orders.md) | 1.2.4 | customers, products, currency | Orders and invoices |
| [Payments](payments.md) | 1.4.0 | currency | Payment methods, intents, gateway registry, bank matching |
| [Returns](returns.md) | 1.1.1 | currency | Returns / RMA |
| [Warehouse](warehouse.md) | 1.1.0 | products | Stock levels and movements |
| [Feeds](feeds.md) | 1.1.1 | — | Product feeds (Google Merchant, Heureka, …) |
| [SearchEngines](searchengines.md) | 1.3.6 | currency | Price comparator drivers (Heureka, Zboží, …) |
| [Currency](currency.md) | 1.4.2 | — | Currencies, exchange rates, conversion |

## Workshop (car & bike service)

| Module | Version | Requires | Purpose |
|---|---|---|---|
| [Fleet](fleet.md) | 1.8.0 | — | Vehicle registry (company fleet and customer vehicles), deadlines |
| [CarService](carservice.md) | 1.14.0 | fleet, customers, currency | Workshop / body shop orders, jobs, costs |
| [BikeService](bikeservice.md) | 1.12.0 | customers, searchengines, currency | Bike / e-bike workshop orders and registry |
| [PartsCatalog](partscatalog.md) | 1.14.0 | currency | Spare-parts catalog (FAPI / TecDoc providers) |

## Accounting integrations

| Module | Version | Requires | Purpose |
|---|---|---|---|
| [iDoklad](idoklad.md) | 1.3.0 | — | iDoklad accounting sync |
| [MoneyS3](moneys3.md) | 1.1.0 | — | Money S3 GraphQL export |

## Communication

| Module | Version | Requires | Purpose |
|---|---|---|---|
| [Emailing](emailing.md) | 1.6.1 | — | SMTP/IMAP/POP3 mail client with local index and rules |
| [Newsletter](newsletter.md) | 1.1.0 | — | Subscribers and campaigns |
| [Engage](engage.md) | 1.0.0 | — | Marketing campaigns (?mkind=) and dynamic content spots (Marketing hub) |
| [Notifications](notifications.md) | 1.1.0 | — | In-app inbox, preferences, delivery |
| [Ai](ai.md) | 1.5.0 | — | AI agents, RAG knowledge base, multi-provider LLM routing |
| [Translate](translate.md) | 2.1.0 | — | Microsoft Translator with database cache |

## Platform & developer

| Module | Version | Requires | Purpose |
|---|---|---|---|
| [Webhooks](webhooks.md) | 1.1.1 | — | Outbound webhooks with HMAC, retries and delivery log |
| [Vitality](vitality.md) | 1.1.0 | — | API latency statistics and slow-request log |

## Conventions shared by all modules

- **Manifest**: `module.php` (`id`, `name`, `version`, `requires`, `groups`, `permissions`, `capabilities`). `version` must equal `<Dir>Module::version()` and the git tag `vX.Y.Z`; any code or migration change requires a version bump (see `.cursor/rules/module-updates.mdc`).
- **Layout**: `Domain/`, `Application/`, `Infrastructure/`, `Http/`, `Admin/`, `Database/Migrations/`, `Resources/{views,translations,assets}/`, `Testing/`.
- **Permissions**: granular `<id>.view|create|edit|delete|manage`, registered in `register()`, synced on enable/update.
- **Translations**: `Resources/translations/messages.{cs,en}.php`, key prefix `<id>.*`, navigation labels `nav.*`, dashboard widgets `<id>.widget.*`.
- **Dashboard**: widgets in `Application/Widgets/` registered in `boot()` — see [Dashboard widgets](../architecture/dashboard-widgets.md).
- **API**: `/api/v1/<id>` via `$context->api()->module(...)`, public endpoints under `/api/public/*` — see [API](../architecture/api.md).
- **Events**: `EventDefinition::make('<id>.<resource>.<action>')`, webhook-enabled where relevant — see [Hooks, events & webhooks](../architecture/hooks-events-webhooks.md).
