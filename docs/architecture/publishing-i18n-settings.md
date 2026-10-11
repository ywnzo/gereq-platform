# Publishing, settings, i18n and supporting services

This page collects the cross-cutting core services that modules use daily: the staging → production **publishing** pipeline (`src/Publish`), DB-backed **settings** (`src/Setting`), **i18n** (`src/I18n`), the **audit log** (`src/Audit`), **files** (`src/File`), **cache** (`src/Cache`), **search** (`src/Search`), **validation** (`src/Validation`), **branding** (`src/Brand`), the **ARES** client (`src/Ares`) and **automation** variables (`src/Automation`). Each section names the entry points and the conventions modules must follow.

## Publishables (staging → production)

Rule: `.cursor/rules/publishables.mdc` — everything an admin edits that is visible through `/api/public/*` (listings, brokers, cities, CMS pages/menus, products, branding, customers) goes through the publish pipeline; runtime intake endpoints (viewing requests, checkout, uploads) and auth/payments stay live.

### Concepts

| Class | Role |
| --- | --- |
| `Gereq\Publish\ContentReadContext` (`src/Publish/ContentReadContext.php`) | per-request mode `staging` / `production`; `ContentWorkspaceMiddleware` (`src/Http/Middleware/ContentWorkspaceMiddleware.php`) sets `useProduction()` for `/api/public*` and `useStaging()` for everything else; `withProduction(callable)` for temporary switches |
| `PublishableRegistry` | `register(category, label, adapter, module)`, `has()`, `get()`, `adapter()`, `isSettingsKey(key)`, `categoryForSettingsKey()`, `all()`, `categories()`, `forModule()` |
| `Publishing` / `PublishingCategoryBuilder` | fluent module API: `$context->publishing($moduleId)->category(…)->labelKey(…)->sort(…)->adapter(Class)` |
| `PublishableAdapterInterface` | per category: `settingsKeys()`, `snapshot()`, `apply()`, `discard()`, `describe()` |
| `StagingStore` | persistence of `ContentStagingEntry` rows; bumps `StagingSignal` on upsert/remove |
| `StagingSignal` | request-scoped revision counter for live admin badge refresh |
| `StagingWriter` | `stageSetting()`, `unstageSetting()`, `stageResource()` — used by `Settings` and adapters |
| `ProductionFreeze` | module-facing mutate API: `freezeIfNeeded`, `stageDelete`, `stageCreate`, … |
| `FrozenPublicReader` | read side for public controllers |
| `PublishService` | `pending()`, `publish()`, `discard()`, `entry()`, `releases()` |
| `ContentVersion` | setting `app.content_version` (default `0.1.0`): letter bump ≤5 changes, else patch |

Core adapters only: `src/Publish/Adapter/{Branding,Customer}PublishAdapter.php`. Module adapters live in `modules/*/Publish/` (Listings, Cms, Products, Forum, …). Admin workspace: `/admin/content/staging`. Topbar badge refreshes via `X-Gereq-Staging-Count` and `publish:pending-changed`.

### Flow

```mermaid
flowchart LR
    A[Admin edits entity] --> F[ProductionFreeze::freezeIfNeeded\nsnapshot of production]
    F --> S[(content_staging)]
    P[/api/public/*] --> R[FrozenPublicReader → snapshot or live row]
    S --> B[Topbar badge: pending count]
    B --> U[Admin → Publish pending → select]
    U --> PS[PublishService::publish]
    PS --> AD[adapter->apply per entry]
    PS --> V[ContentVersion::bump → app.content_version]
    PS --> REL[(content_releases)]
```

Patterns for a module (from the rule):

1. **Settings keys** — list them in `settingsKeys()`; `Settings::set()` stages automatically when the value differs from production.
2. **Update** — before mutating public content call `freezeIfNeeded()` with a closure returning the production snapshot; admin keeps editing the live row, public serves the snapshot; `apply(update)` usually just drops the freeze.
3. **Delete** — `stageDelete()` keeps the row; admin lists hide pending deletes (`pendingDeleteKeys()`); `apply(delete)` hard-deletes.
4. **Create → public** — `stageCreate()` so public excludes the resource until publish; drafts may stay live.

Admin routes: `admin.publish.pending`, `admin.publish.run`, `admin.publish.releases` (`src/Publish/Http`). The topbar badge is described in [admin UI](admin-ui.md#topbar-publish-badge).

## Settings

`Gereq\Setting\Settings` (`src/Setting/Settings.php`), entity `SettingRecord` (`key`, `value` text, `type`, `module`).

| Method | Behaviour |
| --- | --- |
| `define(key, type, label, default, module, options, global = true)` | declare a setting (core in `CoreRegistrar::settings()`, modules in `register()`); `global=false` hides it from Admin → Settings (module renders its own UI) |
| `get(key, default)` | staged value if the request is in staging mode and the key is a publishable settings key; else `getLive()` |
| `getLive(key, default)` | DB value → definition default → `config()` fallback |
| `set(key, value, type, module)` | stages when publishable + differs from production (unstages when equal); otherwise `setLive()` |
| `setLive(key, value, type, module)` | upsert, flush, refresh cache |
| `has()`, `typeOf()`, `definitions()`, `globalDefinitions()` | |

Types (`SettingRecord::TYPE_*`): `string`, `boolean` (`'1'`/`'0'`), `integer`, `json`, `encrypted` (AES-256-GCM with `sha256(APP_KEY)`, payload `gcm:<base64 iv|tag|cipher>`; legacy AES-CBC values are still decrypted). All rows are loaded with one `SELECT` per request (`warm()`).

Core-defined keys: `app.name`, `app.locale`, `admin.theme` (`system|light|dark`), `admin.dashboard.layout` (json), `app.content_version`, and the `brand.*` group (below). Modules define their own with a `module` id so they can be listed/reset per module.

## i18n

- Locales: `config/i18n.php` (`fallback_locale = cs`, `locales = {cs, en}`) → `Gereq\I18n\LocaleCatalog` (`all()`, `codes()`, `isSupported()`, `label()`, `fallback()`).
- Resolution: `LocaleResolver::resolve(user)` — user's `locale` → setting `app.locale` → `config('app.locale')` → fallback; applied by `SetLocaleMiddleware`.
- Translator: `TranslatorFactory::create()` (`src/I18n/TranslatorFactory.php`) builds a Symfony `Translator` with the PHP array loader and loads `resources/translations/*.php` plus `modules/*/Resources/translations/*.php` and `integrations/*/Resources/translations/*.php`. File name pattern `<domain>.<locale>.php` → `messages.cs.php`, `messages.en.php`. Files return `['key' => 'text']`.
- Usage: PHP `__('nav.viewings')` / `trans()` / injected `TranslatorInterface`; Twig `'key'|trans` or `trans('key', {'%n%': 3})`. Labels in `MenuItem`, `PaletteCommand`, permissions, settings definitions and manifests are translation keys resolved at render time.
- Key conventions (observed): `nav.*`, `palette.*`, `perm.<module>.<action>` + `perm.group.<module>`, `module.<id>.description`, `<module>.field.*`, `<module>.status.*`, `validation.*`, `admin.*`, `settings.*`, `errors.<status>.*`. Some legacy Czech literal keys (`'Akce'`, `'Hlavní'`) exist for menu groups.
- Tooling: `php tools/gereq-i18n/index-translations.php` writes `.cache/i18n/catalog.json`; `bash tools/gereq-i18n/install.sh` installs the VS Code/Cursor extension providing hover (cs/en side by side), completion and go-to-definition for keys in Twig and PHP (`tools/gereq-i18n/README.md`).

## Audit log

`Gereq\Audit\Auditor::log(action, resource, resourceId, oldValues, newValues, userId, userName, flush = true)` (`src/Audit/Auditor.php`) writes an `AuditLog` row (`src/Audit/AuditLog.php`: action, user id/name, resource, resource id, old/new JSON, IP, user agent, created at). The user defaults to the session's `auth.user_id`. Core logs `module.enabled|disabled|updated|version_activated`, publish runs and user/role changes; modules should log business actions with dotted names (`listing.published`). Viewer: `/admin/audit` (`audit.view`); Twig `audit_action` / `audit_action_tone` map action names to labels and colours.

## Files

`Gereq\File\FileManager` (`src/File/FileManager.php`):

- `store(UploadedFile, ?User): StoredFile` — enforces `files.max_size` and `files.allowed_mimes`, optionally converts images (`files.image_convert.enabled`) via `ImageConverter` (`src/File/ImageConverter.php`: GD → AVIF when supported, else WebP; quality from config; skips AVIF re-encoding), stores under `storage/uploads/YYYY/MM/<random>.<ext>` on a Flysystem `LocalFilesystemAdapter`, persists a `StoredFile` entity (`path`, `originalName`, `mime`, `size`, `disk`, `uploadedBy`, `metadata` with width/height).
- `filesystem()` exposes the Flysystem instance; `converter()` the converter.
- Drivers: `config/files.php` `driver` (`FILE_DRIVER`) is recorded on the entity and `s3.*` keys exist, but the manager currently instantiates only the local adapter — S3 support requires code, not just config.
- Media (galleries, thumbnails, `/media/{id}/file` with `CACHE_MEDIA_TTL`) is the `media` module built on top of this.

## Cache

- `Gereq\Cache\CacheFactory::create()` (`src/Cache/CacheFactory.php`) → PSR-6 pool by `cache.driver`: `file` (`storage/cache/data`), `redis`, `database`/`db` (Doctrine DBAL adapter, table `cache.database.table`, auto-created), `array`.
- `Gereq\Cache\AppCache` (`src/Cache/AppCache.php`, helper `cache()`): `get(key, default)`, `put(key, value, ttl)`, `remember(key, callable, ttl)`, `forget(key)`, `flush()`, `pool()`. Default TTL `cache.default_ttl`.
- The same pool backs Doctrine's result cache when `cache.doctrine_result` is on (`EntityManagerFactory`), and `CachingConnection` (`src/Database/CachingConnection.php`) caches selected queries.
- `php gereq cache:clear` wipes `storage/cache` (file pool, Twig, Doctrine metadata); for Redis/DB pools call `cache()->flush()`.

## Search

`Gereq\Search\SelfRepairSearch::apply(QueryBuilder $qb, string $alias, array $fields, string $query)` (`src/Search/SelfRepairSearch.php`; helper `self_repair_search()`): splits the query into tokens (AND), and for each token adds an OR across `fields` using case/diacritics-insensitive `LIKE` (custom DQL `REPLACE` function in `src/Database/Dql/ReplaceFunction.php`) plus generated typo variants (transpositions, dropped/duplicated letters) limited by `search.typo_max_variants`; tokens shorter than `search.min_token_length` are matched as prefixes only. Used by admin lists, the admin search registry and public listing search. `config/search.php` `driver` is reserved for future external engines; only `self_repair` exists.

## Validation

- `Gereq\Validation\ValidationRegistry` (`src/Validation/ValidationRegistry.php`; `$context->validation()`): `ruleset(name, rules, after)`, `rule(name, callable)` (custom rule), `after(ruleset, callable)` (post-validation hook), `has()`, `rulesFor()`, `validate(name, data, context)`.
- `Gereq\Validation\Validator` (`src/Validation/Validator.php`): `validateRequest(Request, string|array $rules, context)` and `validate(data, rules, context, form)`; throws `ValidationException` (rendered by `Kernel` as redirect-back with errors or JSON 422).
- Rule syntax is Laravel-like `'field' => 'required|email|max:190'`. Built-ins: `nullable`, `required`, `filled`, `email`, `phone` (Czech `+420…` normalisation), `url`, `numeric`, `integer`, `boolean`, `accepted`, `min`, `max`, `between`, `in`, `not_in`, `confirmed`, `same`, `different`, `regex`, `date`, `alpha`, `alpha_dash`, `json`; anything else resolves to a custom rule registered with `rule()`.
- Hooks: `validation.ruleset` filter (modify rules of a named ruleset), `validation.errors` filter. Messages are translation keys `validation.<rule>`.
- The same rulesets drive admin forms (`docs/FORMS.md`, `field_required()`), API validation and OpenAPI schemas ([API](api.md#openapi)).

## Brand

`Gereq\Brand\Branding` (`src/Brand/Branding.php`) reads the `brand.*` settings defined by core: `brand.name`, `brand.subtitle`, `brand.logo_url`, `brand.favicon_url`, `brand.color_signal`, `brand.color_ink`, `brand.color_cream`, `brand.site_primary`, `brand.site_name`, `brand.site_mark`, `brand.tagline`, `brand.footnote`, `brand.custom_css`.

| Method | Use |
| --- | --- |
| `resolve()` | normalised array with defaults (`DEFAULT_SIGNAL` etc.) |
| `toPublic()` | payload for `GET /api/public/branding` |
| `adminCssVariables()`, `htmlStyleAttribute()` | CSS custom properties for the admin (`brand_css()`, `brand_html_style()` Twig) |
| `customCssAdmin()`, `customCssPublic()` | sanitised custom CSS |
| `documentedVariables()` | list shown in the settings help |

Logo/favicon uploads are served by `Gereq\Http\BrandAssetController` (`brand.asset` route); branding changes are publishable (`BrandingPublishAdapter`). Design tokens for the compiled CSS live in `resources/brand/tokens.css`.

## ARES

`Gereq\Ares\AresClient` (`src/Ares/AresClient.php`) calls `https://ares.gov.cz/ekonomicke-subjekty-v-be/rest` (v3); `Gereq\Ares\AresService` (`src/Ares/AresService.php`) exposes `searchSubjects(query, limit)` (company by name/IČO → name, IČO, DIČ, address) and `searchAddresses(query, limit)`. Admin endpoints `admin.ares.subjects` / `admin.ares.addresses` back the `registerAres` Alpine suggest components (`resources/views/ui/ares_*_suggest.html.twig`); `GET /api/public/ares/addresses` exposes address suggestions to public forms.

## Automation

`Gereq\Automation\WorkflowVariableRegistry` (`src/Automation/WorkflowVariableRegistry.php`; `$context->workflowVariables()`) collects `WorkflowVariableDefinition(path, module, label, description, group = 'general', example)` objects: `register()`, `registerMany()`, `get(path)`, `has()`, `all()`, `grouped()`, `toArray()`. Modules (e.g. `modules/CRM/CrmModule.php`, `src/Customers/CustomersModule.php`) register the placeholders their entities expose (`customer.first_name`, `deal.value`) so the CRM workflow editor (`modules/CRM`, Alpine `registerCrmWorkflowCanvas`/`registerCrmWorkflowTplField`) can offer autocomplete; runtime substitution happens in the CRM `WorkflowRunner`, not in core.
