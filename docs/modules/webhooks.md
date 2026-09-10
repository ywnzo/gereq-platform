# Webhooks (`webhooks`)

Webhooks turns platform events emitted by any GEREQ module into signed HTTP POST deliveries to external endpoints. Administrators (Developer section) register endpoints with an event filter, secret and timeout; the module listens to the core `platform.event.dispatched` hook, matches events against subscriptions, stores a `WebhookDelivery` row and pushes a `DeliverWebhookJob` onto the queue, which signs the payload with HMAC-SHA256, refuses private/loopback targets (SSRF guard), retries with exponential backoff and records every request/response. It also hosts the `InboundWebhookRegistry` capability that payment integrations (Stripe, GoPay, Comgate) plug into for `POST /api/v1/webhooks/inbound/{provider}`.

## At a glance

| Item | Value |
| --- | --- |
| Module id | `webhooks` |
| Version | `1.1.1` (`modules/Webhooks/module.php`, `WebhooksModule::version()`) |
| Directory | `modules/Webhooks/` |
| Requires | `core ^1.0` |
| Optional | none |
| Capabilities provided | `webhooks` → `Application\InboundWebhookRegistry` (`provides: ['webhooks']`) |
| Capabilities consumed | none; uses core `EventBus`, `EventRegistry`, `Queue`, `Config\Repository`, `Auditor` |
| Groups / packages | none in `module.php`; not listed in `config/module_groups.php` |
| Permissions | `webhooks.view` (`perm.webhooks.view`), `webhooks.manage` (`perm.webhooks.manage`), `webhooks.deliveries.retry` (`perm.webhooks.deliveries.retry`) — group `perm.group.webhooks` |
| API scopes | none (inbound endpoint is unauthenticated, verified per provider) |
| Author | GEREQ |

Manifest icon: `zap`.

## Domain model

Entities in `modules/Webhooks/Domain/`; `WebhookDelivery.webhook_id` references `Webhook.id` without a Doctrine association or FK.

```mermaid
erDiagram
    WEBHOOK ||--o{ WEBHOOK_DELIVERY : "webhook_id"
    WEBHOOK { string id PK; string name; string url; string secret; string previous_secret; json events; string status; int timeout_seconds; int consecutive_failures; datetime last_delivery_at }
    WEBHOOK_DELIVERY { string id PK; string webhook_id; string event_id; string event_type; string request_url; json request_headers; text request_body; int response_status; text response_body; int attempt; int duration_ms; string status; datetime next_retry_at }
```

### `Webhook` → table `webhooks`

Index `idx_webhooks_status (status)`. `#[FormEntity('webhooks.save')]` / `#[FormGenerator(cols: 1)]` generate the admin form from `name`, `url`, `timeoutSeconds` (`id`, `secret`, `previousSecret`, `events`, `status`, `consecutiveFailures`, `lastDeliveryAt`, timestamps excluded).

| Field | Column / type | Notes |
| --- | --- | --- |
| `id` | string(36) PK | UUID v7 |
| `name` | string(190) | form `webhooks.field.name`, hint `webhooks.hint.name` |
| `url` | string(2048) | form type `url`, hint `webhooks.hint.url`, placeholder `https://example.com/hooks/gereq` |
| `secret` | string(128) | `whsec_` + 48 hex chars (`generateSecret()`), never editable, shown once in flash and as `secretPreview()` (`whsec_xx…xxxx`) |
| `previousSecret` | `previous_secret` string(128) nullable | set by `rotateSecret()`; not used for signing |
| `events` | json list | subscription patterns; default `['*']` |
| `status` | string(32) | `STATUSES = active, paused, failing`; default `active` |
| `timeoutSeconds` | `timeout_seconds` int | default 10, form `number` 1–120, `max(1, …)` |
| `consecutiveFailures` | `consecutive_failures` int | reset on success / `activate()` |
| `lastDeliveryAt` | `last_delivery_at` datetime nullable | set on success and failure |
| `createdAt`, `updatedAt` | datetime | |

Behaviour: `isDeliverable()` = status `active` or `failing`; `matchesEvent(type)` accepts `*`, an exact type, or a prefix pattern ending in `.*` (e.g. `order.*`). `markSuccess()` clears failures and turns `failing` back into `active`; `markFailure()` increments failures and turns `active` into `failing`; `pause()` / `activate()` are explicit admin actions.

Status transitions: `active → failing` (first failed delivery), `failing → active` (next success), `active|failing → paused` (admin pause or `consecutive_failures >= api.webhooks.max_consecutive_failures`), `paused → active` (admin activate, after SSRF re-check).

### `WebhookDelivery` → table `webhook_deliveries`

Indexes: `idx_webhook_deliveries_webhook (webhook_id)`, `idx_webhook_deliveries_status (status)`, `idx_webhook_deliveries_retry (next_retry_at)`.

| Field | Column / type | Notes |
| --- | --- | --- |
| `id` | string(36) PK | UUID v7, also sent as `X-GEREQ-Delivery` |
| `webhookId` | `webhook_id` string(36) | |
| `eventId` | `event_id` string(64) | `EventEnvelope.id` (`evt_<uuid>`) |
| `eventType` | `event_type` string(190) | |
| `requestUrl` | `request_url` string(2048) | snapshot of the webhook URL |
| `requestHeaders` | `request_headers` json | includes signature and timestamp after sending |
| `requestBody` | `request_body` text | JSON of `EventEnvelope::webhookPayload()` |
| `responseStatus` | `response_status` int nullable | `null` on network error |
| `responseHeaders` | `response_headers` json nullable | |
| `responseBody` | `response_body` text nullable | truncated to 65 535 bytes |
| `attempt` | int | starts at 1 |
| `durationMs` | `duration_ms` int nullable | |
| `status` | string(32) | `STATUSES = pending, delivered, failed, retrying` |
| `createdAt`, `deliveredAt`, `nextRetryAt` | datetime | |

Delivery status transitions: `pending → delivered` (2xx); `pending → retrying` (retryable failure with attempts left, `next_retry_at` set); `retrying → pending` (`beginNextAttempt()` increments `attempt`, clears response); `pending → failed` (non-retryable status or attempts exhausted, or webhook deleted / URL blocked).

## Admin UI

The module lives under the core **Developer** menu (`parent: 'developer'`, group `Systém`).

### Menu (`WebhooksModule::boot()`)

| Menu id | Label key | Icon | Route | Permission | Order / parent |
| --- | --- | --- | --- | --- | --- |
| `developer.webhooks` | `nav.webhooks` | `zap` | `admin.developer.webhooks.index` | `webhooks.view` | 40, parent `developer` |
| `developer.webhook-deliveries` | `nav.webhook_deliveries` | — | `admin.developer.webhook-deliveries.index` | `webhooks.view` | 50, parent `developer` |
| `developer.webhooks.create` | `nav.webhooks.create` | — | `admin.developer.webhooks.create` | `webhooks.manage` | 10, parent `developer.webhooks`, hidden |

Command palette: `webhooks.index` (`palette.webhooks.index`, keywords `webhook`, `integrace`, `události`), `webhooks.create` (`palette.webhooks.create`, icon `plus`, group `Akce`, `webhooks.manage`), `webhook-deliveries.index` (`palette.webhooks.deliveries`, keywords `delivery`, `doručení`, `retry`).

### Routes (middleware `auth`)

Endpoints — group `/admin/developer/webhooks`, controller `modules/Webhooks/Http/WebhookController.php`:

| Method | Path | Route name | Action | Permission |
| --- | --- | --- | --- | --- |
| GET | `/admin/developer/webhooks` | `admin.developer.webhooks.index` | `index` | `webhooks.view` |
| GET | `/admin/developer/webhooks/create` | `admin.developer.webhooks.create` | `create` | `webhooks.manage` |
| POST | `/admin/developer/webhooks` | `admin.developer.webhooks.store` | `store` | `webhooks.manage` |
| GET | `/admin/developer/webhooks/{id}` | `admin.developer.webhooks.show` | `show` | `webhooks.view` |
| GET | `/admin/developer/webhooks/{id}/edit` | `admin.developer.webhooks.edit` | `edit` | `webhooks.manage` |
| POST | `/admin/developer/webhooks/{id}` | `admin.developer.webhooks.update` | `update` | `webhooks.manage` |
| POST | `/admin/developer/webhooks/{id}/delete` | `admin.developer.webhooks.destroy` | `destroy` | `webhooks.manage` |
| POST | `/admin/developer/webhooks/{id}/test` | `admin.developer.webhooks.test` | `test` | `webhooks.manage` |
| POST | `/admin/developer/webhooks/{id}/rotate-secret` | `admin.developer.webhooks.rotate_secret` | `rotateSecret` | `webhooks.manage` |
| POST | `/admin/developer/webhooks/{id}/pause` | `admin.developer.webhooks.pause` | `pause` | `webhooks.manage` |
| POST | `/admin/developer/webhooks/{id}/activate` | `admin.developer.webhooks.activate` | `activate` | `webhooks.manage` |

Deliveries — group `/admin/developer/webhook-deliveries`, controller `modules/Webhooks/Http/DeliveryController.php`:

| Method | Path | Route name | Action | Permission |
| --- | --- | --- | --- | --- |
| GET | `/admin/developer/webhook-deliveries` | `admin.developer.webhook-deliveries.index` | `index` | `webhooks.view` |
| GET | `/admin/developer/webhook-deliveries/{id}` | `admin.developer.webhook-deliveries.show` | `show` | `webhooks.view` |
| POST | `/admin/developer/webhook-deliveries/{id}/retry` | `admin.developer.webhook-deliveries.retry` | `retry` | `webhooks.deliveries.retry` |

### Screens

- **Endpoints list** (`Resources/views/webhooks/index.html.twig`): newest first, `?q=` search over `name`/`url`, `?status=` filter (`active|paused|failing`), `page`/`per_page` (default 20, max 100), status badges.
- **Endpoint form** (`webhooks/form.html.twig`): generated fields name / URL / timeout plus event subscription checkboxes built from `EventRegistry::webhookEnabled()` (every `EventDefinition` with `webhookEnabled = true`, description or type) and a free-text `events_custom` (comma/space separated patterns matching `/^[a-z0-9_.*-]+$/i`). Empty selection → `['*']`. Timeout defaults to `config('api.webhooks.timeout_seconds')` and is clamped to 1–120. The URL is checked by `SsrfGuard::assertSafe()` and a failure becomes a `ValidationException` on `url`. On create the flash `webhooks.flash.created` displays the full secret once.
- **Endpoint detail** (`webhooks/show.html.twig`): status badge, URL, events, secret preview, timeout, consecutive failures, last delivery; actions Test (emits `webhook.test`), Pause / Activate, Rotate secret (`data-confirm`, flash shows new secret), Edit, Delete; the 20 most recent deliveries for this endpoint.
- **Deliveries list** (`deliveries/index.html.twig`): calls `WebhookDispatcher::processDueRetries()` on every load, then lists deliveries newest first with `?webhook=` and `?status=` (`pending|delivered|failed|retrying`) filters, `per_page` default 30.
- **Delivery detail** (`deliveries/show.html.twig`): request URL, headers, body, response status/headers/body, attempt, duration, next retry, and a Retry button.
- **Retry**: creates a *new* `WebhookDelivery` (attempt 1) for the same event id/type and body against the webhook's current URL, strips the old `X-GEREQ-Signature`/`X-GEREQ-Timestamp` headers, enqueues it, audits `webhook.delivery.retried` (`source_delivery_id`, `webhook_id`), redirects to the new delivery.

Audit log entries: `webhook.created`, `webhook.updated` (with old values), `webhook.deleted`, `webhook.secret_rotated`, `webhook.delivery.retried`.

### Settings

No `Settings` keys; behaviour is driven by `config/api.php` `webhooks` block (see Configuration).

## Public / REST API

| Method | Path | Auth | Controller | Notes |
| --- | --- | --- | --- | --- |
| POST | `/api/v1/webhooks/inbound/{provider}` | none (provider-specific `verify()`) | `Http\Api\InboundWebhookController::handle` (route `api.webhooks.inbound`) | Resolves capability `webhooks` → `InboundWebhookRegistry`, looks up `{provider}`; 404 `not_found` when the capability/registry/provider is missing, 401 `unauthorized` when `verify()` fails, otherwise returns the provider's `handle()` response |

There is no REST CRUD for webhook endpoints or deliveries (admin UI only). No `/api/public/*` endpoints. The route is registered directly on the router (not through `ApiModule`), so it is outside API-token scopes; it still passes through core API middleware because its path starts with `/api/`.

### Outbound request format

- `POST <webhook.url>` with body `Json::encode(EventEnvelope::webhookPayload())` = `{id, type, version, created_at, data}` (the `actor`, `resource`, `correlation_id`, `causation_id` fields of the internal envelope are not sent).
- Headers: `Content-Type: application/json`, `User-Agent: GEREQ-Webhooks/1.0`, `X-GEREQ-Event: <type>`, `X-GEREQ-Delivery: <delivery id>`, `X-GEREQ-Timestamp: <unix seconds>`, `X-GEREQ-Signature: sha256=<hex>`.
- Signature (`Application\WebhookSigner`): `hash_hmac('sha256', timestamp . '.' . rawBody, secret)`; `verify()` uses `hash_equals`. Receivers should reject timestamps older than `api.webhooks.replay_tolerance_seconds` (300) — the constant is defined in config but not enforced by the module itself.

## Events, hooks & webhooks

### Platform events registered

| Event | Module | Version | Description | webhookEnabled |
| --- | --- | --- | --- | --- |
| `webhook.test` | `webhooks` | 1 | "Testovací událost webhooku" | true |

Emitted from `WebhookController::test()` with resource `{type: webhook, id}` and data `{webhook_id, webhook_name, message}`. Because it is dispatched through the normal hook, *every* matching endpoint (pattern `*` or `webhook.*`) receives the test, not only the one being tested.

### Hooks

- Adds action `platform.event.dispatched` (`HookPriority::NORMAL`) → `WebhookDispatcher::dispatch(EventEnvelope)`. Core `Gereq\Event\EventBus` fires this hook from both `emit()` (envelope-style events used by all modules, e.g. `order.created`, `stock.low`, `review.approved`) and `dispatch()` for `PlatformEvent` objects. The module therefore needs no per-module wiring; any event listed by a module's `platformEvents()->register()` (see `../architecture/hooks-events-webhooks.md`) becomes subscribable, and `EventRegistry::webhookEnabled()` drives the checkbox list in the form. Events that are not registered can still be delivered when an endpoint subscribes with `*` or a custom pattern.

### Delivery pipeline

1. **Fan-out** (`Application\WebhookDispatcher::dispatch()`, synchronous inside the emitting request): first runs `processDueRetries()`, then loads all webhooks with status `active` or `failing`, keeps those whose `matchesEvent(type)` passes, silently skips URLs rejected by `SsrfGuard`, encodes the payload once, and for each creates a `WebhookDelivery` (attempt 1, placeholder headers with the delivery id) and flushes, then `Queue::dispatch(new DeliverWebhookJob(id))`.
2. **Queue** (`Gereq\Queue\Queue`, `config/queue.php`): driver `QUEUE_DRIVER` (`sync` default — the job runs immediately in the same request; `redis` or file-backed otherwise, processed by `php gereq queue:work`).
3. **Send** (`Application\DeliverWebhookJob::handle()`): re-checks SSRF (fails the delivery and marks the webhook failing if blocked), signs the stored body with the *current* secret and a fresh timestamp, merges the final headers into the delivery, POSTs with cURL (`CURLOPT_FOLLOWLOCATION = false`, `CURLPROTO_HTTP|HTTPS`, connect timeout `min(5, timeout)`, total timeout = webhook timeout) or a stream-context fallback with peer verification and `follow_location = 0`.
4. **Outcome**: 2xx → `markDelivered()` + `Webhook::markSuccess()`. Otherwise `handleFailure()` records the response and `Webhook::markFailure()`; if retryable (network error/timeout, HTTP 408/425/429, any 5xx) and attempts remain, `scheduleRetry(delay)`; else `markFailed()`.
5. **Backoff** (`RETRY_DELAYS = [60, 300, 1800, 7200, 43200]` seconds): attempt 1 immediate, then +1 min, +5 min, +30 min, +2 h, +12 h — 6 attempts in total (`attempt < 6`).
6. **Retry pickup**: `processDueRetries()` selects up to 50 `retrying` deliveries with `next_retry_at <= now`, calls `beginNextAttempt()` and enqueues the job. It is invoked only when another event is dispatched or when the admin deliveries list is opened — there is no scheduler / cron entry in the module.
7. **Auto-pause**: after every failure `maybePause()` pauses the webhook when `consecutive_failures >= api.webhooks.max_consecutive_failures` (default 50, `0` disables).
8. **Delivery log**: every attempt's request headers/body and response are kept on the `WebhookDelivery` row (response body ≤ 64 KiB); the admin lists/details expose them.

### SSRF protection (`Application\SsrfGuard::assertSafe()`)

Rejects URLs without scheme/host or with a scheme other than `http`/`https`; unless `api.webhooks.allow_private_urls` is true it blocks hosts `localhost`, `127.0.0.1`, `0.0.0.0`, `::1`, `*.localhost`, `*.local`, resolves the host (`dns_get_record` A/AAAA + `gethostbynamel`, failing if nothing resolves) and blocks IPv4 `0/8`, `127/8`, `10/8`, `172.16/12`, `192.168/16`, `169.254/16` and IPv6 `::`, `::1`, `fe80::/10`, `fc00::/7`, plus IPv4-mapped `::ffff:` addresses. Applied at save time, at activation, at fan-out and again in the job. Redirects are never followed, so a redirect-based bypass is not possible.

### Inbound webhooks

`Application\InboundWebhookRegistry` (capability `webhooks`) stores implementations of `Gereq\Api\InboundWebhookProviderInterface` (`name()`, `verify(Request)`, `handle(Request)`). Registered by `integrations/Stripe/StripeModule.php`, `integrations/GoPay/GoPayModule.php`, `integrations/Comgate/ComgateModule.php` (each guarded by `class_exists(InboundWebhookRegistry::class)`); see `../architecture/api.md`.

Queue jobs: `Application\DeliverWebhookJob` (`Gereq\Queue\Job`). Scheduled tasks and publishables: None.

## Dashboard widgets

| Widget id | Type | Class | Shows | Permission | Order |
| --- | --- | --- | --- | --- | --- |
| `webhooks.shortcut` | shortcut | `Application/Widgets/WebhooksShortcutWidget.php` | Link to endpoints (`nav.webhooks`, hint `admin.dashboard.hint.webhooks`) | `webhooks.view` | 72 |
| `webhooks.stats` | metric | `WebhookStatsWidget.php` | Count of `active` endpoints; hint with deliveries `failed` in the last 24 h and number of `failing` endpoints (`webhooks.widget.stats.hint`) | `webhooks.view` | 72 |
| `webhooks.failed_deliveries` | panel (list) | `FailedDeliveriesWidget.php` | 8 latest deliveries in `failed`/`retrying` with event type, endpoint, attempt, status badge; link to deliveries list | `webhooks.view` | 36 |

Registered in `boot()`; see `../architecture/dashboard-widgets.md`.

## Services & extension points

| Class | Responsibility |
| --- | --- |
| `Application\WebhookDispatcher` | Hook target; fan-out of envelopes to deliveries, `enqueue(WebhookDelivery)`, `processDueRetries()`. Depends on `EntityManagerInterface`, `Gereq\Queue\Queue`, `SsrfGuard`. |
| `Application\DeliverWebhookJob` | Queue job performing one delivery attempt (signing, HTTP, outcome handling, backoff, auto-pause). Resolves services via `gereq()->make()`. |
| `Application\WebhookSigner` | `sign(secret, timestamp, body)` / `verify(...)` — HMAC-SHA256 with `sha256=` prefix. Public, reusable by receivers/tests. |
| `Application\SsrfGuard` | URL/host/IP allow-list enforcement (see above). |
| `Application\InboundWebhookRegistry` | Capability `webhooks`; `register()`, `get()`, `has()`, `all()` of `InboundWebhookProviderInterface`. |
| `Gereq\Api\InboundWebhookProviderInterface` (core) | Contract integrations implement to receive provider callbacks. |

Extension points for other modules: register events with `platformEvents()->register(EventDefinition …->webhookEnabled(true))` so they appear in the subscription form; emit them via `EventBus::emit()`; implement `InboundWebhookProviderInterface` and register it in the registry for inbound callbacks. No `class_exists` guards are needed inside this module — it depends only on core.

## CLI commands

None in the module. Delivery jobs are processed by the core `queue:work` command (`src/Console/Command/QueueWorkCommand.php`) when `QUEUE_DRIVER` is not `sync`.

## Configuration & translations

Config (`config/api.php` → `api.webhooks.*`, read through `Gereq\Config\Repository`):

| Key | Default | Env | Used by |
| --- | --- | --- | --- |
| `api.webhooks.allow_private_urls` | `false` | `WEBHOOKS_ALLOW_PRIVATE_URLS` | `SsrfGuard` — skips host/IP checks when true |
| `api.webhooks.timeout_seconds` | `10` | — | default for new endpoints in `WebhookController::validated()` |
| `api.webhooks.max_consecutive_failures` | `50` | `WEBHOOKS_MAX_FAILURES` | `DeliverWebhookJob::maybePause()` |
| `api.webhooks.replay_tolerance_seconds` | `300` | — | documented tolerance for receivers; not read by module code |

Queue: `config/queue.php` `driver` (`QUEUE_DRIVER`, default `sync`).

Settings (`Settings` service) keys: none.

Validation ruleset `webhooks.save`: `name required|min:2|max:190`, `url required|url|max:2048`, `timeout_seconds nullable|integer`; `forms()->entity('webhooks.save', Webhook::class)`.

Translations: `Resources/translations/messages.cs.php`, `messages.en.php` (~90 keys); prefixes `module.webhooks.description`, `nav.webhooks`, `nav.webhooks.create`, `nav.webhook_deliveries`, `palette.webhooks.*`, `perm.webhooks.*`, `perm.group.webhooks`, `webhooks.*` (`webhooks.field.*`, `webhooks.hint.*`, `webhooks.status.*`, `webhooks.delivery.*`, `webhooks.delivery.status.*`, `webhooks.flash.*`, `webhooks.error.*`, `webhooks.widget.*`, `webhooks.rotate_secret*`). `admin.dashboard.hint.webhooks` is in core `resources/translations`.

## Database migrations

| File | Purpose |
| --- | --- |
| `Database/Migrations/2026_01_01_000001_webhooks.php` | `up()` documented no-op (tables `webhooks`, `webhook_deliveries` created by `SchemaTool`); `down()` drops both tables. |

## Notes

- With the default `QUEUE_DRIVER=sync`, delivery HTTP calls (up to `timeout_seconds` each, per matching endpoint) run inside the request that emitted the event, and fan-out itself performs one flush per delivery.
- Retries depend on traffic: `processDueRetries()` runs only when another event is dispatched or the deliveries page is viewed; on an idle system scheduled retries never fire. No cron/scheduler registration exists.
- Fan-out matches on the webhook's `status` and does not consult `EventDefinition::webhookEnabled`; a `*` subscription receives events even if they were registered with `webhookEnabled(false)` or never registered.
- The payload is serialised at fan-out and re-signed at send time with the *current* secret; a rotated secret therefore applies to pending retries. `previous_secret` is stored but not used to sign or provide a grace period.
- `webhook.test` is broadcast to every matching endpoint, not just the tested one.
- Admin retry creates a fresh delivery (attempt 1) rather than re-attempting the failed one, so the attempt counter and backoff restart; both rows remain in the log.
- Deleting a webhook leaves its deliveries orphaned; the job marks such pending deliveries `failed` ("Webhook už neexistuje."). Several job/guard error strings and the event description are Czech literals stored in the delivery log.
- `SsrfGuard::resolveIps()` performs DNS lookups at save, activate, fan-out and delivery — DNS rebinding between check and connect is not mitigated, and the cURL request resolves the host independently.
- The delivery log has no pruning/retention policy; `request_body`/`response_body` are text columns.
- `README.md` says v1.0.3 while the manifest is 1.1.1.
