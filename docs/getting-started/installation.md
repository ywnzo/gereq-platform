# Installation

GEREQ is a PHP 8.4 platform with a Doctrine/Symfony-components core, Twig admin UI and a Vite/Bun frontend build. This page covers the three supported ways to get a running instance: the interactive host installer (`./bin/grq install`), the manual command sequence, and the Docker-only `Makefile` flow. It also describes how to load the RealEstates demo dataset. Everything below is derived from `README.md`, `bin/*`, `Makefile`, `INSTALACE.sh` and `src/Console/Command/*`.

## Requirements

| Requirement | Notes | Source |
| --- | --- | --- |
| PHP `^8.4` | extensions `gd`, `zip`, `json`, `mbstring`, `pdo` in `composer.json`; `ext-redis` is optional (`suggest`) and only needed when `CACHE_DRIVER` / `SESSION_DRIVER` / `QUEUE_DRIVER` is `redis` | `composer.json` |
| Composer | | `bin/grq install` checks for it |
| Bun (preferred) or npm | frontend build (`vite build`, Tailwind 4). Workspace rule: always `bun run …` / `bun x …` | `package.json`, `.cursor/rules/tech-stack.mdc` |
| Database | SQLite (default in `config/database.php`), MySQL/MariaDB or PostgreSQL 16 (Docker default) | `config/database.php`, `docker-compose.yml` |
| Redis | optional; required for `CACHE_DRIVER=redis`, `QUEUE_DRIVER=redis`, `SESSION_DRIVER=redis` (Docker stack forces the last two) | `docker-compose.yml` |
| Docker + `docker compose` | optional; used by `php gereq serve-docker` and the `Makefile` | `Makefile`, `src/Console/Command/ServeDockerCommand.php` |

`bin/grq install` refuses to run on PHP < 8.4 and aborts when neither `bun` nor `npm` is present.

### Host PHP extensions

On a minimal PHP install, `composer install` fails when `ext-gd` or `ext-zip` is missing (`webklex/php-imap` needs zip; image conversion and QR codes need gd). Check with `./bin/grq doctor` or `php -m`.

| OS | Install command |
| --- | --- |
| Fedora / RHEL | `sudo dnf install php-gd php-pecl-zip` |
| Debian / Ubuntu | `sudo apt install php-gd php-zip` (add version suffix if needed, e.g. `php8.4-gd php8.4-zip`) |

Docker and `make install` already ship both extensions in `docker/php/Dockerfile`.

## Clone

Modules under `modules/` are git submodules pointing at `git.gereq.cz/gereq/module-<id>` (see `.gitmodules`). Also submodules: dockerless at `dev/dockerless` → [gereq/dockerless](https://git.gereq.cz/gereq/dockerless), tooling at `tools/` → [gereq/GEREQ_TOOLS](https://git.gereq.cz/gereq/GEREQ_TOOLS). Clone recursively or initialise them afterwards:

```bash
git clone --recurse-submodules git@git.gereq.cz:gereq/GEREQ_PLATFORM.git
# or, after a plain clone:
git submodule update --init --recursive
```

Without the module submodules `ModuleManager::discover()` (`src/Module/ModuleManager.php`) finds no `module.php` manifests and the admin has no modules to enable.

## Option A — interactive installer (`./bin/grq install`)

`bin/grq install` is the interactive **clean client** installer (Go CLI). It:

1. Checks `php` (>= 8.4), `composer`, `bun`/`npm`, and optionally Docker.
2. Asks for app name, `APP_URL`, admin name/e-mail/password, environment (`local` / `production`), database type (SQLite / MySQL / PostgreSQL) and connection details, runtime (Docker stack or host), queue driver, whether webhooks may target private URLs, and whether to load demo/sample data.
3. Runs `composer install --no-interaction --prefer-dist`.
4. Generates `APP_KEY` (`bin2hex(random_bytes(32))`), backs up an existing `.env` to `.env.bak.<timestamp>` and writes a new `.env`.
5. Creates `storage/{cache,logs,sessions,tmp/queue,uploads,invoices,database}` and, for SQLite, an empty database file.
6. Runs `bun install && bun run build` (or the npm equivalents).
7. Runs `php gereq migrate --fresh --seed --no-interaction` plus `--no-demo` / `--no-sample` unless you opted in.
8. Either `docker compose up -d --build` (Docker mode) or offers to start `php gereq serve`.

When Docker mode is chosen the script sets `QUEUE_DRIVER=redis`, `SESSION_DRIVER=redis`, `REDIS_HOST=redis` and `WEBHOOKS_ALLOW_PRIVATE_URLS=true` in `.env`.

After it finishes: admin at `<APP_URL>/admin`, first-run wizard at `<APP_URL>/admin/setup` (enforced by `src/Http/Middleware/SetupMiddleware.php` until completed).

## Option B — manual, non-interactive

```bash
composer install
php gereq env:init              # from config/env.schema.toml (or: bin/grq env init)
# adjust DB_* as needed
# Admin účet se nezapisuje do .env — zadejte při migrate/install (--admin-email / --admin-password)
# nebo nechte vygenerovat (vypíše se jednou do konzole).
php gereq migrate --fresh --seed --no-demo --no-sample
bun install && bun run build
php gereq serve                 # http://127.0.0.1:8080/admin
```

Notes:

- Schema defaults use `DB_CONNECTION=sqlite`. For Docker use `php gereq env:init --docker` / `bin/grq env init --docker` (pgsql + redis hosts).
- `php gereq install` (`src/Console/Command/InstallCommand.php`) is the interactive equivalent of `migrate --fresh --seed` once `vendor/` and `.env` already exist. Options: `--fresh`, `--no-demo`, `--no-sample`, `--yes|-y` (skip prompts, for CI).
- The admin account is created by `migrate --bare` / `migrate --seed` / `install` via CLI flags or interactive prompt (`src/Console/AdminBootstrap.php`). Credentials are **not** stored in `.env`. Change the password after first login (setup wizard).

### `migrate` options

`php gereq migrate` (`src/Console/Command/MigrateCommand.php`) runs `Gereq\Database\Migrator`:

| Command | Effect |
| --- | --- |
| `php gereq migrate` | run pending module migrations (`modules/*/Database/Migrations`) and sync Doctrine schema for core + enabled modules; no seed |
| `php gereq migrate --seed` | as above, then `Seeder::seed(withDemo: true, withSample: true)` |
| `php gereq migrate --fresh --seed --no-demo --no-sample` | drop all mapped tables, recreate, seed only permissions, roles, admin user and default modules ("clean client"); marks the setup wizard as pending |
| `php gereq migrate:status` | list every module migration with `ran` / `ran_at` |

`--fresh` calls `Migrator::fresh()` which drops the schema for all mapped entities and clears `schema_migrations`. It is destructive.

## Option C — Docker only (`Makefile`)

The `Makefile` needs a **running** Docker daemon and the Compose plugin on the host; PHP, Composer and Bun run inside containers (`oven/bun:1.2` for assets). It also uses prebuilt `bin/grq` for `.env` and creates `storage/*` plus placeholder `../fe` / `../cms-lib` bind mounts when missing.

```bash
make install            # .env + image build + composer + assets + migrate --fresh --seed --no-demo --no-sample + up
make up                 # start stack (detached); variables: PORT=8080 HTTPS=0 BUILD=0
make down               # stop stack
make demo               # like install but seeds demo data (demo@gereq.cz / demo123)
make gereq ARGS="cache:clear"
make shell              # bash in the php container
make logs | make ps
make migrate            # php gereq migrate --no-interaction
make migrate-fresh      # SEED_FLAGS (default: --fresh --seed --no-demo --no-sample --no-interaction)
make seed               # php gereq seed:demo
make assets             # bun install + bun run build via oven/bun
make adminer            # Adminer on :8086 (compose profile "tools")
make hot                # Vite HMR on :5173 via Docker bun
make urls
```

`make env` (run implicitly by most targets) runs `bin/grq env init --docker`, which writes `.env` from `config/env.schema.toml`, generates `APP_KEY` and `DB_PASSWORD`, and switches drivers to the Docker services (`DB_CONNECTION=pgsql`, `DB_HOST=postgres`, `REDIS_HOST=redis`, `SESSION_DRIVER=redis`, `QUEUE_DRIVER=redis`, `CACHE_DRIVER=redis`). `HTTPS=1` adds `docker-compose.https.yml` and generates a self-signed certificate into `docker/nginx/certs/` (`make certs`).

The compose stack (`docker-compose.yml`, project name `gereq`) contains: `nginx` (port `${GEREQ_PORT:-8080}`), `php` (PHP-FPM, image from `docker/php/Dockerfile`), `postgres` (16, not published to the host), `redis`, `queue` (`php gereq queue:work`), `scheduler` (`schedule:run` every 60 s) and the opt-in `adminer` (profile `tools`). `DB_PASSWORD` must be set in `.env` or compose fails with `Set a strong DB_PASSWORD in .env`.

## Option D — Dockerless / immutable host

For machines that cannot write outside `/home` (no Docker, no root package installs), use the home-only toolchain from the standalone repo [gereq/dockerless](https://git.gereq.cz/gereq/dockerless) (submodule at `dev/dockerless`):

```bash
git submodule update --init --recursive dev/dockerless
./dev/dockerless/install.sh --fresh
./dev/dockerless/serve.sh                 # php -S on :8080
./dev/dockerless/serve.sh --mode=nginx    # optional user-space nginx + php-fpm
```

Standalone clone (next to the platform):

```bash
git clone git@git.gereq.cz:gereq/dockerless.git
export GEREQ_ROOT=/path/to/GEREQ_PLATFORM
./dockerless/install.sh --fresh
```

Uses SQLite + file/sync drivers; runtime files go to `~/.local/share/gereq-dockerless`. Full detail: [Dockerless local runtime](../development/dockerless.md).
## Dev servers

### Built-in PHP server

```bash
php gereq serve --port=8080 --host=0.0.0.0
bun run dev                      # watch rebuild of public/build
```

`ServeCommand` (`src/Console/Command/ServeCommand.php`) probes up to 20 following ports if the requested one is taken and runs `php -S <host>:<port> -t public public/router.php`. Use `127.0.0.1`, not `localhost`, in the browser (session cookie behaviour).

### Docker stack (`php gereq serve-docker`)

`ServeDockerCommand` (`src/Console/Command/ServeDockerCommand.php`) wraps `docker compose` and adds a log TUI. It forces `SESSION_DRIVER=redis` / `QUEUE_DRIVER=redis` via compose environment.

| Option | Meaning |
| --- | --- |
| `--port=8080` | host HTTP port for nginx |
| `--https` | also publish HTTPS on host port 443 with the self-signed cert (`docker-compose.https.yml`) |
| `--hot` | start `bun run dev` on the host (`AssetWatchProcess`; writes `public/build`) |
| `--build` | rebuild the PHP image before starting |
| `-d`, `--detach` | `docker compose up -d` |
| `--reuse` | `down` then `up` (change port / toggle `--https` without a manual `--down`) |
| `--down` | stop the stack (and the Vite process started by `--hot -d`) |
| `--raw` | raw compose logs instead of the compact HTTP log stream |
| `--dashboard` | fullscreen TUI (`src/Console/Docker/ServeLogTui.php`) |
| `--keep` | leaving the log view does not run `compose down` |
| `--queue` | legacy no-op; the `queue` worker service is part of the default stack |

Log stream keys: `q` `a` `p` `n` `space` (see README).

## Frontend build

```bash
bun install
bun run build        # hashed files → public/build/manifest.json
bun run dev          # watch; RenderEngine reads .dev + cache-busts URLs
```

Entry point is `resources/js/admin.ts` (`scripts/build.ts`). `Gereq\View\RenderEngine` (`src/View/RenderEngine.php`) emits tags from `public/build/manifest.json`. Origins and prefixes: `config/render.php`.

## Demo data

| Tool | What it does |
| --- | --- |
| `php gereq seed:demo` | `Gereq\Database\DemoSeeder` — demo user plus realistic click-through data on top of an existing install |
| `php gereq migrate --seed` (without `--no-demo`) | full seed including demo business data (`Seeder::seed(true, true)`) |
| `./bin/grq install demo [--serve] [--port=8080]` | non-interactive demo install: `./bin/grq doctor` checks PHP extensions (`gd`, `zip`, …), `composer install` (adds `--ignore-platform-req=ext-redis` when the extension is missing), writes a SQLite `.env` with `demo@gereq.cz` / `demo123`, generates `API_PUBLIC_KEY=grq_pub_*` and writes `FE2/.env` (`VITE_API_URL`, `VITE_API_PUBLIC_KEY`), builds assets and runs `php gereq migrate --fresh --seed` |
| `./INSTALACE.sh` | thin wrapper that `exec`s `bin/grq install demo "$@"` (shipped in the demo zip) |
| `./bin/grq pack demo` | builds `dist/gereq-realestates-demo-<yyyymmdd>.zip` via `rsync` excluding `.git`, `vendor`, `node_modules`, `public/build`, `.env*`, SQLite DB and storage contents; adds `INSTALACE.sh` and `DEMO-README.txt` |
| `php gereq portals:seed-demo` | Portals module: sample portals (Sreality/Idnes/Bezrealitky) linked to listings |

## After installation

- Log in at `/admin`, complete `/admin/setup`.
- Enable the modules you need: `php gereq module:enable <id>` or Admin → Modules (package groups such as `realestates` can be enabled in one click; see [module system](../architecture/module-system.md)).
- Set `GITLAB_TOKEN` in `.env` if you want to install module versions from GitLab through the admin.
- Enable git hooks once with `bin/grq commit --install-hooks` (or `git config core.hooksPath .githooks`). Hooks live in `.githooks/` (`pre-commit` guards secrets/submodules/junk and runs staged `composer cs`, `commit-msg` strips `Co-authored-by` and self-heals Conventional Commits — e.g. bare text → `chore: …` — then enforces the shape, `pre-push` protects `main`/`dev`/… and runs the full quality pipeline matching CI: `composer test` + phpstan + phpstan:tests + cs). See [`.githooks/README.md`](../../.githooks/README.md).
- Commit with `bin/grq commit "..."` — it ensures hooks are installed and applies the same subject self-heal before committing.

Next: [Configuration](configuration.md), [CLI reference](cli.md).
