# GEREQ Modules

<p align="center">
  <img src="logo.png" alt="GEREQ Modules" width="128" height="128" />
</p>

**CLI / TUI** (Go + Bubble Tea) — compare local `modules/*/module.php` versions with GitLab tags.

| | |
| --- | --- |
| **GitLab** | https://git.gereq.cz/gereq/tool-modules |
| **Run** | `./gereq-modules.sh` |

## Config (from platform `.env`)

| Variable | Default |
| --- | --- |
| `GITLAB_URL` | `https://git.gereq.cz` |
| `GITLAB_TOKEN` | _(required)_ |
| `GITLAB_GROUP` | `gereq` |
| `MODULE_REPO_PREFIX` | `module-` |
| `GEREQ_ROOT` | parent of `tools/` |

## Usage

```bash
./gereq-modules.sh           # TUI
./gereq-modules.sh --plain   # text
./gereq-modules.sh -h
```

Rows: `ok` / `DIFF` / `NO_TAGS` / `MISSING`. Keys: `1–5` / arrows tabs, `r` refresh, `q` quit.
