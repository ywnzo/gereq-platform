# Coding standards

This page lists the conventions enforced (by tooling or by rule files) in the GEREQ / RealEstates codebase: PHP style and static analysis, TypeScript/Bun rules, Twig conventions, translation keys, and the commit/versioning rules that come from `.cursor/rules/*.mdc` and `.cursor/skills/*`. Where a rule is enforced by a tool, the command to run it is given; where it is a convention, the source file that states it is cited.

## PHP

| Rule | Enforced by |
| --- | --- |
| `declare(strict_types=1);` at the top of every file | `phpcs.xml` → `Generic.PHP.RequireStrictTypes` (+ convention for non-scanned paths) |
| PSR-12 formatting | `phpcs.xml` (ruleset PSR12, paths `src`, `modules`, `tests`, `bin/gereq-test`) — `composer cs`, autofix `composer cs-fix` (phpcbf) |
| PHPStan level 5 on `src` + `modules` + `tests` | `phpstan.neon` + `phpstan-baseline.neon` + `phpstan/phpstan-phpunit` — `composer phpstan` |
| PHPStan level 6 + strict-rules on testing surface | `phpstan-tests.neon` (`src/Testing`, `tests`) — `composer phpstan:tests` |
| Test namespaces / `final` test classes / strict_types | `CodingConventionsTest` under ModuleStructure; Cursor rule `.cursor/rules/php-testing-conventions.mdc` |
| Classes are `final` unless designed for extension | convention — ~218 of the core classes are `final`; the only `abstract` base classes are `Gereq\Http\Controller` and `Gereq\Admin\Dashboard\TwigDashboardWidget`. Doctrine entities are non-final (proxies) |
| Constructor promotion + `readonly` for dependencies; one class per file; PSR-4 (`Gereq\` → `src/`, `Modules\` → `modules/`, `Integrations\` → `integrations/`, `Gereq\Tests\` → `tests/`) | `composer.json` autoload |
| Return types and parameter types everywhere; array shapes via PHPDoc (`@return list<...>`, `array<string, mixed>`) so PHPStan can check them | PHPStan |
| Console commands use `#[AsCommand(name:, description:)]` | `src/Console/Command/*` |
| Comments, docblocks, TODO/FIXME, CLI help strings and Makefile `##` help are **English only** | `.cursor/skills/english-code-comments/SKILL.md` |
| No narrating comments (`// increment counter`); comment only intent, trade-offs, constraints | project instruction; see existing code |
| Never talk to another module's tables — use its provider/contract or a capability | `README.md` "Moduly" |
| Schema changes only through `Database/Migrations/` with `up()` **and** `down()` | `.cursor/rules/module-updates.mdc`, `src/Database/Migrator.php` |
| Input validation through named rulesets (`ValidationRegistry`) rather than ad-hoc checks | `src/Validation` |
| Public content mutations go through `ProductionFreeze` | `.cursor/rules/publishables.mdc` |
| Business-critical mutations write an audit entry | README checklist ("Audit integration") |

Translation catalogs, UI copy and seed content stay in the product language (Czech); the English-only rule is for developer notes. A number of legacy Czech strings still appear in CLI output (`MakeModuleCommand`: "Modul už existuje.") — treat them as candidates for cleanup, not as a pattern to follow.

Run everything before committing:

```bash
composer test && composer analyse
```

## TypeScript / frontend

| Rule | Source |
| --- | --- |
| **Bun only**: `bun install`, `bun run build`, `bun run dev`, `bun x <tool>` — never `npm run` / `npx` | `.cursor/rules/tech-stack.mdc` (always applied) |
| `tsconfig.json` has `"strict": true`; TS sources live in `resources/js/**` and `modules/*/Resources/assets/js/**` | `tsconfig.json` |
| Alpine components are registered through `register*` functions exported from `resources/js/ui/*.ts` and wired in `resources/js/ui/index.ts` (CSP build of Alpine — no inline JS expressions) | `resources/js/ui/index.ts`, `package.json` (`@alpinejs/csp`) |
| **No native dialogs**: never `window.alert` / `window.confirm` / `window.prompt`; use `window.gereq.confirm(...)`, `window.gereq.prompt(...)` (`resources/js/ui/confirm.ts`) or `data-confirm` on forms | `.cursor/skills/no-native-dialogs/SKILL.md` |
| Bun kernel entry `resources/js/admin.ts` only; module CSS/JS live in `modules/*/Resources/assets` (`js/admin.ts` barrel + optional `css/admin.css`) | `scripts/build.ts`, `scripts/build-module.ts` |
| Module assets are **lazy**: built on enable / module update / `migrateModule` / `php gereq assets:ensure` as `module:{id}` in the manifest; runtime loads only enabled modules | `ModuleAssetBuilder`, `RenderEngine` |
| Kernel Alpine via `registerUi`; module `register*` via dynamic import of `__GEREQ_MODULE_ASSETS__` before `Alpine.start()` | `resources/js/ui/index.ts`, `resources/js/admin.ts` |
| Tags: `render_css()` / `render_js()` (JS bootstrap injects module URLs) | `config/render.php`, `src/View/RenderEngine.php` |
| Scripts: `bun run build` (kernel), `bun run build:full` (kernel + `assets:ensure`), `bun run build:module -- <id>`, `bun run dev` (kernel + rebuild modules already in manifest) | `package.json` |

There is no ESLint/Prettier configuration in the repository; rely on `tsc` strictness and the Bun build.

## Twig

- Admin pages `{% extends 'admin/layout.html.twig' %}` and fill the blocks defined there (`title`, `breadcrumb`, `content`); see [Admin UI](../architecture/admin-ui.md).
- Module templates are referenced through their namespace: `@viewings/viewings/index.html.twig` (namespace = lowercased module directory, registered by `src/View/TwigFactory.php`).
- Use the shared components (`ui_render('button', {...})`, `ui_render('table', ...)`, form partials in `resources/views/ui/`) instead of hand-written markup; icons via `icon('name')` with keys from `src/Admin/Ui/Icons.php`.
- All user-visible text goes through `|trans` / `trans()`; no hard-coded Czech or English in templates except legacy code.
- Escape by default (autoescape is on); use `|raw` only for content that went through `HtmlSanitizer` or is generated server-side.
- CSRF: every mutating form includes `{{ csrf_field() }}` (hidden `_token`); AJAX sends the `X-CSRF-TOKEN` header with `csrf_token()` (`src/Http/Middleware/CsrfMiddleware.php`). Delete/destructive forms use `data-confirm="..."` so the global handler shows the styled dialog.
- Templates must not contain inline `<script>` with logic — register an Alpine component or a module in `resources/js` (CSP-friendly).

## Translation keys

Files: `resources/translations/messages.{cs,en}.php` (core), `modules/<Dir>/Resources/translations/messages.{cs,en}.php`, `integrations/<Dir>/Resources/translations/...`. Each file returns a flat `key => text` array; both locales must contain the same keys (checklist: "i18n cs + en").

| Prefix | Used for | Example |
| --- | --- | --- |
| `nav.` | menu items | `nav.viewings` |
| `palette.` | command palette entries | `palette.new_listing` |
| `perm.<module>.<action>` / `perm.group.<module>` | permission labels and groups | `perm.viewings.manage`, `perm.group.viewings` |
| `module.<id>.description` | manifest description | `module.viewings.description` |
| `<module>.field.*`, `<module>.status.*`, `<module>.action.*` | entity fields, enum labels, buttons | `viewings.status.scheduled` |
| `validation.<rule>` | validator messages (with `%attribute%`-style placeholders) | `validation.required` |
| `admin.*`, `settings.*`, `dashboard.*`, `publish.*` | core admin UI | `admin.dashboard.title` |
| `audit.action.<action>` | audit log labels (`audit_action` Twig function) | `audit.action.module.enabled` |
| `errors.<status>.*` | error pages | `errors.404.title` |

Keys are lowercase, dot-separated, snake_case segments; the key is the identifier, never the Czech text (a few legacy literal-text keys like `'Akce'` still exist in menu groups). Placeholders use Symfony's `%name%` syntax. Regenerate the editor index after adding keys: `php $GEREQ_TOOLS/gereq-i18n/index-translations.php` ([GEREQ_TOOLS](https://git.gereq.cz/gereq/GEREQ_TOOLS)).

## Commits, versions and modules

Sources: `.cursor/rules/module-updates.mdc`, `.cursor/rules/semver-rollover.mdc`, `README.md` ("Práce se submodulem", "Verze a instalace z GitLabu", "Checklist nového modulu"), `VERSION`.

- Commit messages follow Conventional-Commit style prefixes as used in the README examples: `chore: pin Customers to v1.6.4`, `chore: initial Warehouse v0.1.0`, `chore: add Warehouse submodule @ v0.1.0`; use `feat:` / `fix:` for functional changes.
- **Module code is committed in the module repository** (`git.gereq.cz/gereq/module-<id>`); the platform repository only records the submodule pin. `.gitmodules` URLs must be relative (`../module-<id>.git`, same GitLab group) without tokens. CI uses `CI_JOB_TOKEN`; each module (or the `gereq` group) must allow inbound job tokens from `gereq/GEREQ_PLATFORM`.
- **Any change in `modules/*` requires a version bump**: `module.php` `version` == `<Id>Module::version()` == git tag `vX.Y.Z`. Semver: patch = fix, minor = new feature or migration, major = breaking. Each of MAJOR/MINOR/PATCH stays in **0–99**; a bump that would reach 100 carries to the next component (e.g. `1.99.1` + minor → `2.0.0`, not `1.100.0`) — `.cursor/rules/semver-rollover.mdc`. `tests/Unit/Modules/ModuleStructure/ModuleManifestConsistencyTest.php` fails when manifest and class disagree.
- After a bump the module's admin UI is blocked by `ModuleCurrentMiddleware` until the admin runs **Update**, which applies migrations and re-syncs permissions. Do not change modules "in place" without a bump, and do not rely on `php gereq migrate` as a substitute for the admin update.
- Platform version lives in `VERSION` (read by `Gereq\Support\AppVersion`) and is shown in the console application banner and `php gereq about`.
- `./bin/grq modules status --plain` checks that every submodule's local version matches a GitLab tag before release. Pull / subset workflows: `grq modules pull`, `grq modules-git` — see [Tooling](../infrastructure/tooling.md#modules-pull-and-modules-git).

Related: [Creating a module](creating-a-module.md), [Testing](testing.md), [Module system](../architecture/module-system.md).
