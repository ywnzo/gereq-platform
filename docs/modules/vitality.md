# Vitality (`vitality`)

Vitality is an API latency monitor. A kernel middleware times every `/api/*` request, aggregates per-endpoint statistics (sample count, average, last and max duration) and, when an endpoint's running average crosses 500 ms, stores a detailed slow-request log with the executed SQL queries, memory peak, response size and sanitised query string. The admin gets a latency dashboard with a traffic-light heart badge in the menu (green / orange / red), a per-endpoint table, slow-request details and a reset action. It is a developer / operations tool with no public surface.

## At a glance

| Item | Value |
| --- | --- |
| Module id | `vitality` |
| Version | `1.99.0` (`modules/Vitality/module.php`, `VitalityModule::version()`) |
| Directory | `modules/Vitality/` |
| Requires | `core ^1.0` |
| Optional | none |
| Capabilities provided | none (`capabilities: []`, `provides: ['vitality']`) |
| Capabilities consumed | none; uses core `Gereq\Debug\QueryCollector` and `Gereq\Cache\AppCache` |
| Groups / packages | none in `module.php`; not listed in `config/module_groups.php` |
| Permissions | `vitality.view` (`perm.vitality.view`), `vitality.manage` (`perm.vitality.manage`) — group `perm.group.vitality` |
| API scopes | none |
| Author | GEREQ |

Manifest icon: `heart`. Description key: `module.vitality.description`.

## Domain model

Entities in `modules/Vitality/Domain/`; no relations between them other than the shared `endpoint_key` string.

### `EndpointStat` → table `vitality_endpoint_stats`

Index `idx_vitality_endpoint_avg (avg_duration_ms)`. Primary key is the natural key `endpoint_key` = `"<METHOD> <normalized path>"`.

| Field | Column / type | Notes |
| --- | --- | --- |
| `endpointKey` | `endpoint_key` string(255) PK | e.g. `GET /api/v1/products/{id}` |
| `method` | string(16) | upper-cased |
| `path` | string(255) | normalised path (UUIDs and numeric segments → `{id}`) |
| `routeName` | `route_name` string(190) nullable | from request attribute `_route`; updated on each sample |
| `sampleCount` | `sample_count` int | |
| `totalDurationMs` | `total_duration_ms` float | |
| `avgDurationMs` | `avg_duration_ms` float | `round(total / count, 2)` |
| `lastDurationMs` | `last_duration_ms` int | |
| `maxDurationMs` | `max_duration_ms` int | |
| `lastSeenAt` | `last_seen_at` datetime | |

`status()` derives a colour: `red` when `avg >= 500`, `orange` when `avg >= 300`, else `green` (thresholds duplicated as `VitalityMonitor::SLOW_AVG_MS` / `WARN_AVG_MS`).

### `SlowRequestLog` → table `vitality_slow_requests`

Indexes: `idx_vitality_slow_created (created_at)`, `idx_vitality_slow_endpoint (endpoint_key)`.

| Field | Column / type | Notes |
| --- | --- | --- |
| `id` | string(36) PK | UUID v7 |
| `endpointKey` | `endpoint_key` string(255) | matches `EndpointStat.endpoint_key` |
| `method` | string(16) | |
| `path` | string(2048) | raw (un-normalised) path |
| `routeName` | `route_name` string(190) nullable | |
| `statusCode` | `status_code` int | HTTP status |
| `durationMs` | `duration_ms` int | this request |
| `avgAtTimeMs` | `avg_at_time_ms` float | endpoint average when logged |
| `queryCount` | `query_count` int | |
| `queryTotalMs` | `query_total_ms` float | |
| `queriesJson` | `queries_json` json | up to 80 entries `{sql (≤ 2000 chars), duration, origin}` |
| `memoryPeakBytes` | `memory_peak_bytes` int | `memory_get_peak_usage(true)` |
| `responseBytes` | `response_bytes` int | `Content-Length` or body length |
| `correlationId` | `correlation_id` string(64) nullable | from `CorrelationIdMiddleware` attribute |
| `requestQuery` | `request_query` json | query string with `password`/`token`/`secret`/`authorization`/`api_key`/`apikey` keys redacted, non-scalars as `[complex]` |
| `createdAt` | `created_at` datetime | |

## Admin UI

### Menu (`VitalityModule::boot()`)

| Menu id | Label key | Icon | Route | Permission | Order / group | Badge |
| --- | --- | --- | --- | --- | --- | --- |
| `vitality` | `nav.vitality` | `heart` | `admin.vitality.index` | `vitality.view` | 92, group `Systém` | Heart SVG coloured by `VitalityMonitor::overallStatus()` (`heartBadgeHtml()`), title `vitality.status.<green|orange|red>`; falls back to green when tables do not exist yet |

Command palette: `vitality.index` (`palette.vitality.index`, `/admin/vitality`, keywords `vitality`, `api`, `latency`, `performance`, `monitor`).

### Routes (group `/admin/vitality`, middleware `auth`)

| Method | Path | Route name | Controller::action | Permission |
| --- | --- | --- | --- | --- |
| GET | `/admin/vitality` | `admin.vitality.index` | `VitalityController::index` | `vitality.view` |
| GET | `/admin/vitality/logs/{id}` | `admin.vitality.show` | `VitalityController::show` | `vitality.view` |
| POST | `/admin/vitality/reset` | `admin.vitality.reset` | `VitalityController::reset` | `vitality.manage` |

Controller: `modules/Vitality/Http/VitalityController.php`.

### Latency dashboard

- **Index** (`Resources/views/index.html.twig`): overall status heart, table of up to 150 endpoints ordered by average duration (`VitalityMonitor::endpointStats(150)`) with method, path, route name, samples, avg / last / max ms, status colour; list of the 40 most recent slow-request logs (`recentSlowLogs(40)`); reset button (`data-confirm`). Styles in `Resources/assets/css/admin.css`; heart partial `_heart.html.twig`.
- **Slow request detail** (`show.html.twig`): request metadata (status code, duration vs. average at the time, memory, response bytes, correlation id, sanitised query params) and the captured SQL query list with durations and origins.
- **Reset**: `VitalityMonitor::reset()` deletes both tables' rows, clears the status cache, logs audit `vitality.reset`, flash `vitality.flash.reset`.

### Settings

None.

## Public / REST API

None. The module adds the response header `X-Response-Time: <ms>ms` to every `/api/*` response via its middleware.

## Events, hooks & webhooks

None registered by the module. Recording happens through the HTTP middleware pipeline:

- `src/Http/Kernel.php` appends `Modules\Vitality\Http\Middleware\VitalityTimingMiddleware` to the middleware stack for every route whose path starts with `/api/` (after `ApiRateLimitMiddleware`). The middleware is a no-op when `ModuleManager::isEnabled('vitality')` is false.
- `VitalityTimingMiddleware::process()` records `QueryCollector::count()` before the request, measures wall time with `hrtime()`, then calls `VitalityMonitor::record()` inside a `try/catch` so monitoring can never break the API response.

Queue jobs, scheduled tasks, publishables: None.

## Dashboard widgets

| Widget id | Type | Class | Shows | Permission | Order |
| --- | --- | --- | --- | --- | --- |
| `vitality.shortcut` | shortcut | `Application/Widgets/VitalityShortcutWidget.php` | Link to dashboard, hint `admin.dashboard.hint.vitality` | `vitality.view` | 88 |
| `vitality.latency.stats` | metric | `VitalityLatencyStatsWidget.php` | Global average latency (`SUM(total)/SUM(samples)` in ms, `—` when no samples); hint = slow logs in the last 24 h | `vitality.view` | 74 |
| `vitality.slow.endpoints` | panel | `VitalitySlowEndpointsWidget.php` | 8 slowest endpoints (`sampleCount > 0`, by avg desc) with sample count and status badge | `vitality.view` | 36 |

See `../architecture/dashboard-widgets.md`.

## Services & extension points

| Class | Responsibility |
| --- | --- |
| `Application\VitalityMonitor` | `record(Request, Response, durationMs, queryOffset)` — normalises the path, upserts `EndpointStat`, captures queries from `QueryCollector` (offset-based so only this request's queries are stored), writes a `SlowRequestLog` when the endpoint average is ≥ 500 ms, prunes logs to `MAX_SLOW_LOGS = 500` (oldest first), invalidates the status cache. `overallStatus()` — cached 15 s in `AppCache` (`vitality.overall_status`), `MAX(avg)` → colour. `endpointStats(limit)`, `recentSlowLogs(limit)`, `findSlowLog(id)`, `reset()`, `statusBadgeHtml()` / static `heartBadgeHtml(status, title, size sm|md|lg)` (used by the menu badge and templates). |
| `Http\Middleware\VitalityTimingMiddleware` | Timing + `X-Response-Time` header; wired in core `Kernel` for `/api/*`. |

Constants: `SLOW_AVG_MS = 500.0`, `WARN_AVG_MS = 300.0`, `MAX_SLOW_LOGS = 500`, `MAX_QUERIES_STORED = 80`, `MAX_SQL_LENGTH = 2000`. There is no configuration surface for them.

Integration with other modules: none; the module is referenced by core (`src/Http/Kernel.php`) rather than the other way round.

## CLI commands

None.

## Configuration & translations

- Settings keys: none. Thresholds and limits are class constants.
- Environment variables: none.
- Translations: `Resources/translations/messages.cs.php`, `messages.en.php`; prefixes `module.vitality.description`, `nav.vitality`, `palette.vitality.index`, `perm.vitality.*`, `perm.group.vitality`, `vitality.*` (`vitality.status.*` — `green` = Healthy, `orange` = Degraded, `red` = Slow; `vitality.endpoints.*`, `vitality.widget.*`, `vitality.flash.*`, `vitality.error.*`). `admin.dashboard.hint.vitality` is in core.

## Database migrations

| File | Purpose |
| --- | --- |
| `Database/Migrations/2026_09_05_000001_vitality.php` | `up()` is a documented no-op (tables created by `SchemaTool`); `down()` drops `vitality_slow_requests` and `vitality_endpoint_stats`. |

## Notes

- Only `/api/*` routes are measured (admin pages, public HTML and webhooks are not); the middleware is inserted by core `Kernel`, so the module cannot be timed without it and the kernel references the module class directly (soft coupling from core to module).
- Every measured request performs at least one `SELECT` + `UPDATE`/`INSERT` and a flush on the entity manager, plus a cache invalidation — the monitor itself adds latency and DB writes to each API call and runs inside the request (no queue).
- Slow logs are triggered by the endpoint *average* (`avg >= 500`), not by the individual request duration; a single very slow request on a fast endpoint is not logged, while every request on a slow endpoint is logged until the average recovers or stats are reset.
- Averages are cumulative for the lifetime of the stats row (no time window or decay); only `reset()` clears them.
- Path normalisation replaces UUIDs and any `/<digits>` segment with `/{id}`; other identifiers (slugs, codes) create distinct endpoint keys.
- `queries_json` stores raw SQL (parameters are whatever `QueryCollector` provides) — sensitive values in SQL literals are not redacted, only query-string keys are.
- Menu badge computation runs a `MAX()` query during `boot()` on every admin request (cached 15 s).
- `README.md` says v1.0.3 while the manifest is 1.1.0.
