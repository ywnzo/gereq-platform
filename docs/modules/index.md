# Modules

GEREQ ships business functionality as self-contained modules under `modules/<Dir>/`. Each module is its own git repository (`git.gereq.cz/gereq/module-<id>`) pinned as a submodule, carries a `module.php` manifest, a `<Dir>Module.php` class with `register()`/`boot()`, and owns its Doctrine entities, admin UI, API, events, dashboard widgets and migrations. Modules never touch another module's tables; they integrate through capabilities, providers and guarded `class_exists()` lookups. See [Module system](../architecture/module-system.md) for the lifecycle and [Creating a module](../development/creating-a-module.md) for the workflow.

Checkout / subset from GitLab: `./bin/grq modules pull`, `./bin/grq modules-git …` — [Tooling](../infrastructure/tooling.md#modules-pull-and-modules-git).

Versions below are read from `module.php` at the time of writing; the authoritative value is always the manifest. Current workspace pins use **`1.99.0`** across modules (development alignment).

## Packages

Packages (`config/module_groups.php`, surface `packages`) bundle modules into a product.

| Package | Modules | Required |
|---|---|---|
| `gereq` — GEREQ desk | gereq | — |
| `ecs` — Car service | customers, fleet, carservice, media, documents, crm, calendar, idoklad | customers, fleet, carservice |
| `ebs` — Bike service | customers, bikeservice, searchengines, media, documents, calendar | customers, bikeservice |
| `realestates` — Real estate agency | currency, customers, media, cms, listings, crm, calendar, viewings, documents, reviews, emailing, webplus, webplus_ai | currency, media, cms, listings |
| `forum` — Community forum | customers, forum, community, achievements, messenger, media, notifications, newsletter, calendar | customers, forum |

Third-party adapters (payments, shipping, AI providers) live in `integrations/` and are grouped as plug-ins — see [Integrations](../integrations/index.md).

## Platform / GEREQ

| Module | Version | Requires | Purpose |
|---|---|---|---|
| [GEREQ](gereq.md) | 1.99.0 | — | Ticketing desk + Security form protection (developer-only) |

## Real estate

| Module | Version | Requires | Purpose |
|---|---|---|---|
| [Listings](listings.md) | 1.99.0 | currency | Property listings, brokers, inquiries, public catalog API |
| [Viewings](viewings.md) | 1.99.0 | listings | Property viewing requests and scheduling |
| [Portals](portals.md) | 1.99.0 | listings | Export of listings to real-estate portals |
| [WebPlus](webplus.md) | 1.99.0 | — | SEO/OG/GEO meta, sitemap, health checks, Google Products feed |
| [AI (Web+)](webplus_ai.md) | 2.0.0 | webplus (parent) | WebMCP agent-tool registry + public discovery (`tools.json`, `llms-full.txt`) |
| [Cms](cms.md) | 1.99.0 | — | Page builder and content for small public sites |
| [Reviews](reviews.md) | 1.99.0 | — | Reviews with moderation and replies |

## Forum & community

| Module | Version | Requires | Purpose |
|---|---|---|---|
| [Forum](forum.md) | 1.99.0 | — | Q&A / discussion threads, votes, moderation, public forum API |
| [Community](community.md) | 1.99.0 | — | Profiles, follows, friendships, groups, activity stats |
| [Achievements](achievements.md) | 1.99.0 | — | Badges, reputation, trust levels |
| [Messenger](messenger.md) | 1.99.0 | — | Private member conversations (public API) |

## CRM & customers

| Module | Version | Requires | Purpose |
|---|---|---|---|
| [Customers](customers.md) | 1.99.0 | — | Customer and company contact records (CRM base) |
| [CRM](crm.md) | 1.99.0 | customers, currency | Deals pipeline, activities, workflows |
| [Calendar](calendar.md) | 1.99.0 | crm | Calendar events linked to CRM and external calendars |
| [Projects](projects.md) | 1.99.0 | customers | Projects and tasks |
| [Documents](documents.md) | 1.99.0 | — | Documents attached to any entity |
| [Media](media.md) | 1.99.0 | — | Media asset library |
| [ESign](esign.md) | 1.99.0 | — | Simple electronic signatures (eIDAS SES) |

## Commerce

Parent [E-Shop](shop.md) (`shop` 2.1.0) bundles storefront checkout with children under `modules/Shop/Submodules/`. Enable the parent first, then each child separately.

| Module | Version | Requires | Purpose |
|---|---|---|---|
| [E-Shop](shop.md) | 2.1.0 | currency | Carts, shipping, checkout (parent) |
| [Products](products.md) | 2.0.0 | shop, currency | Catalog, categories, prices, public catalog API |
| [Orders](orders.md) | 2.0.0 | shop, customers, products, currency | Orders and invoices |
| [Payments](payments.md) | 2.0.0 | shop, currency | Payment methods, intents, gateway registry, bank matching |
| [Returns](returns.md) | 2.0.0 | shop, currency | Returns / RMA |
| [Coupons](coupons.md) | 2.0.0 | shop | Discount coupons |
| [Warehouse](warehouse.md) | 2.0.0 | shop, products | Stock levels and movements |
| [Bundles](bundles.md) | 2.0.0 | shop | Product bundles / sets (Sestavy) |
| [Loyalty](loyalty.md) | 2.0.0 | shop | Loyalty tiers and points |
| [Customer Uploads](customer_uploads.md) | 2.0.0 | shop | Session-based public uploads with retention |
| [ProductDiscounts](product_discounts.md) | 1.99.0 | products, currency | Percentage/amount discounts on catalog items |
| [Feeds](feeds.md) | 1.99.0 | — | Product feeds (Google Merchant, Heureka, …) |
| [SearchEngines](searchengines.md) | 1.99.0 | currency | Price comparator drivers (Heureka, Zboží, …) |
| [Currency](currency.md) | 1.99.0 | — | Currencies, exchange rates, conversion |

## Workshop (car & bike service)

| Module | Version | Requires | Purpose |
|---|---|---|---|
| [Fleet](fleet.md) | 1.99.0 | — | Vehicle registry (company fleet and customer vehicles), deadlines |
| [CarService](carservice.md) | 1.99.0 | fleet, customers, currency | Workshop / body shop orders, jobs, costs |
| [BikeService](bikeservice.md) | 1.99.0 | customers, searchengines, currency | Bike / e-bike workshop orders and registry |
| [PartsCatalog](partscatalog.md) | 1.99.0 | currency | Spare-parts catalog (FAPI / TecDoc providers) |

## Accounting integrations

| Module | Version | Requires | Purpose |
|---|---|---|---|
| [iDoklad](idoklad.md) | 1.99.0 | — | iDoklad accounting sync |
| [MoneyS3](moneys3.md) | 1.99.0 | — | Money S3 GraphQL export |

## Communication

| Module | Version | Requires | Purpose |
|---|---|---|---|
| [Emailing](emailing.md) | 1.99.0 | — | SMTP/IMAP/POP3 mail client with local index and rules |
| [Newsletter](newsletter.md) | 1.99.0 | — | Subscribers and campaigns |
| [Engage](engage.md) | 1.99.0 | — | Marketing campaigns (?mkind=) and dynamic content spots (Marketing hub) |
| [Notifications](notifications.md) | 1.99.0 | — | In-app inbox, preferences, delivery |
| [Ai](ai.md) | 1.99.0 | — | AI agents, RAG knowledge base, multi-provider LLM routing |
| [Translate](translate.md) | 1.99.0 | — | Microsoft Translator with database cache |

## Platform & developer

| Module | Version | Requires | Purpose |
|---|---|---|---|
| [Webhooks](webhooks.md) | 1.99.0 | — | Outbound webhooks with HMAC, retries and delivery log |
| [Vitality](vitality.md) | 1.99.0 | — | API latency statistics and slow-request log |

## Conventions shared by all modules

- **Manifest**: `module.php` (`id`, `name`, `version`, `requires`, `groups`, `permissions`, `capabilities`). `version` must equal `<Dir>Module::version()` and the git tag `vX.Y.Z`; any code or migration change requires a version bump (see `.cursor/rules/module-updates.mdc`).
- **Layout**: `Domain/`, `Application/`, `Infrastructure/`, `Http/`, `Admin/`, `Database/Migrations/`, `Resources/{views,translations,assets}/`, `Tests/`.
- **Permissions**: granular `<id>.view|create|edit|delete|manage`, registered in `register()`, synced on enable/update.
- **Translations**: `Resources/translations/messages.{cs,en}.php`, key prefix `<id>.*`, navigation labels `nav.*`, dashboard widgets `<id>.widget.*`.
- **Dashboard**: widgets in `Application/Widgets/` registered in `boot()` — see [Dashboard widgets](../architecture/dashboard-widgets.md).
- **API**: `/api/v1/<id>` via `$context->api()->module(...)`, public endpoints under `/api/public/*` — see [API](../architecture/api.md).
- **Events**: `EventDefinition::make('<id>.<resource>.<action>')`, webhook-enabled where relevant — see [Hooks, events & webhooks](../architecture/hooks-events-webhooks.md).
