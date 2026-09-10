# Architecture overview

GEREQ is a single PHP application built from a small core (`src/`, namespace `Gereq\`) and independently versioned modules (`modules/`, namespace `Modules\`) plus third-party adapters (`integrations/`, namespace `Integrations\`). The core owns bootstrap, HTTP/console kernels, DI, auth, the admin shell and cross-cutting services; modules contribute routes, entities, permissions, API endpoints, widgets and events through a single `ModuleContext` facade. This page explains bootstrap, the `src/` layout, the request lifecycle and the DI conventions.

## Bootstrap

Both entry points build the application the same way:

- `public/index.php` → `Application::create($basePath)->run()`
- `gereq` (CLI) → `Application::create($basePath)->runConsole()`

`Gereq\Foundation\Application::create()` (`src/Foundation/Application.php`):

1. `loadEnv()` — `Dotenv::createImmutable()->safeLoad()` if `.env` exists.
2. `loadConfig()` — `require`s every `config/*.php` into `Gereq\Config\Repository`.
3. Builds a PHP-DI `ContainerBuilder` with autowiring + attributes and the explicit `definitions()` map (factories for `EntityManagerInterface`, `CacheItemPoolInterface`, `MailerInterface`, `TranslatorInterface`, Twig `Environment`, `Queue`, `RateLimiter`, `SelfRepairSearch`, `FileManager`, `ModuleManager`, plus `basePath` constructor bindings for a few classes). Everything else is autowired.
4. `Gereq::set($app)` — static accessor used by the `gereq()` helper.
5. `CoreRegistrar::register()` (`src/Foundation/CoreRegistrar.php`) — registers the core's own publishables, topbar publish badge, permissions, settings definitions, validation rulesets, routes (`/admin/*`, auth, setup, API health/OpenAPI…), menu, command palette entries, admin search sources and dashboard widgets. It is effectively the "core module".
6. In debug mode, eagerly instantiates `DebugBar` so it can start collecting.
7. `ModuleManager::boot(ModuleContext)` — discovers `modules/*/module.php` and `integrations/*/module.php`, instantiates the `class` of every **enabled** module, calls `register()` on all of them and then `boot()` on all of them (see [module system](module-system.md#lifecycle)).
8. `ApiRegistry::bind(Router)` — materialises API route definitions collected from modules into real routes under `/api/v1` and `/api/public`.
9. `ResourceRegistrar::bindRoutes(Router)` — generates CRUD routes for `#[AdminResource]` entities.

```mermaid
flowchart TD
    A[public/index.php / gereq] --> B[Application::create]
    B --> C[.env + config/*.php]
    C --> D[PHP-DI container]
    D --> E[CoreRegistrar::register]
    E --> F[ModuleManager::boot\nregister() then boot() per enabled module]
    F --> G[ApiRegistry::bind + ResourceRegistrar::bindRoutes]
    G --> H{entry}
    H -->|HTTP| I[Kernel::handle]
    H -->|CLI| J[ConsoleKernel::run]
    I --> K[Router::match]
    K --> L[Middleware Pipeline]
    L --> M[ControllerDispatcher]
    M --> N[Controller → Twig / JSON Response]
    N --> O[Authenticator::applyCookies + DebugBar::inject]
```

## `src/` layout

| Namespace (`Gereq\…`) | Responsibility | Key classes |
| --- | --- | --- |
| `Admin` | admin shell: menu, command palette, dashboard, UI components, forms, `#[AdminResource]` CRUD, admin search, core admin controllers | `Admin\Menu\Menu`, `Admin\Dashboard\Dashboard`, `Admin\Ui\Ui`, `Admin\Form\Forms`, `Admin\Resource\ResourceRegistrar`, `Admin\Http\*Controller` |
| `Api` | REST API registry/DSL, auth middleware, tokens, rate limiting, OpenAPI, idempotency | `Api\ApiRegistry`, `Api\ApiModule`, `Api\Http\ApiAuthMiddleware`, `Api\Auth\ApiTokenService`, `Api\OpenApi\OpenApiGenerator` |
| `Ares` | Czech business register (ARES) lookup | `Ares\AresService`, `Ares\AresClient` |
| `Audit` | audit log entity + writer | `Audit\Auditor`, `Audit\AuditLog` |
| `Auth` | session authentication, remember-me, MFA (TOTP, passkeys) | `Auth\Authenticator`, `Auth\PasskeyService`, `Auth\TotpService` |
| `Automation` | workflow variable registry used by the automation editor | `Automation\WorkflowVariableRegistry` |
| `Brand` | brand settings → CSS variables / logo / favicon | `Brand\Branding` |
| `Cache` | PSR-6 pool factory + app cache facade | `Cache\CacheFactory`, `Cache\AppCache` |
| `Config` | dot-notation config repository | `Config\Repository` |
| `Console` | Symfony Console kernel and core commands, Docker log TUI | `Console\ConsoleKernel`, `Console\Command\*`, `Console\Docker\*` |
| `Database` | EntityManager factory, migrator, seeders, DQL functions, query caching connection | `Database\EntityManagerFactory`, `Database\Migrator`, `Database\Seeder`, `Database\DemoSeeder` |
| `Debug` | debug bar, query collector, N+1 detector, dumper, redaction | `Debug\DebugBar`, `Debug\NPlusOneDetector`, `Debug\Doctrine\*` |
| `Event` | in-process event bus and platform event registry/envelopes | `Event\EventBus`, `Event\EventRegistry`, `Event\EventEnvelope`, `Event\EventDefinition` |
| `File` | uploads via Flysystem, image conversion (AVIF/WebP) | `File\FileManager`, `File\ImageConverter`, `File\StoredFile` |
| `Foundation` | application bootstrap, static accessor, core registrations | `Foundation\Application`, `Foundation\Gereq`, `Foundation\CoreRegistrar` |
| `Hook` | WordPress-style actions & filters | `Hook\HookManager`, `Hook\HookPriority` |
| `Http` | kernel, router, routes, middleware pipeline, controller base, exception handler, sessions, CORS, trusted proxies, list query helper | `Http\Kernel`, `Http\Router`, `Http\Route`, `Http\Middleware\*`, `Http\Controller`, `Http\ExceptionHandler`, `Http\ListQuery` |
| `I18n` | locale catalog/resolver, Symfony Translator factory | `I18n\LocaleCatalog`, `I18n\LocaleResolver`, `I18n\TranslatorFactory` |
| `Module` | manifests, discovery, enable/disable, dependency checks, capabilities, GitLab versions, updater | `Module\ModuleManager`, `Module\ModuleContext`, `Module\ModuleManifest`, `Module\ModuleVersionActivator`, `Module\ModuleUpdater` |
| `Notification` | notification contracts and multi-channel notifier | `Notification\Notifier`, `Notification\Notification` |
| `Publish` | staging → production content pipeline, content version, publishable registry | `Publish\PublishService`, `Publish\ProductionFreeze`, `Publish\StagingStore`, `Publish\ContentVersion` |
| `Queue` | job queue with `sync` / `redis` / `file` drivers | `Queue\Queue`, `Queue\Job` |
| `Search` | typo/diacritics tolerant search over Doctrine query builders | `Search\SelfRepairSearch` |
| `Security` | CSRF, rate limiter, HTML sanitizer, CSP builder | `Security\Csrf`, `Security\RateLimiter`, `Security\HtmlSanitizer`, `Security\ContentSecurityPolicy` |
| `Setting` | DB-backed settings with definitions, types, encryption, staging overlay | `Setting\Settings`, `Setting\SettingRecord` |
| `Setup` | first-run wizard state | `Setup\SetupState` |
| `Support` | helpers (`gereq()`, `config()`, `__()`, …), `Str`, `Json`, `AppVersion`, `EnvFile`, `SimpleMarkdown` | `Support\helpers.php` |
| `User` | users, roles, permissions, permission registry | `User\Entity\User`, `User\Entity\Role`, `User\PermissionRegistry` |
| `Validation` | rule-based validator + named rulesets | `Validation\Validator`, `Validation\ValidationRegistry` |
| `View` | Twig factory/runtime extensions, Vite asset manager | `View\TwigFactory`, `View\TwigRuntime`, `View\AssetManager` |

Doctrine entity paths are `src/` plus every directory under `modules/` (`EntityManagerFactory::create()`); `integrations/` is **not** scanned for entities, which is consistent with the rule that integrations must not own domain tables (`integrations/README.md`).

## Request lifecycle

`Gereq\Http\Kernel::handle()` (`src/Http/Kernel.php`):

1. `TrustedProxies::configure($request)` (reads `TRUSTED_PROXIES`), push to `RequestStack`, debug-bar mark.
2. `dispatch()`:
   - `Router::match()` → `Route` + path parameters; stores `_route`, `_route_params`, `_gereq_route` request attributes.
   - Builds the middleware list:
     - always: `StartSessionMiddleware`, `SetLocaleMiddleware`, `SecurityHeadersMiddleware`, `CsrfMiddleware`, `CorrelationIdMiddleware`, `SetupMiddleware`, `ContentWorkspaceMiddleware`;
     - for `/api/*` paths: `ApiRateLimitMiddleware` and `Modules\Vitality\Http\Middleware\VitalityTimingMiddleware`;
     - for `/api/public/*` (`PublicCors::isPublicApiPath`): `PublicApiAuthMiddleware`;
     - route-level aliases from `Route::middleware()` — `auth`, `guest`, `csrf`, `api`, `api.rate`, `api.correlation` — or fully-qualified class names;
     - `PermissionMiddleware::withPermission()` when a non-API route has `->permission()`;
     - `ModuleCurrentMiddleware::forModule()` when the route belongs to a module (explicit `->module()` or inferred from the `admin.<module>.` route-name prefix).
   - `Pipeline::handle()` runs the middlewares, then `ControllerDispatcher::dispatch()`.
3. Exceptions: `ValidationException` → `FormState::respond()` (redirect back with `old()` / `error()` flash data); anything else → `ExceptionHandler::report()` + `render()` (HTML error page or JSON for API paths).
4. `Authenticator::applyCookies()` (remember-me / session cookies), `Connection: close`, `DebugBar::inject()`.

### Controllers

`ControllerDispatcher` (`src/Http/ControllerDispatcher.php`) resolves the handler: a closure `fn(Request, array $params)`, an invokable class name, or `[Class::class, 'method']`. Method arguments are injected by type (`Request`, any container service) or by name from route parameters, falling back to the parameter's default value. Return values must be a `Response` or a string (wrapped into a `Response`); anything else throws.

Controllers extend `Gereq\Http\Controller` (`src/Http/Controller.php`), which provides `render($view, $data, $status)` (Twig), `abort()`, `redirect($routeName, $params)`, `redirectTo($url)`, `redirectBack()`.

### Routing

`Router` (`src/Http/Router.php`): `get/post/put/patch/delete/add`, `group($prefix, fn(Router $r), ['middleware' => [...], 'permission' => '...', 'module' => '...'])`, `match()`, `url($name, $params)`, `absoluteUrl()`, `has()`, `all()`. `Route` (`src/Http/Route.php`) is fluent: `->name()`, `->middleware(...)`, `->permission()`, `->module()`, `->scope(...)` (API scopes). Path parameters use `{param}` placeholders.

```php
$router->group('/admin/viewings', function (Router $r): void {
    $r->get('', [ViewingController::class, 'index'])->name('admin.viewings.index');
    $r->post('/{id}/confirm', [ViewingController::class, 'confirm'])->name('admin.viewings.confirm')->permission('viewings.manage');
}, ['middleware' => ['auth'], 'module' => 'viewings']);
```

### Views

Twig is created by `View\TwigFactory` (`src/View/TwigFactory.php`): loader path `resources/views` plus a lower-cased namespace per module/integration directory (`@viewings/...` → `modules/Viewings/Resources/views`); cache `storage/cache/twig` unless `app.debug`. See [admin UI](admin-ui.md#twig-extensions) for the functions/filters.

## DI container conventions

| Access | When to use |
| --- | --- |
| constructor injection | default everywhere (controllers, services, commands, widgets); PHP-DI autowires by type |
| `$context->get(Service::class)` / `$context->container()` | inside a module's `register()`/`boot()` (`Gereq\Module\ModuleContext`) |
| `gereq()->make(Service::class)` / `gereq()->container()` | helpers and Twig runtime; `gereq()` returns `Gereq::app()` (`src/Foundation/Gereq.php`) |
| `config('key.path', $default)` | static config; runtime-editable values go through `Settings::get()` |
| `__()` / `trans()` | translator |
| `ui()`, `forms()`, `cache()`, `self_repair_search()` | shortcuts to `Admin\Ui\Ui`, `Admin\Form\Forms`, `Cache\AppCache`, `Search\SelfRepairSearch` |
| `dump()`, `bdump()` | debug-bar dumps (only useful with `APP_DEBUG=true`) |

Explicit bindings in `Application::definitions()` are limited to factories that need config or `basePath` and to interface → implementation aliases (`RateProviderInterface`, `TranslateProviderInterface`). Modules do **not** register their own container definitions; they rely on autowiring and, when necessary, on `Application::definitions()` (a few module classes with `basePath` parameters are bound there — see `src/Foundation/Application.php` lines ~229-235).

## Storage layout

| Path | Content |
| --- | --- |
| `storage/cache/{data,twig,doctrine}` | file cache pool, compiled Twig, Doctrine metadata/proxies |
| `storage/sessions` | file session driver |
| `storage/uploads/YYYY/MM` | `FileManager` local disk |
| `storage/tmp/queue` | `file` queue driver |
| `storage/modules/{id}/{version}` | downloaded module versions |
| `storage/logs/nplusone.log` | N+1 detector log |
| `storage/framework/phpunit` | PHPUnit cache |
| `public/build`, `public/hot` | Vite manifest / HMR marker |

Related: [module system](module-system.md), [auth & permissions](auth-permissions.md), [API](api.md), [admin UI](admin-ui.md).
