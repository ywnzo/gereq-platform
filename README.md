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

## Instalace

```bash
composer install
cp .env.example .env
```

Do `.env` doplňte `APP_KEY` (náhodný 64znakový hex) a přístupové údaje.

```bash
php gereq migrate
npm install
npm run build
php gereq serve
```

Administrace: [http://127.0.0.1:8080/admin](http://127.0.0.1:8080/admin)

Výchozí účet z `.env`:

- e-mail: `jiri.novak@gereq.cz`
- heslo: `change-me`

Heslo ihned změňte.

## Environment

| Proměnná | Význam |
| --- | --- |
| `APP_ENV` | `local` / `production` |
| `APP_DEBUG` | podrobné chyby |
| `APP_URL` | veřejná URL |
| `DB_CONNECTION` | `sqlite`, `mysql`, `pgsql` |
| `CACHE_DRIVER` | `file`, `redis`, `array` |
| `QUEUE_DRIVER` | `sync`, `redis`, `file` |
| `FILE_DRIVER` | `local` (S3 driver lze doplnit) |

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

```bash
php gereq serve --port=8080
npm run dev
```

Frontend build do `public/build`:

```bash
npm run build
```

## CLI

```bash
php gereq about
php gereq module:list
php gereq module:enable customers
php gereq module:disable customers
php gereq make:module Warehouse
php gereq make:resource Product --module=Warehouse
php gereq migrate
php gereq migrate:status
php gereq cache:clear
php gereq queue:work
php gereq user:create
```

## Moduly

Každý modul je samostatný balík v `modules/`:

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

Potom doplňte `register()` (služby, routes, permissions) a `boot()` (menu, hooky, widgety). Po `php gereq migrate` modul aktivujte v administraci nebo přes CLI.

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
└── Customers (demo) + další později
```

Budoucí moduly (CRM, CMS, faktury, sklad, AI…) sem patří jako samostatné balíky. V 0.1 se neimplementují.
