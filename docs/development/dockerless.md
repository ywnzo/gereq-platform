# Dockerless local runtime (home-only)

Run GEREQ **without Docker** on hosts where the OS outside `/home` is immutable / read-only (Fedora Atomic, locked-down VMs, no root package installs). All runtime state lives under `$HOME`; the app uses **SQLite** and **file/sync** drivers so Postgres and Redis are not required.

Scripts: `dev/dockerless/`. Source of truth for behaviour is those scripts plus the templates in `dev/dockerless/{nginx,php}/`.

## What you get

| Piece | Default | Notes |
| --- | --- | --- |
| HTTP | `php gereq serve` (`--mode=php`) | Built-in PHP server; no root, no sockets |
| Optional HTTP | user-space **nginx + php-fpm** (`--mode=nginx`) | `nginx -p $GEREQ_HOME`, FPM unix socket under `$GEREQ_HOME/run/` |
| DB | SQLite `storage/database.sqlite` | No system DB service |
| Cache / session / queue | `file` / `file` / `sync` | Redis optional later |
| Toolchain install | Composer PHAR + Bun into `$HOME` | If PHP is missing, installer tries Linuxbrew under home |
| Runtime prefix | `~/.local/share/gereq-dockerless` | Override with `GEREQ_HOME` |

Nothing is written to `/usr` or `/etc`.

## Quick start

```bash
./dev/dockerless/install.sh --fresh          # .env (SQLite), composer, bun build, migrate
./dev/dockerless/serve.sh                    # foreground PHP server on :8080
# or:
./dev/dockerless/install.sh --mode=nginx --fresh
./dev/dockerless/serve.sh --mode=nginx       # nginx+fpm (daemonized)
./dev/dockerless/serve.sh --hot              # also bun run dev (Vite HMR)
./dev/dockerless/status.sh
./dev/dockerless/down.sh
```

Admin: `http://127.0.0.1:8080/admin` (use `127.0.0.1`, not `localhost`). Credentials from `.env` (`ADMIN_EMAIL` / `ADMIN_PASSWORD`).

### Installer flags

| Flag | Effect |
| --- | --- |
| `--mode=php\|nginx` | Runtime mode persisted into `$GEREQ_HOME/env` |
| `--port=N` | `APP_URL` + listen port (default `8080`) |
| `--fresh` | `php gereq migrate --fresh --seed --no-demo --no-sample` |
| `--demo` | `--fresh` with demo seed |
| `--skip-assets` | skip `bun install` / `bun run build` |
| `--skip-migrate` | skip migrations |

### Environment

| Variable | Default | Purpose |
| --- | --- | --- |
| `GEREQ_HOME` | `~/.local/share/gereq-dockerless` | nginx prefix, FPM socket, logs, pid files, rendered conf |
| `GEREQ_BIN` | `~/.local/bin` | Composer PHAR and other user binaries |
| `GEREQ_PORT` | `8080` | HTTP port |
| `GEREQ_MODE` | `php` | `php` or `nginx` |

`install.sh` appends a PATH export marker to `~/.bashrc` once (`# gereq-dockerless`).

## Layout under `$GEREQ_HOME`

```
~/.local/share/gereq-dockerless/
  conf/           nginx.conf, site.conf, php-fpm.conf, php-fpm.d/, gereq.ini
  run/            nginx.pid, php-fpm.pid, php-fpm.sock, *.pid helpers
  logs/           nginx-*, php-fpm, vite, queue, php-server
  tmp/            nginx client_body / proxy / fastcgi temp dirs
  env             exported MODE/PORT/ROOT from last install
```

Templates live in the repo (`dev/dockerless/nginx/*.template`, `dev/dockerless/php/*.template`) and are rendered with `@ROOT@`, `@GEREQ_HOME@`, `@GEREQ_PORT@`, `@USER@`.

## PHP / nginx on immutable hosts

1. Prefer a system PHP **≥ 8.4** that is already on `PATH` with `gd`, `zip`, `mbstring`, `json`, `pdo_sqlite` (extensions are loaded from readable `/etc/php.d` — the installer does not modify them).
2. If PHP is missing or incomplete, `install.sh` installs **Linuxbrew** into the home prefix and `brew install php` (and `nginx` when `--mode=nginx`).
3. `ext-redis` is optional; Composer is invoked with `--ignore-platform-req=ext-redis` when the extension is absent.
4. nginx mode needs a readable `/etc/nginx/mime.types` and `/etc/nginx/fastcgi_params` (stock packages); all writable paths (`pid`, `error_log`, temp) are redirected under `$GEREQ_HOME`.

Alternative: layer packages once with your OS tool (`rpm-ostree install php php-cli php-fpm php-gd … nginx`) and reboot — then re-run `install.sh` without Homebrew.

## Asset watch

```bash
./dev/dockerless/serve.sh --mode=nginx --hot
```

`bun run dev` writes `public/build` (stable names + `.dev`). PHP serves those files; `render_reload()` refreshes the browser when the manifest changes. There is no Vite origin or `/@vite` proxy.

## Relation to other entry points

| Entry | When to use |
| --- | --- |
| `./dev/dockerless/*` | No Docker; immutable `/home`-only |
| `php gereq serve` | Host already has PHP; one-shot built-in server |
| `./bin/grq install` | Interactive full installer (may still offer Docker) |
| `make install` / `php gereq serve-docker` | Full Compose stack (pgsql + redis + nginx in containers) |

## Scripts

| Script | Role |
| --- | --- |
| `install.sh` | toolchain, `.env`, composer, assets, migrate, render conf |
| `serve.sh` / `up.sh` | start php or nginx+fpm (+ optional `--hot` / `--queue`) |
| `down.sh` | stop nginx, php-fpm, vite, queue, detached php-server |
| `status.sh` | pid / port summary |
| `lib/common.sh` | shared paths and helpers |

## Troubleshooting

- **Permission denied on `/var/log/nginx`**: you started system nginx without `-p $GEREQ_HOME`. Use `./dev/dockerless/serve.sh --mode=nginx`.
- **Port busy**: serve probes the next 20 ports; or `./dev/dockerless/down.sh` first.
- **SELinux denials** on the unix socket: check `ausearch` / set a permissive context on `$GEREQ_HOME/run`, or stick to `--mode=php`.
- **Fresh migrate wiped data**: `--fresh` is destructive; omit it for incremental `php gereq migrate`.
