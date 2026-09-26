# grq — GEREQ platform CLI

Single static binary (linux / macOS / Windows) for platform + modules workflows. **Nothing is downloaded at runtime** — launchers pick a prebuilt file from `bin/dist/`.

## Install / run

```bash
./bin/grq doctor
./bin/grq version
# Windows: bin\grq.cmd doctor
```

Rebuild for this machine:

```bash
make grq
make grq-release   # all GOOS/GOARCH
```

Sources: `tools/gereq-cli/` (Go, vendored). Optional AI via Cursor CLI `agent` on PATH (`agent login` or `CURSOR_API_KEY`) for module create polish and `grq pr` drafts.

## Commands

| Command | Purpose |
| --- | --- |
| `grq doctor` | php / composer / bun\|npm / docker / git |
| `grq env init [--docker] [--force] [--port N]` | Write `.env` from `config/env.schema.toml` |
| `grq env validate` | Validate `.env` against schema |
| `grq install` | Interactive clean-client install |
| `grq install demo [--serve] [--port N]` | Non-interactive SQLite demo |
| `grq pack demo` | Zip demo package to `dist/` |
| `grq commit …` | Commit without `Co-authored-by` trailers |
| `grq pr …` | Draft MR (Cursor Composer 2.5) + create on GitLab |
| `grq modules` | TUI: local modules ↔ GitLab tags |
| `grq modules status [--plain]` | Text status |
| `grq modules update [id…] [--yes]` | Report / checkout matching tags |
| `grq modules create Name [--kind …] [--no-agent] [--no-gitlab]` | Scaffold + logo + badges + remote |

### Pull / merge request

```bash
./bin/grq pr --dry-run              # preview title+body only
./bin/grq pr --push                 # push branch, draft via Cursor, create MR
./bin/grq pr --draft --base main    # mark as GitLab draft
./bin/grq pr --fast                 # Composer 2.5 fast variant (pricier)
./bin/grq pr --no-agent             # local template from git log (no API)
```

Body always follows team guidelines (`## Summary` + `## Test plan`). Default model is Composer 2.5 **standard** (cheaper).

Cursor (you set this up): install [Cursor CLI](https://cursor.com/install), then `agent login` (or put `CURSOR_API_KEY` in `.env`). `grq` calls `agent` from the binary — no Bun/TS helper.

GitLab token in project-root **`.env`**:

```env
GITLAB_TOKEN=glpat-…
```

PAT needs `api` (create MR). Without Cursor CLI, falls back to a local git-log template.

### Module create

```bash
./bin/grq modules create Warehouse --kind warehouse
./bin/grq modules create Listings --kind realestate --no-agent
```

Kinds: `realestate`, `fleet`, `commerce`, `crm`, `content`, `integration`, `warehouse`, `payments`, `generic`.

With Cursor CLI `agent` on PATH (and `agent login` / `CURSOR_API_KEY`), create polishes logo / description / i18n. Without it, deterministic SVG logos are used.
