# Git hooks (platform)

Tracked hooks for this repository. Enable once per clone:

```bash
git config core.hooksPath .githooks
# or
bin/grq commit --install-hooks
```

## Hooks

| Hook | Purpose |
| --- | --- |
| `pre-commit` | Blocks secrets, vendor/build/storage dumps, oversized blobs, conflict markers, and accidental commits of files inside `modules/*` submodules. Then runs **quality on staged paths** (PHP tools + JS/TS when frontend surface is staged). |
| `commit-msg` | Strips `Co-authored-by`; self-heals Conventional Commits subjects (`chore foo` → `chore: foo`, bare text → `chore: …`); then enforces the final shape |
| `pre-push` | Blocks deletion / non-fast-forward pushes to protected branches (`main`, `master`, `dev`, `production`, `prod`). Then runs **quality on files in the push range** (`remote..local`), unless a matching stamp from a successful `pre-commit` already verified the same mode + file blobs (typical `commit` → `push` flow). **Analysis failure always blocks the push** (`GEREQ_HOOKS_SKIP_QUALITY` / `QUALITY=off` are ignored on push). |

## Quality pipeline

Shared implementation: `lib/quality.sh`.

**Default (`changed`)** — only files you commit or push:

| Step | When |
| --- | --- |
| `phpcs` | changed `*.php` under `src/`, `modules/`, `tests/`, `integrations/`, `bin/` |
| `phpstan` (`phpstan.neon`) | changed under `src/`, `modules/`, `tests/`, `bin/` |
| `phpstan:tests` | changed under `tests/`, `src/Testing/` |
| `phpunit` | changed `*Test.php` only |
| `bunx tsc --noEmit` + `bun run build` | JS/TS surface touched: `resources/js/**`, `scripts/**/*.ts`, `package.json`, `bun.lock`, `tsconfig.json` |

| Mode | Behaviour |
| --- | --- |
| `changed` | incremental (default for commit + push) |
| `full` / `ci` | entire tree: `composer test` + phpstan + phpstan:tests + cs + JS/TS gate (same as MR/tag CI / `AGENTS.md`) |
| `analyse` | `composer analyse` on the whole tree (PHP only) |
| `cs` | phpcs only (still scoped to the path list when available) |
| `off` | skip quality (repo guards still run) |

```bash
GEREQ_HOOKS_QUALITY=full git push          # complete CI gate locally
GEREQ_HOOKS_QUALITY=cs git commit          # style only (commit)
GEREQ_HOOKS_SKIP_QUALITY=1 git commit      # skip quality on commit only (NOT on push)
```

On **push**, a failed `phpstan` / `phpstan:tests` / `phpcs` / JS gate **always aborts** the push. If the push range has no resolvable paths, pre-push falls back to full `analyse` (`composer phpstan` + `composer phpstan:tests`) instead of skipping.

**Commit → push dedupe:** a successful `pre-commit` quality run writes `.git/gereq-quality.stamp` (mode + content fingerprint). `pre-push` skips re-running the same checks when the fingerprint still matches. Re-runs when you push extra commits, change files after commit, use a different `GEREQ_HOOKS_QUALITY` mode, or set `GEREQ_HOOKS_QUALITY_FORCE=1`.

Requires:

- PHP tools: `composer` on `PATH` and an installed `vendor/` tree (when PHP paths are in scope)
- JS/TS gate: `bun` on `PATH` and `node_modules/` (`bun install`) when frontend surface is in scope

> Incremental checks can miss cross-file regressions. GitLab CI mirrors this split: **branch push** → `quality:changed` only; **merge request / tag** → full suite (+ `analyse:js`). Locally: `GEREQ_HOOKS_QUALITY=full git push`.

## Bypass

Emergency only:

```bash
GEREQ_HOOKS_SKIP=1 git commit ...
GEREQ_HOOKS_SKIP=1 git push ...
```

Optional knobs:

- `GEREQ_HOOKS_MAX_BYTES` — max staged blob size (default `5242880` = 5 MiB)
- `GEREQ_HOOKS_MAX_BYTES_DIST` — max size for allowlisted `bin/dist/grq-*` prebuilts (default `20971520` = 20 MiB)
- `GEREQ_HOOKS_QUALITY` — `changed` \| `full` \| `analyse` \| `cs` \| `off`
- `GEREQ_HOOKS_SKIP_QUALITY` — skip quality on **commit** only (ignored on **push**; use `GEREQ_HOOKS_SKIP=1` for emergency push bypass)
- `GEREQ_HOOKS_QUALITY_FORCE=1` — ignore the commit→push stamp and always re-run quality on push
