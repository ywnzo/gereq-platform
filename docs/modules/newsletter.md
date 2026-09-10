# Newsletter (`newsletter`)

Lightweight e-mail marketing bookkeeping: a **subscriber list** (subscribe / unsubscribe / bounced, with source tracking) and **campaigns** (subject + HTML body, `draft` → `sent`). Marketing staff manage both from the admin under *Marketing → Newsletter*; storefronts and external forms add subscribers through the `/api/v1/newsletter` endpoints. The module records intent only — it does **not** send e-mails itself (`markSent` just flips the status), so an external sending tool or a future integration is expected to deliver campaigns.

## At a glance

| | |
|---|---|
| Module id | `newsletter` |
| Version | `1.1.0` (`modules/Newsletter/module.php`, `NewsletterModule::version()`) |
| Directory | `modules/Newsletter/` |
| Requires | `core ^1.0`; optional: none |
| Capabilities | provides `newsletter` (payload: `Application/NewsletterProvider`); no module in `modules/` consumes it at present |
| Groups / packages | none in `module.php`; not listed in `config/module_groups.php` |
| Permissions | `newsletter.view` (View newsletter), `newsletter.manage` (Manage newsletter) — group `perm.group.newsletter` |
| Menu group | `Marketing` |

## Domain model

| Entity | Table | Key fields | Enum values | Relations |
|---|---|---|---|---|
| `Domain/Subscriber` | `newsletter_subscribers` (unique `uniq_newsletter_subscribers_email (email)`, index `idx_newsletter_subscribers_status (status)`) | `id: string(36)` UUIDv7; `email: string(190)` lower-cased & trimmed in constructor; `name: ?string(190)`; `status: string(32)` default `subscribed`; `source: ?string(64)` (`api`, `admin`, or caller-supplied); `subscribed_at: datetime_immutable`; `unsubscribed_at: ?datetime_immutable`; `created_at` | `STATUSES = subscribed, unsubscribed, bounced` — transitions `subscribe()` (resets `unsubscribed_at`), `unsubscribe()`, `markBounced()` | none |
| `Domain/Campaign` | `newsletter_campaigns` (index `idx_newsletter_campaigns_status (status)`) | `id: string(36)` UUIDv7; `subject: string(190)`; `body_html: text`; `status: string(32)` default `draft`; `sent_at: ?datetime_immutable`; `created_at` | `STATUSES = draft, sent` — `markSent()` sets `sent_at = now`, `setDraft()` clears it | none (no recipient/segment link) |

Both entities carry `#[FormEntity]` / `#[FormGenerator]` metadata: subscriber form fields `email` (type `email`), `name`, `source`; campaign form fields `subject` and `body_html` (type `editor`, full-width). Status labels resolve via `statusLabel()` → `newsletter.subscriber.status.*` / `newsletter.campaign.status.*`.

## Admin UI

### Menu & palette (`NewsletterModule::boot()`)

| Kind | Id | Label key | Icon | Route / URL | Permission | Order |
|---|---|---|---|---|---|---|
| Menu item | `newsletter` | `nav.newsletter` | `mail` | `admin.newsletter.subscribers.index` | `newsletter.view` | 55 (group `Marketing`) |
| Menu item | `newsletter.subscribers` (parent `newsletter`) | `nav.newsletter.subscribers` | — | `admin.newsletter.subscribers.index` | `newsletter.view` | 56 |
| Menu item | `newsletter.campaigns` (parent `newsletter`) | `nav.newsletter.campaigns` | — | `admin.newsletter.campaigns.index` | `newsletter.view` | 57 |
| Palette | `newsletter.subscribers` | `palette.newsletter.subscribers` | `mail` | `/admin/newsletter/subscribers` | `newsletter.view` | keywords: newsletter, odběratel, subscriber, email |
| Palette | `newsletter.campaigns` | `palette.newsletter.campaigns` | `mail` | `/admin/newsletter/campaigns` | `newsletter.view` | keywords: kampaň, campaign, newsletter |

### Routes (`NewsletterModule::register()`, middleware `auth`)

| Method | Path | Route name | Controller::action | Permission |
|---|---|---|---|---|
| GET | `/admin/newsletter/subscribers` | `admin.newsletter.subscribers.index` | `SubscriberController::index` | `newsletter.view` |
| GET | `/admin/newsletter/subscribers/create` | `admin.newsletter.subscribers.create` | `SubscriberController::create` | `newsletter.manage` |
| POST | `/admin/newsletter/subscribers` | `admin.newsletter.subscribers.store` | `SubscriberController::store` | `newsletter.manage` |
| POST | `/admin/newsletter/subscribers/{id}/unsubscribe` | `admin.newsletter.subscribers.unsubscribe` | `SubscriberController::unsubscribe` | `newsletter.manage` |
| POST | `/admin/newsletter/subscribers/{id}/delete` | `admin.newsletter.subscribers.destroy` | `SubscriberController::destroy` | `newsletter.manage` |
| GET | `/admin/newsletter/campaigns` | `admin.newsletter.campaigns.index` | `CampaignController::index` | `newsletter.view` |
| GET | `/admin/newsletter/campaigns/create` | `admin.newsletter.campaigns.create` | `CampaignController::create` | `newsletter.manage` |
| POST | `/admin/newsletter/campaigns` | `admin.newsletter.campaigns.store` | `CampaignController::store` | `newsletter.manage` |
| GET | `/admin/newsletter/campaigns/{id}/edit` | `admin.newsletter.campaigns.edit` | `CampaignController::edit` | `newsletter.manage` |
| POST | `/admin/newsletter/campaigns/{id}` | `admin.newsletter.campaigns.update` | `CampaignController::update` | `newsletter.manage` |
| POST | `/admin/newsletter/campaigns/{id}/send` | `admin.newsletter.campaigns.send` | `CampaignController::markSent` | `newsletter.manage` |
| POST | `/admin/newsletter/campaigns/{id}/delete` | `admin.newsletter.campaigns.destroy` | `CampaignController::destroy` | `newsletter.manage` |

### Screens

* **Subscribers list** (`Resources/views/subscribers/index.html.twig`) — `ListQuery` driven: search on `email`, `name`; sortable `email`, `status`, `subscribedAt`, `createdAt` (default `createdAt desc`); filter `status` (must be one of `Subscriber::STATUSES`). Row actions: unsubscribe, delete.
* **Subscriber form** (`subscribers/form.html.twig`) — create only (no edit route). `store()` delegates to `NewsletterProvider::subscribe()` with `source` defaulting to `admin`; re-subscribes an existing e-mail instead of failing on the unique constraint.
* **Campaigns list** (`campaigns/index.html.twig`) — search `subject`; sortable `subject`, `status`, `createdAt`, `sentAt`; filter `status`.
* **Campaign form** (`campaigns/form.html.twig`) — subject + HTML editor; "Mark as sent" button (`newsletter.campaigns.mark_sent`).

Validation rulesets (`register()`): `newsletter.subscriber.save` (`email` required|email|max:190, `name` ≤190, `source` ≤64), `newsletter.subscribe` (same), `newsletter.unsubscribe` (`email`), `newsletter.campaign.save` (`subject` 1–190, `body_html` 1–500000). Forms bound via `$context->forms()->entity(...)` for both entities.

Settings pages / setting keys: none.

## Public / REST API

Registered with `$context->api()->module('newsletter', …)`; known scopes `newsletter.read`, `newsletter.write` (`newsletter.read` is registered but unused by any route). See [API](../architecture/api.md).

| Method | Path | Scope | Controller::action | Behaviour |
|---|---|---|---|---|
| POST | `/api/v1/newsletter/subscribe` | `newsletter.write` | `Http/Api/NewsletterApiController::subscribe` | Validates `newsletter.subscribe` (form or JSON body), upserts subscriber (`source` defaults to `api`), emits `newsletter.subscribed`, returns 201 |
| POST | `/api/v1/newsletter/unsubscribe` | `newsletter.write` | `NewsletterApiController::unsubscribe` | Validates `newsletter.unsubscribe`; 404 when e-mail unknown; emits `newsletter.unsubscribed` |

Serializer (`ser()` / controllers' `public()`): `id, email, name, status, source, subscribed_at (ATOM), unsubscribed_at, created_at`. Campaign payload (events only): `id, subject, body_html, status, sent_at, created_at`. Filters: none. No `/api/public/*` endpoints and no list/read endpoints.

## Events, hooks & webhooks

Platform events (`EventDefinition`, all `webhookEnabled(true)`, descriptions are Czech literals):

| Event | Emitted from | Payload |
|---|---|---|
| `newsletter.subscribed` | `SubscriberController::store`, `NewsletterApiController::subscribe` | subscriber |
| `newsletter.unsubscribed` | `SubscriberController::unsubscribe`, `NewsletterApiController::unsubscribe` | subscriber |
| `newsletter.campaign_created` | `CampaignController::store` | campaign |
| `newsletter.campaign_sent` | `CampaignController::markSent` | campaign |

Resource envelope: `['type' => 'newsletter_subscriber' | 'newsletter_campaign', 'id' => …]`. Audit log: `newsletter.subscriber.created`, `newsletter.subscriber.deleted`, `newsletter.campaign.created|updated|deleted` (note: `markSent` and `unsubscribe` are not audited). No hooks, queue jobs, scheduled tasks or publishables. See [hooks, events & webhooks](../architecture/hooks-events-webhooks.md).

## Dashboard widgets

| Widget id | Type | Class | Shows | Permission | Order |
|---|---|---|---|---|---|
| `newsletter.shortcut` | shortcut | `Application/Widgets/NewsletterShortcutWidget` | Link to subscribers list, icon `mail`, hint `admin.dashboard.hint.newsletter` | `newsletter.view` | 55 |
| `newsletter.stats` | metric | `Application/Widgets/SubscriberStatsWidget` | Count of `subscribed` subscribers; hint: new sign-ups in last 30 days | `newsletter.view` | 55 |
| `newsletter.growth.chart` | panel (wide, `chart_panel.html.twig`) | `Application/Widgets/SubscriberGrowthChartWidget` | 30-day bar chart of sign-ups (`#34d399`) vs unsubscribes (`#f87171`) built with `ChartSeries::dayLabels/countsPerDay`; stats: sign-ups, unsubscribes, net | `newsletter.view` | 34 |

Registered in `boot()`. See [Dashboard widgets](../architecture/dashboard-widgets.md).

## Services & extension points

| Class | Responsibility |
|---|---|
| `Application/NewsletterProvider` | Capability payload. `subscribe(email, ?name, ?source)` — finds by lower-cased e-mail, re-subscribes existing rows (updating name/source if given) or persists a new `Subscriber`; `unsubscribe(email)` — returns `null` if unknown. |
| `Http/SubscriberController`, `Http/CampaignController` | Admin CRUD (see above). |
| `Http/Api/NewsletterApiController` | REST subscribe/unsubscribe. |

Other modules can call `$capabilities->get('newsletter')->subscribe(...)` (e.g. a checkout opt-in in [Shop](shop.md) or CMS forms in [Cms](cms.md)); no such consumer exists yet. There is no sending abstraction, segment/list concept, or bounce webhook.

## CLI commands

None.

## Configuration & translations

Settings / env vars: none.

Translations: `Resources/translations/messages.cs.php`, `messages.en.php`. Prefixes: `module.newsletter.description`, `nav.newsletter*`, `palette.newsletter.*`, `perm.newsletter.*`, `perm.group.newsletter`, `newsletter.subscribers.*`, `newsletter.campaigns.*`, `newsletter.subscriber.status.*`, `newsletter.campaign.status.*`, `newsletter.field.*`, `newsletter.action.*`, `newsletter.flash.*`, `newsletter.error.*`, `newsletter.widget.*`. The shortcut widget also uses `admin.dashboard.hint.newsletter` (defined in core translations, not in the module). Twig namespace: `@newsletter`.

## Database migrations

| File | Purpose |
|---|---|
| `Database/Migrations/2026_01_01_000001_newsletter.php` | Empty `up()`/`down()`. Tables `newsletter_subscribers` and `newsletter_campaigns` are created by the platform's schema sync (`src/Database/Migrator::syncSchema()` runs Doctrine `SchemaTool` over entity metadata of enabled modules after file migrations). |

## Notes

* Campaign "sending" is a status change only; `body_html` is never rendered or mailed by this module. `Subscriber::markBounced()` exists but nothing calls it.
* There is no subscriber edit route — only create, unsubscribe and delete. Re-subscribing is done by submitting the same e-mail again.
* `newsletter.read` scope is registered but no endpoint uses it; there is no API to list subscribers or campaigns.
* Public (unauthenticated) subscribe forms need an API token with `newsletter.write`; there is no `/api/public` variant.
* Platform event descriptions are Czech literals in `NewsletterModule::register()`.
* Related: [Customers](customers.md), [Crm](crm.md), [Notifications](notifications.md).
