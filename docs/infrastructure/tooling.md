# Developer tooling

Besides the `php gereq` console, the repository carries three groups of developer tooling: editor extensions and CLIs in `tools/` (translation/permission/Twig indexers for Cursor, a Go TUI that compares modules against GitLab, a work monitor, and PDF deck generators), shell scripts in `bin/` (interactive installer, demo installer/packager, commit helper, Twiggy bootstrap), and Cursor guidance in `.cursor/` (rules and skills). Static analysis and tests are configured in `phpstan.neon`, `phpcs.xml` and `phpunit.xml`. Everything JS-related is run with **Bun** (`.cursor/rules/tech-stack.mdc`).

## `tools/`

| Directory / file | What it is | How to run |
| --- | --- | --- |
| `tools/gereq-i18n/` | Cursor/VS Code extension `gereq.i18n` (v0.2.0): hover, autocomplete and Go to Definition for translation keys in Twig (`'key'\|trans`), PHP (`->trans('key')`, `label:`/`hint:`/`title:`… named args, `'label' => '…'`). Reads a catalog generated from `resources/translations` + `modules/*/Resources/translations` + `integrations/*/Resources/translations`. | `php tools/gereq-i18n/index-translations.php` → `.cache/i18n/catalog.json`; `bash tools/gereq-i18n/install.sh` installs into `~/.cursor-server/extensions/` (then *Developer: Reload Window*). Command palette: *GEREQ i18n: Reindex translations*. |
| `tools/gereq-permissions/` | Extension `gereq.permissions` (v0.1.0): hover/autocomplete/F12 for permission keys (`user.can('…')` in Twig, `->can()`, `->permission()`, `permission:` in PHP). | `php tools/gereq-permissions/index-permissions.php` → `.cache/permissions/catalog.json`; `bash tools/gereq-permissions/install.sh`. Command: *GEREQ Permissions: Reindex*. |
| `tools/gereq-twig-goto/` | Extension `gereq.twig-goto` (v0.3.4, "GEREQ Twig IDE"): hover, `.`-completion and Go to Definition for variables in `.twig` files, based on types exported from controllers. Types are stored outside templates. | `php gereq twig:types` (`src/Console/Command/TwigTypesCommand.php`) → `.cache/twig/types.json` + `.cache/twig/vars/**/*.types.twig`; `bash tools/gereq-twig-goto/install.sh`. Command: *GEREQ Twig: Reindex types*. Also includes `index-templates.php`. |
| `tools/gereq-monitor/` | Extension `gereq.work-monitor` (v0.3.0, "GEREQ Pulse"): Toggl-style work tracking from the editor (open/close/save/edit/focus/git events, AI-edit heuristic), dashboard on `http://127.0.0.1:8772`, data in `.cache/worklog/events.jsonl`. `lib/` holds the server/store/auth, `ui/` a Vite UI (`bun run dev` on :8773 proxied to :8772). `standalone.js` runs the server without the editor. README documents a temporary hard-coded login. | `bash tools/gereq-monitor/install.sh`; `node tools/gereq-monitor/standalone.js`; UI dev: `cd tools/gereq-monitor/ui && bun install && bun run dev`. |
| `tools/gereq-modules/` | Go program (Bubble Tea TUI) comparing `modules/*/module.php` versions with tags in GitLab (`GITLAB_URL` default `https://gitlab.com`, `GITLAB_TOKEN` required, `GITLAB_GROUP` default `gereq`, `MODULE_REPO_PREFIX` default `module-`, all read from `.env`; optional `GEREQ_ROOT`). Rows are `ok / DIFF / NO_TAGS / MISSING`. | `tools/gereq-modules/gereq-modules.sh` (builds with `go build` when `main.go` is newer than the binary, then execs it); `--plain` for text output, `-h` for help. TUI keys: `1–5`/`←→` tabs, `r` refresh, `q` quit. |
| `tools/generate_gereq_platform_deck.py`, `tools/generate_gereq_platform_deck_editorial.py` | ReportLab scripts producing the sales deck PDF `output/pdf/GEREQ_Platform_prezentace_a_cenik_2026.pdf` (960×540). They reference brand assets and fonts via hard-coded Windows paths (`V:\home\dev\projects\GEREQ.cz\.brand`, `C:\Windows\Fonts`), so they only run on that workstation. The editorial variant additionally uses Pillow and fontTools to instantiate variable fonts. | `python tools/generate_gereq_platform_deck.py` (needs `reportlab`; editorial also `Pillow`, `fonttools`). |

Generated indexes live in `.cache/` (ignored by git; excluded from demo packages by `bin/pack-demo`).

## `bin/`

| Script | Purpose | Usage |
| --- | --- | --- |
| `bin/install` | Interactive TUI installer for a **new client with a clean DB**. Checks PHP ≥ 8.4, Composer, bun or npm, Docker (optional); asks app name, `APP_URL`, admin account, env (`local`/`production`), DB type (`sqlite`/`mysql`/`pgsql`), runtime (Docker → `QUEUE_DRIVER=redis`, `SESSION_DRIVER=redis`, `REDIS_HOST=redis`, `WEBHOOKS_ALLOW_PRIVATE_URLS=true`; otherwise queue driver choice), demo/sample data; generates `APP_KEY`; runs `composer install`, writes `.env` (backing up an existing one), creates `storage/*`, `bun install && bun run build`, `php gereq migrate --fresh --seed [--no-demo] [--no-sample]`, and either `docker compose up -d --build` or offers `php gereq serve`. | `./bin/install` |
| `bin/install-demo` | Non-interactive **demo** install used by the packaged zip: SQLite, `APP_DEBUG=true`, `TRUSTED_PROXIES=*`, `QUEUE_DRIVER=sync`, `SESSION_DRIVER=file`, `MAIL_DSN=null://null`, `SEARCH_DRIVER=self_repair`, `FRONTEND_ORIGIN` for :5173, generated `API_PUBLIC_KEY` (also written to `FE2/.env` as `VITE_API_PUBLIC_KEY`), admin `demo@gereq.cz` / `demo123`; ignores `ext-redis` platform requirement if missing; `migrate --fresh --seed` with demo data. | `./bin/install-demo [--serve] [--port=8080]` (also via `./INSTALACE.sh`) |
| `bin/pack-demo` | Builds `dist/gereq-realestates-demo-YYYYMMDD.zip` with `rsync` excluding `.git`, `vendor`, `node_modules`, `public/build`, `.env*`, SQLite DB, `storage/{logs,cache,sessions,tmp}/*`, `docs/proposals/`; adds `INSTALACE.sh` and `DEMO-README.txt`. | `./bin/pack-demo` |
| `bin/commit` | `git commit` wrapper that strips `Co-authored-by:` trailers and enables the repo hook (`git config core.hooksPath .githooks`; `.githooks/commit-msg` does the same stripping for plain `git commit`). | `bin/commit "feat: …"`, `bin/commit -a -m "…"`, `bin/commit --all -m "…"`, `bin/commit --install-hooks` |
| `bin/twiggy-env.php` | Bootstraps the application (`Gereq\Foundation\Application::create`) and returns the Twig `Environment` for the Twiggy Twig language server, so it sees the same functions/filters as the app (`asset`, `vite_tags`, `csp_nonce`, `icon`, …). | referenced from Twiggy configuration; not run directly |

`INSTALACE.sh` in the project root is the copy generated by `bin/pack-demo` (delegates to `bin/install-demo`).

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
