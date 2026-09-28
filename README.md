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

- PHP 8.4+ (`gd`, `zip`, `json`, `mbstring`, `pdo`; volitelně `redis` — viz `./bin/grq doctor`)
- Composer
- Node.js 20+ (Vite / Tailwind)
- SQLite (výchozí), nebo MariaDB 10.11+ / PostgreSQL
- volitelně Redis
- volitelně Docker (pro `php gereq serve-docker`)

## Instalace

Clone (moduly jsou **git submodules** → `gereq/module-<id>`):

```bash
git clone --recurse-submodules git@git.gereq.cz:gereq/GEREQ_PLATFORM.git
# nebo po klasickém clone:
git submodule update --init --recursive
```

### Nový klient (doporučeno)

Interaktivní TUI — Composer, `.env`, assets, **čistá DB**, admin účet:

```bash
./bin/grq install
```

Nebo non-interactive po vygenerovaném `.env`:

```bash
composer install
./bin/grq env init          # nebo: php gereq env:init
# doplňte ADMIN_* podle potřeby
php gereq migrate --fresh --seed --no-demo --no-sample
bun install && bun run build
```

`php gereq install` — interaktivní fresh seed (když už běží vendor).

### Ruční (klasika)

```bash
composer install
./bin/grq env init   # katalog klíčů: config/env.schema.toml
```

Do `.env` doplňte přístupové údaje (klíče `APP_KEY` / `DB_PASSWORD` se vygenerují).

```bash
php gereq migrate --fresh --seed --no-demo --no-sample
bun install && bun run build
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

Katalog klíčů: `config/env.schema.toml`. Generování: `./bin/grq env init` / `php gereq env:init` (Docker: `--docker`). Validace: `./bin/grq env validate` / `php gereq env:validate`.

IDE (Cursor/VS Code): `bash tools/gereq-env/install.sh` → autocomplete / hover / diagnostika v `.env` a `env_value('…')`.

CLI: `./bin/grq` — doctor, install, pack, commit, pr, modules (viz `tools/gereq-cli/README.md`).

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
| `GITLAB_URL` | GitLab instance (default `https://git.gereq.cz`) |
| `GITLAB_TOKEN` | token s `read_api` / `read_repository` (+ `api` pro zakládání modulů) |
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
bun run dev
```

Docker stack s nginx + PHP-FPM + Redis (bližší produkci, připravené na frontu):

```bash
php gereq serve-docker --port=8080
php gereq serve-docker --https          # HTTPS na :443 (self-signed) + HTTP na --port
php gereq serve-docker --hot            # + Bun asset watch (bun run dev → public/build)
php gereq serve-docker --https --hot    # HTTPS + Bun watch
php gereq serve-docker --build          # první start / po změně Dockerfile
php gereq serve-docker --queue          # + worker (queue profil)
php gereq serve-docker -d               # na pozadí
php gereq serve-docker --raw            # surové compose logy
php gereq serve-docker --dashboard      # fullscreen TUI (volitelné)
php gereq serve-docker --keep           # ukončení logů neshodí stack
php gereq serve-docker --reuse          # down + up (změna portu / --https bez ručního --down)
php gereq serve-docker --down           # zastavit stack (+ Bun watch z --hot -d)
```

S `--https` nginx poslouchá na host portu **443** (self-signed cert v `docker/nginx/certs/`). Prohlížeč jednou varuje — přidejte výjimku. HTTP zůstává na `--port`.

S `--hot` se na hostu spustí `bun run dev` (Bun watch → stabilní `public/build/assets/admin.js|css` + marker `.dev`). Stejný strom je namountovaný do Dockeru, takže změny jdou rovnou do nginx. V adminu běží auto-reload (poll `manifest.json`); ruční refresh není potřeba. Ukončení TUI / `--down` watch vypne (u `-d` zůstane do `--down`).

Stejný efekt má i ruční `bun run dev` vedle běžícího `serve-docker` (volume mount).

Frontend build do `public/build`:

```bash
bun run build   # produkční hashe
bun run dev     # watch → Docker volume + auto-reload v adminu
```

## CLI

```bash
php gereq about
php gereq install
php gereq migrate --fresh --seed --no-demo --no-sample
php gereq module:list
php gereq module:enable customers
php gereq module:disable customers
php gereq make:module Warehouse   # or: ./bin/grq modules create Warehouse
php gereq make:resource Product --module=Warehouse
php gereq migrate
php gereq migrate:status
php gereq cache:clear
php gereq queue:work
php gereq serve
php gereq serve-docker
php gereq user:create
```

Shell instalátor: `./bin/grq install`

## Moduly

Každý modul je **vlastní GitLab projekt** `git.gereq.cz/gereq/module-<id>` a v platformě (`GEREQ_PLATFORM`) je napojený jako **git submodule** pod `modules/<Dir>`, pinovaný na semver tag `vX.Y.Z`.

```text
GEREQ_PLATFORM                    → git.gereq.cz/gereq/GEREQ_PLATFORM
└── modules/
    ├── Customers/                → submodule gereq/module-customers @ v1.6.3
    ├── Listings/                 → submodule gereq/module-listings @ …
    └── …
```

Struktura uvnitř modulu:

```text
modules/Customers/
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

Manifest `module.php` popisuje id, verzi, závislosti, capabilities a permissions. Modul implementuje `ModuleInterface` (`register` / `boot`).

Platforma:

- najde nainstalované moduly (složky v `modules/` se `module.php`)
- umí je aktivovat a deaktivovat
- zkontroluje závislosti
- nepovolí vypnout modul, který jiný aktivní modul vyžaduje
- spouští migrace Core i modulů

Moduly mezi sebou **nesahají do cizích tabulek**. Komunikují přes contracts / providers a capabilities.

Příklad: capability `contacts` může poskytovat GEREQ CRM, HubSpot modul i vlastní implementace. Ostatní moduly žádají capability, ne konkrétní tabulku.

IDE tip: po převodu na submodules Cursor/VS Code může u souborů uvnitř `modules/*` ukazovat **D** (deleted) vůči parent repu — soubory na disku jsou, jen je parent už nesleduje jako běžné soubory (sleduje gitlink). Badge **S** = submodule.

### Práce se submodulem

```bash
# aktualizovat jeden modul na nový tag
cd modules/Customers
git fetch --tags
git checkout v1.6.4
cd ../..
git add modules/Customers
git commit -m "chore: pin Customers to v1.6.4"
git push

# srovnání lokálních verzí vs GitLab tagy (TUI)
./bin/grq modules
./bin/grq modules status --plain
```

Commit kódu modulu patří do **module repo** (`module-customers`). Commit v `GEREQ_PLATFORM` jen posouvá pin submodule (SHA/tag).

### Verze a instalace z GitLabu

Semver tagy `v1.2.3` musí sedět s `version` v `module.php` i s `Module::version()`.

V adminu (detail modulu → **Verze**), pokud je nastavený `GITLAB_TOKEN`:

1. obnoví seznam tagů z GitLab API  
2. stáhne ZIP do `storage/modules/{id}/{version}/`  
3. aktivuje vybranou verzi do live `modules/…` (upgrade = migrace up, downgrade = `down()` u migrací chybějících v cíli)

Override cesty repa: `'repository' => 'gereq/module-listings'` v `module.php`.

Po změně kódu/migrací v modulu **vždy bumpni verzi** (viz checklist) — admin UI modulu se zablokuje, dokud neproběhne „Aktualizovat“.

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

### 1. Kostra v platformě

```bash
./bin/grq modules create Warehouse --kind warehouse
# or: php gereq make:module Warehouse
```

Doplňte `register()` (služby, routes, permissions, **API**, **events**, případně **publishables**) a `boot()` (menu, hooky, widgety).

### 2. GitLab repo + submodule

```bash
# vytvořit private projekt ve skupině gereq (path = module-<id>)
# např. gereq/module-warehouse

cd modules/Warehouse
git init -b main
git add -A
git commit -m "chore: initial Warehouse v0.1.0"
git tag -a v0.1.0 -m "Release v0.1.0"
git remote add origin git@git.gereq.cz:gereq/module-warehouse.git
git push -u origin main --tags
cd ../..

# z platformy: nahradit běžnou složku submodulem (pokud ještě není)
git submodule add git@git.gereq.cz:gereq/module-warehouse.git modules/Warehouse
# URL v .gitmodules sjednoť na relativní: ../module-warehouse.git
git add .gitmodules modules/Warehouse
git commit -m "chore: add Warehouse submodule @ v0.1.0"
# CI: povolit job token z GEREQ_PLATFORM (jednou na nový module projekt)
#   GITLAB_TOKEN=… bash tools/gitlab-allow-platform-job-token.sh
#   nebo UI: module → Settings → CI/CD → Job token permissions → gereq/GEREQ_PLATFORM

git push
```

### 3. Aktivace

```bash
php gereq migrate
php gereq module:enable warehouse
# nebo Administrace → Moduly
```

### Checklist nového modulu

Každý modul má být plnohodnotná platform capability, ne jen admin CRUD:


### Repo / verze
- [x] GitLab projekt gereq/module-<id> (private)
- [x] Napojený jako submodule v GEREQ_PLATFORM (modules/<Dir>)
- [x] version v module.php == Module::version() == git tag vX.Y.Z
- [x] .gitmodules URL relativní (`../module-<id>.git`), bez tokenu
- [x] Job token: modul povoluje inbound z `gereq/GEREQ_PLATFORM`

### Produkt
- [x] Domain + Admin UI
- [x] Permissions (+ sync po enable/update)
- [x] Extension points (hooks) kde dává smysl
- [x] Audit integration u kritických mutací

### API / Events
- [x] Public API endpoints (/api/v1/…) — pokud má modul data ven
- [x] API scopes (resource.read / resource.write)
- [x] API serializers + whitelisted filters
- [x] Domain / platform events (resource.action)
- [x] Webhook-enabled event registrace
- [x] Event payload version (ne Doctrine entity)
- [x] Idempotency (finanční / kritické POST)
- [x] Queue jobs (pokud async)

### Publishables (pokud sahá na /api/public nebo CRM content)
- [x] register() → publishables()->register(…)
- [x] create / update / delete přes staging (ne hard delete public obsahu)

### Kvalita
- [x] Migrace jen přes Database/Migrations/
- [x] Tests (unit / feature dle rozsahu)
- [x] i18n cs + en
- [x] Kontrola: `./bin/grq modules status --plain` — modul OK na GitLabu


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
GEREQ_PLATFORM (git.gereq.cz/gereq/GEREQ_PLATFORM)
├── Core
│   ├── Auth, Users, Roles, Permissions
│   ├── Modules, Events, Hooks, Publish
│   ├── Database, Cache, Queue
│   ├── Notifications, Files, Settings
│   └── API, Audit, Admin UI
└── modules/   (git submodules)
    ├── Customers  → gereq/module-customers
    ├── CRM        → gereq/module-crm
    ├── Currency   → gereq/module-currency
    └── …          → gereq/module-<id>
```

Platforma je od začátku navržena jako integrační vrstva: **Admin + Domain + API + Events + Webhooks**.

## Dokumentace

Kompletní anglická dokumentace: [docs/index.md](docs/index.md) (architektura, moduly, infrastruktura, API, dashboard widgety, …).

Admin formuláře (atributy `#[FormField]` / `#[FormGenerator]`, `forms()->render()`): viz [docs/FORMS.md](docs/FORMS.md).
