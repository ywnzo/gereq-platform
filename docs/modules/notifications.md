# Notifications (core)

In-app notification inbox, preferences and delivery. Formerly `modules/Notifications`; now part of the platform core under `src/Notifications/`.

| | |
| --- | --- |
| Namespace | `Gereq\Notifications` |
| Registrar | `Gereq\Notifications\NotificationsRegistrar` |
| Capability | `notifications` (provider id `core`) |
| Entity | `Gereq\Notifications\Domain\NotificationPreference` |
| Contracts | `Gereq\Notification\*` (`Notifier`, `DatabaseNotification`, …) |

Admin UI: `/admin/notifications`. Public member feed: `/api/public/notifications`.
