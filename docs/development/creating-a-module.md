# Creating a module

This guide walks through building a new GEREQ module from the generated skeleton to a versioned GitLab submodule: scaffolding with `php gereq make:module`, choosing what goes into `register()` vs `boot()`, adding permissions, routes/controllers/Twig views, an admin resource, API endpoints, events, dashboard widgets, translations, migrations and tests, and finally the release/version-bump checklist. Background on every extension point is in [Module system](../architecture/module-system.md); this page is the practical order of operations.

## 1. Scaffold

```bash
php gereq make:module Warehouse
```

`src/Console/Command/MakeModuleCommand.php` creates `modules/Warehouse/` with:

```text
modules/Warehouse/
  module.php                 # manifest (id 'warehouse', version 0.1.0, requires core ^1.0)
  WarehouseModule.php        # final class implementing ModuleInterface (register/boot stubs)
  Application/  Application/Widgets/
  Domain/  Infrastructure/  Http/  Admin/
  Database/Migrations/
  Resources/views/
  Tests/
```

Conventions that are not created for you but are discovered automatically when present:

| Path | Discovered by |
| --- | --- |
| `Resources/views/*.twig` | `src/View/TwigFactory.php` — registered as Twig namespace `@warehouse` (lowercased directory name) |
| `Resources/translations/messages.{cs,en}.php` | `src/I18n/TranslatorFactory.php` |
| `Resources/assets/{js,css}/*` | only when imported from `resources/js/admin.ts` (single Vite entry; see [Admin UI](../architecture/admin-ui.md#vite)) |
| `Database/Migrations/*.php` | `src/Database/Migrator.php` |
| `Console/*Command.php` | **not** auto-discovered — module commands are listed by class name in `src/Console/ConsoleKernel.php::commands()` (core file), e.g. `Modules\Currency\Console\SyncRatesCommand` |

Namespace: `Modules\Warehouse\…` (PSR-4 root `modules/` in `composer.json`). Integrations use `Integrations\…` under `integrations/` and set `'kind' => 'integration'` in the manifest (`integrations/README.md`).

## 2. Manifest

Edit `module.php` (fields are validated by `src/Module/ModuleManifest.php`):

```php
return [
    'id' => 'warehouse',
    'name' => 'Warehouse',
    'icon' => 'modules',            // key from src/Admin/Ui/Icons.php
    'version' => '0.1.0',           // == WarehouseModule::version() == git tag v0.1.0
    'description' => 'module.warehouse.description',
    'class' => Modules\Warehouse\WarehouseModule::class,
    'requires' => ['core' => '^1.0', 'products' => '^1.0'],
    'optional' => [],
    'provides' => [],
    'capabilities' => [],           // e.g. ['stock'] if other modules depend on it
    'permissions' => ['warehouse.view', 'warehouse.manage'],
    'repository' => 'gereq/module-warehouse',
];
```

The module is visible in Admin → Modules as soon as the manifest exists; it is inert until enabled.

## 3. `register()` vs `boot()`

`ModuleManager` (`src/Module/ModuleManager.php`) calls `register()` for every enabled module first, then `boot()` for every enabled module. Put declarations other modules or core may need in `register()`; put things that consume other modules' registrations in `boot()`.

| `register(ModuleContext $context)` | `boot(ModuleContext $context)` |
| --- | --- |
| DI bindings (`$context->container()`), permissions, settings definitions, routes + API, platform event definitions, publishables, capabilities, validation rulesets and form bindings, workflow variables | menu items, dashboard widgets, command palette entries, admin search sources, hooks/listeners on other modules' events |

```php
final class WarehouseModule implements ModuleInterface
{
    public function id(): string { return 'warehouse'; }
    public function version(): string { return '0.1.0'; }

    public function register(ModuleContext $context): void
    {
        $context->permissions()->register('warehouse.view', 'perm.warehouse.view', 'perm.group.warehouse');
        $context->permissions()->register('warehouse.manage', 'perm.warehouse.manage', 'perm.group.warehouse');

        $context->router()->group('/admin/warehouse', function (Router $routes): void {
            $routes->get('', [StockController::class, 'index'])->name('admin.warehouse.index')->permission('warehouse.view');
            $routes->post('/adjust', [StockController::class, 'adjust'])->name('admin.warehouse.adjust')->permission('warehouse.manage');
        }, ['middleware' => ['auth'], 'module' => 'warehouse']);
    }

    public function boot(ModuleContext $context): void
    {
        $context->menu()->add(new MenuItem(
            id: 'warehouse', label: 'nav.warehouse', icon: 'modules',
            route: 'admin.warehouse.index', permission: 'warehouse.view', order: 40,
        ));
        $context->dashboard()->addWidget(new LowStockWidget($context->get(StockRepository::class)));
    }
}
```

This mirrors `modules/Viewings/ViewingsModule.php`. Accessors available on `ModuleContext` (`src/Module/ModuleContext.php`): `container()`, `get()`, `hooks()`, `events()`, `platformEvents()`, `workflowVariables()`, `api()`, `router()`, `menu()`, `capabilities()`, `permissions()`, `settings()`, `dashboard()`, `commandPalette()`, `adminSearch()`, `resources()`, `config()`, `publishables()`, `validation()`, `forms()`.

## 4. Permissions

Declare each permission in `module.php` **and** register labels via `$context->permissions()->register(name, labelKey, groupKey)`; both label and group are translation keys (`perm.warehouse.view`, `perm.group.warehouse`). Permissions are synced into the DB on enable/update (`Gereq\User\PermissionRegistry`). Protect routes with `->permission('warehouse.manage')` and check in code with `$user->can('…')` / `current_user().can('…')` in Twig. Details: [Auth & permissions](../architecture/auth-permissions.md).

## 5. Routes, controllers, views

- Register routes in `register()` through `$context->router()->group(prefix, callback, options)`; options are `middleware` (list), `permission` and `module`. Admin routes need the `auth` middleware; setting `'module' => '<id>'` (as `modules/Calendar/CalendarModule.php` does) lets `ModuleCurrentMiddleware` block the UI while a version bump is pending. Public intake endpoints are registered with `$context->router()->post('/api/public/…')` plus an `OPTIONS` route for CORS (see Viewings).
- Controllers live in `Http/`; they receive dependencies via constructor injection (PHP-DI autowiring, e.g. `Twig\Environment $twig`) and return a `Response` or a string (`Kernel` wraps strings). Render with `$this->twig->render('@warehouse/stock/index.html.twig', [...])`.
- Views extend the admin shell: `{% extends 'admin/layout.html.twig' %}` and use the `ui_render()` components described in [Admin UI](../architecture/admin-ui.md). Never use `window.alert/confirm/prompt` — use `window.gereq.confirm` / `data-confirm` (`.cursor/skills/no-native-dialogs/SKILL.md`).
- Validate input with a named ruleset declared in `register()`: `$context->validation()->ruleset('warehouse.adjust', ['sku' => 'required|max:64', 'qty' => 'required|integer'])`, optionally bind it to an entity for form generation with `$context->forms()->entity('warehouse.adjust', StockItem::class)`, and call `$validator->validateRequest($request, 'warehouse.adjust')` in the controller.

### Admin resources (generated CRUD)

For simple entities use the attribute-driven CRUD instead of hand-written controllers:

```bash
php gereq make:resource StockItem --module=Warehouse
```

`src/Console/Command/MakeResourceCommand.php` writes `Domain/StockItem.php` with `#[ORM\Entity]`, `#[AdminResource(name: 'stockitems', label: …, permission: 'stockitems')]` and `#[AdminField(...)]` columns. `Gereq\Admin\Resource\ResourceRegistrar` binds list/create/edit/delete routes and forms for every `#[AdminResource]` entity found in enabled modules; field attributes are documented in `docs/FORMS.md`. Adjust the generated table name, permission prefix and labels (the generator uses `<name>s` and Czech default labels).

## 6. Domain and persistence

- Doctrine entities in `Domain/` with `#[ORM\Entity]`; UUIDv7 ids via `Ramsey\Uuid\Uuid::uuid7()` as in the generated entity.
- Never query another module's tables directly — depend on its provider/contract class or a capability (`README.md` "Moduly").
- Repositories/services in `Application/` or `Infrastructure/`; register non-autowirable ones in the container in `register()`.

## 7. API

Declare endpoints in `register()`:

```php
$context->api()->registerKnownScope('stock.read');
$context->api()->registerKnownScope('stock.write');
$context->api()->module('warehouse', function (ApiModule $api): void {
    // shorthand for index/store/show/update/destroy with <prefix>.read / <prefix>.write scopes
    $api->resource('stock', StockApiController::class, 'stock');
    // or explicit routes:
    $api->post('/stock/{id}/adjust', [StockApiController::class, 'adjust'])
        ->scope('stock.write')->ruleset('warehouse.adjust')->summary('Adjust stock level');
});
```

Routes are mounted under `/api/v1/warehouse/…`. Serialization is done in the controller (return arrays via `ApiResponse`), filtering/pagination via `ApiQuery`. See [API](../architecture/api.md) for `src/Api/ApiModule.php`, `ApiRouteDefinition` (`scope()`, `permission()`, `summary()`, `ruleset()`), scope naming and OpenAPI generation. Public read endpoints go through `FrozenPublicReader` when the data is publishable.

## 8. Events, hooks, webhooks

- Define events in `register()` so they appear in the platform catalogue and in the Webhooks module: `$context->platformEvents()->register((new EventDefinition('stock.adjusted', $this->id()))->description('…')->webhookEnabled(true))`.
- Emit from services/controllers with the injected `EventBus`: `$this->events->emit('stock.adjusted', ['type' => 'stock', 'id' => $id], $payload)` — the payload is a plain versioned array, never a Doctrine entity.
- React to other modules in `boot()` via `$context->hooks()->action('viewing.created', fn (...) => …)` or `filter()` for value transformation.
- Async work: implement `Gereq\Queue\Job` and dispatch with the injected `Gereq\Queue\Queue::dispatch($job)`.

Reference: [Hooks, events, webhooks](../architecture/hooks-events-webhooks.md).

## 9. Publishables

If the module exposes content on `/api/public/*`, register an adapter in `register()` (`$context->publishables()->register('warehouse', 'publish.category.warehouse', $adapter, $this->id())`) and route create/update/delete through `ProductionFreeze` as described in [Publishing](../architecture/publishing-i18n-settings.md#publishables-staging--production) and `.cursor/rules/publishables.mdc`.

## 10. Dashboard widgets and palette

Widgets extend `TwigDashboardWidget` in `Application/Widgets/` and are added in `boot()` with `$context->dashboard()->addWidget(...)`; command-palette entries via `$context->commandPalette()->add(new PaletteCommand(...))` (see `modules/CRM/CrmModule.php`); admin search sources via `$context->adminSearch()->add(...)` (see `modules/Fleet/FleetModule.php`). Templates and schemas: [Dashboard widgets](../architecture/dashboard-widgets.md).

## 11. Translations

Create `Resources/translations/messages.cs.php` and `messages.en.php` returning flat arrays. Every label passed to menu/palette/permissions/settings/manifest description is a key. Keep both locales complete (checklist item "i18n cs + en"); `php tools/gereq-i18n/index-translations.php` refreshes the editor index.

## 12. Migrations

Files in `Database/Migrations/` named `YYYY_MM_DD_NNNNNN_name.php` returning an anonymous class with `up(Schema $schema)` and `down(Schema $schema)` (Doctrine DBAL `Schema` API), e.g. `modules/Viewings/Database/Migrations/2026_09_06_000001_viewings.php`. `Migrator` records them as `<moduleId>:<basename>` in the `migrations` table. They run on `php gereq migrate`, on enable, and on "Update" in Admin → Modules. `down()` is mandatory because version downgrades roll back migrations missing from the target version (`Migrator::rollbackMigrationsMissingFrom()`). Never alter schema outside migrations.

## 13. Tests

Module unit tests live in `modules/<Module>/Tests/` (namespace `Modules\<Module>\Tests`). The testing engine discovers them: `composer test -- module:<id>` or `php bin/gereq-test <id>`. Prefer pure unit tests of domain/application logic (no DB). `tests/Unit/Modules/ModuleStructure/ModuleManifestConsistencyTest.php` asserts that every `module.php` version equals `Module::version()`. See [Testing](testing.md).

## 14. Enable locally

```bash
php gereq migrate
php gereq module:enable warehouse     # or Admin → Modules → Enable
```

Enabling checks `requires`, runs pending migrations and syncs permissions (`ModuleManager::enable()`).

## 15. GitLab repository and submodule

Each module is its own private GitLab project `git.gereq.cz/gereq/module-<id>`, pinned in the platform repo as a submodule (`README.md` "Nový modul", step 2):

```bash
cd modules/Warehouse
git init -b main
git add -A
git commit -m "chore: initial Warehouse v0.1.0"
git tag -a v0.1.0 -m "Release v0.1.0"
git remote add origin git@git.gereq.cz:gereq/module-warehouse.git
git push -u origin main --tags
cd ../..
git submodule add git@git.gereq.cz:gereq/module-warehouse.git modules/Warehouse
# normalise .gitmodules to a relative URL (CI inherits CI_REPOSITORY_URL / job token):
#   url = ../module-warehouse.git
git add .gitmodules modules/Warehouse
git commit -m "chore: add Warehouse submodule @ v0.1.0"
```

Allow platform CI to clone the new private module (once per module project):

- UI: module project → **Settings → CI/CD → Job token permissions** → authorize `gereq/GEREQ_PLATFORM`, or
- API: `GITLAB_TOKEN=… bash tools/gitlab-allow-platform-job-token.sh` (re-runs over all `module-*`, skips already allowed).

Code changes are committed in the module repo; the platform repo only moves the submodule pin. `./tools/gereq-modules/gereq-modules.sh` (TUI, `--plain` for text) compares local versions with GitLab tags. With `GITLAB_TOKEN` set, Admin → Modules → Versions can download and activate tags (`storage/modules/{id}/{version}`, see [Module system](../architecture/module-system.md#install-and-upgrade-from-gitlab)).

## 16. Releasing a change (version bump checklist)

From `.cursor/rules/module-updates.mdc` and `README.md` "Checklist nového modulu":

Repo / version

- [ ] Bump `version` in `module.php`; same value in `<Id>Module::version()`; git tag `vX.Y.Z` (semver: patch = fix, minor = feature/migration, major = breaking).
- [ ] Private GitLab project `gereq/module-<id>`, wired as submodule under `modules/<Dir>`, `.gitmodules` URL relative (`../module-<id>.git`).
- [ ] Module project allows inbound CI job tokens from `gereq/GEREQ_PLATFORM` (Job token permissions / `tools/gitlab-allow-platform-job-token.sh`).

Product

- [ ] Domain + admin UI; permissions declared and synced on enable/update.
- [ ] Hooks where extension makes sense; audit log entries for critical mutations.

API / events

- [ ] Public API endpoints under `/api/v1/…` when data leaves the module; scopes `resource.read` / `resource.write`; serializers and whitelisted filters.
- [ ] Domain/platform events (`resource.action`) registered so webhooks can subscribe; versioned payloads (no Doctrine entities); idempotency for financial/critical POSTs; queue jobs for async work.

Publishables (when touching `/api/public` or CRM content)

- [ ] `register()` → `publishables()->register(…)`; create/update/delete through staging, never hard-deleting public content.

Quality

- [ ] Schema changes only through `Database/Migrations/`.
- [ ] Tests (unit/feature according to scope); `composer test`, `composer phpstan`, `composer cs`.
- [ ] Translations complete in cs + en.
- [ ] `./tools/gereq-modules/gereq-modules.sh` shows the module OK on GitLab.

After the bump the platform blocks the module's admin UI until an admin clicks **Update** (`ModuleCurrentMiddleware`), which runs pending migrations and re-syncs permissions — do not rely on `php gereq migrate` alone in production.
