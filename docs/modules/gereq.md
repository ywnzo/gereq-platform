# GEREQ (Ticketing + Security)

Platform module porting GEREQ.cz **Ticketing** and **Security** products into the RealEstates admin.

## Access

Admin UI is gated by `gereq.manage`. Privileged roles (`superadmin`, `admin`) see it via `isAdministrator()` bypass. The `admin-client` seeder explicitly **excludes** `gereq.*` permissions.

## Surfaces

| Area | Admin | Public |
|------|-------|--------|
| Ticketing | `/admin/gereq/ticketing` desk + sites + extras | `/js/gereq-tickets.js`, `/tickets/widget`, `/tickets/api/*` |
| Security | `/admin/gereq/security` sites + events | `/js/gereq-security.js`, `/js/gereq-monitor.js`, `/security/api/*` |
| API | — | `/api/v1/gereq/*` (scopes `gereq.ticketing.*`, `gereq.security.*`) |

Realtime uses **polling** (no Reverb). Extras (SLA, canned replies, tags, KB, automations) are Phase-4 stubs under Ticketing → extras.
