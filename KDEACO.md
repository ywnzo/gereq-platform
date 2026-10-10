# KdeAco — GEREQ Platform backend

Instance je GEREQ Platform **0.4.0** (`dev` / `abcb930`) v `backend/`.

Veřejný frontend je React v `app/`. Lokálně Vite na portu **5155**. Na **demo.kdeaco.cz** (HTTPS) běží storefront ze stejného originu jako API (`/api`).

| URL | Aplikace |
| --- | --- |
| `/` | React storefront (`app/` → `backend/public/storefront`) |
| `/admin` | **GEREQ Platform** Twig admin (`ADMIN_PATH=admin`) |
| `/api` | REST API |

Vue product admin v `admin/` je připravený (build v `backend/public/kdeaco-admin`), ale **dočasně vypnutý** — `KdeacoAdmin` se aktivuje až po `ADMIN_PATH` mimo kanonické `admin` (např. `gereq-platform/admin`).

## Moduly

Zapnuté jen to, co KdeAco potřebuje (smlouva DIL-2026-027):

| Modul | Proč |
| --- | --- |
| **core** (vždy) | účty adminů, zákazníci / storefront login, soubory, API |
| **authorization** | host OAuth pro storefront (plug-iny Google / Facebook / GitHub) |
| **events** (lokální) | katalog akcí, schválení/archiv, týden/měsíc, hledání, oblíbené |
| **cms** | statické stránky pro portál |
| **newsletter** | odběry novinek |
| **webplus** | SEO / sitemap / cookies pro veřejný web |
| **achievements** | trust level / badge u důvěryhodných profilů (Meta-like verified) |

**Vitality** a **Listings** jsou na disku (jádro Customers má vazbu na `BrokerIdentity`), ale **nejsou zapnuté**.

Modul **gereq** — klient GEREQ.cz (Security pro FE + Support floating widget v adminu; tokeny v `/admin/gereq/settings`). Není zapnutý shop, CRM, calendar, community, fleet, forum.

OAuth: zapněte modul **Autorizace** a v Plug-inech Google / Facebook / GitHub. Klíče vyplňte v nastavení plug-inu (nebo fallback `GOOGLE_*` / `FACEBOOK_*` / `GITHUB_*` v `.env`). Redirect URI:

- `{APP_URL}/api/public/auth/google/callback`
- `{APP_URL}/api/public/auth/facebook/callback`
- `{APP_URL}/api/public/auth/github/callback`

Na demu: `https://demo.kdeaco.cz/api/public/auth/google/callback`.

## Veřejné API (React)

- `GET /api/public/events` — `search`, `filter[category]`, `filter[region]`, `filter[spotlight]=week|month`
- `GET /api/public/events/{slug}`
- `POST /api/public/events` — návrh akce (Bearer store token z `/api/v1/store/auth/login`)
- `POST /api/public/events/{id}/favorite`
- `GET /api/public/events/favorites`
- `GET /api/public/events/meta`
- `GET /api/public/auth/providers` — zapnuté a nakonfigurované OAuth poskytovatele
- `GET /api/public/auth/{google|facebook|github}` — OAuth redirect
- Store login: `POST /api/v1/store/auth/register` a `/login`

## Admin API (Vue `/admin`)

Session cookie (`gereq_session`, `credentials: 'include'`):

- `POST /api/v1/admin/auth/login` — JSON `{ email, password, remember? }`
- `GET /api/v1/admin/auth/me`
- `POST /api/v1/admin/auth/logout`
- `GET /api/v1/admin/dashboard`
- `GET/POST/PATCH/DELETE /api/v1/catalog-events` (+ `publish` / `archive` / `reject` / `spotlight` / `bulk`)
- `GET/PATCH… /api/v1/customers` — stávající Customers API

CORS: lokálně `FRONTEND_ORIGIN` = Vite originy na :5155. Na HTTPS platformě `FRONTEND_ORIGIN=https://demo.kdeaco.cz` (stejné jako `APP_URL`). Web+ `frontend_url` / `base_url` se z loopbacku přepíšou na HTTPS `APP_URL`.

## Lokální běh (SQLite, bez Dockeru)

```bash
cd backend
composer install
php gereq env:init --force
# uprav .env: APP_URL, GEREQ_PORT=8088, DB_CONNECTION=sqlite, ADMIN_PATH=admin
php gereq migrate --fresh --seed --no-demo --no-sample --no-interaction \
  --admin-email admin@kdeaco.cz --admin-password 'KdeAco-local-2026'
php gereq module:enable authorization
php gereq module:enable webplus
php gereq module:enable events
php gereq module:enable achievements
bun install && bun run build
php gereq serve --port=8088

# storefront
cd ../app && bun install && bun run build   # nebo bun run dev :5155
```

Admin: http://127.0.0.1:8088/admin

## Produkce (demo.kdeaco.cz, HTTPS)

Stejný host: katalog na `/`, admin na `/admin`, API na `/api`.
V `.env`: `ADMIN_PATH=admin`, `TRUSTED_PROXIES=*`, `APP_URL=https://…`.

```bash
cd app && bun install && bun run build          # → backend/public/storefront
cd ../backend && composer install --no-dev --optimize-autoloader
php gereq migrate
php gereq cache:clear
# po změně ADMIN_PATH: docker compose up -d --force-recreate php queue
```

Google Cloud Console: Authorized redirect URI = `https://<host>/api/public/auth/google/callback`.
JavaScript origin = `https://<host>`.

Toto je **klientská kopie**, ne pushuj ji do `gereq/GEREQ_PLATFORM`.
