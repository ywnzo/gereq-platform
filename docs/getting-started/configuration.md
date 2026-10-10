# Configuration

Configuration lives in plain PHP arrays under `config/*.php`; each file becomes a top-level key in `Gereq\Config\Repository` (`config('cache.driver')`, `$config->get('app.url')`). Environment-specific values are read from `.env` through the `env_value()` helper. The catalogue of every env key (type, default, secret, description) is `config/env.schema.toml` — generate `.env` with `php gereq env:init` / `bin/grq env init`, validate with `php gereq env:validate`. This page lists every config file with its keys, and every `.env` variable read by `config/` or `src/` with its default and meaning.

## How values are loaded

1. `Gereq\Foundation\Application::loadEnv()` (`src/Foundation/Application.php`) loads the dotenv file with `Dotenv::createImmutable(...)->safeLoad()` — existing process environment wins over the file. Default file is project-root `.env`; CLI may pass `config.env=<path>` (see [CLI reference](cli.md#env-file-configenv)).
2. `Application::loadConfiguration()` requires every `config/*.php` and stores the arrays in `Gereq\Config\Repository` (`src/Config/Repository.php`, dot-notation `get()`/`set()`).
3. `env_value(string $key, mixed $default)` (`src/Support/helpers.php`) reads `$_ENV`, then `$_SERVER`, then `getenv()`. Empty string and `false` fall back to the default. Literal `true`/`false`/`null`/`empty` (optionally in parentheses) are converted to their PHP values.

Runtime settings stored in the database (`Gereq\Setting\Settings`) sit **on top** of config: `Settings::getLive()` falls back to `config()` when a key has neither a DB record nor a definition (see [publishing-i18n-settings](../architecture/publishing-i18n-settings.md#settings)). Convention: `app.*`, `brand.*`, `admin.*` keys can be overridden from Admin → Settings.

## Files

### `config/app.php`

| Key | Default | Notes |
| --- | --- | --- |
| `name` | `APP_NAME` → `GEREQ Platform` | |
| `env` | `APP_ENV` → `production` | `local`, `production`, `testing` |
| `debug` | `APP_DEBUG` → `false` | enables debug bar, disables Twig/metadata caches |
| `url` | `APP_URL` → `http://localhost:8080` | trailing slash stripped |
| `url_prefix` | `APP_URL_PREFIX` or path of `APP_URL` | subdirectory deploys; used by routing and as fallback for `render.url_prefix` |
| `key` | `APP_KEY` → `''` | 64 hex chars; used for encrypted settings (AES-256-GCM) and cookies |
| `timezone` | `Europe/Prague` | hard-coded |
| `locale` | `cs` | hard-coded default, overridable via setting `app.locale` |
| `version` | `AppVersion::current()` | read from root `VERSION` file (`src/Support/AppVersion.php`) |
| `debug_editor_root` | `APP_DEBUG_EDITOR_ROOT` → `''` | host path so debug-bar template links open locally when running in Docker |
| `n_plus_one_threshold` | `APP_N_PLUS_ONE_THRESHOLD` → `2` (min 2) | N+1 detector (`src/Debug/NPlusOneDetector.php`) |
| `n_plus_one_log` | `APP_N_PLUS_ONE_LOG` → `true` | log N+1 to `error_log` |

### `config/admin.php`

| Key | Default | Notes |
| --- | --- | --- |
| `path` | `ADMIN_PATH` → `admin` | Admin UI URL segment (no slashes). `admin` → `/admin/…`; e.g. `panel` → `/panel/…`. Modules may keep writing `/admin/…`; the router and `UrlPrefix` rewrite at runtime. Restart required after change. Shown read-only in Admin → Settings. |
| `brand` | `GEREQ` | admin brand name (overridable via `brand.*` settings, `src/Brand/Branding.php`) |
| `brand_sub` | `Platform` | |
| `theme` | `system` | |

### `config/api.php`

| Key | Default | Notes |
| --- | --- | --- |
| `version` / `prefix` | `v1` / `/api/v1` | authenticated API base path |
| `public_key` | `API_PUBLIC_KEY` → `''` | site public key `grq_pub_*` (`src/Api/Auth/PublicApiKey.php`) |
| `rate_limit.standard` | `API_RATE_LIMIT` → `600` | requests / window per token, read |
| `rate_limit.write` | `API_RATE_LIMIT_WRITE` → `120` | |
| `rate_limit.store` | `API_RATE_LIMIT_STORE` → `60` | |
| `rate_limit.public` | `API_RATE_LIMIT_PUBLIC` → `120` | `/api/public/*` read |
| `rate_limit.public_write` | `API_RATE_LIMIT_PUBLIC_WRITE` → `30` | |
| `rate_limit.window` | `60` | seconds |
| `rate_limit.perf_secret` | `API_PERF_SECRET` → `''` | matching `X-Perf-Secret` header bypasses limits (load tests) |
| `token_prefix_live/test/public` | `grq_live_` / `grq_test_` / `grq_pub_` | |
| `webhooks.allow_private_urls` | `WEBHOOKS_ALLOW_PRIVATE_URLS` → `false` | SSRF guard for outbound webhooks |
| `webhooks.timeout_seconds` | `10` | |
| `webhooks.max_consecutive_failures` | `WEBHOOKS_MAX_FAILURES` → `50` | endpoint auto-disable threshold |
| `webhooks.replay_tolerance_seconds` | `300` | |

### `config/cache.php`

| Key | Default | Notes |
| --- | --- | --- |
| `driver` | `CACHE_DRIVER` → `file` | `file`, `redis`, `database` (`db`), `array` — `src/Cache/CacheFactory.php` |
| `prefix` | `CACHE_PREFIX` → `gereq` | pool namespace |
| `default_ttl` | `CACHE_TTL` → `3600` | |
| `doctrine_result` | `CACHE_DOCTRINE_RESULT` → `true` | Doctrine result cache uses the app pool |
| `twig` | `CACHE_TWIG` → `true` | compiled templates (`storage/cache/twig`); ignored when `app.debug` |
| `media_ttl` | `CACHE_MEDIA_TTL` → `604800` | browser cache for `/media/{id}/file` |
| `redis.host/port/password/database` | `REDIS_HOST` → `127.0.0.1`, `REDIS_PORT` → `6379`, `REDIS_PASSWORD` → `null`, `0` | |
| `database.table` | `CACHE_DB_TABLE` → `cache_items` | |

### `config/database.php`

| Key | Default | Notes |
| --- | --- | --- |
| `connection` | `DB_CONNECTION` → `sqlite` | `sqlite`, `mysql`, `pgsql` (`src/Database/EntityManagerFactory.php`) |
| `database` | `DB_DATABASE` → `storage/database.sqlite` | SQLite path (relative to project) or `:memory:` |
| `host` / `port` | `DB_HOST` → `127.0.0.1`, `DB_PORT` → `3306` | pgsql falls back to 5432 when port is empty |
| `name` | `DB_DATABASE_NAME` → `gereq` | |
| `username` / `password` | `DB_USERNAME` → `gereq`, `DB_PASSWORD` → `''` | |
| `charset` | `utf8mb4` | |

### `config/files.php`

| Key | Default | Notes |
| --- | --- | --- |
| `driver` | `FILE_DRIVER` → `local` | stored on `StoredFile::disk`; only a local Flysystem adapter is instantiated in `src/File/FileManager.php` |
| `local_path` | `storage/uploads` | |
| `max_size` | `10485760` | bytes |
| `allowed_mimes` | images, PDF, txt, csv, xlsx, docx | list in file |
| `image_convert.enabled` | `FILE_IMAGE_CONVERT` → `1` | convert uploads to AVIF/WebP (`src/File/ImageConverter.php`) |
| `image_convert.avif_quality` / `webp_quality` | `FILE_AVIF_QUALITY` → `55`, `FILE_WEBP_QUALITY` → `82` | |
| `s3.*` | `S3_BUCKET`, `S3_REGION` → `eu-central-1`, `S3_ENDPOINT`, `S3_KEY`, `S3_SECRET` | declared for future use; not consumed by `FileManager` |

### `config/i18n.php`

| Key | Default |
| --- | --- |
| `fallback_locale` | `cs` |
| `locales` | `cs` → Čeština, `en` → English |

Consumed by `Gereq\I18n\LocaleCatalog`.

### `config/mail.php`

| Key | Default |
| --- | --- |
| `dsn` | `MAIL_DSN` → `null://null` (Symfony Mailer DSN) |
| `from.address` / `from.name` | `MAIL_FROM_ADDRESS` → `noreply@gereq.cz`, `MAIL_FROM_NAME` → `GEREQ Platform` |

### `config/modules.php`

| Key | Default | Notes |
| --- | --- | --- |
| `gitlab.url` | `GITLAB_URL` → `https://git.gereq.cz` | |
| `gitlab.token` | `GITLAB_TOKEN` → `''` | PAT with `read_api`; required for remote install/upgrade |
| `gitlab.group` | `GITLAB_GROUP` → `gereq` | |
| `gitlab.repo_prefix` | `MODULE_REPO_PREFIX` → `module-` | repo = `<group>/<prefix><module id>` unless manifest `repository` overrides |
| `gitlab.tags_cache_ttl` | `MODULE_TAGS_CACHE_TTL` → `900` | seconds |
| `versions_path` | `storage/modules` | on-disk cache `storage/modules/{id}/{version}/` |

See [module system](../architecture/module-system.md#install-and-upgrade-from-gitlab).

### `config/module_groups.php`

Declares **packages** (`surface: packages`) and **plugin groups** (`surface: plugins`) shown in Admin → Modules. Each entry: `name`, `label` / `description` (translation keys), `surface`, `order`, `modules` (display order and one-click enablement) and `required` (subset needed for a "complete" setup; empty = all). Manifest `groups` in `module.php` are merged in by `Gereq\Module\ModuleGroupCatalog`.

| Group | Surface | Modules | Required |
| --- | --- | --- | --- |
| `gereq` | packages | gereq | — |
| `ecs` | packages | customers, fleet, carservice, media, documents, crm, calendar, idoklad | customers, fleet, carservice |
| `ebs` | packages | customers, bikeservice, searchengines, media, documents, calendar | customers, bikeservice |
| `realestates` | packages | currency, customers, media, cms, listings, crm, calendar, viewings, documents, reviews, emailing, webplus, webplus_ai | currency, media, cms, listings |
| `forum` | packages | customers, forum, community, achievements, messenger, media, notifications, newsletter, calendar | customers, forum |
| `plugin.payments` | plugins | comgate, stripe, gopay | — |
| `plugin.shipping` | plugins | packeta | — |
| `plugin.ai` | plugins | ollama, openai_compat, zai, deepseek, gemini, anthropic, openai | — |

### `config/queue.php`

| Key | Default | Notes |
| --- | --- | --- |
| `driver` | `QUEUE_DRIVER` → `sync` | `sync`, `redis`, `file` (`src/Queue/Queue.php`) |
| `retry_after` | `90` | seconds |

### `config/search.php`

| Key | Default | Notes |
| --- | --- | --- |
| `driver` | `SEARCH_DRIVER` → `self_repair` | only built-in driver exists (`src/Search/SelfRepairSearch.php`) |
| `typo_max_variants` | `SEARCH_TYPO_MAX_VARIANTS` → `14` | |
| `min_token_length` | `SEARCH_MIN_TOKEN_LENGTH` → `3` | |

### `config/security.php`

| Key | Default | Notes |
| --- | --- | --- |
| `login_max_attempts` / `login_decay_seconds` | `5` / `60` | login throttling |
| `password_min_length` | `10` | |
| `cookie_samesite` | `lax` | |
| `mfa_challenge_ttl` | `600` | seconds, TOTP/passkey second step |
| `webauthn.rp_name` | `WEBAUTHN_RP_NAME` → `APP_NAME` | |
| `webauthn.rp_id` | `WEBAUTHN_RP_ID` → `''` | hostname only; empty = request host; must not be an IP |
| `webauthn.local_hostname` | `WEBAUTHN_LOCAL_HOSTNAME` → `''` | suggested `*.localhost` alias for LAN testing |

### `config/render.php`

| Key | Default | Notes |
| --- | --- | --- |
| `asset_origin` | `RENDER_ASSET_ORIGIN` → `''` | CDN origin for `/build` assets; empty = same-origin |
| `url_prefix` | `RENDER_URL_PREFIX` → `''` | asset path prefix; empty uses `app.url_prefix` |
| `public_path` | `/build` | public URL of the build directory |
| `manifest` | `public/build/manifest.json` | Bun manifest |
| `dev_marker` | `public/build/.dev` | present during `bun run dev` |
| `watch.reload` / `watch.poll_ms` | `true` / `1200` | browser auto-reload in watch mode |
| `entries` | `admin` → `resources/js/admin.ts` | named bundler entries |
| `csp_origins` | `RENDER_CSP_ORIGINS` → `''` | extra CSP origins (plus `asset_origin`) |

Consumed by `Gereq\View\RenderEngine`.

### `config/session.php`

| Key | Default | Notes |
| --- | --- | --- |
| `driver` | `SESSION_DRIVER` → `file` | `file`, `redis` |
| `lifetime` | `SESSION_LIFETIME` → `120` | minutes |
| `cookie` | `gereq_session` | |
| `samesite` | `SESSION_SAME_SITE` → `lax` | |
| `files` | `storage/sessions` | |
| `redis.*` | `REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD`, prefix `gereq:session:` | |

## `.env` variables read outside `config/`

| Variable | Default | Read by | Meaning |
| --- | --- | --- | --- |
| — | — | Initial admin is **not** an env var. Use `php gereq migrate --bare --admin-email=… --admin-password=…` (or interactive / one-time generated password). |
| `TRUSTED_PROXIES` | `''` | `src/Http/TrustedProxies.php` | comma-separated CIDRs/IPs for `Request::setTrustedProxies` |
| `FRONTEND_ORIGIN` | `''` | `src/Http/PublicCors.php` | comma-separated allowed origins for `/api/public/*` CORS |
| `MONEYS3_APP_ID`, `MONEYS3_CLIENT_ID`, `MONEYS3_CLIENT_SECRET`, `MONEYS3_CLIENT_USERNAME`, `MONEYS3_CLIENT_PASSWORD`, `MONEYS3_API_URL` | `''` | `modules/MoneyS3/Application/MoneyS3Client.php` | Money S3 accounting integration fallbacks |
| `GEREQ_PORT` | `8080` | `docker-compose.yml` | nginx host port (set by `serve-docker --port`) |
| `DB_PASSWORD` | — | `docker-compose.yml` | required (`?Set a strong DB_PASSWORD in .env`) |

`config/env.schema.toml` is the full catalogue. `bin/grq install demo` additionally writes `FE2/.env` (`VITE_API_URL`, `VITE_API_PUBLIC_KEY`) for the React demo frontend; those are not read by PHP.

## Complete `.env` variable index

| Variable | Default | Config key |
| --- | --- | --- |
| `APP_NAME` | `GEREQ Platform` | `app.name`, `security.webauthn.rp_name` |
| `APP_ENV` | `production` | `app.env` |
| `APP_DEBUG` | `false` | `app.debug` |
| `APP_URL` | `http://localhost:8080` | `app.url` |
| `APP_URL_PREFIX` | `''` | `app.url_prefix` |
| `ADMIN_PATH` | `admin` | `admin.path` — admin UI URL segment (`/admin`, `/panel`, …) |
| `RENDER_ASSET_ORIGIN` | `''` | `render.asset_origin` |
| `RENDER_URL_PREFIX` | `''` | `render.url_prefix` |
| `RENDER_CSP_ORIGINS` | `''` | `render.csp_origins` |
| `APP_KEY` | `''` | `app.key` |
| `APP_DEBUG_EDITOR_ROOT` | `''` | `app.debug_editor_root` |
| `APP_N_PLUS_ONE_THRESHOLD` | `2` | `app.n_plus_one_threshold` |
| `APP_N_PLUS_ONE_LOG` | `true` | `app.n_plus_one_log` |
| `API_PUBLIC_KEY` | `''` | `api.public_key` |
| `API_RATE_LIMIT` | `600` | `api.rate_limit.standard` |
| `API_RATE_LIMIT_WRITE` | `120` | `api.rate_limit.write` |
| `API_RATE_LIMIT_STORE` | `60` | `api.rate_limit.store` |
| `API_RATE_LIMIT_PUBLIC` | `120` | `api.rate_limit.public` |
| `API_RATE_LIMIT_PUBLIC_WRITE` | `30` | `api.rate_limit.public_write` |
| `API_PERF_SECRET` | `''` | `api.rate_limit.perf_secret` |
| `WEBHOOKS_ALLOW_PRIVATE_URLS` | `false` | `api.webhooks.allow_private_urls` |
| `WEBHOOKS_MAX_FAILURES` | `50` | `api.webhooks.max_consecutive_failures` |
| `CACHE_DRIVER` | `file` | `cache.driver` |
| `CACHE_PREFIX` | `gereq` | `cache.prefix` |
| `CACHE_TTL` | `3600` | `cache.default_ttl` |
| `CACHE_DOCTRINE_RESULT` | `true` | `cache.doctrine_result` |
| `CACHE_TWIG` | `true` | `cache.twig` |
| `CACHE_MEDIA_TTL` | `604800` | `cache.media_ttl` |
| `CACHE_DB_TABLE` | `cache_items` | `cache.database.table` |
| `REDIS_HOST` | `127.0.0.1` | `cache.redis.host`, `session.redis.host` |
| `REDIS_PORT` | `6379` | `cache.redis.port`, `session.redis.port` |
| `REDIS_PASSWORD` | `null` | `cache.redis.password`, `session.redis.password` |
| `DB_CONNECTION` | `sqlite` | `database.connection` |
| `DB_DATABASE` | `storage/database.sqlite` | `database.database` |
| `DB_HOST` | `127.0.0.1` | `database.host` |
| `DB_PORT` | `3306` | `database.port` |
| `DB_DATABASE_NAME` | `gereq` | `database.name` |
| `DB_USERNAME` | `gereq` | `database.username` |
| `DB_PASSWORD` | `''` | `database.password` |
| `FILE_DRIVER` | `local` | `files.driver` |
| `FILE_IMAGE_CONVERT` | `1` | `files.image_convert.enabled` |
| `FILE_AVIF_QUALITY` | `55` | `files.image_convert.avif_quality` |
| `FILE_WEBP_QUALITY` | `82` | `files.image_convert.webp_quality` |
| `S3_BUCKET`, `S3_REGION`, `S3_ENDPOINT`, `S3_KEY`, `S3_SECRET` | `''` / `eu-central-1` / `null` / `''` / `''` | `files.s3.*` |
| `MAIL_DSN` | `null://null` | `mail.dsn` |
| `MAIL_FROM_ADDRESS` | `noreply@gereq.cz` | `mail.from.address` |
| `MAIL_FROM_NAME` | `GEREQ Platform` | `mail.from.name` |
| `GITLAB_URL` | `https://git.gereq.cz` | `modules.gitlab.url` |
| `GITLAB_TOKEN` | `''` | `modules.gitlab.token` |
| `GITLAB_GROUP` | `gereq` | `modules.gitlab.group` |
| `MODULE_REPO_PREFIX` | `module-` | `modules.gitlab.repo_prefix` |
| `MODULE_TAGS_CACHE_TTL` | `900` | `modules.gitlab.tags_cache_ttl` |
| `QUEUE_DRIVER` | `sync` | `queue.driver` |
| `SEARCH_DRIVER` | `self_repair` | `search.driver` |
| `SEARCH_TYPO_MAX_VARIANTS` | `14` | `search.typo_max_variants` |
| `SEARCH_MIN_TOKEN_LENGTH` | `3` | `search.min_token_length` |
| `WEBAUTHN_RP_NAME` | `APP_NAME` | `security.webauthn.rp_name` |
| `WEBAUTHN_RP_ID` | `''` | `security.webauthn.rp_id` |
| `WEBAUTHN_LOCAL_HOSTNAME` | `''` | `security.webauthn.local_hostname` |
| `SESSION_DRIVER` | `file` | `session.driver` |
| `SESSION_LIFETIME` | `120` | `session.lifetime` |
| `SESSION_SAME_SITE` | `lax` | `session.samesite` |

`config/env.schema.toml` is the annotated catalogue; `tests/bootstrap.php` overrides `APP_ENV=testing`, `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:` for PHPUnit.
