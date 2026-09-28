# Achievements (`achievements`)

Achievements awards **badges**, tracks **progress counters**, and maintains **reputation points / trust levels**. It listens to [Forum](forum.md) and [Community](community.md) platform events; Forum uses trust for `forum.min_trust_level_to_post`. Seed badges (first post, 10 accepted answers, 5 threads) are created when the badge table is empty.

Related: [Forum](forum.md), [Community](community.md), [Customers](customers.md), [module system](../architecture/module-system.md).

## At a glance

| Item | Value |
| --- | --- |
| Module id | `achievements` |
| Version | `1.99.0` (`modules/Achievements/module.php`, `AchievementsModule::version()`) |
| Directory | `modules/Achievements/` |
| Class | `Modules\Achievements\AchievementsModule` |
| Requires | `core ^1.0` |
| Optional | `forum ^0.2`, `customers ^1.0` |
| Capabilities provided | `achievements` → `Application/AchievementsProvider` |
| Groups / packages | `forum` (`module.php`); package **Forum** |
| Permissions | `achievements.view`, `achievements.manage` |
| API scopes | none (public API only) |

## Domain model

| Entity | Table | Key fields |
| --- | --- | --- |
| `AchievementBadge` | `achievement_badges` | name, rarity, icon, `event_type`, `threshold`, `is_active` |
| `AchievementAward` | `achievement_awards` | `badge_id`, `user_id`, `awarded_at` (unique pair) |
| `AchievementProgress` | `achievement_progress` | `user_id`, `event_type`, `count` |
| `AchievementReputation` | `achievement_reputation` | PK `user_id`; `points`, `trust_level` |

Trust levels (examples): 0 Nováček (&lt;100 pts), 1 Pokročilý (≥100), 2 Profesionál (≥500). Reputation deltas include `forum.post.accepted` +25, `forum.thread.created` +5, `forum.post.created` +2 (else +1).

## Admin UI

`/admin/achievements` — list/save badges. Menu **Obsah** (order 58).

## Public API (`/api/public/achievements/…`)

`GET features`, `GET badges`, `GET gallery` (`badges?user_id=` for earned). No `/api/v1`.

## Events

Emits `achievement.awarded` (webhook-enabled). Consumes `forum.*` and `community.*` via `platform.event.dispatched`.

## Other

- Migrations: 1 — four tables.
- No settings, CLI or dashboard widgets.
- Capability methods: `trustLevel()`, `trustLabel()` used by Forum.
