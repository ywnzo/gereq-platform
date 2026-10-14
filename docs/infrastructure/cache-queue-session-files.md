# Cache, queue, session, files and mail

GEREQ keeps its runtime state in a handful of pluggable backends, each selected by one env var: application cache (`CACHE_DRIVER`), job queue (`QUEUE_DRIVER`), PHP session (`SESSION_DRIVER`), file uploads (`FILE_DRIVER`) and outgoing mail (`MAIL_DSN`). All file-based defaults live under `storage/`. This page maps the config files in `config/` to their implementations in `src/` and documents the `storage/` layout. In the Docker stack Redis is forced for session and queue — see [docker.md](docker.md).

## Cache (`config/cache.php` → `src/Cache/CacheFactory.php`)

| Config key | Env | Default | Meaning |
| --- | --- | --- | --- |
| `driver` | `CACHE_DRIVER` | `file` | `file`, `redis`, `database` (alias `db`), `array` |
| `prefix` | `CACHE_PREFIX` | `gereq` | cache namespace |
| `default_ttl` | `CACHE_TTL` | `3600` | seconds |
| `doctrine_result` | `CACHE_DOCTRINE_RESULT` | `true` | Doctrine result cache uses the same pool |
| `twig` | `CACHE_TWIG` | `true` | compiled Twig templates (ignored when `APP_DEBUG=true`) |
| `media_ttl` | `CACHE_MEDIA_TTL` | `604800` | browser `Cache-Control` for `/media/{id}/file` (Media module) |
| `redis.*` | `REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD` | `127.0.0.1`, `6379`, `null`; database `0` | |
| `database.table` | `CACHE_DB_TABLE` | `cache_items` | table for the `database` driver |

Adapter per driver (`CacheFactory::create()`):

| Driver | Adapter | Storage |
| --- | --- | --- |
| `file` (default) | `Symfony\Component\Cache\Adapter\FilesystemAdapter` | `storage/cache/data` |
| `redis` | `RedisAdapter` | Redis DB 0, namespace = prefix |
| `database` / `db` | `DoctrineDbalAdapter` | table `cache_items` in the main DB (own DBAL connection built from `config/database.php`) |
| `array` | `ArrayAdapter` | per-request memory |

`src/Cache/AppCache.php` wraps the PSR-6 pool with `get/put/remember/forget/flush`. Other caches that are *not* controlled by `CACHE_DRIVER`:

| Cache | Location | Set in |
| --- | --- | --- |
| Doctrine metadata + query cache (non-debug only) | `storage/cache/doctrine` | `src/Database/EntityManagerFactory.php` |
| Compiled Twig | `storage/cache/twig` | `src/View/TwigFactory.php` |
| Login rate limiter | `storage/cache/rate` | `src/Security/RateLimiter.php` (wired in `src/Foundation/Application.php`) |

`php gereq cache:clear` (`src/Console/Command/CacheClearCommand.php`) flushes the app pool and wipes `storage/cache/{data,twig,doctrine,rate}`.

## Queue (`config/queue.php` → `src/Queue/Queue.php`)

| Config key | Env | Default |
| --- | --- | --- |
| `driver` | `QUEUE_DRIVER` | `sync` (`sync`, `redis`, `file`) |
| `retry_after` | – | `90` (declared; not read by `Queue.php`) |
| `max_attempts` | `QUEUE_MAX_ATTEMPTS` | `3` |

Behaviour of `Queue::dispatch(Job $job)` / `Queue::later(Job, delay)` / `Queue::pop()`:

| Driver | dispatch | later | pop |
| --- | --- | --- | --- |
| `sync` | `$job->handle()` immediately | same (no worker to wait) | never returns a job |
| `redis` | `serialize` → `RPUSH gereq:queue` | `ZADD gereq:queue:delayed` until `available_at` | promote due → `LPOP` |
| `file` | `storage/tmp/queue/*.job` | `storage/tmp/queue-delayed/{ts}_*.job` | promote due → oldest ready file |

Worker: `php gereq queue:work` loops forever, promotes delayed jobs, `popRaw()` → `handle()`, sleeps 1 s when idle. Failures go to `failed_jobs` via `FailedJobStore`; the payload is re-queued until `max_attempts` is spent. Admin UI (`/admin/queues`, `/admin/queues/schedule`) and `queue:failed` / `schedule:list` are core.

Scheduler: `php gereq schedule:run` (once per minute) runs due `Gereq\Schedule\Schedule` entries. Prefer `->async()` so work is dispatched to the queue worker. Register from modules with `$context->schedule()->command(…)` / `->job(…)`.

In Docker: `queue` (`queue:work`) and `scheduler` (`schedule:run` loop), both `QUEUE_DRIVER=redis`. Logs: `docker compose logs -f queue` / `scheduler`. Outside Docker run both under systemd/supervisor (or cron for `schedule:run`).

## Session (`config/session.php` → `src/Http/Session/SessionHandlerFactory.php`)

| Config key | Env | Default |
| --- | --- | --- |
| `driver` | `SESSION_DRIVER` | `file` (`file`, `redis`) |
| `lifetime` | `SESSION_LIFETIME` | `120` (minutes) |
| `cookie` | – | `gereq_session` |
| `path` | – | `/` |
| `samesite` | `SESSION_SAME_SITE` | `lax` |
| `files` | – | `storage/sessions` |
| `redis.*` | `REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD` | prefix `gereq:session:` |

Handlers: `NativeFileSessionHandler(storage/sessions)` or Symfony `RedisSessionHandler`. `docker-compose.yml` hard-codes `SESSION_DRIVER=redis` for the `php` service, so the value in `.env` is irrelevant inside Docker (the README's "Docker forces redis" refers to this). `bin/grq install` also switches `SESSION_DRIVER` to `redis` whenever the Redis queue or Docker runtime is chosen.

## Files (`config/files.php` → `src/File/`)

| Config key | Env | Default | Notes |
| --- | --- | --- | --- |
| `driver` | `FILE_DRIVER` | `local` | recorded on each `StoredFile` as `disk`; **only the local filesystem is implemented** in `FileManager` |
| `local_path` | – | `storage/uploads` | root for uploads |
| `max_size` | – | 10 MiB | validated in `FileManager::store()` |
| `allowed_mimes` | – | jpeg, png, webp, avif, gif, pdf, txt, csv, xlsx, docx | |
| `image_convert.enabled` | `FILE_IMAGE_CONVERT` | `1` | `src/File/ImageConverter.php` produces AVIF/WebP variants |
| `image_convert.avif_quality` / `webp_quality` | `FILE_AVIF_QUALITY`, `FILE_WEBP_QUALITY` | `55` / `82` | |
| `s3.*` | `S3_BUCKET`, `S3_REGION` (`eu-central-1`), `S3_ENDPOINT`, `S3_KEY`, `S3_SECRET` | – | **config only** — no S3 adapter exists in `src/File`; `README.md` states the S3 driver "can be added" |

Paths that hold user-visible files:

| Path | Content | Served by |
| --- | --- | --- |
| `storage/uploads/` | `FileManager` uploads (`src/File/FileManager.php`), Media module imports (`src/Media/Application/MediaImporter.php` also writes here) | Media module `GET /media/{id}/file` (`src/Media/MediaRegistrar.php`) |
| `storage/uploads/brand/` | white-label logo/favicon uploaded in Admin → Settings (`src/Admin/Http/SettingsController.php`) | `GET /brand/asset/{name}` (`src/Http/BrandAssetController.php`) |
| `public/brand/icons/`, `public/brand/logos/` | static default brand assets (default logo `/brand/icons/favicon.svg`, `src/Brand/Branding.php`) | nginx / PHP built-in server directly |
| `storage/invoices/` | rendered invoices (`modules/Orders/Application/InvoiceIssuingService.php`) | Orders module |

## Mail (`config/mail.php`)

| Config key | Env | Default |
| --- | --- | --- |
| `dsn` | `MAIL_DSN` | `null://null` |
| `from.address` | `MAIL_FROM_ADDRESS` | `noreply@gereq.cz` |
| `from.name` | `MAIL_FROM_NAME` | `GEREQ Platform` |

Transport: `Symfony\Component\Mailer\Mailer` built with `Transport::fromDsn(config('mail.dsn'))` in `src/Foundation/Application.php`; consumed by `src/Notification/Notifier.php`. Any Symfony Mailer DSN works (e.g. `smtp://user:pass@host:587`). The Emailing module manages its own mail accounts (see `modules/Emailing`), independent of this global DSN.

## `storage/` layout

| Directory | What lives there | Created by |
| --- | --- | --- |
| `storage/cache/data` | app cache (`file` driver) | `CacheFactory` |
| `storage/cache/doctrine` | Doctrine metadata/query cache (non-debug) | `EntityManagerFactory` |
| `storage/cache/twig` | compiled Twig templates | `TwigFactory` |
| `storage/cache/rate` | login rate-limit counters | `RateLimiter` |
| `storage/logs/gereq.log` | application errors (`src/Http/ExceptionHandler.php`) | runtime |
| `storage/logs/nplusone.log` | N+1 query report (debug, `serve-docker` TUI / `php gereq nn`) | runtime |
| `storage/logs/bun-assets.log` | `bun run dev` output when started by `serve-docker --hot` | `AssetWatchProcess` |
| `storage/sessions` | file sessions | `SessionHandlerFactory` |
| `storage/tmp/queue` | file-driver ready jobs | `Queue` |
| `storage/tmp/queue-delayed` | file-driver delayed jobs | `Queue::later` |
| `storage/tmp/schedule-locks` | `withoutOverlapping` mutex files | `ScheduleLock` |
| `storage/tmp/bun-assets.pid` | pid of Bun watch started by `serve-docker --hot` | `AssetWatchProcess` |
| `storage/uploads` (+ `brand/`) | uploads, media, brand assets | `FileManager`, Media, Settings |
| `storage/invoices` | invoice documents | Orders module |
| `storage/modules/{id}/{version}/` | downloaded module versions (`src/Module/ModuleVersionStore.php`, config `modules.versions_path`) | module updater |
| `storage/database.sqlite` | SQLite database (default `DB_DATABASE`) | `EntityManagerFactory` |
| `storage/framework/phpunit` | PHPUnit cache (`phpunit.xml` `cacheDirectory`) | tests |

Directory bootstrap happens in `bin/grq install`, `bin/grq install demo`, `make env` (`storage/{cache,logs,sessions,tmp/queue,uploads,invoices,database}`) and in the container entrypoint `docker/php/entrypoint.sh` (`chmod -R a+rwX storage`). Git tracks only `storage/.gitkeep`; everything else under `storage/` is gitignored (runtime). `.dockerignore` similarly excludes runtime dumps.

## Related

- [docker.md](docker.md) — Redis/PostgreSQL containers and forced drivers
- [deployment.md](deployment.md) — production values for these drivers
- [../getting-started/configuration.md](../getting-started/configuration.md)
