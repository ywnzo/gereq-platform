# CLI reference

All console commands run through `php gereq <command>` (entry script `gereq` → `Gereq\Console\ConsoleKernel`, `src/Console/ConsoleKernel.php`). The kernel boots the same `Application` container as HTTP, then registers a fixed list of core commands plus a handful of module commands. Commands use Symfony Console, so `php gereq list`, `php gereq help <command>` and the usual `-v`, `-n/--no-interaction`, `-q` flags work. Module commands are **not** discovered automatically — they are listed explicitly in `ConsoleKernel::commands()`.

### Env file (`config.env`)

By default the CLI loads project-root `.env`. When `.env.cli` or `.env.bin` exists, it is loaded **after** `.env` (host overrides for `php gereq` on the machine — typically `DB_HOST=127.0.0.1` while Docker keeps `postgres` in `.env`). Generate with `php gereq env:init --cli`.

Override the primary file before any command with a global `config.env=` token (stripped before Symfony Console sees argv):

```bash
php gereq config.env=.env.production about
php gereq --config.env=.env.production migrate
php gereq config.env=/abs/path/.env env:validate
```

Relative paths are resolved from the project root. An explicit path that does not exist fails fast. `env:init` / `env:validate` write/validate the same resolved file (overlay is not used when `config.env=` is set).

## Setup

| Command | Arguments / options | What it does | Source |
| --- | --- | --- | --- |
| `about` | — | prints platform name, version (`AppVersion::current()`), PHP version, environment | `src/Console/Command/AboutCommand.php` |
| `install` | `--fresh`, `--bare`, `--no-demo`, `--no-sample`, `-y/--yes`, `--admin-email`, `--admin-password`, … | interactive clean install; admin credentials via CLI/prompt (never `.env`) | `src/Console/Command/InstallCommand.php` |
| `serve` | `--host=0.0.0.0`, `--port=8080` | built-in PHP server (`php -S … -t public public/router.php`), probes the next 20 ports if busy | `src/Console/Command/ServeCommand.php` |
| `serve-docker` | `--port=8080`, `--https`, `--hot`, `--build`, `-d/--detach`, `--down`, `--reuse`, `--raw`, `--dashboard`, `--keep`, `--queue` (legacy no-op) | manage the Docker compose stack, optional Vite HMR process and a formatted log TUI | `src/Console/Command/ServeDockerCommand.php`, `src/Console/Docker/*` |

See [installation](installation.md#dev-servers) for the `serve-docker` option details.

## Modules

| Command | Arguments / options | What it does | Source |
| --- | --- | --- | --- |
| `module:list` | — | table of discovered manifests with installed version and enabled state (`ModuleManager::all()`) | `ModuleListCommand.php` |
| `module:enable <module>` | `module` id (lower-cased) | `ModuleManager::enable()` (dependency check, `installed_modules` row) then `Migrator::migrateModule($id)` — runs the module's `Database/Migrations` and syncs its Doctrine schema | `ModuleEnableCommand.php` |
| `module:pause <module>` | `module` | `ModuleManager::pauseMany()` — disables the module but keeps its tables and data | `ModulePauseCommand.php` |
| `module:disable <module>` | `module`, `--purge-media` | `ModuleUninstaller::uninstallMany()` — disables **and drops the module's DB tables** (rollback of its migrations); `--purge-media` also deletes related media | `ModuleDisableCommand.php` |
| `make:module <name>` | `name` (StudlyCase directory name) | scaffolds `modules/<Name>/` with `module.php`, `<Name>Module.php`, `Http/`, `Resources/views`, `Database/Migrations` etc. | `MakeModuleCommand.php` |
| `make:resource <name>` | `name`, `-m/--module=Customers` | generates a Doctrine entity annotated with `#[AdminResource]` inside the given module | `MakeResourceCommand.php` |

Enabling from the admin UI (`/admin/modules`) goes through the same `ModuleManager` / `Migrator` path plus the GitLab version installer; see [module system](../architecture/module-system.md).

## Database

| Command | Arguments / options | What it does | Source |
| --- | --- | --- | --- |
| `migrate` | `--fresh`, `--seed`, `--no-demo`, `--no-sample` | `Migrator::migrate()` (pending module migrations + schema sync). `--fresh` drops everything first, `--seed` runs `Seeder::seed(!no-demo, !no-sample)` | `MigrateCommand.php` |
| `migrate:status` | — | per-module migration list with `ran`/`ran_at` from `schema_migrations` | `MigrateStatusCommand.php` |
| `migrate:import-laravel` | `-f/--file=<json>`, `--dry-run` | scaffold importer of products/customers from a Laravel JSON dump | `ImportLaravelCommand.php` |
| `db:import-sqlite [sqlite]` | `sqlite` path (default `storage/database.sqlite`), `--migrate`, `--dry-run` | copies table data from an SQLite file into the current (typically PostgreSQL) connection | `ImportSqliteCommand.php` |
| `seed:demo` | — | `DemoSeeder` — demo user and click-through data on top of an existing install | `SeedDemoCommand.php` |

## Cache / queue

| Command | Arguments / options | What it does | Source |
| --- | --- | --- | --- |
| `cache:clear` | — | wipes `storage/cache` (app pool, Twig compiled templates, Doctrine metadata/proxies) | `CacheClearCommand.php` |
| `queue:work` | — | infinite loop: promote due delayed jobs → `popRaw()` → `handle()`; sleeps 1 s when idle; failures go to `failed_jobs` and are re-queued until `QUEUE_MAX_ATTEMPTS` | `QueueWorkCommand.php` |
| `schedule:run` | — | runs due `Schedule` entries once (cron should call every minute); async entries dispatch to the queue | `ScheduleRunCommand.php` |
| `schedule:list` | — | lists registered schedule entries with next run | `ScheduleListCommand.php` |
| `queue:failed` | `--limit`, `--retry`, `--forget`, `--prune` | list / retry / forget / prune failed jobs | `QueueFailedCommand.php` |

`queue:work` is pointless with `QUEUE_DRIVER=sync` (jobs run inline). The Docker stack runs it as the `queue` service.

## Users

| Command | Arguments / options | What it does | Source |
| --- | --- | --- | --- |
| `user:create` | `--name`, `--email`, `--password`, `--role=superadmin` (`superadmin|admin|editor|view`) | creates a `User`, hashes the password, assigns the role by name; prompts for missing values | `UserCreateCommand.php` |

## Developer tooling

| Command | Arguments / options | What it does | Source |
| --- | --- | --- | --- |
| `test [target]` | `target` = `all` (default) \| `core` \| `file` \| `modules` \| `integrations` \| `module:<id>` \| bare module id; `--list`, `--all-packages`, `--filter`, `--stop-on-failure`, `--testdox` | testing engine — runs PHPUnit for core, all modules, or one module (also `php bin/gereq-test` / `composer test` without booting the app) | `TestCommand.php`, `src/Testing/` |
| `nn` | `-m/--minutes=20` | prints N+1 query groups recorded in `storage/logs/nplusone.log` within the window (`Gereq\Debug\NPlusOneLog`) | `NnCommand.php` |
| `twig:types` | — | runs `gereq-twig-goto/index-templates.php` from [GEREQ_TOOLS](https://git.gereq.cz/gereq/GEREQ_TOOLS) (`GEREQ_TOOLS` env or sibling checkout) into `.cache/twig/` | `TwigTypesCommand.php` |

See [Testing](../development/testing.md) for target layout and Docker/`make test` usage.

Related IDE tooling lives in [GEREQ_TOOLS](https://git.gereq.cz/gereq/GEREQ_TOOLS): `gereq-i18n/index-translations.php`, `gereq-permissions/index-permissions.php`, `gereq-monitor` (VS Code extension). Module status TUI: `./bin/grq modules`.

## Module commands

Registered in `ConsoleKernel` regardless of whether the module is enabled; they fail at runtime if the module's tables are missing.

| Command | Module | Arguments / options | What it does |
| --- | --- | --- | --- |
| `currency:rates:sync` | Currency (`src/Currency/Console/SyncRatesCommand.php`) | `--date=<d.m.Y|Y-m-d>` | downloads daily ČNB exchange rates into `exchange_rates` |
| `uploads:purge` | CustomerUploads (`modules/CustomerUploads/Console/PurgeUploadsCommand.php`) | `--days=<n>`, `--dry-run` | deletes customer uploads older than `retention_days` |
| `partscatalog:sync-jama` | PartsCatalog (`modules/PartsCatalog/Console/SyncJamaCatalogCommand.php`) | `--brand`, `--structure-only`, `--parts-only`, `--limit-vehicles`, `--delay-ms=200` | crawls autodily-jama.cz brands/models/categories/parts into the DB |
| `payments:imap-match` | Payments (`modules/Payments/Console/ImapMatchCommand.php`) | `--limit=50` | matches incoming bank payments read over IMAP to payment intents by variable symbol |
| `portals:seed-demo` | Portals (`modules/Portals/Console/SeedDemoPortalsCommand.php`) | — | creates sample portals (Sreality / Idnes / Bezrealitky) linked to listings |
| `products:import-csv` | Products (`modules/Products/Console/ImportProductsCsvCommand.php`) | `-f/--file=<csv>`, `--dry-run` | imports products (`sku,name,price,currency,status`) |

## Scheduled / cron usage

Core owns `Gereq\Schedule\Schedule`. Modules register entries in `boot()` via `$context->schedule()`; core registers `currency:rates:sync`. Docker runs the `scheduler` service (`schedule:run` every 60 s). Outside Docker install one cron line:

```cron
* * * * *  php /path/to/gereq schedule:run
```

Built-in entries (async → job queue unless noted):

| Schedule | When | Mode |
| --- | --- | --- |
| `currency:rates:sync` | daily 07:00 Europe/Prague | async |
| `payments:imap-match` | every 5 minutes | async |
| `uploads:purge` | daily 03:00 Europe/Prague | async |

One-off delayed jobs from code: `$queue->later($job, $seconds)` or `$queue->later($job, $dateTime)` (redis/file; sync runs inline).

## Composer scripts

Not `gereq` commands but part of the same toolbelt (`composer.json`):

| Script | Runs |
| --- | --- |
| `composer test` | `phpunit` |
| `composer phpstan` | `phpstan analyse` (level 5, `src` + `modules`) |
| `composer cs` | `phpcs` (PSR-12) |

See [testing](../development/testing.md).
