# Notifications (`notifications`)

In-app inbox and per-user notification preferences on top of the core `Gereq\Notification\Notifier`. The module listens to every dispatched platform event, turns a whitelist of "interesting" events (new customer, deal, order, invoice, task, low stock, …) into notifications for all active admins who may view notifications, and renders an unread badge in the admin top bar. Other modules (currently [Projects](projects.md)) can push targeted notifications to a single user through the `notifications` capability. Channels are `in_app` (stored in the core `notifications` table) and `email` (via Symfony Mailer), each toggled per user and event type.

## At a glance

| | |
|---|---|
| Module id | `notifications` |
| Version | `1.1.0` (`modules/Notifications/module.php`, `NotificationsModule::version()`) |
| Directory | `modules/Notifications/` |
| Requires | `core ^1.0`; optional: none |
| Capabilities | provides `notifications` (payload: `Application/NotificationsProvider`, exposing `bridge(): NotifierBridge`); consumed by `modules/Projects/Http/TaskController::notify()` (duck-typed `method_exists` guards) |
| Groups / packages | none in `module.php`; not listed in `config/module_groups.php` |
| Permissions | `notifications.view` (View notifications), `notifications.manage` (Manage notification preferences) — group `perm.group.notifications` |
| Menu | no sidebar item; top-bar bell icon via `admin.topbar.actions` filter + two palette commands |

## Domain model

| Entity | Table | Key fields | Enum values | Relations |
|---|---|---|---|---|
| `Domain/NotificationPreference` | `notification_preferences` (unique `uniq_notif_pref (user_id, channel, event_type)`) | `id: string(36)` UUIDv7; `user_id: string(36)`; `channel: string(32)`; `event_type: string(120)` (`*` = wildcard row written by the preferences form, but see Notes); `enabled: bool` default `true` | channels used: `in_app`, `email` | `user_id` → `users.id` (core `Gereq\User\Entity\User`) |
| *(core)* `Gereq\Notification\DatabaseNotification` | `notifications` | `id`, `user_id: string(36)`, `type: string(120)` (notification class name, i.e. `Modules\Notifications\Application\SimpleNotification`), `data: json` (`{title, body, meta}`), `read_at`, `created_at` | — | `user_id` → `users.id` |

The module owns only the preference entity; the inbox rows live in the core table and are read/updated through the entity manager.

## Admin UI

### Palette & top bar (`NotificationsModule::boot()`)

| Kind | Id | Label key | Icon | URL | Permission |
|---|---|---|---|---|---|
| Palette | `notifications.index` | `palette.notifications.index` | `bell` | `/admin/notifications` | `notifications.view` |
| Palette | `notifications.preferences` | `palette.notifications.preferences` (group `Účet`) | `settings` | `/admin/notifications/preferences` | `notifications.manage` |
| Top bar | filter `admin.topbar.actions` | — | `bell` (`Icons::svg('bell')`) with `<span class="nav-item__badge">` unread count | `admin.notifications.index` | `notifications.view` (checked via `User::can`) |

No `MenuItem` is registered.

### Routes (`register()`, group `/admin/notifications`, middleware `auth`)

| Method | Path | Route name | Controller::action | Permission |
|---|---|---|---|---|
| GET | `/admin/notifications` | `admin.notifications.index` | `NotificationController::index` | `notifications.view` |
| POST | `/admin/notifications/read-all` | `admin.notifications.read_all` | `NotificationController::markAllRead` | `notifications.view` |
| POST | `/admin/notifications/{id}/read` | `admin.notifications.read` | `NotificationController::markRead` | `notifications.view` |
| GET | `/admin/notifications/preferences` | `admin.notifications.preferences` | `NotificationController::preferences` | `notifications.manage` |
| POST | `/admin/notifications/preferences` | `admin.notifications.preferences.save` | `NotificationController::savePreferences` | `notifications.manage` |

### Screens

* **Inbox** (`Resources/views/index.html.twig`) — latest 100 notifications of the current user (`NotifierBridge::inbox()`), unread count, per-row "Mark read" and "Mark all as read". `markRead` refuses notifications belonging to another user (404). Audit: `notification.read`.
* **Preferences** (`Resources/views/preferences.html.twig`) — a matrix of channels `in_app`, `email` × event types `customer.created`, `deal.created`, `order.created`, `invoice.issued`, `task.assigned`, `*`. Checkbox names are `pref[<channel>__<event_type with dots→underscores>]`; unchecked boxes are persisted as `enabled = false`. Audit: `notification.preferences_updated`.

Settings pages / setting keys: none.

## Public / REST API

`$context->api()->module('notifications', …)`; known scopes `notifications.read`, `notifications.write`. All endpoints operate on the **authenticated user** (401 when no user). See [API](../architecture/api.md).

| Method | Path | Scope | Controller::action | Response |
|---|---|---|---|---|
| GET | `/api/v1/notifications/` | `notifications.read` | `Http/Api/NotificationApiController::index` | collection of up to 50 latest notifications + meta `unread` |
| POST | `/api/v1/notifications/{id}/read` | `notifications.write` | `NotificationApiController::markRead` | item; 404 `not_found` if missing or foreign |
| GET | `/api/v1/notifications/preferences` | `notifications.read` | `NotificationApiController::preferences` | collection `{id, channel, event_type, enabled}` |

Notification serializer: `id, type, data, read_at (ATOM), created_at (ATOM)`. Filters: none. No public endpoints; preferences cannot be written via API.

## Events, hooks & webhooks

* **Platform event registered:** `notification.created` (`EventDefinition`, description key `notifications.event.created`, `webhookEnabled(true)`). Note: nothing in the module currently emits this event.
* **Hook action `platform.event.dispatched`** (priority 50, `boot()`): receives the `EventEnvelope`; if `type` ∈ `customer.created, deal.created, order.created, order.confirmed, invoice.issued, invoice.paid, task.assigned, stock.low, document.uploaded, project.created`, calls `NotifierBridge::notifyAdmins()` with title `notifications.inbox.event_title` and body `notifications.inbox.event_body` (`%type%`, `%id%`), meta `{resource, event_id}`.
* **Hook filter `admin.topbar.actions`**: appends the bell icon/badge HTML.
* Queue jobs, scheduled tasks, publishables: none. Notifications are written synchronously inside the event dispatch.

See [hooks, events & webhooks](../architecture/hooks-events-webhooks.md).

## Dashboard widgets

| Widget id | Type | Class | Shows | Permission | Order |
|---|---|---|---|---|---|
| `notifications.shortcut` | shortcut | `Application/Widgets/NotificationsShortcutWidget` | Link to inbox, icon `bell`, hint `admin.dashboard.hint.notifications` | `notifications.view` | 60 |
| `notifications.stats` | metric | `Application/Widgets/UnreadNotificationsWidget` | Unread count of current user; hint: unread arrived in last 24 h | `notifications.view` | 60 |
| `notifications.recent` | panel (`list_panel.html.twig`) | `Application/Widgets/RecentNotificationsWidget` | Last 8 unread notifications (title/body from `data`, badge = `meta.event_type`, date/time aside) | `notifications.view` | 38 |

Registered in `boot()`. See [Dashboard widgets](../architecture/dashboard-widgets.md).

## Services & extension points

| Class | Responsibility |
|---|---|
| `Application/NotifierBridge` | Preference-aware façade over core `Notifier`. `notifyUser(User, eventType, title, body, meta)` resolves channels (`in_app` → `database`, `email`) via `allowed()`; `notifyAdmins(eventType, title, body, meta)` fans out to all `status = active` users with `notifications.view`; `unreadCount(userId)`; `inbox(userId, limit)`. Default policy when no preference row exists: in-app **on**, e-mail **off**. |
| `Application/SimpleNotification` | `Gereq\Notification\Notification` implementation: `via()` = channels, `toArray()` = `{title, body, meta}`, `toMail()` = `MailMessage(title, body)` only when `email` channel present. |
| `Application/NotificationsProvider` | Capability payload; `bridge()` returns the `NotifierBridge`. |
| *(core)* `Gereq\Notification\Notifier` | Dispatches per channel: `database` → persists `DatabaseNotification`; `email` → Symfony `Email` (plain text) to `User::email()`; `websocket` → no-op. |

### Integration pattern

```php
if ($capabilities->has('notifications')) {
    $bridge = $capabilities->get('notifications')->bridge();
    $bridge->notifyUser($user, 'task.assigned', $title, $body, ['resource' => [...]]);
}
```

`modules/Projects/Http/TaskController::notify()` uses exactly this (with `method_exists` guards). To add new automatically-notified events, extend the `$interesting` array in `NotificationsModule::boot()`; to expose them in the preferences UI, extend the `$eventTypes` array in `NotificationController::preferences()` / `savePreferences()`.

## CLI commands

None.

## Configuration & translations

Settings / env vars: none in the module. E-mail delivery depends on the core mailer configuration (`MailerInterface`).

Translations: `Resources/translations/messages.cs.php`, `messages.en.php`. Prefixes: `module.notifications.description`, `nav.notifications`, `perm.notifications.*`, `perm.group.notifications`, `palette.notifications.*`, `notifications.title`, `notifications.preferences.*`, `notifications.mark_*`, `notifications.unread_count`, `notifications.col.*`, `notifications.status.*`, `notifications.channel.*`, `notifications.flash.*`, `notifications.error.*`, `notifications.inbox.*`, `notifications.event.created`, `notifications.widget.*`. `admin.dashboard.hint.notifications` comes from core translations. Twig namespace: `@notifications`.

## Database migrations

| File | Purpose |
|---|---|
| `Database/Migrations/2026_01_01_000001_notifications.php` | Empty `up()`/`down()`; `notification_preferences` is created by the platform schema sync (`src/Database/Migrator::syncSchema()` via Doctrine `SchemaTool`). The `notifications` table belongs to core. |

## Notes

* The `*` wildcard event type is offered in the preferences matrix and stored, but `NotifierBridge::allowed()` looks up an exact `(user, channel, event_type)` match only — the wildcard row has no effect on delivery.
* The `$interesting` whitelist in `boot()` (10 types) and the `$eventTypes` list in the preferences UI (5 types + `*`) are hard-coded and out of sync; e.g. `order.confirmed`, `invoice.paid`, `stock.low`, `document.uploaded`, `project.created` generate notifications but cannot be muted via the UI.
* `notifyAdmins()` runs one `findOneBy` per user × channel and persists+flushes one row per user inside the event dispatch — cost grows linearly with the number of active admins.
* `notification.created` is registered as a webhook-enabled platform event but is never emitted.
* The top-bar link `title` attribute is the hard-coded English string `"Notifications"`.
* The two data widgets accept a `null` user id and would then count notifications across all users; in practice the dashboard always has an authenticated user.
* Related: [Projects](projects.md) (task assignment notifications), [Orders](orders.md), [Customers](customers.md), [Crm](crm.md) (event sources).
