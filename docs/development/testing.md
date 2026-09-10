# Testing

GEREQ uses PHPUnit 11 for automated tests, PHPStan (level 5) for static analysis and PHP_CodeSniffer (PSR-12) for style. Tests are unit-level and DB-free by default (`tests/bootstrap.php` points Doctrine at an in-memory SQLite connection), which keeps `composer test` fast enough to run before every commit. This page shows how the suites are laid out, how to run one test, how to run the tooling inside the Docker stack, and where the current coverage of module logic is tracked.

## Commands

| Command | Defined in | What it does |
| --- | --- | --- |
| `composer test` | `composer.json` scripts → `phpunit` | runs all suites from `phpunit.xml` |
| `composer phpstan` | → `phpstan analyse --memory-limit=1G` | static analysis of `src/` and `modules/` at level 5 (`phpstan.neon`) |
| `composer cs` | → `phpcs` | PSR-12 check on `src`, `modules`, `tests` (`phpcs.xml`) |
| `composer cs-fix` | → `phpcbf` | auto-fix style violations |
| `vendor/bin/phpunit --testsuite Core` | | single suite (see below) |
| `php gereq nn` | `src/Console/Command/NnCommand.php` | inspects the N+1 query log collected by the debug bar — a runtime check, not a test (see [CLI](../getting-started/cli.md)) |

Tool versions come from `composer.json`: `phpunit/phpunit ^11.5`, `phpstan/phpstan ^2.1`, `squizlabs/php_codesniffer ^3.11`.

## `phpunit.xml`

```xml
<phpunit bootstrap="tests/bootstrap.php" colors="true" cacheDirectory="storage/framework/phpunit">
  <testsuites>
    <testsuite name="Core">    <directory>tests/Unit</directory> <exclude>tests/Unit/Modules</exclude> </testsuite>
    <testsuite name="Modules"> <directory>tests/Unit/Modules</directory> </testsuite>
    <testsuite name="File">    <directory>tests/File</directory> </testsuite>
  </testsuites>
</phpunit>
```

`tests/bootstrap.php` loads the Composer autoloader and sets `APP_ENV=testing`, `APP_DEBUG=true`, `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:` in `$_ENV`, so any test that boots services through `Application` gets an isolated in-memory database and never touches the configured PostgreSQL/MySQL instance.

## Directory layout

| Path | Contents |
| --- | --- |
| `tests/Unit/` | core unit tests, one file per component: `HookManagerTest`, `EventBusTest`, `ValidatorTest`, `SettingsCacheTest`, `SelfRepairSearchTest`, `ModuleManifestTest`, `CapabilityRegistryTest`, `PermissionTest`, `FormsTest`, `FormStateTest`, `MenuBreadcrumbsTest`, `ConfigRepositoryTest`, `AppCacheTest`, `AppVersionTest`, `AbortHelperTest`, `ExceptionHandlerTest`, `DebugBarTest`, `NPlusOneLogTest`, `PdfRendererTest`, `SimpleInvoicePdfTest`, `StoreCookieBridgeTest` |
| `tests/Unit/Api/` | `ApiQueryTest`, `ApiAuthSessionAllowsTest`, `PublicApiAuthMiddlewareTest`, `OpenApiGeneratorTest`, `ValidationRulesToSchemaTest` |
| `tests/Unit/Http/` | `ListQueryTest` |
| `tests/Unit/Support/` | `SimpleMarkdownTest` |
| `tests/Unit/Webhook/` | `WebhookSecurityTest` (SSRF guard, signing) |
| `tests/Unit/Modules/<Module>/` | per-module unit tests (Ai, BikeService, Calendar, CarService, Coupons, CRM, Currency, ESign, Feeds, Listings, Loyalty, Media, Orders, Payments, Portals, ProductDiscounts, Products, Returns, Reviews, Shop, Viewings, Vitality, Warehouse, Webhooks, WebPlus) — suite `Modules` |
| `tests/Unit/Modules/ModuleStructure/` | `ModuleManifestConsistencyTest` — data-provider over every `modules/*/module.php`, asserts the manifest `version` equals `Module::version()` and the class exists |
| `tests/File/` | `ImageConverterTest` — exercises `src/File/ImageConverter.php` with real GD encoding; skips itself when `ext-gd` or its WebP/AVIF support is missing |
| `tests/Integration/` | present but empty — there are currently no HTTP/DB integration tests |
| `tests/Support/` | helpers, e.g. `PassthroughTranslator` (returns keys unchanged so assertions do not depend on catalogs) |
| `modules/<Dir>/Tests/` | created by `make:module` for tests that ship with the module repo; not included in `phpunit.xml` |

Namespaces follow PSR-4 `Gereq\Tests\` → `tests/` (`composer.json` `autoload-dev`), e.g. `Gereq\Tests\Unit\Modules\Viewings\ViewingTest`.

## Running a single test

```bash
vendor/bin/phpunit tests/Unit/HookManagerTest.php              # one file
vendor/bin/phpunit --filter testModuleManifestAndVersionAlign   # by method name
vendor/bin/phpunit --filter 'ViewingTest::testTransition'       # class::method
vendor/bin/phpunit --testsuite Modules                          # one suite
vendor/bin/phpunit tests/Unit/Modules/Listings                  # one directory
```

PHPStan on a subset: `vendor/bin/phpstan analyse modules/Viewings --memory-limit=1G`. phpcs on a file: `vendor/bin/phpcs src/Http/Kernel.php`.

## Writing tests

- Prefer pure unit tests for domain calculations and state transitions (money, discounts, status machines) — this is the pattern recommended in `docs/MODULE_AUDIT.md` and used by the existing module tests. Avoid the container and the database.
- Use PHPUnit attributes (`#[DataProvider]`) rather than docblock annotations — the codebase has no `@dataProvider`/`@test` annotations (PHPUnit 11).
- When a service needs a translator, inject `Gereq\Tests\Support\PassthroughTranslator`.
- Tests are `final class … extends TestCase` with `declare(strict_types=1);` like the rest of the codebase and must pass `phpcs` (the `tests` directory is in `phpcs.xml`).
- Add module tests under `tests/Unit/Modules/<Module>/` so they run in CI with the `Modules` suite; mirror the module's namespace layout.

## Running inside Docker

The Compose project is named `gereq` (`docker-compose.yml`), the PHP service is `php`, and the repository is mounted at `/var/www/html`. Either address the container directly or use Compose:

```bash
docker exec gereq-php-1 composer test
docker exec gereq-php-1 vendor/bin/phpunit --filter ViewingTest
docker exec gereq-php-1 composer phpstan
docker exec gereq-php-1 composer cs

# equivalent via Compose (works regardless of container naming)
docker compose exec php composer test

# one-off container without the stack running
docker compose run --rm php composer test
make gereq ARGS="migrate --seed"     # Makefile wrapper for php gereq in a throwaway container
```

`php gereq serve-docker` (`src/Console/Command/ServeDockerCommand.php`) starts the same stack for development; while it is running, `docker exec gereq-php-1 …` is the quickest way to run tests against the container's PHP build (`docker/php/Dockerfile` builds `gd` with WebP+AVIF, `pdo_sqlite`, `pdo_mysql`, `pdo_pgsql`, `opcache`, `zip` and `redis`, so `ImageConverterTest` runs instead of being skipped).

## Coverage status

There is no coverage driver configured in `phpunit.xml`; generate a report ad hoc with `XDEBUG_MODE=coverage vendor/bin/phpunit --coverage-text` if Xdebug or PCOV is installed. Qualitative coverage per module — which classes are tested, which business rules are still untested and where the risks are (float money maths, anaemic entities, I/O mixed into services) — is maintained in `docs/MODULE_AUDIT.md` ("Katalog modulů" table and "Doporučené další kroky"). Core components with tests are listed in the layout table above; notable gaps are HTTP-level tests (routing, middleware pipeline, CSRF) and anything requiring a database (`tests/Integration` is empty).

Related: [Coding standards](coding-standards.md), [Creating a module](creating-a-module.md), [CLI](../getting-started/cli.md).
