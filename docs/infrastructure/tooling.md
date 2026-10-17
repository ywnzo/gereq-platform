# Developer tooling

Besides the `php gereq` console, the platform ships the prebuilt **`grq`** CLI under `bin/` (`bin/grq` → `bin/dist/…`). Sources, Cursor/VS Code extensions, GitLab helpers and deck generators live in the standalone monorepo **[gereq/GEREQ_TOOLS](https://git.gereq.cz/gereq/GEREQ_TOOLS)** (not a submodule — clone separately when you need to rebuild or install IDE tools). Static analysis and tests are configured in `phpstan.neon`, `phpcs.xml` and `phpunit.xml`. Everything JS-related is run with **Bun** (`.cursor/rules/tech-stack.mdc`).

```bash
git clone git@git.gereq.cz:gereq/GEREQ_TOOLS.git
export GEREQ_ROOT=/path/to/GEREQ_PLATFORM
```

## `bin/grq`

Help: `❯ ./bin/grq help [<topic>]` or `❯ ./bin/grq <topic> help` (e.g. `ai`, `modules`, `modules-git`, `gitlab`, `version`).

Interactive AI (Czech/English, tool-calling): `❯ ./bin/grq ai` — e.g. *aktualizuj modul CMS na 1.22.2 s popisem YAZ*.

Cross-platform CLI (Go). Launchers `bin/grq` / `bin/grq.cmd` select a prebuilt binary from `bin/dist/` (no download). Rebuild from GEREQ_TOOLS (see that repo’s README). Sources: [`gereq-cli/`](https://git.gereq.cz/gereq/GEREQ_TOOLS/-/tree/main/gereq-cli).

| Command | Purpose |
| --- | --- |
| `grq doctor` | Check php ≥ 8.4, required extensions (`gd`, `zip`, …), composer, bun\|npm, docker, git |
| `grq env init [--docker]` | Write `.env` from `config/env.schema.toml` |
| `grq env validate` | Validate `.env` |
| `grq install` / `grq install demo` | Interactive clean install / SQLite demo (native Go) |
| `grq serve` / `grq server` | Proxy → `php gereq serve` (`--hot`, `--queue`, …) |
| `grq serve-docker` | Proxy → `php gereq serve-docker` |
| `grq migrate` | Proxy → `php gereq migrate` |
| `grq cache:clear` | Proxy → `php gereq cache:clear` |
| `grq <php-command> …` | Passthrough for any other `php gereq` command (e.g. `grq about`, `grq module:list`) |
| `grq pack demo` | Demo zip into `dist/` |
| `grq commit …` | Git commit stripping `Co-authored-by` |
| `grq pr [--base] [--draft] [--dry-run] [--push] [--fast] [--no-agent]` | Draft MR via baked-in OpenRouter agent (Summary + Test plan), create on GitLab |
| `grq modules` | TUI comparing modules vs GitLab (replaces `gereq-modules`) |
| `grq modules status [--plain]` | Text status (local version ↔ GitLab tags) |
| `grq modules pull [--force] [--init]` | Fetch/checkout all submodules from active `.gitmodules` (clone missing) |
| `grq module <id> pull [--force] [--init]` | Same for one module |
| `grq modules update [id…]` | Interactive release (version, feat, desc, files → feat branch → main → tag → atomic push) |
| `grq module <id> update <version> <feat> <desc> [files…]` | Non-interactive single-module release |
| `grq modules-git add\|remove\|include\|exclude\|use` | Prepare `.gitmodules.disabled`, then activate with `use` (backup zip + pull) |
| `grq modules create Name [--kind …]` | Scaffold + logo + badges; optional OpenRouter polish (`--no-agent` to skip) |
| `grq gitlab init <token\|login>` | Open/print GitLab sign-in or PAT creation URL |
| `grq gitlab auth <token\|env\|login> [PAT]` | Write/verify `GITLAB_TOKEN` in `.env` |

### GitLab auth

```bash
❯ ./bin/grq gitlab init token          # link to create PAT (opens browser)
❯ ./bin/grq gitlab init login          # link to sign in
❯ ./bin/grq gitlab auth login          # open links, prompt for PAT → .env + verify
❯ ./bin/grq gitlab auth token glpat-…
❯ ./bin/grq gitlab auth env            # verify existing token
```

Recommended scopes: `api`, `write_repository`, `read_repository`. Use `--no-open` to print URLs only.

### Modules pull and modules-git

Active set = **`.gitmodules`**. Staging set = **`.gitmodules.disabled`** until `use`.

```bash
# download / refresh checkouts (preferred over raw git submodule update)
❯ ./bin/grq modules pull --init
❯ ./bin/grq module Cms pull --force

# prepare a smaller client set, then activate
❯ ./bin/grq modules-git include CMS@1.20.1 Listing
❯ ./bin/grq modules-git use --force
# → writes prepared into .gitmodules, parks previous in .gitmodules.disabled,
#   creates modules.bak.zip, clears modules/, pulls the new set
```

| `modules-git` | Behaviour |
| --- | --- |
| `add Module[@tag]…` | Upsert into `.gitmodules.disabled` (latest GitLab tag if `@tag` omitted; needs `GITLAB_TOKEN`) |
| `remove Module…` | Drop from `.gitmodules.disabled` |
| `include Module[@tag]…` | Replace disabled file with **only** these; delete other `modules/*` dirs |
| `exclude Module…` | Copy current `.gitmodules` minus listed names into disabled |
| `use [--force]` | Swap disabled↔active, zip+clear `modules/`, `modules pull` |

Relative submodule URLs (`../module-cms.git`) resolve via `GITLAB_URL` / `GITLAB_GROUP` when cloning. Full CLI table: [`gereq-cli/README.md`](https://git.gereq.cz/gereq/GEREQ_TOOLS/-/blob/main/gereq-cli/README.md) in GEREQ_TOOLS.

`bin/twiggy-env.php` remains for the Twiggy language server.

## [GEREQ_TOOLS](https://git.gereq.cz/gereq/GEREQ_TOOLS) (standalone)

Clone next to the platform (or anywhere) and set `GEREQ_ROOT` to the platform path.

| Path in GEREQ_TOOLS | What it is | How to run |
| --- | --- | --- |
| `gereq-cli/` | Source of `grq` (OpenRouter agent for `pr` / module polish / `ai`) | see GEREQ_TOOLS README → build into `bin/dist` |
| `gereq-env/` | Cursor/VS Code extension `gereq.gereq-env` (v0.1.0): autocomplete, hover and diagnostics for `.env` + `env_value()` from `config/env.schema.toml`. GitLab: [`gereq/tool-env`](https://git.gereq.cz/gereq/tool-env). | `bash gereq-env/install.sh`; generate `.env`: `bin/grq env init` / `php gereq env:init`. |
| `gereq-i18n/` | Extension `gereq.i18n` (v0.2.2): hover, autocomplete and Go to Definition for translation keys. GitLab: [`gereq/tool-i18n`](https://git.gereq.cz/gereq/tool-i18n). | `php gereq-i18n/index-translations.php` → `.cache/i18n/catalog.json`; `bash gereq-i18n/install.sh`. |
| `gereq-permissions/` | Extension `gereq.permissions` (v0.1.2). GitLab: [`gereq/tool-permissions`](https://git.gereq.cz/gereq/tool-permissions). | `php gereq-permissions/index-permissions.php`; `bash gereq-permissions/install.sh`. |
| `gereq-twig-goto/` | Extension `gereq.twig-goto` (v0.3.6). GitLab: [`gereq/tool-twig-goto`](https://git.gereq.cz/gereq/tool-twig-goto). | `php gereq twig:types`; `bash gereq-twig-goto/install.sh`. |
| `gereq-monitor/` | Extension `gereq.work-monitor` (v0.3.2, "GEREQ Pulse"). GitLab: [`gereq/tool-pulse`](https://git.gereq.cz/gereq/tool-pulse). | `bash gereq-monitor/install.sh` |
| `gereq-modules/` | Deprecated wrapper → `bin/grq modules`. GitLab: [`gereq/tool-modules`](https://git.gereq.cz/gereq/tool-modules). | `./gereq-modules/gereq-modules.sh` → `grq modules` |
| `gitlab-*.sh`, `publish-tools-to-gitlab.sh`, `generate_*.py` | GitLab helpers / logos / sales decks | see GEREQ_TOOLS README |

Generated indexes live in `.cache/` (ignored by git; excluded from demo packages by `grq pack demo`).

## `bin/` (legacy note)

`INSTALACE.sh` delegates to `bin/grq install demo`.

## `.cursor/` rules and skills

| File | Type | What it enforces |
| --- | --- | --- |
| `.cursor/rules/tech-stack.mdc` | rule, `alwaysApply: true` | Always use Bun: `bun run …` instead of `npm run …`, `bun x` instead of `npx`. |
| `.cursor/rules/module-updates.mdc` | rule, `globs: modules/**/*` | Any change in `modules/*` must bump `version` in `module.php` **and** `Module::version()`; schema/data changes only via `Database/Migrations/`; semver (patch/minor/major). After a bump the platform blocks the module's admin UI (`ModuleCurrentMiddleware`) until "Aktualizovat" runs pending migrations + permission sync. Do not rely on `php gereq migrate` as a substitute for the admin update. |
| `.cursor/rules/publishables.mdc` | rule | Public-facing module content and opted-in CRM entities must go through the publish pipeline in `src/Publish` (`$context->publishables()->register(category, label, adapter, module)`, adapters implement `Gereq\Publish\PublishableAdapterInterface`). Patterns: staged settings keys, `ProductionFreeze::freezeIfNeeded/stageDelete/stageCreate`; `/api/public/*` serves the frozen snapshot until topbar publish bumps `app.content_version`. Auth/permissions, payments and runtime intake endpoints stay live. |
| `.cursor/skills/english-code-comments/SKILL.md` | skill | All code comments, docblocks, TODO/FIXME, Makefile `##` help and CLI description strings must be English. Translation catalogs, UI copy, CMS/demo seed content and end-user docs are out of scope. |
| `.cursor/skills/no-native-dialogs/SKILL.md` | skill | Never use `window.alert/confirm/prompt` in the admin UI; use `window.gereq.confirm(...)` / `window.gereq.prompt(...)` from `resources/js/ui/confirm.ts` (async), `data-confirm` / `data-confirm-title` / `data-confirm-danger` attributes on forms, and `window.gereq.toast` for results. A fallback to native dialogs is allowed only inside `askConfirm`/`askPrompt` helpers. |

Admin confirms (`gereqConfirmModuleVersion`, `dataTable.confirmBulk`, module table disable) use `window.gereq.confirm`; native `window.confirm` remains only as a fallback in `resources/js/ui/confirm.ts` when the dialog host is missing.

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
| `php gereq twig:types` | Generate Twig types into `.cache/twig/` (needs [GEREQ_TOOLS](https://git.gereq.cz/gereq/GEREQ_TOOLS) via `GEREQ_TOOLS` or sibling checkout) |
| `php gereq nn [--minutes]` | List N+1 queries from the last N minutes (reads `storage/logs/nplusone.log`) |
| `php gereq user:create [--name --email --password --role=superadmin\|admin\|editor\|view]` | Create a user |
| `php gereq serve` / `./bin/grq serve` `[--host] [--port] [--hot] [--queue]` | Built-in PHP server (`public/router.php`); alias `server` |
| `php gereq serve-docker …` | Docker stack — see [docker.md](docker.md) |
