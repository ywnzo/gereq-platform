# Testing

GEREQ uses PHPUnit 11 under a small **testing engine** (`Gereq\Testing`) that knows about core, per-module and per-integration targets. Tests stay unit-level and DB-free by default (`tests/bootstrap.php` points Doctrine at an in-memory SQLite connection), which keeps `composer test` fast enough to run before every commit. This page shows how targets are laid out, how to run one module, how to run the tooling inside Docker, and where coverage of module logic is tracked.

## Commands

| Command | Defined in | What it does |
| --- | --- | --- |
| `composer test` | `composer.json` → `php bin/gereq-test` | testing engine, default target `all` |
| `composer test -- core` | | core suite only |
| `composer test -- modules` | | every module (platform + shipped) |
| `composer test -- shop` / `module:shop` | | one module |
| `composer test -- --list` | | list targets (packages with tests) |
| `composer test:core` / `test:modules` / `test:file` | composer scripts | shortcuts |
| `composer test:phpunit` | → raw `phpunit` | bypass the engine; use `phpunit.xml` suites only |
| `php bin/gereq-test [target]` | `bin/gereq-test` | same engine **without** booting Application / DB |
| `php gereq test [target]` | `TestCommand` | same engine via the full console (needs a working install) |
| `make test` / `make test ARGS="core"` | Makefile | engine inside the PHP container (`--no-deps`) |
| `composer phpstan` | → `phpstan analyse --memory-limit=1G` | static analysis of `src/` and `modules/` at level 5 |
| `composer cs` / `composer cs-fix` | phpcs / phpcbf | PSR-12 on `src`, `modules`, `tests` |
| `php gereq nn` | `NnCommand` | N+1 query log — runtime check, not a test |

Tool versions come from `composer.json`: `phpunit/phpunit ^11.5`, `phpstan/phpstan ^2.1`, `squizlabs/php_codesniffer ^3.11`.

## Testing engine

Code lives in `src/Testing/`:

| Class | Role |
| --- | --- |
| `TestEngine` | facade: resolve target → run PHPUnit |
| `TestCatalog` | discovers paths on disk |
| `TestTarget` / `TestTargetKind` | named set of directories |
| `PhpUnitRunner` | spawns `vendor/bin/phpunit` with the right paths |
| `TestCase` | optional base (`extends` PHPUnit) |

### Targets

| Target | Meaning | Paths |
| --- | --- | --- |
| `all` (default) | everything the engine knows | core + file + modules + integrations |
| `core` | platform unit tests | `tests/Unit/*` except `Modules/` |
| `file` | GD / ImageConverter | `tests/File/` |
| `modules` | every module | every `modules/*/Tests/` + ModuleStructure |
| `module:<id>` or bare id (`shop`, `Shop`) | one module | `modules/<Dir>/Tests/` only |
| `integrations` | every integration | `integrations/*/Tests/` |
| `integration:<id>` | one integration | `integrations/<Dir>/Tests/` |

Options: `--list` / `-l`, `--all-packages` (list empty packages too), `--filter=…` / `-f`, `--stop-on-failure`, `--testdox`.

```bash
php bin/gereq-test --list
php bin/gereq-test core
php bin/gereq-test modules
php bin/gereq-test module:shop
php bin/gereq-test shop --filter=CartTest
composer test -- file --testdox
```

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

The engine passes **explicit paths** to PHPUnit for `modules/*/Tests`. Raw `vendor/bin/phpunit --testsuite Modules` only runs ModuleStructure checks — prefer the engine.

`tests/bootstrap.php` loads the Composer autoloader and sets `APP_ENV=testing`, `APP_DEBUG=true`, `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:` in `$_ENV`.

## Directory layout

| Path | Contents |
| --- | --- |
| `tests/Unit/` | core unit tests (suite `Core` / target `core`) |
| `tests/Unit/Api/`, `Http/`, `Support/`, `Webhook/`, `Testing/` | core sub-areas (incl. engine self-tests) |
| `tests/Unit/Modules/ModuleStructure/` | manifest/version consistency across all modules |
| `tests/File/` | `ImageConverterTest` (skips when GD/WebP/AVIF missing) |
| `tests/Integration/` | reserved for future HTTP/DB integration tests |
| `tests/Support/` | helpers, e.g. `PassthroughTranslator` |
| `modules/<Dir>/Tests/` | **canonical** module tests (every module has this directory) |
| `integrations/<Dir>/Tests/` | integration-plugin tests |

Namespaces: platform/core tests use PSR-4 `Gereq\Tests\` → `tests/`. Module tests use `Modules\<Name>\Tests\` (autoload `Modules\` → `modules/`).

## Writing tests

- Prefer pure unit tests for domain calculations and state transitions — see `docs/MODULE_AUDIT.md`. Avoid the container and the database.
- Use PHPUnit attributes (`#[DataProvider]`) — PHPUnit 11, no docblock annotations.
- When a service needs a translator, inject `Gereq\Tests\Support\PassthroughTranslator`.
- Tests are `final class … extends TestCase` with `declare(strict_types=1);` and must pass `phpcs`.
- **Module tests:** always in `modules/<Name>/Tests/` (scaffolded by `make:module`), namespace `Modules\<Name>\Tests`.
- Prefer `Gereq\Testing\TestCase` or `PHPUnit\Framework\TestCase`.

## Running inside Docker

```bash
make test
make test ARGS="core"
make test ARGS="shop --filter=CartTest"
make test ARGS="--list"

docker compose exec php composer test
docker compose exec php php bin/gereq-test modules
docker compose run --rm --no-deps php php bin/gereq-test module:viewings
```

`php gereq serve-docker` starts the stack; the PHP image includes `gd` (WebP+AVIF), `pdo_sqlite`, etc., so `ImageConverterTest` runs instead of being skipped.

## Coverage status

No coverage driver is configured in `phpunit.xml`; generate ad hoc with `XDEBUG_MODE=coverage vendor/bin/phpunit --coverage-text` if Xdebug or PCOV is installed. Qualitative coverage per module is in `docs/MODULE_AUDIT.md`. Notable gaps: HTTP-level tests and anything requiring a database (`tests/Integration` is empty).

Related: [Coding standards](coding-standards.md), [Creating a module](creating-a-module.md), [CLI](../getting-started/cli.md).
