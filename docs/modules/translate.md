# Translate (`translate`)

The Translate module is a small machine-translation facade backed by Microsoft (Azure) Translator Text API v3 with a persistent database cache. It exposes a `TranslateService` capability (`translate`) that other modules can call to translate batches of strings (by default Russian → Czech, tuned for imported parts catalogues with Cyrillic names), a settings page to configure the API key / region / endpoint and locales, a connectivity test, and a cache counter widget. It is an operator/integration tool with no public-facing UI; it was renamed from the legacy `aitranslate` module (migration renames the table and module row).

## At a glance

| Item | Value |
| --- | --- |
| Module id | `translate` |
| Version | `2.1.0` (`modules/Translate/module.php`, `TranslateModule::version()`) |
| Directory | `modules/Translate/` |
| Requires | `core ^1.0` |
| Optional | none |
| Capabilities provided | `translate` (capability object `TranslateService`) |
| Capabilities consumed | none |
| Groups / packages | manifest `groups: ['ecs']`; not listed in `config/module_groups.php` (membership in **ECS** comes from the manifest merge only) |
| Permissions | `translate.view` (`perm.translate.view`), `translate.manage` (`perm.translate.manage`) — group `perm.group.translate` |
| API scopes | none |
| Author | GEREQ |

Manifest icon: `language` (menu uses `edit`). Description key: `module.translate.description`.

## Domain model

### `TranslationCache` → table `translate_cache`

Unique `uniq_translate_hash (hash)`, index `idx_translate_langs (source_locale, target_locale)`.

| Field | Column / type | Notes |
| --- | --- | --- |
| `id` | `id` string(36) PK | UUID v7 |
| `hash` | `hash` string(64), unique | `sha256(source_locale|target_locale|source_text)` via `TranslationCache::makeHash()` |
| `sourceLocale` | `source_locale` string(8) | |
| `targetLocale` | `target_locale` string(8) | |
| `sourceText` | `source_text` text | trimmed original |
| `translatedText` | `translated_text` text | `setTranslatedText()` is the only mutator |
| `createdAt` | `created_at` datetime | |

No statuses, no relations to other entities.

## Admin UI

### Menu (`TranslateModule::boot()`)

| Menu id | Label key | Icon | Route | Permission | Order / group |
| --- | --- | --- | --- | --- | --- |
| `translate` | `nav.translate` | `edit` | `admin.translate.settings` | `translate.view` | 79, group `Integrace` |

Command palette: `translate.settings` (`palette.translate.settings`, `/admin/translate/settings`, keywords `translate`, `překlad`, `microsoft`, `azure`, `translator`).

### Routes (group `/admin/translate`, middleware `auth`)

| Method | Path | Route name | Controller::action | Permission |
| --- | --- | --- | --- | --- |
| GET | `/admin/translate/settings` | `admin.translate.settings` | `SettingsController::edit` | `translate.view` |
| POST | `/admin/translate/settings` | `admin.translate.settings.update` | `SettingsController::update` | `translate.manage` |
| POST | `/admin/translate/test` | `admin.translate.test` | `SettingsController::test` | `translate.manage` |
| POST | `/admin/translate/ping` | `admin.translate.ping` | `SettingsController::ping` | `translate.manage` |
| POST | `/admin/translate/cache/clear` | `admin.translate.cache.clear` | `SettingsController::clearCache` | `translate.manage` |

Controller: `modules/Translate/Http/SettingsController.php`; single view `Resources/views/settings.html.twig`.

### Settings screen

One page with: API key (encrypted; the form only shows whether a key is set, `apiKeySet`), region, endpoint, source/target locale; a **test translation** form (`test` → `TranslateService::translate($input)`, shows `testResult` / `testError`), a **ping** button (`TranslateProviderInterface::ping()` — small test translation against the API, flashes the message), **clear cache** (`clearCache()` → flash `translate.flash.cache_cleared` with count), cache count, and the tail of the debug log (`TranslateDebug::tail(50)`).

Settings read/written (see Configuration): `translate.ms_api_key`, `translate.ms_region`, `translate.ms_endpoint`, `translate.source_locale`, `translate.target_locale`.

## Public / REST API

None.

## Events, hooks & webhooks

None. No platform events, hooks, queue jobs, scheduled tasks or publishables.

## Dashboard widgets

| Widget id | Type | Class | Shows | Permission | Order |
| --- | --- | --- | --- | --- | --- |
| `translate.shortcut` | shortcut | `Application/Widgets/TranslateShortcutWidget.php` | Link to settings, hint `admin.dashboard.hint.translate` | `translate.view` | 79 |
| `translate.cache.stats` | metric | `Application/Widgets/TranslateCacheStatsWidget.php` | Total cached translations; hint with count created in the last 7 days | `translate.view` | 76 |

See `../architecture/dashboard-widgets.md`.

## Services & extension points

| Class | Responsibility |
| --- | --- |
| `Application\TranslateService` | Capability `translate` (DB cache + provider facade). `isConfigured()`, `ping()`, `sourceLocale()` / `targetLocale()` (from settings, defaults `ru` → `cs`), `cacheCount()`, `clearCache()` (DQL delete, returns removed count), `translate(text, ?from, ?to)`, `batch(texts, ?from, ?to)` — cache lookup by hash, calls the provider only for misses, persists new `TranslationCache` rows, preserves input order and duplicates; `batchInstant(texts, ?from, ?to)` — cache-only, never calls the API (misses return the original text; intended for request-time catalogue rendering). `needsTranslation()` skips strings that are purely `[0-9A-Za-z .,-/+#]` (SKUs, codes) and only translates text containing Cyrillic characters. |
| `Application\TranslateProviderInterface` | Provider contract: `translateBatch(list<string>, from, to): list<string>`, `isConfigured()`, `ping(): {ok, message, details?}`. Bound in `src/Foundation/Application.php` to `MicrosoftTranslateProvider` (`\DI\get`). Implementing another provider means rebinding this interface in the container. |
| `Application\MicrosoftTranslateProvider` | Azure Translator v3 (`/translate?api-version=3.0&from=&to=`), default endpoint `https://api.cognitive.microsofttranslator.com`, headers `Ocp-Apim-Subscription-Key` and optional `Ocp-Apim-Subscription-Region`; chunks requests to 100 texts, HTTP timeout 30 s; `normalizeLocale()` keeps only the language part (`cs-CZ` → `cs`). Throws `RuntimeException` (Czech messages) on missing key, non-2xx HTTP, or malformed response. |
| `Application\HttpJsonClient` | Thin JSON HTTP client (cURL, falls back to PHP streams). |
| `Application\TranslateDebug` | File logger (`storage/logs/translate.log`, fallback `storage/cache/translate` or system temp) plus `bdump()` to the debug bar; `tail(n)` for the settings page. |

Integration with other modules: no module in the repository currently resolves the `translate` capability or injects `TranslateService` (the `batchInstant()` comment references catalogue requests, i.e. [PartsCatalog](partscatalog.md), but no call exists there).

## CLI commands

None.

## Configuration & translations

Settings (module `translate`, all defined with `global = false` in `Settings::define()`, i.e. not shown in the global settings screen):

| Key | Type | Default | Purpose |
| --- | --- | --- | --- |
| `translate.ms_api_key` | encrypted | `''` | Azure Translator subscription key |
| `translate.ms_region` | string | `''` | Azure resource region (optional for global resources) |
| `translate.ms_endpoint` | string | `''` | Custom endpoint; empty → default |
| `translate.source_locale` | string | `ru` | Default source language |
| `translate.target_locale` | string | `cs` | Default target language |

Environment fallbacks (used when the setting is empty, `MicrosoftTranslateProvider::apiKey()/region()/endpoint()`): `MS_TRANSLATOR_KEY`, `AZURE_TRANSLATOR_KEY`, `MS_TRANSLATOR_REGION`, `AZURE_TRANSLATOR_REGION`, `MS_TRANSLATOR_ENDPOINT`.

Translations: `Resources/translations/messages.cs.php`, `messages.en.php`; prefixes `module.translate.description`, `nav.translate`, `palette.translate.settings`, `perm.translate.*`, `perm.group.translate`, `translate.*` (`translate.settings.*`, `translate.flash.*`, `translate.widget.*`). `admin.dashboard.hint.translate` lives in core.

## Database migrations

| File | Purpose |
| --- | --- |
| `Database/Migrations/2026_09_05_000001_translate_cache.php` | `up()` is a no-op (table created by `SchemaTool`); `afterUp()` renames legacy `aitranslate_cache` → `translate_cache` and rewrites the `modules` registry row `aitranslate` → `translate` (version `2.0.0`), migrating from the previous module id. |

## Notes

- `TranslateService` and `MicrosoftTranslateProvider` are heavily instrumented with `bdump()` and `TranslateDebug::log()` (including a masked key prefix) on every call, including `isConfigured()`; this writes to `storage/logs/translate.log` in production as well.
- `needsTranslation()` is Cyrillic-only: Latin-script source languages (e.g. `de` → `cs`) are never sent to the API regardless of the configured `source_locale`.
- `batch()` writes one cache row per distinct source text; a unique-hash collision (same text translated twice concurrently) surfaces as a DB constraint error because there is no upsert.
- Provider exceptions carry Czech user-facing messages rather than translation keys.
- `translate.manage` protects mutations; the settings page itself only needs `translate.view` but displays the masked key state and log tail.
- `README.md` says v2.0.1 while the manifest is 2.1.0.
