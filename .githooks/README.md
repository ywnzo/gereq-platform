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
| `pre-commit` | Blocks secrets, vendor/build/storage dumps, oversized blobs, conflict markers, and accidental commits of files inside `modules/*` submodules. Then runs **fast quality** (`composer cs` on staged PHP under `src/`, `modules/`, `tests/`, `integrations/`, `bin/`). |
| `commit-msg` | Strips `Co-authored-by`; self-heals Conventional Commits subjects (`chore foo` → `chore: foo`, bare text → `chore: …`); then enforces the final shape |
| `pre-push` | Blocks deletion / non-fast-forward pushes to protected branches (`main`, `master`, `dev`, `production`, `prod`). Then runs the **full quality pipeline** (same gate as CI / `AGENTS.md`). |

## Quality pipeline

Shared implementation: `lib/quality.sh`. Commands match the project gate and [`.gitlab-ci.yml`](../.gitlab-ci.yml) analyse/test stages:

| Mode | Commands | Default |
| --- | --- | --- |
| `full` | `composer test` → `composer phpstan` → `composer phpstan:tests` → `composer cs` | `pre-push` |
| `analyse` | `composer analyse` (= phpstan + phpstan:tests + cs) | — |
| `cs` | `composer cs` (pre-commit: only staged PHP paths) | `pre-commit` |
| `off` | skip quality (repo guards still run) | — |

```bash
# Examples
GEREQ_HOOKS_QUALITY=analyse git push
GEREQ_HOOKS_QUALITY=full git commit   # heavy: full gate on commit
GEREQ_HOOKS_SKIP_QUALITY=1 git push   # skip quality only
```

Requires `composer` on `PATH` and an installed `vendor/` tree.

## Bypass

Emergency only:

```bash
GEREQ_HOOKS_SKIP=1 git commit ...
GEREQ_HOOKS_SKIP=1 git push ...
```

Optional knobs:

- `GEREQ_HOOKS_MAX_BYTES` — max staged blob size (default `5242880` = 5 MiB)
- `GEREQ_HOOKS_QUALITY` — `full` \| `analyse` \| `cs` \| `off`
- `GEREQ_HOOKS_SKIP_QUALITY` — skip quality checks while keeping secret/submodule/branch guards
