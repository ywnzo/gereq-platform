# Analytics

GEREQ module `analytics` — v0.1.1.

First-party web analytics for the platform storefront: tracked sites, pseudonymous visitors,
sessions, pageviews and events, collected only after cookie consent. Heatmap visualisation is a
later phase — this module only guarantees that the data needed for it (click coordinates, scroll
depth, engaged time) is collected from day one.

This README is the implementation contract for phase 1. Any change to the names listed here must be
reflected in this file, the manifest and the module class version.

## Non-goals (phase 1)

- Heatmap/funnel visualisation, segmentation builder, exports.
- Tracking of external (non-platform) websites: the tracker is first-party only.
- Console commands. Retention is automated with queue jobs registered on the scheduler
  (`Schedule::job()`), so no console command and no core change is needed.
- Geo-IP lookup: `country_code` is only filled from an inbound header when present, never resolved.
- No stored IP address, ever.

## Files that must NOT be touched in this phase

These platform edits are deliberately deferred and need an explicit approval first:

- `config/module_groups.php` (new package group `analytics` + `realestates` membership).
- `src/Api/Http/ApiRateLimitMiddleware.php` + `config/api.php` + `config/env.schema.toml`
  (a dedicated rate-limit bucket for the ingest endpoint — see "Operational prerequisites").
- `src/Api/Http/PublicApiAuthMiddleware.php` (adding the tracker bootstrap paths to
  `OPEN_DISCOVERY_PATHS` — see "Operational prerequisites").
- `modules/WebPlus/Application/WebPlusProvider.php` (filter `webplus.cookies_config`) + WebPlus
  version bump.
- `modules/Events/Application/LegalDocs.php` (cookie table rows `grq_consent`, `grq_vid`,
  `grq_no_track`) + Events version bump.
- `docs/**`.

Do not edit any file outside `modules/Analytics/**`.

## Module

| | |
| --- | --- |
| id | `analytics` |
| php namespace | `Modules\Analytics\` |
| twig namespace | `@analytics` |
| module class | `Modules\Analytics\AnalyticsModule` |
| version | `0.1.1` in `module.php`, `AnalyticsModule::version()` and `TrackerConfig::TRACKER_VERSION` (asserted by `ModuleManifestConsistencyTest` / `TrackerConfigTest`) |
| icon | `analytics` (raw Material Symbols ligature, `src/Admin/Ui/Icons.php`) |
| requires | `core: ^1.0` |
| optional | `webplus: ^1.0` (cookie CMP bridge only) |
| capabilities | `analytics` (provider `Application\AnalyticsProvider`) |
| permissions | `analytics.view`, `analytics.manage`, `analytics.raw`, `analytics.purge` |
| settings route | `admin.analytics.settings` |
| groups | `['analytics']` (group entry lives in the deferred `config/module_groups.php`) |

Layout follows the existing module convention (`modules/Viewings`, `modules/Vitality`) — there is
**no** `Infrastructure/` layer: `Domain/`, `Application/` (services + `Repositories/`, `Jobs/`,
`Widgets/`), `Http/`, `Database/Migrations/`, `Resources/`, `Tests/`.

## Data model

All tables are `analytics_*`, primary keys are UUIDv7 strings (`Ramsey\Uuid\Uuid::uuid7()`), all
foreign references are plain string id columns with an index — **no ORM associations** (ingest must
stay cheap, deletes are explicit per site). Column types use Doctrine types
(`date_immutable` → `DATE`, `datetime_immutable` → `DATETIME`/`TIMESTAMP`); JSON columns are
non-nullable arrays defaulting to `[]`.

| Entity | Table | Key columns | Indexes |
| --- | --- | --- | --- |
| `Domain\Site` | `analytics_sites` | `id`, `name`, `key` (`grq_an_` + 32 hex, generated once), `domains` json, `enabled`, `timezone`, `secret` (64 hex), `retention_override_days` null, `keep_bot_hits`, `respect_dnt`, `excluded_paths` json, `excluded_ips` json, `created_at`, `updated_at` | unique(`key`) |
| `Domain\Visitor` | `analytics_visitors` | `id`, `site_id`, `visitor_hash` (64), `hash_version`, `first_seen_at`, `last_seen_at`, `session_count`, `pageview_count`, `event_count`, `first_referrer_host` null, `landing_path` null, `last_path` null, `device_type`, `browser`, `os`, `language` null, `country_code` null | unique(`site_id`,`visitor_hash`), (`site_id`,`last_seen_at`) |
| `Domain\VisitSession` | `analytics_sessions` | `id`, `site_id`, `visitor_id`, `started_at`, `last_activity_at`, `ended_at` null, `pageview_count`, `event_count`, `duration_seconds`, `is_bounce`, `entry_path`, `exit_path`, `entry_referrer_host` null, `source`, `utm` json, `device_type`, `browser`, `os` | (`site_id`,`started_at`), (`site_id`,`last_activity_at`), (`visitor_id`) |
| `Domain\Pageview` | `analytics_pageviews` | `id`, `site_id`, `session_id`, `visitor_id`, `occurred_at`, `path`, `title` null, `referrer_host` null, `is_entry`, `is_exit`, `engaged_seconds`, `viewport_width` null, `viewport_height` null, `language` null, `props` json | (`site_id`,`occurred_at`), (`session_id`), (`site_id`,`path`), (`visitor_id`) |
| `Domain\TrackedEvent` | `analytics_events` | `id`, `site_id`, `session_id`, `visitor_id`, `pageview_id` null, `name`, `occurred_at`, `value` null, `props` json | (`site_id`,`occurred_at`), (`site_id`,`name`,`occurred_at`) |
| `Domain\DailyStat` | `analytics_daily_stats` | `id`, `site_id`, `day` (date), `visitors`, `new_visitors`, `sessions`, `bounces`, `pageviews`, `events`, `total_duration_seconds` | unique(`site_id`,`day`), (`day`) |
| `Domain\DailyDimension` | `analytics_daily_dimensions` | `id`, `site_id`, `day` (date), `dimension`, `value`, `visitors`, `sessions`, `pageviews`, `events`, `duration_seconds` | unique(`site_id`,`day`,`dimension`,`value`) |
| `Domain\ConsentEvent` | `analytics_consent_events` | `id`, `site_id`, `occurred_at`, `decision` (`granted`/`denied`/`revoked`), `categories` json, `visitor_hash` null | (`site_id`,`occurred_at`) |

`dimension` values used in phase 1 (`Domain\Dimension`): `path`, `referrer`, `device`, `browser`,
`os`, `country`, `event`, `entry`, `exit`, `source`. `source` values (`Domain\TrafficSource`):
`direct`, `referral`, `search`, `social`, `email`, `paid`, `internal` (same-site referrer).
Column lengths (255 for paths/titles, 190 for hosts/names, 120 for event names, 64 for
keys/secrets, …) are declared in the mapping; untrusted tracker strings are clipped in the entity
factories, so ingest can never fail on an oversized URL/title.

### Pseudonymity rules

- `visitor_hash = hash_hmac('sha256', $rawVisitorId, $site->secret())`; the raw id only ever lives in
  the visitor's own browser storage/cookie. `hash_version` starts at `1`. Rotating the site secret
  is the documented "forget everyone" lever and invalidates all stored pseudonyms for that site.
- Sessionization is server-side: a new session starts when there is no open session for the visitor
  or the gap since `last_activity_at` exceeds `analytics.session_idle_minutes`. A client-supplied
  session id is never trusted.
- User agent is reduced to `device_type` / `browser` / `os`; the raw UA string is never stored.
- The client IP is used only for rate limiting, internal-traffic exclusion and bot heuristics and is
  never written to any table.
- Suppression diagnostics log the normalised path (query and fragment stripped) and exception
  **class names** only — never a raw URL, never a DB error message.

## Consent contract

Three layers, in this order:

1. **Client** — the tracker reads the CMP decision from `localStorage` (key configurable via
   `analytics.consent_storage_key`, default `cookie-consent`) and only starts when the consent
   category (`analytics.consent_category`, default `statistics`) is granted. No consent → no cookie
   and no beacon, except the `grq_no_track` opt-out cookie (see below).
2. **Server (honesty handshake, not a trust boundary)** — `POST /api/public/analytics/collect`
   requires the first-party cookie `grq_consent`
   (JSON `{"v":1,"statistics":true,"marketing":false,"ts":<unix>}`) *and* the matching assertion in
   the payload. Missing/mismatched consent → empty `204` no-op, nothing written, no reason leaked.
   The cookie is written by page JavaScript and is therefore a client assertion; the real integrity
   guarantees are the origin binding (below), the caps and the retention — treat this layer as
   "collection does not start by accident", not as authorisation.
3. **Revocation** — the tracker stops and deletes `grq_vid`; `POST /api/public/analytics/consent`
   with `decision=revoked` closes the open session, records `analytics_consent_events` and (when
   `analytics.purge_on_revocation` is true) deletes all rows of that `visitor_hash`. The `revoked`
   decision is deliberately accepted **without** the consent cookie (a visitor must be able to revoke
   after clearing the CMP state); `granted`/`denied` are only recorded **with** the assertion cookie.
   The consent row for the revocation itself carries the (purgeable) `visitor_hash`; it is removed by
   the retention purge described below.

Cookie names owned by this module: `grq_consent` (consent assertion), `grq_vid` (pseudonymous
visitor id, `SameSite=Lax`, 13 months), `grq_no_track` (opt-out, 1 year). They stay plaintext on
purpose — the client has to be able to read and clear them, so they are **not** added to
`EncryptCookiesMiddleware`'s allowlist. `grq_no_track` is the single cookie written before consent:
an opt-out has to work without a CMP decision, and it is re-checked server-side.

### Origin binding

A site key is public (it is part of the page source). Therefore, when a hit resolves the site **by
key**, the request `Host` **and** the `Origin`/`Referer` host must match `analytics_sites.domains`
(exact host or `*.example.com`). An empty `domains` list means "no domain restriction configured"
and accepts the key alone; missing host evidence is rejected. A mismatch is suppressed exactly like
an unknown site (internal reason `foreign_host`, never returned to the caller).
`GET /api/public/analytics/config` is bound the same way and answers `{"enabled":false}` for a
foreign host, so it cannot be used as a key-existence oracle.

## Public surface

| Route | Methods | Handler |
| --- | --- | --- |
| `/api/public/analytics/collect` | `OPTIONS`, `POST` | `Http\Public\PublicAnalyticsController::options` / `::collect` |
| `/api/public/analytics/consent` | `OPTIONS`, `POST` | `::options` / `::consent` |
| `/api/public/analytics/config` | `GET` | `::config` (`Cache-Control` + `ETag` + 304, `Vary: Host`) |
| `/api/public/analytics/tracker.js` | `GET` | `::tracker` (`Cache-Control` + `ETag` + 304, `?v=0.1.1`) |

`collect` payload (limit `analytics.beacon_max_events` events, 32 KB body, ±1 day clock skew):

```json
{
  "site": "grq_an_…",
  "consent": {"v": 1, "statistics": true, "ts": 1699999999},
  "visitor": "<raw visitor id from grq_vid>",
  "page": {"url": "/cesta?x=1", "title": "…", "referrer": "https://…", "lang": "cs"},
  "viewport": {"w": 390, "h": 844},
  "events": [
    {"t": "pageview", "ts": 1699999999, "props": {}},
    {"t": "engagement", "ts": 1699999999, "props": {"engaged_ms": 4200, "scroll_depth": 0.62}},
    {"t": "click", "ts": 1699999999, "props": {"x": 0.42, "y": 0.118, "sel": "a13f…", "w": 390, "h": 844}},
    {"t": "event", "ts": 1699999999, "props": {"name": "form_sent", "value": 1}}
  ]
}
```

`t` accepts `pageview`, `engagement`, `click`, `scroll` and `event`; for `event` the stored name
comes from `props.name` and the numeric value from `props.value` (both sanitised/capped).

Response semantics: `200` with `{"ok":true,"sid":"<server session id>"}` (collect) or
`{"ok":true,"decision":"…"}` (consent) when the hit was stored; empty `204` for every suppressed or
unparseable request, with no reason attached (the tracker ignores the body, so this split only
serves operators/debugging); `429` when rate limited.

Tracker config (`config`) returns the public, cacheable subset: `enabled`, `endpoint`, `site` key,
`consent` contract (`storage_key`, `category`), `tracker_url`, `beacon_max_events`, `respect_dnt`.
No admin-only values and never the site `secret`.

Tracker (`Resources/public/tracker.js`) is standalone vanilla JS served by the controller — no Bun
entry, no build step, no external requests (≈15.6 KB raw / ≈5 KB gzip on the wire). It uses
`navigator.sendBeacon` with a `fetch(..., {keepalive: true})` fallback, patches
`history.pushState`/`replaceState` and listens to `popstate` for SPA routing, and skips itself on
`navigator.webdriver`, DNT/GPC (when `respect_dnt`), `?grq_notrack=1`,
`document.documentElement.dataset.gereqNoTrack` or the `grq_no_track` cookie. Every endpoint,
config and consent URL it resolves from the DOM or from the config response is validated as
**same-origin http(s)** first; anything else falls back to the built-in default path, so an injected
`data-endpoint`/`data-config` cannot be used to ship visitor ids off-site.

Injection happens in `boot()` via the `storefront.spa_html` filter (same pattern as
`modules/WebPlus/WebPlusModule.php`): the site is resolved from the request host against
`analytics_sites.domains` (memoised once per request), the tracker is skipped when it is disabled or
the host is unknown, and any failure (missing schema, DB down) returns the HTML unchanged — an
optional analytics module must never break the storefront or its 404/error page.

## Bot and internal traffic

- `Application\BotClassifier::isBot(?string $userAgent, ?bool $webdriver, string $honeypot): bool`
  — pure function: crawler/bot UA patterns (including brands glued to `bot`, e.g. `SomeBrandBot/1.2`,
  and headless markers), `webdriver === true`, and a non-empty honeypot (`website_url` / `website`).
- `Application\TrafficClassifier` — internal traffic: CIDR match against `analytics.internal_ips`
  (global) plus per-site `excluded_ips`, prefixed `excluded_paths` match, and the `grq_no_track`
  opt-out cookie.
- Default `keep_bot_hits = false` → matched traffic is dropped (empty `204`), with a debug log of the
  normalised path. With `keep_bot_hits = true` the hit is stored with `device_type = 'bot'`
  (or `'internal'`) and `props.excluded = true`, and every report excludes those rows.

## Settings (all prefixed `analytics.`)

| Key | Type | Default | Meaning |
| --- | --- | --- | --- |
| `analytics.tracker_enabled` | bool | `true` | master switch for injection + config |
| `analytics.consent_category` | string | `statistics` | CMP category that gates collection |
| `analytics.consent_storage_key` | string | `cookie-consent` | CMP storage key in `localStorage` |
| `analytics.respect_dnt` | bool | `true` | honour Do Not Track / GPC |
| `analytics.session_idle_minutes` | int | `30` | server-side sessionization window |
| `analytics.beacon_max_events` | int | `50` | max events per beacon |
| `analytics.keep_bot_hits` | bool | `false` | store instead of dropping bot hits |
| `analytics.internal_ips` | text | `''` | comma/newline separated CIDR list |
| `analytics.purge_on_revocation` | bool | `true` | delete the visitor's rows on revocation |
| `analytics.retention_raw_days` | int | `90` | pageviews/events retention |
| `analytics.retention_visitor_days` | int | `395` | visitors/sessions **and consent records** retention |
| `analytics.retention_rollup_days` | int | `1095` | daily rollups retention |
| `analytics.purge_batch_size` | int | `5000` | delete batch size |

`Application\AnalyticsConfig` is the single reader of these values (per-site overrides win over
globals); controllers, jobs, the tracker config and the retention services use only it.

## Retention and rollups

- `Application\RollupService::rollup(?string $day = null, int $days = 1): array{rows:int}` —
  idempotent upsert into `analytics_daily_stats` + `analytics_daily_dimensions` (delete the day's
  dimension rows, then insert). `$days` is capped at `MAX_ROLLUP_DAYS = 400` inside the service, so a
  forged queue payload cannot trigger an unbounded rebuild.
- `Application\PurgeService::purge(?int $days = null, bool $dryRun = false): array{pageviews,events,sessions,visitors,consent,rollups,candidates,deleted}`
  — chunked deletes (`analytics.purge_batch_size`) driven by `occurred_at` / `last_seen_at` indexes;
  visitors are purged only when idle longer than the visitor window, consent records
  (`analytics_consent_events`) by `occurred_at` against the visitor window, and rollups against the
  rollup window. `candidates`/`deleted` include every target.
- `Application\Jobs\RollupAnalyticsJob` and `Application\Jobs\PurgeAnalyticsJob` are thin wrappers:
  constructor takes scalars only (the queue payload is JSON rebuilt through the constructor), and
  services are resolved in `handle()` via `gereq()`.
- Registered in `boot()`: `analytics.rollup` hourly, `analytics.purge` `dailyAt('03:20')`
  `timezone('Europe/Prague')`, both `async()` (jobs are always async) and
  `withoutOverlapping(120)` so a crashed run cannot block a whole day of ticks.
- Manual dry run: `POST /admin/analytics/settings/purge-preview` (permission `analytics.purge`)
  renders the eight counters of `purge(dryRun: true)`.

## Admin surface

Routes are grouped under `/admin/analytics` with `['middleware' => ['auth'], 'module' => 'analytics']`.

| Route name | Path | Permission | Controller |
| --- | --- | --- | --- |
| `admin.analytics.index` | `/admin/analytics` | `analytics.view` | `Http\OverviewController::index` |
| `admin.analytics.pages` | `/admin/analytics/pages` | `analytics.view` | `Http\ReportController::pages` |
| `admin.analytics.sources` | `/admin/analytics/sources` | `analytics.view` | `Http\ReportController::sources` |
| `admin.analytics.devices` | `/admin/analytics/devices` | `analytics.view` | `Http\ReportController::devices` |
| `admin.analytics.events` | `/admin/analytics/events` | `analytics.view` | `Http\ReportController::events` |
| `admin.analytics.visitors` | `/admin/analytics/visitors` | `analytics.raw` | `Http\VisitorController::index` |
| `admin.analytics.sites.index` | `/admin/analytics/sites` | `analytics.manage` | `Http\SiteController::index` |
| `admin.analytics.sites.create` | `/admin/analytics/sites/create` | `analytics.manage` | `Http\SiteController::create` |
| `admin.analytics.sites.store` | `POST /admin/analytics/sites` | `analytics.manage` | `Http\SiteController::store` |
| `admin.analytics.sites.edit` | `/admin/analytics/sites/{id}/edit` | `analytics.manage` | `Http\SiteController::edit` |
| `admin.analytics.sites.update` | `POST /admin/analytics/sites/{id}` | `analytics.manage` | `Http\SiteController::update` |
| `admin.analytics.sites.delete` | `POST /admin/analytics/sites/{id}/delete` | `analytics.manage` | `Http\SiteController::destroy` |
| `admin.analytics.sites.rotate` | `POST /admin/analytics/sites/{id}/rotate-key` | `analytics.manage` | `Http\SiteController::rotateSecret` |
| `admin.analytics.settings` | `/admin/analytics/settings` | `analytics.manage` | `Http\SettingsController::index` |
| `admin.analytics.settings.save` | `POST /admin/analytics/settings` | `analytics.manage` | `Http\SettingsController::save` |
| `admin.analytics.settings.purge-preview` | `POST /admin/analytics/settings/purge-preview` | `analytics.purge` | `Http\SettingsController::purgePreview` |

Menu item id `analytics` (route `admin.analytics.index`, permission `analytics.view`, icon
`analytics`), one command-palette entry and one admin-search source are registered in `boot()`.
Site create/update/delete/rotate and the purge preview write an `Auditor` entry; event payloads and
every Twig context are secret-free (`AnalyticsProvider::publicSite()`), so the HMAC `secret` never
reaches the browser.

The site form is generated from the entity: `#[FormGenerator(form: 'analytics.site.save', cols: 2, …)]`
on `Domain\Site` yields exactly `name`, `timezone`, `enabled` (switch), `keep_bot_hits` (switch),
`respect_dnt` (switch), `retention_override_days` (number), and the three JSON list columns
(`domains`, `excluded_paths`, `excluded_ips`) are rendered as explicit `ui/textarea.html.twig`
controls (one entry per line) because an array value cannot go through a scalar control. POST field
names match the `analytics.site.save` ruleset and `SiteController::siteInput()`.

Dashboard widgets (`boot()`, `Application/Widgets/`): `AnalyticsKpiWidget` and
`AnalyticsTrendChartWidget` (`chart_panel` config built with `Gereq\Admin\Dashboard\ChartSeries`).

Twig views (namespace `@analytics`, all extend `admin/layout.html.twig`, shared UI components via
`ui_render()`, `csrf_field()` in every mutating form, no native dialogs, no module JS assets):
`overview.html.twig`, `reports/{pages,sources,devices,events}.html.twig`, `visitors.html.twig`,
`sites/{index,form}.html.twig`, `settings.html.twig`, shared partials under `views/partials/`.
Query parameters used by the filters/nav: `?site=<site id>&range=7d|30d|90d|<n>d`.

## API (internal, `/api/v1/analytics`)

Read-only, scope `analytics.read`: `GET /stats`, `GET /pages`, `GET /sources`, `GET /sites`.
Declared with `$context->api()->module('analytics', …)`; responses via the `ApiResponse` envelope,
filters through a whitelist. No writes — site management stays in the admin UI. The admin reports
read the raw tables (accurate, includes today); the API reads the daily rollups (so long ranges
survive raw retention).

## Events

Registered via `$context->platformEvents()->register(...)` in `register()`, payloads are versioned
plain arrays (never entities, never secrets):

- `analytics.site.created`, `analytics.site.updated` (webhook-enabled)
- `analytics.consent.revoked`
- `analytics.retention.purged`

## Operational prerequisites

- `API_PUBLIC_KEY` must be configured: `PublicApiAuthMiddleware` answers `503 misconfigured` for the
  whole `/api/public/*` surface otherwise (pre-existing platform behaviour).
- The tracker `<script>`/config are plain same-origin `GET`s. They pass the first-party check through
  `Origin`/`Referer`; a deployment that strips both (a `no-referrer` policy) would see `401`. Adding
  the bootstrap paths to `OPEN_DISCOVERY_PATHS` is the deferred platform fix.
- `TRUSTED_PROXIES` must match the reverse proxy, otherwise every visitor shares the proxy IP: the
  platform's `/api/public/*` write bucket (30/min/IP) then throttles everyone, and an IP listed in
  `analytics.internal_ips` would mark all traffic internal.
- Each site's `domains` list must cover the host it is actually served on (or stay empty to disable
  the origin binding), otherwise beacons are silently suppressed with the internal reason
  `foreign_host`.
- Until the deferred core change lands, the ingest endpoint is still fronted by the platform's
  `public_write` bucket (30 writes/min/IP). The module's own limiter
  (`analytics:{ip}[:{resolved site id}]`, 300/min) only sees requests that pass the core bucket, and
  a handful of visitors behind one NAT can exhaust it. A dedicated bucket (or an exemption) in
  `src/Api/Http/ApiRateLimitMiddleware.php` + `config/api.php` is required before release.

## Known deviations from the original contract

1. **Ingest order**: site resolution happens inside the classification step (per-site
   `excluded_ips`/`excluded_paths` and `keep_bot_hits` need the site row); everything else keeps the
   order validate → consent → classify → pseudonymise → session → persist → counters.
2. **One DBAL transaction per beacon** instead of a single ORM `flush()` — all analytics writes are
   explicit column-contract SQL (the README's own rationale: cheap ingest, batched deletes).
3. **Stored bot/internal marking** uses `device_type = 'bot'|'internal'` plus
   `props.excluded = true`, not a `source` value (`source` keeps its genuine traffic classes).
4. **Reports read raw tables in the admin UI and rollups in the API** (see API section).
5. **Day buckets are UTC.** `analytics_sites.timezone` is stored but not yet applied to day
   bucketing; rollups, admin ranges and the trend chart therefore follow UTC days. Applying the site
   timezone is a follow-up decision, not half-implemented here.
6. **Rate limiting** as described under prerequisites.
7. **Tracker size**: ≈15.6 KB raw / ≈5 KB gzip — the earlier "≤6 KB raw" budget is not attainable
   with the mandated feature set in readable ES2018; the HTTP layer compresses it.
8. **Admin report ranges** beyond the raw retention window fall back to raw-only data (the API is the
   rollup-backed surface).

## Tests and verification

`Tests/` holds 17 pure unit test files (the project has no DB/HTTP harness for modules; the
retention and resolution services are tested against narrow doubles plus a real in-memory SQLite case
for the purge SQL):

`AnalyticsConfigTest`, `AnalyticsProviderPublicSiteTest`, `BotClassifierTest`, `ConsentGateTest`,
`ConsentServiceTest`, `FormsSiteFormContractTest`, `IngestServiceTest`, `PublicAnalyticsControllerTest`,
`PurgeServiceTest`, `RollupServiceTest`, `SessionizerTest`, `SiteRepositoryHostsMatchSiteTest`,
`TrackerConfigTest`, `TrackerInjectorTest`, `TrafficClassifierTest`, `TranslationCatalogParityTest`,
`VisitorPseudonymizerTest`.

Last verified state (module scope, run from the repository root):

```bash
php bin/gereq-test module:analytics                        # OK (214 tests, 561 assertions)
php bin/gereq-test modules --filter=ModuleManifestConsistency  # OK (46 tests, 414 assertions)
composer phpstan                                            # OK, no errors
composer phpstan:tests                                      # OK, no errors
composer cs                                                 # 1 pre-existing error in
                                                            # modules/Listings/Tests/… (unrelated)
```

No Bun/TS gate applies: phase 1 adds no admin JS/TS or CSS assets (the tracker is standalone runtime
JS served from `Resources/public/`).

Release status: the module is published as `gereq/module-analytics`, the first release is tagged
`v0.1.1` and the platform tracks it as a submodule (`url = ../module-analytics.git`, pin `v0.1.1`).
The version is `0.1.1` in all four places (`module.php`, `AnalyticsModule::version()`,
`TrackerConfig::TRACKER_VERSION`, the tracker bundle) and must stay in lockstep with the tag.
Migrations were not run from this checkout as part of the phase; enabling the module and running
migrations remain explicit admin steps.

## Behaviour analytics (0.2.1)

- `/admin/analytics/forms` (`analytics.view`) reports inquiry form starts, field focus and filled/cleared transitions, validation failures, submission attempts, backend-confirmed success, request failures and abandonment. Counts are per event and per distinct session, not a strict ordered funnel. Field names and fixed reason codes are recorded; input contents and server error messages are never included.
- `/admin/analytics/recordings` and `/recordings/{id}` (`analytics.raw`) list the latest 100 sessions in the selected site/date range and show a bounded timeline plus visual playback. `/recordings/{id}/data` is authenticated, checks site ownership, and responds with `private, no-store`.
- The Vue frontend uses `@rrweb/record` 2.0.0; the module uses matching `@rrweb/replay`. The recorder starts only when the existing tracker confirms analytics consent and passes DNT/bot/opt-out checks. Forms and editable controls are blocked in snapshots; all inputs are additionally masked. No console/network/canvas plugins are enabled. Public metadata URLs have query strings/fragments stripped. Storefront asset URLs can still be present in the snapshot.
- Replay batches are gzip-compressed, split into bounded 16 KB base64 parts and sent through the existing consent-gated collector as `session_replay` transport events. They reuse raw event retention, site deletion and visitor consent-revocation purging. Transport chunks do not increment behavioural event counters and are excluded from event reports. No new table or migration is required.
- Recordings are best effort: a 10-second flush interval, a 2 MB compressed limit per document recording, bounded buffers and a maximum of 600 chunks per session read. Incomplete initial batches cannot be played; a missing later batch ends the playable prefix. The last unflushed seconds may be lost when a browser terminates. Each document/tab has a separate recording inside its analytics session.
- The replayer keeps rrweb's sandbox without script execution, disables pointer interaction and canvas execution, and allows storefront image/font assets only from the configured `FRONTEND_ORIGIN` in addition to the normal admin asset policy. Assets are loaded from their original locations and may become unavailable after a later deploy; this is not an archival video export.
- Analytics collection has its own `api.rate_limit.analytics` bucket (default 300/min/IP), independent of inquiry submissions. The module collector's own limit remains active.
- This release adds recording/playback and form activity, not Clarity's complete product: aggregate heatmaps, rage-click classification, searchable segments and long-term asset archiving are not implemented.

## Collector query fixes (0.2.2)

- The module rate limiter now uses a fixed IP bucket before any site lookup. Rotating tracker keys cannot mint more buckets, and the collector no longer resolves the same site twice (limiter + ingest/consent).
- Events from one beacon use a multi-row INSERT, chunked at 90 rows / 900 binds to remain compatible with SQLite's conservative parameter limit. The existing ingest transaction still covers events and counters atomically. A default 50-event beacon uses one event INSERT instead of 50; replay transport chunks remain excluded from behavioural counters.
- The core N+1 fingerprint preserves double-quoted SQL identifiers. The overview's source/device GROUP BY queries are distinct and must not be merged as repeated reads.
- Collection, consent handling and replay decoding still run synchronously in the HTTP request. Rollup and retention are scheduled jobs, but `QUEUE_DRIVER=sync` also executes those jobs inline. Real background execution requires a `file` or `redis` driver plus `queue:work` and `schedule:run` workers. Switching the global driver affects all modules and was not done by this fix.
- Moving ingestion to a queue also requires durable ordering/idempotency and a consent-revocation barrier so a delayed beacon cannot recreate purged visitor data. Merely dispatching the current ingestion service is insufficient.

## Cookie integration (0.3.0)

Consent UI now belongs to the standalone Cookie module rather than Analytics or Web+. When the `cookie` capability is available, Analytics uses its storage key and the statistics category. Replay additionally requires `replay: true` in both the analytics cookie and beacon assertion; statistics-only consent never accepts replay chunks. The GEREQ Vue frontend loads the Cookie widget before bootstrapping Analytics and checks the replay category before recording. The obsolete `webplus.cookies_config` hook was removed.
