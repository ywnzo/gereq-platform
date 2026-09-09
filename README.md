# GEREQ Platform

Interní modulární backendová platforma. Není to CMS ani kopie WordPressu — je to **Core**, na kterém lze stavět administrace, CRM, ERP, portály a další interní systémy.

Verze **0.1** obsahuje funkční jádro, administraci, oprávnění, modulový systém a demonstrační modul **Customers**.

## Co platforma umí

- vlastní lightweight Core nad PHP 8.4+, Doctrine a Symfony komponentami
- zapínání a vypínání modulů včetně závislostí a capabilities
- hooks (actions/filters) a oddělený EventBus
- role a granulární permissions
- jednotnou administraci s design systémem, command palette a dashboard widgety
- REST základ `/api/v1/`
- CLI `php gereq`
- audit log, settings, soubory, notifikace a frontu

Business logika (faktury, objednávky, projekty) do Core nepatří. Patří do modulů.

## Požadavky

- PHP 8.4+
- Composer
- Node.js 20+ (Vite / Tailwind)
- SQLite (výchozí), nebo MariaDB 10.11+ / PostgreSQL
- volitelně Redis
- volitelně Docker (pro `php gereq serve-docker`)

## Instalace

Clone (moduly jsou **git submodules** → `gereq/module-<id>`):

```bash
git clone --recurse-submodules git@gitlab.com:gereq/GEREQ_PLATFORM.git
# nebo po klasickém clone:
git submodule update --init --recursive
```

### Nový klient (doporučeno)

Interaktivní TUI — Composer, `.env`, assets, **čistá DB**, admin účet:

```bash
./bin/install
```

Nebo non-interactive po ručním `.env`:

```bash
composer install
cp .env.example .env   # doplňte APP_KEY, ADMIN_*
php gereq migrate --fresh --seed --no-demo --no-sample
bun install && bun run build   # nebo npm
```

`php gereq install` — interaktivní fresh seed (když už běží vendor).

### Ruční (klasika)

```bash
composer install
cp .env.example .env
```

Do `.env` doplňte `APP_KEY` (náhodný 64znakový hex) a přístupové údaje.

```bash
php gereq migrate --fresh --seed --no-demo --no-sample
npm install
npm run build
php gereq serve
```

Administrace: [http://127.0.0.1:8080/admin](http://127.0.0.1:8080/admin)

Účet z `.env` (`ADMIN_EMAIL` / `ADMIN_PASSWORD`). Heslo ihned změňte.

Migrace:

| Příkaz | Význam |
| --- | --- |
| `php gereq migrate` | jen migrace / sync schématu (bez seedu) |
| `php gereq migrate --seed` | migrace + seed (včetně dema) |
| `php gereq migrate --fresh --seed --no-demo --no-sample` | čistý klient |
| `php gereq seed:demo` | dodatečně demo data |

## Environment

| Proměnná | Význam |
| --- | --- |
| `APP_ENV` | `local` / `production` |
| `APP_DEBUG` | podrobné chyby |
| `APP_URL` | veřejná URL |
| `DB_CONNECTION` | `sqlite`, `mysql`, `pgsql` |
| `CACHE_DRIVER` | `file`, `redis`, `array` |
| `QUEUE_DRIVER` | `sync`, `redis`, `file` |
| `SESSION_DRIVER` | `file`, `redis` (Docker `serve-docker` vynutí `redis`) |
| `FILE_DRIVER` | `local` (S3 driver lze doplnit) |
| `GITLAB_URL` | GitLab instance (default `https://gitlab.com`) |
| `GITLAB_TOKEN` | token s `read_api` / `read_repository` pro stahování tagů |
| `GITLAB_GROUP` | skupina projektů (default `gereq`) |
| `MODULE_REPO_PREFIX` | prefix repa (`module-` → `gereq/module-listings`) |

MariaDB:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE_NAME=gereq
DB_USERNAME=gereq
DB_PASSWORD=secret
```

## Dev server

Vestavěný PHP server (rychlé lokální zkoušení):

```bash
php gereq serve --port=8080
npm run dev
```

Docker stack s nginx + PHP-FPM + Redis (bližší produkci, připravené na frontu):

```bash
php gereq serve-docker --port=8080
php gereq serve-docker --https          # HTTPS na :443 (self-signed) + HTTP na --port
php gereq serve-docker --hot            # + Vite HMR (bun run dev na :5173)
php gereq serve-docker --https --hot    # HTTPS + HMR
php gereq serve-docker --build          # první start / po změně Dockerfile
php gereq serve-docker --queue          # + worker (queue profil)
php gereq serve-docker -d               # na pozadí
php gereq serve-docker --raw            # surové compose logy
php gereq serve-docker --dashboard      # fullscreen TUI (volitelné)
php gereq serve-docker --keep           # ukončení logů neshodí stack
php gereq serve-docker --down           # zastavit stack (+ Vite z --hot -d)
```

S `--https` nginx poslouchá na host portu **443** (self-signed cert v `docker/nginx/certs/`). Prohlížeč jednou varuje — přidejte výjimku. HTTP zůstává na `--port`.

S `--hot` se na hostu spustí `bun run dev` (port **5173**, `host 0.0.0.0` z `vite.config.ts`). Nginx proxyuje `/@vite` → `host.docker.internal:5173`. Ukončení TUI / `--down` Vite vypne (u `-d` zůstane do `--down`).

Ve výchozím režimu běží compact log stream (HTTP řádky, filtry assetů/php/nginx šumu). Klávesy: `q` `a` `p` `n` `space`.

Pro Redis frontu v aplikaci nastavte v `.env` `QUEUE_DRIVER=redis` (v Dockeru je `REDIS_HOST=redis`). Session v Dockeru běží přes Redis (`SESSION_DRIVER=redis`).

Frontend build do `public/build`:

```bash
npm run build
```

## CLI

```bash
php gereq about
php gereq install
php gereq migrate --fresh --seed --no-demo --no-sample
php gereq module:list
php gereq module:enable customers
php gereq module:disable customers
php gereq make:module Warehouse
php gereq make:resource Product --module=Warehouse
php gereq migrate
php gereq migrate:status
php gereq cache:clear
php gereq queue:work
php gereq serve
php gereq serve-docker
php gereq user:create
```

Shell instalátor: `./bin/install`
## Moduly

Každý modul je samostatné GitLab repo `gereq/module-<id>`, v platformě napojené jako **submodule** pod `modules/<Dir>` (pin na semver tag `vX.Y.Z`).

```text
modules/Customers/          → gitlab.com/gereq/module-customers @ v…
    Domain/
    Application/
    Infrastructure/
    Http/
    Admin/
    Database/Migrations/
    Resources/views/
    Tests/
    module.php
    CustomersModule.php
```

Manifest `module.php` popisuje id, verzi, závislosti, capabilities a permissions.

Modul implementuje `ModuleInterface` (`register` / `boot`).

Platforma:

- najde nainstalované moduly
- umí je aktivovat a deaktivovat
- zkontroluje závislosti
- nepovolí vypnout modul, který jiný aktivní modul vyžaduje
- spouští migrace Core i modulů

Moduly mezi sebou **nesahají do cizích tabulek**. Komunikují přes contracts / providers a capabilities.

Příklad: capability `contacts` může poskytovat GEREQ CRM, HubSpot modul i vlastní implementace. Ostatní moduly žádají capability, ne konkrétní tabulku.

### Verze z GitLabu

Každý modul může žít ve vlastním repu `gitlab.com/{GITLAB_GROUP}/{MODULE_REPO_PREFIX}{id}` (např. `gereq/module-listings`). Semver tagy `v1.2.3` musí sedět s `version` v `module.php`.

V adminu (detail modulu → **Verze**):

1. obnoví seznam tagů z GitLab API  
2. stáhne ZIP do `storage/modules/{id}/{version}/`  
3. aktivuje vybranou verzi do live `modules/…` (upgrade = migrace up, downgrade = `down()` u migrací chybějících v cíli)

Bez `GITLAB_TOKEN` zůstává lokální monorepo flow beze změny. Override cesty: `'repository' => 'gereq/module-listings'` v `module.php`.

## Permissions

Granulární oprávnění, ne jen `admin` / `user`.

```text
customers.view
customers.create
customers.edit
customers.delete
users.manage
modules.manage
settings.manage
```

Role `Administrator`, `Manager`, `Editor`, `Viewer` obsahují množiny permissions. Kontrola je vždy na backendu.

## Hooks a events

Hooks rozšiřují chování a UI:

```php
$hooks->action('order.created', function (OrderCreated $event): void {
    // ...
});

$title = $hooks->filter('admin.page.title', $title, $context);
```

Events jsou doménové události:

```php
$events->dispatch(new CustomerCreated($customer->id()));
```

## Nový modul

```bash
php gereq make:module Warehouse
```

Potom doplňte `register()` (služby, routes, permissions, **API**, **events**) a `boot()` (menu, hooky, widgety). Po `php gereq migrate` modul aktivujte v administraci nebo přes CLI.

### Checklist nového modulu

Každý modul má být plnohodnotná platform capability, ne jen admin CRUD:

```text
[ ] Domain + Admin UI
[ ] Public API endpoints (/api/v1/…)
[ ] API scopes (resource.read / resource.write)
[ ] API serializers + whitelisted filters
[ ] Domain / platform events (resource.action)
[ ] Webhook-enabled event registrace
[ ] Event payload version (ne Doctrine entity)
[ ] Audit integration
[ ] Queue jobs (pokud async)
[ ] Idempotency (finanční / kritické POST)
[ ] Extension points (hooks)
[ ] Permissions
[ ] Tests
```

## API, Events, Webhooks

- Verzované API: `/api/v1/…`, OpenAPI: `/api/openapi.json`
- Auth: session nebo Bearer token `grq_live_*` / `grq_test_*` (Developer → API Tokens)
- Events: `EventRegistry` + `EventEnvelope`; dispatch přes `EventBus` / `emit()`
- Outbound webhooks: modul `Webhooks` (HMAC, retries, SSRF protection, delivery log)
- Developer admin: API explorer, Tokens, Events, Webhooks

```php
$context->api()->module('customers', function (ApiModule $api): void {
    $api->get('/', [CustomerApiController::class, 'index'])->scope('customers.read');
});

$context->platformEvents()->register(
    EventDefinition::make('customer.created')->withModule('customers')->version(1)->webhookEnabled()
);

$context->events()->emit('customer.created', ['type' => 'customer', 'id' => $id], ['customer' => $payload]);
```

## Testy

```bash
composer test
vendor/bin/phpstan analyse
vendor/bin/phpcs
```

## Architektura

```text
GEREQ Platform
Core
├── Auth, Users, Roles, Permissions
├── Modules, Events, Hooks
├── Database, Cache, Queue
├── Notifications, Files, Settings
├── API, Audit, Admin UI
Modules
└── Customers, CRM, Currency, Webhooks + další
```

Platforma je od začátku navržena jako integrační vrstva: **Admin + Domain + API + Events + Webhooks**.

Admin formuláře (atributy `#[FormField]` / `#[FormGenerator]`, `forms()->render()`): viz [docs/FORMS.md](docs/FORMS.md).
