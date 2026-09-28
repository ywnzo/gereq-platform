# GEREQ (`gereq`)

Platform module porting GEREQ.cz **Ticketing** and **Security** products into the admin. Part of the `gereq` package group.

## At a glance

| Item | Value |
| --- | --- |
| Module id | `gereq` |
| Version | `1.99.0` (`modules/Gereq/module.php`) |
| Directory | `modules/Gereq/` |
| Requires | `core ^1.0` |
| Groups / packages | `gereq` |
| Permissions | `gereq.manage` (and related ticketing/security perms) |

## Access

Admin UI is gated by `gereq.manage`. Privileged roles (`superadmin`, `admin`) see it via `isAdministrator()` bypass. The `admin-client` seeder explicitly **excludes** `gereq.*` permissions.

## Surfaces

| Area | Admin | Public |
|------|-------|--------|
| Ticketing | `/admin/gereq/ticketing` desk + sites + extras | `/js/gereq-tickets.js`, `/tickets/widget`, `/tickets/api/*` |
| Security | `/admin/gereq/security` sites + events | `/js/gereq-security.js`, `/js/gereq-monitor.js`, `/security/api/*` |
| API | — | `/api/v1/gereq/*` (scopes `gereq.ticketing.*`, `gereq.security.*`) |

Realtime uses **polling** (no Reverb). Extras (SLA, canned replies, tags, KB, automations) are Phase-4 stubs under Ticketing → extras.
