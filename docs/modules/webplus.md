# Web+ (`webplus`)

Web+ is the SEO / GEO / Open Graph layer for headless GEREQ frontends. Editors maintain `WebMeta` records — either for a concrete path (`/o-nas`) or a path template (`/nabidky/{slug}`) — holding SEO, geo, AI-summary, JSON-LD, OG, Twitter and sitemap fields. A resolver merges the stored record, site-wide defaults from settings and live data from [Listings](listings.md) and [Cms](cms.md) pages into a "meta package" that the public API serves as JSON or ready-made `<head>` HTML. The module also publishes `sitemap.xml`, `robots.txt`, `llms.txt` and a Google Merchant products RSS feed ([Products](products.md) + listings), runs a weighted health score shown in the admin topbar, dashboard and a dedicated health page, and ships a Vite plugin (`storefront/vite-plugin-webplus.ts`) that injects the resolved tags into the SPA's `index.html`.

## At a glance

| Item | Value |
| --- | --- |
| Module id | `webplus` |
| Version | 1.5.1 (`modules/WebPlus/module.php`, `WebPlusModule::version()`) |
| Directory | `modules/WebPlus/` |
| Requires | `core ^1.0` |
| Optional | `cms ^1.0`, `products ^1.0`, `listings ^1.0`, `media ^1.0` |
| Capabilities provided | `webplus` → `Application\WebPlusProvider` (`provides: ['webplus']`) |
| Capabilities consumed | `products`, `listings` (checked via `CapabilityRegistry::has()` in `BuiltInGoogleFeedCollectors`); Cms/Listings/Products entities used behind `class_exists()` |
| Groups / packages | none in `module.php`; not listed in `config/module_groups.php` |
| Permissions | `webplus.view` (`perm.webplus.view`), `webplus.manage` (`perm.webplus.manage`) — group `perm.group.webplus` |
| API scopes | `webplus.read`, `webplus.write` |
| Author | GEREQ |

Manifest name `Web+`, icon `globe`.

## Domain model

Single entity `modules/WebPlus/Domain/WebMeta.php` → table `webplus_metas`. Unique `uniq_webplus_metas_path (path)`, indexes `idx_webplus_metas_cms_page (cms_page_id)`, `idx_webplus_metas_active (is_active)`. `#[FormEntity('webplus.meta.save')]` / `#[FormGenerator(exclude: id, jsonLd, createdAt, updatedAt; cols: 1)]` — 23 `#[FormField]`s in groups `identity`, `seo`, `geo`, `og`, `twitter`, `sitemap` (`og_image_url` / `twitter_image_url` edited via `media_box`, not FormField).

| Group | Field | Column / type | Notes |
| --- | --- | --- | --- |
| identity | `id` | string(36) PK | UUID v7 |
| identity | `path` | string(255) unique | `normalizePath()`: leading `/`, collapsed slashes, no trailing slash (`/` for root); may contain `{tokens}` → template (`isPathTemplate()`) |
| identity | `cmsPageId` | `cms_page_id` string(36) nullable | link to `Modules\Cms\Domain\Page` |
| identity | `active` | `is_active` boolean, default true | |
| seo | `seoTitle` | `seo_title` string(190) nullable | may contain `{title}`-style placeholders |
| seo | `seoDescription` | `seo_description` text nullable | |
| seo | `canonicalUrl` | `canonical_url` string(2048) nullable | |
| seo | `robots` | string(64), default `index,follow` | `ROBOTS = index,follow · index,nofollow · noindex,follow · noindex,nofollow` |
| seo | `keywords` | text nullable | |
| geo | `geoRegion`, `geoPlacename`, `geoPosition` | string(64) / string(190) / string(64) nullable | `geo.position` "lat;long" also rendered as `ICBM` |
| geo | `aiSummary` | `ai_summary` text nullable | GEO (generative engine) summary |
| geo | `jsonLd` | `json_ld` json nullable | schema.org object; edited as raw JSON (`json_ld_json`) |
| og | `ogTitle`, `ogDescription`, `ogImageUrl`, `ogUrl`, `ogLocale` | nullable | |
| og | `ogType` | `og_type` string(32), default `website` | `OG_TYPES = website, article, product, profile` |
| twitter | `twitterCard` | `twitter_card` string(32), default `summary_large_image` | `TWITTER_CARDS = summary, summary_large_image` |
| twitter | `twitterTitle`, `twitterDescription`, `twitterImageUrl` | nullable | |
| sitemap | `sitemapInclude` | `sitemap_include` boolean, default true | |
| sitemap | `sitemapPriority` | `sitemap_priority` string(8), default `0.5` | |
| sitemap | `sitemapChangefreq` | `sitemap_changefreq` string(16), default `weekly` | `CHANGEFREQS = always, hourly, daily, weekly, monthly, yearly, never` |
| — | `createdAt`, `updatedAt` | datetime | |

No other entities; no Mermaid diagram needed.

## Admin UI

### Menu (`WebPlusModule::boot()`)

| Menu id | Label key | Icon | Route | Permission | Order / parent |
| --- | --- | --- | --- | --- | --- |
| `webplus` | `nav.webplus` | `globe` | `admin.webplus.metas.index` | `webplus.view` | 51, group `Obsah` |
| `webplus.metas` | `nav.webplus.metas` | — | `admin.webplus.metas.index` | `webplus.view` | 52, parent `webplus` |
| `webplus.mapping` | `webplus.mapping.title` | — | `admin.webplus.metas.mapping` | `webplus.view` | 53 |
| `webplus.health` | `nav.webplus.health` | — | `admin.webplus.health` | `webplus.view` | 54 |
| `webplus.settings` | `nav.webplus.settings` | — | `admin.webplus.settings` | `webplus.manage` | 55 |

Command palette: `webplus.metas` (keywords seo, geo, og, opengraph, meta, web+), `webplus.mapping` (mapovani, routes, slug…), `webplus.health` (icon `activity`; health, score…), `webplus.settings` (`webplus.manage`; google, feed, merchant, llms, sitemap, robots, frontend).

Topbar badge: filter `admin.topbar.context` appends `partials/topbar_health_badge.html.twig` (score, colour from `HealthReport::scoreColor()`, grade, link to health page) for users with `webplus.view`, using `WebHealthChecker::runForBadge()` (no FE HTTP probes); wrapped in `try/catch`.

### Routes (middleware `auth`)

| Method | Path | Route name | Controller::action | Permission |
| --- | --- | --- | --- | --- |
| GET | `/admin/webplus/metas` | `admin.webplus.metas.index` | `MetaController::index` | `webplus.view` |
| GET | `/admin/webplus/metas/create` | `admin.webplus.metas.create` | `MetaController::create` | `webplus.manage` |
| GET | `/admin/webplus/metas/mapping` | `admin.webplus.metas.mapping` | `MetaController::mapping` | `webplus.view` |
| POST | `/admin/webplus/metas/ensure-templates` | `admin.webplus.metas.ensure_templates` | `MetaController::ensureTemplates` | `webplus.manage` |
| POST | `/admin/webplus/metas/auto-map` | `admin.webplus.metas.auto_map` | `MetaController::autoMap` | `webplus.manage` |
| GET | `/admin/webplus/metas/for-page/{pageId}` | `admin.webplus.metas.for_page` | `MetaController::forPage` | `webplus.manage` |
| POST | `/admin/webplus/metas` | `admin.webplus.metas.store` | `MetaController::store` | `webplus.manage` |
| GET | `/admin/webplus/metas/{id}/edit` | `admin.webplus.metas.edit` | `MetaController::edit` | `webplus.manage` |
| POST | `/admin/webplus/metas/{id}` | `admin.webplus.metas.update` | `MetaController::update` | `webplus.manage` |
| POST | `/admin/webplus/metas/{id}/delete` | `admin.webplus.metas.destroy` | `MetaController::destroy` | `webplus.manage` |
| GET | `/admin/webplus/settings` | `admin.webplus.settings` | `SettingsController::edit` | `webplus.manage` |
| POST | `/admin/webplus/settings` | `admin.webplus.settings.update` | `SettingsController::update` | `webplus.manage` |
| GET | `/admin/webplus/settings/google-feed` | `admin.webplus.google_feed` | `SettingsController::googleFeedPreview` | `webplus.view` |
| GET | `/admin/webplus/health` | `admin.webplus.health` | `HealthController::index` | `webplus.view` |

Controllers in `modules/WebPlus/Http/`. Views in `Resources/views/` (`metas/index|form|mapping`, `settings/edit|google_feed`, `health/index`, `partials/_hub_tabs`, `partials/topbar_health_badge`); styles `Resources/assets/css/admin.css`.

### Screens

- **SEO meta editor** (`metas/index.html.twig`, `metas/form.html.twig`): list of all metas ordered by path with `?q=` search over `path`/`seoTitle`/`ogTitle` and a "coverage" column from `RouteMappingCatalog::cover()` (which route mapping a path belongs to). The form is generated from the entity groups (identity → SEO → GEO → combined Sharing/OG+Twitter with live social preview → sitemap) plus a raw JSON-LD textarea (`json_ld_json`, decoded on save) and a CMS page selector (`cmsPages()`, only when Cms is installed). OG/Twitter images use `ui/media_box` (upload / media library / URL) instead of plain text. Create accepts `?path=` / `?cms_page_id=` presets. Store/update enforce unique path, emit `webplus.meta.created|updated|deleted`, audit `webplus.meta.*`, flash `webplus.flash.meta_*`.
- **Social share preview**: Alpine `webplusOgPreview` (`Resources/assets/js/ogPreview.ts` + `partials/og_preview.html.twig`) shows Facebook / Messenger / X / LinkedIn card mocks and updates live from form fields; template paths get a sample-path input to interpolate `{slug}`-style tokens. Fallbacks: OG title ← SEO title ← site name; OG image ← site `default_og_image`.
- **CMS link**: `for-page/{pageId}` redirects to the existing meta's edit form or to create with the page slug as preset path; Cms page show/edit views link to it (`modules/Cms/Resources/views/pages/{show,form}.html.twig`, label `webplus.cms.action`).
- **Route mapping** (`metas/mapping.html.twig`): `RouteMappingCatalog::all()` lists the known route shapes — `home /`, `listings.index /nabidky`, `listings.detail` (template from `webplus.google_feed_listings_path`), `cms.page` (template from `webplus.cms_pages_path`), `cms.alias /{slug}`, `generic.xy /{page}/{x}`, `generic.xyz /{page}/{x}/{y}` — with live entity counts, override counts, example paths and supported tokens; filterable by hook `webplus.route_mappings`. "Ensure templates" creates missing seedable template metas; "Auto-map" (`UrlTemplateDiscoverer::discover()`, min 2 hits) clusters existing concrete paths into `{page}/{x}/{y}` templates and creates them (audit `webplus.mapping.auto`), and the page also previews `discoveredFromUrls()`.
- **Health page** (`health/index.html.twig`): full `WebHealthChecker::run()` report — score 0–100, grade (`excellent ≥90`, `good ≥75`, `fair ≥50`, `poor ≥25`, else `critical`), grouped checks (`settings`, `seo`, `geo`, `og`, `discovery`, `frontend`) with status `pass|warn|fail`, weight, detail and fix recommendations.
- **Settings** (`settings/edit.html.twig`): all keys below in sections site defaults / discovery (llms, robots) / Google feed, with available feed sources (`googleFeedSources()`) and a list of public endpoint URLs. Site section includes `media_box` for `webplus.default_og_image` and the same live social preview as the meta form. Saving writes each key via `Settings::set()`, emits `webplus.settings.updated` (`keys`), audits `webplus.settings.updated`.
- **Google feed preview** (`settings/google_feed.html.twig`): renders the generated XML inline (422 `webplus.error.google_feed_disabled` when disabled).

## Public / REST API

### Authenticated `/api/v1/webplus` (`ApiModule`, token scopes)

| Method | Path | Scope | Action | Notes |
| --- | --- | --- | --- | --- |
| GET | `/api/v1/webplus/metas` | `webplus.read` | `WebPlusApiController::index` | search `path`/`seoTitle`/`ogTitle`; filters `active`, `cms_page_id`; sort `path|updatedAt|createdAt` |
| POST | `/api/v1/webplus/metas` | `webplus.write` | `store` | ruleset `webplus.meta.save`; 422 `validation_error` "Path taken"; emits `webplus.meta.created` |
| GET | `/api/v1/webplus/metas/{id}` | `webplus.read` | `show` | |
| PATCH | `/api/v1/webplus/metas/{id}` | `webplus.write` | `update` | emits `webplus.meta.updated` |
| DELETE | `/api/v1/webplus/metas/{id}` | `webplus.write` | `destroy` | emits `webplus.meta.deleted` |
| GET | `/api/v1/webplus/resolve` | `webplus.read` | `resolve` | `?path=` or `?cms_page_id=[&slug=]`, optional `title`, `description` fallbacks |
| GET | `/api/v1/webplus/site` | `webplus.read` | `site` | `siteDefaults()` |
| GET | `/api/v1/webplus/google-products` | `webplus.read` | `googleProducts` | feed XML or 404 `feed_disabled` |

### Public `/api/public/webplus/*` (`Http\Public\PublicWebPlusController`, `PublicCors::apply(…, 'GET, OPTIONS')`, OPTIONS pre-flight routes registered for every path)

| Method | Path | Route name | Returns |
| --- | --- | --- | --- |
| GET | `/api/public/webplus/meta?path=…[&title=&description=&format=html]` | `api.public.webplus.meta` | resolved package JSON (`ApiResponse::data`) or, with `format=html`, a standalone share document; 422 when `path` missing |
| GET | `/api/public/webplus/html?path=…` | `api.public.webplus.html` | `MetaHtmlRenderer::document()` HTML (`text/html`) |
| GET | `/api/public/webplus/meta/by-page/{pageId}` | `api.public.webplus.meta.page` | package for a Cms page (by link or slug); 404 `page_not_found` |
| GET | `/api/public/webplus/site` | `api.public.webplus.site` | `{site: siteDefaults, endpoints: {...}}` |
| GET | `/api/public/webplus/sitemap.xml` | `api.public.webplus.sitemap` | `application/xml` |
| GET | `/api/public/webplus/robots.txt` | `api.public.webplus.robots` | `text/plain` |
| GET | `/api/public/webplus/llms.txt` | `api.public.webplus.llms` | `text/plain`; default stub when setting empty |
| GET | `/api/public/webplus/google-products.xml` | `api.public.webplus.google_products` | Google Merchant RSS 2.0; 404 `feed_disabled` when disabled |

Public API auth/rate limiting is applied by core middleware for `/api/public/*` paths (see `../architecture/api.md`, `../frontend/public-frontends.md`).

### Serializers (`Application\WebPlusProvider`)

- `serialize(WebMeta)` (admin API): `id, path, cms_page_id, active, seo{title, description, canonical_url, robots, keywords}, geo{region, placename, position, ai_summary, json_ld}, og{title, description, image, type, url, locale}, twitter{card, title, description, image}, sitemap{include, priority, changefreq}, created_at, updated_at`.
- `serializeResolved(WebMeta, fallbackTitle, fallbackDescription)` / `resolveForPath()` package: `path, cms_page_id, site_name, seo{…}, geo{region, placename, position, ai_summary}, og{…, site_name}, twitter{card, site, …}, json_ld[]` (organization JSON-LD from settings first, then record) and `tags[]` — list of `{kind, key, value}` head tags (`title`, `description`, `canonical`, `robots`, `keywords`, `geo.*`, `ICBM`, `og:*`, `twitter:*`) ready for `<head>` rendering. Fallback chain: record field → dynamic entity data → request fallback → site default; canonical defaults to `base_url + path`; relative URLs are absolutised against `frontend_url`/`base_url`.

### Resolution algorithm (`resolveForPath()`)

1. Normalise path; look for an active exact record (template records are ignored as exact hits).
2. Else find the most specific active template (`PathPattern::match()`, static segments beat wildcards) and extract `{tokens}`.
3. `DynamicMetaResolver::resolve()` loads live data: listing by slug for the listings template (title, excerpt, image, city/region geo, `RealEstateListing`/`Offer` JSON-LD with availability from `marketStatus`), Cms page by slug for the CMS template / `/{slug}` alias (reserved first segments `nabidky, stranka, api, admin, media, assets` are skipped); hook `webplus.dynamic_meta` may replace the payload.
4. Package from record or from defaults, then `{token}` interpolation (`path params + dynamic vars` such as `title`, `city`, `price`), merge dynamic values, absolutise URLs, rebuild `tags`.

`resolveForPaths()` batches DB lookups (`findByPaths`, `warmForPaths`) and is used by the health checker.

## Events, hooks & webhooks

Platform events (`registerPlatformEvents()`, all `webhookEnabled(true)`, default version):

| Event | Description | Emitted from |
| --- | --- | --- |
| `webplus.meta.created` | "Web+ meta vytvořeno" | `MetaController::store`, `WebPlusApiController::store` (payload `serialize()`) |
| `webplus.meta.updated` | "Web+ meta upraveno" | `MetaController::update`, API `update` |
| `webplus.meta.deleted` | "Web+ meta smazáno" | `MetaController::destroy`, API `destroy` |
| `webplus.settings.updated` | "Web+ nastavení uloženo" | `SettingsController::update` (`{keys}`) |

Hooks added by the module:

- filter `cms.serialize_page` — adds `webplus` (resolved package) to Cms page JSON when the page has `id` + `slug`.
- filter `admin.topbar.context` — health badge (see Admin UI).
- `EventBus::listen(Modules\Cms\Domain\Events\PageUpdated)` (guarded by `class_exists`) — `syncPathFromCmsPage()` keeps a linked meta's path equal to the page slug when it changes.

Hooks exposed for other modules (via `HookManager::filter`):

| Hook | Where | Purpose |
| --- | --- | --- |
| `webplus.dynamic_meta` (`?DynamicMetaPayload, path, params`) | `DynamicMetaResolver::resolve()` | supply/override live meta for a path |
| `webplus.dynamic_sitemap` (`list<entry>`) | `DynamicMetaResolver::sitemapEntries()` | add sitemap URLs for dynamic content |
| `webplus.route_mappings` (`rows`) | `RouteMappingCatalog::all()` | add/alter known route shapes |
| `webplus.google_feed.sources` (`list<{id,label,default_path,module}>`) | `WebPlusProvider::googleFeedSources()` | register additional feed sources |
| `webplus.google_feed.items` (`items, GoogleFeedContext`) | `WebPlusProvider::collectGoogleFeedItems()` | contribute feed items (`id`, `title`, `description`, `link`, `image_link`, `price`, …) |

Audit entries: `webplus.meta.created|updated|deleted`, `webplus.mapping.auto`, `webplus.settings.updated`. Queue jobs, scheduled tasks, publishables: None. Outbound delivery of the events is handled by [Webhooks](webhooks.md); see `../architecture/hooks-events-webhooks.md`.

## Dashboard widgets

| Widget id | Type | Class | Shows | Permission | Order |
| --- | --- | --- | --- | --- | --- |
| `webplus.shortcut` | shortcut | `Application/Widgets/WebPlusShortcutWidget.php` | Link to Web+ (`nav.webplus`, hint `admin.dashboard.hint.webplus`) | `webplus.view` | 51 |
| `webplus.stats` | metric | `WebPlusStatsWidget.php` | Count of active metas; hint with how many active metas are included in the sitemap and how many are `noindex` (`webplus.widget.stats.hint`) | `webplus.view` | 60 |
| `webplus.health` | panel | `WebPlusHealthWidget.php` | Badge-mode health score and grade, pass/warn/fail counts, top recommendations; link to health page | `webplus.view` | 22 |

See `../architecture/dashboard-widgets.md`.

## Services & extension points

| Class | Responsibility |
| --- | --- |
| `Application\WebPlusProvider` | Capability `webplus`. Repository helpers with request memo (`find`, `findByPath(s)`, `findByCmsPageId(s)`, `findActiveByPath(s)`, `allActiveForSitemap`), `siteDefaults()`, `robotsTxt()` (custom override or `User-agent: * / Allow: / / Sitemap:` default), `llmsTxt()`, `resolveForPath/Paths/CmsPage`, `serialize*`, `renderSitemapXml()` (active non-template metas with `sitemap_include` + dynamic entries, base = `frontend_url` or `base_url`), `generateGoogleProductsFeed()`, `googleFeedSources()`, `syncPathFromCmsPage()`. Depends on `Settings`, `HookManager`, `GoogleProductsFeedDriver`, `BuiltInGoogleFeedCollectors`, `DynamicMetaResolver`. |
| `Application\DynamicMeta\DynamicMetaResolver` / `DynamicMetaPayload` | Live meta from Listings and Cms (`class_exists` guarded), batch warming, dynamic sitemap entries (listings + published pages), listing JSON-LD. |
| `Application\PathPattern` | `isTemplate()`, `match(template, path)`, `segmentVars()`, specificity scoring, `{token}` interpolation. |
| `Application\RouteMappingCatalog` | Route-shape catalogue, coverage lookup, `ensureTemplates()`, `autoMapFromUrls()`, `discoveredFromUrls()`. |
| `Application\UrlTemplateDiscoverer` | Clusters concrete paths by depth into `{page}/{x}/{y}/{z}` templates (`minHits`). |
| `Application\MetaHtmlRenderer` | `head(pkg)` — tag list to HTML; `document(pkg)` — standalone share HTML page. |
| `Application\Health\WebHealthChecker` / `HealthReport` / `HealthCheck` | Weighted checks: settings (`settings.site_name`, `settings.frontend_url`, `settings.og_image`, `settings.llms`), metas (`metas.count`, `metas.home`, `metas.cms_link`), resolved sample paths `/`, `/nabidky`, `/o-nas`, `/kontakt` (`package.seo|og|geo`), discovery (`discovery.sitemap|robots|google_feed`) and, in full mode, frontend probes (`fe.reach`, `fe.head` — `<title>`, `og:title`, `og:image` present in first HTML, `fe.routes`) via cURL; localhost frontend URLs are rewritten to `host.docker.internal` / docker bridge gateway when running in Docker. Results memoised per request. |
| `Application\GoogleFeed\BuiltInGoogleFeedCollectors` / `GoogleFeedContext` | Sources `products` (Products + `ProductPrice` by currency) and `listings`; enabled-source filter, per-source URL templates, limit, media absolutising. |
| `Application\Drivers\GoogleProductsFeedDriver` | Renders RSS 2.0 with `g:` namespace: `g:id, g:title, g:description, g:link, g:image_link, g:condition, g:availability, g:price, g:brand` (+ `g:gtin`, `g:mpn`). |

Frontend integration (`../frontend/public-frontends.md`): `storefront/vite-plugin-webplus.ts` (`webplusMetaPlugin`, registered in `storefront/vite.config.ts`) hooks `transformIndexHtml` — for non-asset paths it fetches `${VITE_API_PROXY_TARGET|VITE_API_URL}/api/public/webplus/meta?path=…` (Bearer `VITE_API_PUBLIC_KEY` when set) and injects the rendered head tags into `index.html` at dev/preview time; `storefront/src/lib/webplus.ts` (`applyWebPlus`, `useWebPlus`) applies packages client-side.

Other integrations: Cms views link to the meta editor; Cms page JSON is enriched via `cms.serialize_page`.

## CLI commands

None.

## Configuration & translations

Settings (`Settings::define()` in `registerSettings()`, all module `webplus`, `global = false` → shown only on the module settings page):

| Key | Type | Default | Used for |
| --- | --- | --- | --- |
| `webplus.site_name` | string | `''` | `site_name`, `og:site_name` |
| `webplus.base_url` | string | `''` | canonical/OG URL base, feed links, robots sitemap URL |
| `webplus.frontend_url` | string | `''` | sitemap `<loc>` base, health probes, media absolutising (falls back to `base_url`) |
| `webplus.default_og_image` | string | `''` | fallback `og:image` |
| `webplus.twitter_site` | string | `''` | `twitter:site` |
| `webplus.default_robots` | string | `index,follow` | fallback robots |
| `webplus.default_og_locale` | string | `cs_CZ` | fallback `og:locale` |
| `webplus.organization_json_ld` | string (JSON) | `''` | prepended to `json_ld` |
| `webplus.llms_txt` | string | `''` | `/llms.txt` body |
| `webplus.robots_txt` | string | `''` | `/robots.txt` override |
| `webplus.google_feed_enabled` | boolean | `true` | feed on/off |
| `webplus.google_feed_sources` | string | `*` | comma list of enabled source ids |
| `webplus.google_feed_title` | string | `Google Products` | channel title |
| `webplus.google_feed_description` | string | `''` | channel description |
| `webplus.google_feed_currency` | string | `CZK` | price currency |
| `webplus.google_feed_brand` | string | `''` | default `g:brand` |
| `webplus.google_feed_product_path` | string | `/p/{sku}` | product URL template |
| `webplus.google_feed_listings_path` | string | `/nabidky/{slug}` | listing URL template; also the listings meta template |
| `webplus.cms_pages_path` | string | `/stranka/{slug}` | CMS page meta template |
| `webplus.google_feed_extra_paths` | string (JSON map) | `''` | URL templates for hook-registered sources |
| `webplus.google_feed_limit` | integer | `5000` | item cap (clamped 1–10 000) |

Environment variables: none in PHP; the Vite plugin reads `VITE_API_PROXY_TARGET`, `VITE_API_URL`, `VITE_API_PUBLIC_KEY`.

Validation ruleset `webplus.meta.save` (see `registerValidation()`): `path required|max:255`, `robots in:<ROBOTS>`, `og_type in:website,article,product,profile`, `twitter_card in:summary,summary_large_image`, `sitemap_changefreq in:<CHANGEFREQS>`, `json_ld_json max:100000`, `ai_summary max:10000`, other fields nullable with length caps; `forms()->entity('webplus.meta.save', WebMeta::class)`.

Translations: `Resources/translations/messages.cs.php`, `messages.en.php` (~185 keys); prefixes `module.webplus.description`, `nav.webplus*`, `palette.webplus.*`, `perm.webplus.*`, `perm.group.webplus`, `webplus.*` (`webplus.field.*`, `webplus.metas.*`, `webplus.mapping.*`, `webplus.health.*`, `webplus.health.grade.*`, `webplus.settings.*`, `webplus.google_feed.*`, `webplus.flash.*`, `webplus.error.*`, `webplus.widget.*`, `webplus.cms.action`). `admin.dashboard.hint.webplus` is in core.

## Database migrations

| File | Purpose |
| --- | --- |
| `Database/Migrations/2026_01_01_000001_webplus.php` | `up()` no-op (schema from `WebMeta` attributes via `SchemaTool`); `down()` drops `webplus_metas`. |
| `Database/Migrations/2026_09_10_000002_webplus_route_templates.php` | `afterUp()` seeds template metas `/nabidky/{slug}`, `/stranka/{slug}`, `/{page}/{x}` (title/description placeholders, `sitemap_include = 0`) if missing. |

## Notes

- Health checks and the topbar badge run DB queries (and, on the health page, live HTTP probes to the frontend with cURL) on each request; the report is memoised only per request, not cached across requests.
- Check labels, details and fix texts inside `WebHealthChecker`, as well as event descriptions and some API error messages (`Stránka neexistuje.`), are Czech literals rather than translation keys.
- `robots.txt` points to `/api/public/webplus/sitemap.xml` under `base_url`; the frontend must proxy or rewrite `/robots.txt`, `/sitemap.xml` and `/llms.txt` to these API paths for crawlers to find them.
- The listings/CMS templates come from Google-feed settings keys (`webplus.google_feed_listings_path`, `webplus.cms_pages_path`), so changing the feed URL template also changes which meta template resolves for those pages.
- `DynamicMetaResolver` hardcodes Czech reserved segments (`nabidky`, `stranka`, …) and defaults; `SAMPLE_PATHS` for health (`/o-nas`, `/kontakt`) assume the RealEstates frontend layout.
- Template metas are excluded from the sitemap; concrete URLs for listings and CMS pages come from `dynamic->sitemapEntries()` only, so hook-provided content is required for other dynamic sections.
- `WebPlusModule::registerCmsHooks()` resolves `GoogleProductsFeedDriver` from the container solely to "silence unused DI".
- `README.md` describes the module in Czech and states no version; the manifest is 1.4.3.
