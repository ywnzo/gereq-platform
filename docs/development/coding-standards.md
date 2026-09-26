# Coding standards

This page lists the conventions enforced (by tooling or by rule files) in the GEREQ / RealEstates codebase: PHP style and static analysis, TypeScript/Bun rules, Twig conventions, translation keys, and the commit/versioning rules that come from `.cursor/rules/*.mdc` and `.cursor/skills/*`. Where a rule is enforced by a tool, the command to run it is given; where it is a convention, the source file that states it is cited.

## PHP

| Rule | Enforced by |
| --- | --- |
| `declare(strict_types=1);` at the top of every file | convention — every file under `src/` and `modules/` has it (0 exceptions at time of writing) |
| PSR-12 formatting | `phpcs.xml` (ruleset PSR12, paths `src`, `modules`, `tests`; extension `php`) — `composer cs`, autofix `composer cs-fix` (phpcbf) |
| PHPStan level 5 on `src` + `modules` | `phpstan.neon` (tmpDir `.phpstan-tmp`; ignored identifiers `property.onlyWritten`, `while.alwaysTrue`, `nullCoalesce.offset`, `property.unusedType`) — `composer phpstan` (`phpstan analyse --memory-limit=1G`) |
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
composer test && composer phpstan && composer cs
```

## TypeScript / frontend

| Rule | Source |
| --- | --- |
| **Bun only**: `bun install`, `bun run build`, `bun run dev`, `bun x <tool>` — never `npm run` / `npx` | `.cursor/rules/tech-stack.mdc` (always applied) |
| `tsconfig.json` has `"strict": true`; TS sources live in `resources/js/**` and `modules/*/Resources/assets/js/**` | `tsconfig.json`, `vite.config.ts` |
| Alpine components are registered through `register*` functions exported from `resources/js/ui/*.ts` and wired in `resources/js/ui/index.ts` (CSP build of Alpine — no inline JS expressions) | `resources/js/ui/index.ts`, `package.json` (`@alpinejs/csp`) |
| **No native dialogs**: never `window.alert` / `window.confirm` / `window.prompt`; use `window.gereq.confirm(...)`, `window.gereq.prompt(...)` (`resources/js/ui/confirm.ts`) or `data-confirm` on forms | `.cursor/skills/no-native-dialogs/SKILL.md` |
| Single Vite entry `admin: resources/js/admin.ts`; module assets are `import`ed from `admin.ts` (e.g. `modules/CarService/Resources/assets/css/admin.css`) rather than added as separate entries; tags rendered by the `vite_tags()` / `vite_hot()` Twig functions | `vite.config.ts`, `resources/js/admin.ts`, `src/View/TwigRuntime.php` |
| Scripts available: `bun run dev` (Vite dev server / HMR), `bun run build` (production build to `public/build`) | `package.json` |

There is no ESLint/Prettier configuration in the repository; rely on `tsc` strictness and the Vite build.

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

Keys are lowercase, dot-separated, snake_case segments; the key is the identifier, never the Czech text (a few legacy literal-text keys like `'Akce'` still exist in menu groups). Placeholders use Symfony's `%name%` syntax. Regenerate the editor index after adding keys: `php tools/gereq-i18n/index-translations.php`.

## Commits, versions and modules

Sources: `.cursor/rules/module-updates.mdc`, `README.md` ("Práce se submodulem", "Verze a instalace z GitLabu", "Checklist nového modulu"), `VERSION`.

- Commit messages follow Conventional-Commit style prefixes as used in the README examples: `chore: pin Customers to v1.6.4`, `chore: initial Warehouse v0.1.0`, `chore: add Warehouse submodule @ v0.1.0`; use `feat:` / `fix:` for functional changes.
- **Module code is committed in the module repository** (`git.gereq.cz/gereq/module-<id>`); the platform repository only records the submodule pin. `.gitmodules` URLs must be `https://git.gereq.cz/gereq/...` without tokens.
- **Any change in `modules/*` requires a version bump**: `module.php` `version` == `<Id>Module::version()` == git tag `vX.Y.Z`. Semver: patch = fix, minor = new feature or migration, major = breaking. `tests/Unit/Modules/ModuleStructure/ModuleManifestConsistencyTest.php` fails when manifest and class disagree.
- After a bump the module's admin UI is blocked by `ModuleCurrentMiddleware` until the admin runs **Update**, which applies migrations and re-syncs permissions. Do not change modules "in place" without a bump, and do not rely on `php gereq migrate` as a substitute for the admin update.
- Platform version lives in `VERSION` (read by `Gereq\Support\AppVersion`) and is shown in the console application banner and `php gereq about`.
- `./tools/gereq-modules/gereq-modules.sh` (`--plain` for CI) checks that every submodule's local version matches a GitLab tag before release.

Related: [Creating a module](creating-a-module.md), [Testing](testing.md), [Module system](../architecture/module-system.md).
