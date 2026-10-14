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
| `pre-commit` | Blocks secrets, vendor/build/storage dumps, oversized blobs, conflict markers, and accidental commits of files inside `modules/*` submodules |
| `commit-msg` | Strips `Co-authored-by`; self-heals Conventional Commits subjects (`chore foo` → `chore: foo`, bare text → `chore: …`); then enforces the final shape |
| `pre-push` | Blocks deletion / non-fast-forward pushes to protected branches (`main`, `master`, `dev`, `production`, `prod`) |

## Bypass

Emergency only:

```bash
GEREQ_HOOKS_SKIP=1 git commit ...
GEREQ_HOOKS_SKIP=1 git push ...
```

Optional knobs:

- `GEREQ_HOOKS_MAX_BYTES` — max staged blob size (default `5242880` = 5 MiB)
