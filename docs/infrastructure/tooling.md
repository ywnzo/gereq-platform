# Developer tooling

Besides the `php gereq` console, developer tooling lives in the standalone monorepo **[gereq/GEREQ_TOOLS](https://git.gereq.cz/gereq/GEREQ_TOOLS)** (git submodule at `tools/`): the **`grq`** single binary (`bin/grq` → `bin/dist/…`) for env/install/pack/commit/pr/modules, editor extensions under `tools/gereq-*`, PDF deck generators, and Cursor guidance in `.cursor/`. Static analysis and tests are configured in `phpstan.neon`, `phpcs.xml` and `phpunit.xml`. Everything JS-related is run with **Bun** (`.cursor/rules/tech-stack.mdc`).

```bash
git submodule update --init --recursive tools
# standalone: git clone git@git.gereq.cz:gereq/GEREQ_TOOLS.git
```

## `bin/grq`

Cross-platform CLI (Go). Launchers `bin/grq` / `bin/grq.cmd` select a prebuilt binary from `bin/dist/` (no download). Rebuild: `make grq` / `make grq-release`. Sources: [`tools/gereq-cli/`](https://git.gereq.cz/gereq/GEREQ_TOOLS/-/tree/main/gereq-cli) in GEREQ_TOOLS.

| Command | Purpose |
| --- | --- |
| `grq doctor` | Check php ≥ 8.4, required extensions (`gd`, `zip`, …), composer, bun\|npm, docker, git |
| `grq env init [--docker]` | Write `.env` from `config/env.schema.toml` |
| `grq env validate` | Validate `.env` |
| `grq install` / `grq install demo` | Interactive clean install / SQLite demo |
| `grq pack demo` | Demo zip into `dist/` |
| `grq commit …` | Git commit stripping `Co-authored-by` |
| `grq pr [--base] [--draft] [--dry-run] [--push] [--fast] [--no-agent]` | Draft MR via Cursor CLI `agent` (guidelines: Summary + Test plan), create on GitLab |
| `grq modules` | TUI comparing modules vs GitLab (replaces `gereq-modules`) |
| `grq modules update [id…] [--yes\|--push\|--patch]` | Pull GitLab tag (`--yes`), publish local tag (`--push --yes`), or bump patch + push (`--patch --yes`) |
| `grq modules create Name [--kind …]` | Scaffold + logo + badges; optional Cursor CLI polish when `agent` is on PATH |

RealityV3 product scripts live under `scripts/realityv3/` (not in `bin/`). `bin/twiggy-env.php` remains for the Twiggy language server.

## `tools/` (submodule → [GEREQ_TOOLS](https://git.gereq.cz/gereq/GEREQ_TOOLS))

| Directory / file | What it is | How to run |
| --- | --- | --- |
| `tools/gereq-cli/` | Source of `grq` (see above). Calls Cursor CLI `agent` when present. | `make grq` |
| `tools/gereq-env/` | Cursor/VS Code extension `gereq.gereq-env` (v0.1.0, "GEREQ Env Schema"): autocomplete, hover and diagnostics for `.env` + `env_value()` from `config/env.schema.toml`. GitLab: [`gereq/tool-env`](https://git.gereq.cz/gereq/tool-env). | `bash tools/gereq-env/install.sh`; generate `.env`: `bin/grq env init` / `php gereq env:init`. |
| `tools/gereq-i18n/` | Cursor/VS Code extension `gereq.i18n` (v0.2.2): hover, autocomplete and Go to Definition for translation keys in Twig (`'key'\|trans`), PHP (`->trans('key')`, `label:`/`hint:`/`title:`… named args, `'label' => '…'`). Reads a catalog generated from `resources/translations` + `modules/*/Resources/translations` + `integrations/*/Resources/translations`. GitLab: [`gereq/tool-i18n`](https://git.gereq.cz/gereq/tool-i18n). | `php tools/gereq-i18n/index-translations.php` → `.cache/i18n/catalog.json`; `bash tools/gereq-i18n/install.sh` installs into `~/.cursor-server/extensions/` (then *Developer: Reload Window*). Command palette: *GEREQ i18n: Reindex translations*. |
| `tools/gereq-permissions/` | Extension `gereq.permissions` (v0.1.2): hover/autocomplete/F12 for permission keys (`user.can('…')` in Twig, `->can()`, `->permission()`, `permission:` in PHP). GitLab: [`gereq/tool-permissions`](https://git.gereq.cz/gereq/tool-permissions). | `php tools/gereq-permissions/index-permissions.php` → `.cache/permissions/catalog.json`; `bash tools/gereq-permissions/install.sh`. Command: *GEREQ Permissions: Reindex*. |
| `tools/gereq-twig-goto/` | Extension `gereq.twig-goto` (v0.3.6, "GEREQ Twig IDE"): hover, `.`-completion and Go to Definition for variables in `.twig` files, based on types exported from controllers. Types are stored outside templates. GitLab: [`gereq/tool-twig-goto`](https://git.gereq.cz/gereq/tool-twig-goto). | `php gereq twig:types` (`src/Console/Command/TwigTypesCommand.php`) → `.cache/twig/types.json` + `.cache/twig/vars/**/*.types.twig`; `bash tools/gereq-twig-goto/install.sh`. Command: *GEREQ Twig: Reindex types*. Also includes `index-templates.php`. |
| `tools/gereq-monitor/` | Extension `gereq.work-monitor` (v0.3.2, "GEREQ Pulse"): Toggl-style work tracking from the editor. GitLab: [`gereq/tool-pulse`](https://git.gereq.cz/gereq/tool-pulse). | `bash tools/gereq-monitor/install.sh` |
| `tools/gereq-modules/` | Deprecated wrapper → `bin/grq modules`. GitLab: [`gereq/tool-modules`](https://git.gereq.cz/gereq/tool-modules). | `./tools/gereq-modules/gereq-modules.sh` → `grq modules` |
| `tools/generate_tool_logos.py` | Generates `logo.svg` / `logo.png` / `icon.png` for each IDE tool. | `python3 tools/generate_tool_logos.py` |
| `tools/publish-tools-to-gitlab.sh` | Push-to-create / update standalone GitLab projects under `gereq/tool-*`. | `bash tools/publish-tools-to-gitlab.sh` |
| `tools/gitlab-set-tool-meta.sh` | Sets GitLab project description + avatar (`logo.png`) via API (`GITLAB_TOKEN`). | `bash tools/gitlab-set-tool-meta.sh` |
| `tools/generate_gereq_platform_deck.py`, `tools/generate_gereq_platform_deck_editorial.py` | ReportLab sales deck PDFs (Windows brand paths). | `python tools/generate_gereq_platform_deck.py` |

Generated indexes live in `.cache/` (ignored by git; excluded from demo packages by `grq pack demo`).

## `bin/` (legacy note)

`INSTALACE.sh` delegates to `bin/grq install demo`. RealityV3: `scripts/realityv3/install` and `scripts/realityv3/pack`.

## `.cursor/` rules and skills

| File | Type | What it enforces |
| --- | --- | --- |
| `.cursor/rules/tech-stack.mdc` | rule, `alwaysApply: true` | Always use Bun: `bun run …` instead of `npm run …`, `bun x` instead of `npx`. |
| `.cursor/rules/module-updates.mdc` | rule, `globs: modules/**/*` | Any change in `modules/*` must bump `version` in `module.php` **and** `Module::version()`; schema/data changes only via `Database/Migrations/`; semver (patch/minor/major). After a bump the platform blocks the module's admin UI (`ModuleCurrentMiddleware`) until "Aktualizovat" runs pending migrations + permission sync. Do not rely on `php gereq migrate` as a substitute for the admin update. |
| `.cursor/rules/publishables.mdc` | rule | Public-facing module content and opted-in CRM entities must go through the publish pipeline in `src/Publish` (`$context->publishables()->register(category, label, adapter, module)`, adapters implement `Gereq\Publish\PublishableAdapterInterface`). Patterns: staged settings keys, `ProductionFreeze::freezeIfNeeded/stageDelete/stageCreate`; `/api/public/*` serves the frozen snapshot until topbar publish bumps `app.content_version`. Auth/permissions, payments and runtime intake endpoints stay live. |
| `.cursor/skills/english-code-comments/SKILL.md` | skill | All code comments, docblocks, TODO/FIXME, Makefile `##` help and CLI description strings must be English. Translation catalogs, UI copy, CMS/demo seed content and end-user docs are out of scope. |
| `.cursor/skills/no-native-dialogs/SKILL.md` | skill | Never use `window.alert/confirm/prompt` in the admin UI; use `window.gereq.confirm(...)` / `window.gereq.prompt(...)` from `resources/js/ui/confirm.ts` (async), `data-confirm` / `data-confirm-title` / `data-confirm-danger` attributes on forms, and `window.gereq.toast` for results. A fallback to native dialogs is allowed only inside `askConfirm`/`askPrompt` helpers. |

Note: `resources/js/admin.ts` still contains `window.confirm` calls (`gereqConfirmModuleDisable`, `gereqConfirmModuleVersion`, `dataTable.confirmBulk`) that predate the `no-native-dialogs` skill.

## Static analysis and tests

| File | Summary | Run |
| --- | --- | --- |
| `phpstan.neon` | level 5; paths `src`, `modules`; `tmpDir: .phpstan-tmp`; ignored identifiers `property.onlyWritten`, `while.alwaysTrue`, `nullCoalesce.offset`, `property.unusedType`. `integrations/` is not analysed. | `vendor/bin/phpstan analyse` |
| `phpcs.xml` | ruleset "GEREQ": PSR-12 over `src`, `modules`, `tests`; excludes `vendor`; PHP files only. | `vendor/bin/phpcs` / `vendor/bin/phpcbf` |
| `phpunit.xml` | bootstrap `tests/bootstrap.php`, `cacheDirectory: storage/framework/phpunit`; suites **Core** (`tests/Unit` minus `tests/Unit/Modules`), **Modules** (`tests/Unit/Modules`), **File** (`tests/File`). `tests/Integration` and `tests/Support` exist but are not part of a suite. | `vendor/bin/phpunit`, `vendor/bin/phpunit --testsuite Modules` |

TypeScript for the admin bundle is type-checked by the `tsconfig.json` at the root (`strict`, `noEmit`, `include: resources/js/**/*.ts`) — see [../frontend/admin-assets.md](../frontend/admin-assets.md).

## Related console commands used by tooling

| Command | Description (from `#[AsCommand]`) |
| --- | --- |
| `php gereq about` | Information about GEREQ Platform |
| `php gereq twig:types` | Generate Twig types into `.cache/twig/` |
| `php gereq nn [--minutes]` | List N+1 queries from the last N minutes (reads `storage/logs/nplusone.log`) |
| `php gereq user:create [--name --email --password --role=superadmin\|admin\|editor\|view]` | Create a user |
| `php gereq serve [--host=0.0.0.0] [--port=8080]` | Built-in PHP server (`public/router.php`) |
| `php gereq serve-docker …` | Docker stack — see [docker.md](docker.md) |
