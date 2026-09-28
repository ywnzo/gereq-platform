#!/usr/bin/env bash
# Bootstrap GEREQ without Docker — everything writable lives under $HOME.
# Intended for immutable / atomic hosts where /usr and /etc are read-only.
#
# Usage:
#   ./dev/dockerless/install.sh
#   ./dev/dockerless/install.sh --fresh --demo
#   ./dev/dockerless/install.sh --mode=nginx --port=8080
#   GEREQ_HOME=~/gereq-rt ./dev/dockerless/install.sh
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/common.sh
source "${SCRIPT_DIR}/lib/common.sh"

FRESH=0
DEMO=0
SKIP_ASSETS=0
SKIP_MIGRATE=0
MODE="php"
PORT="${GEREQ_PORT}"

usage() {
  cat <<'EOF'
GEREQ dockerless installer (home-only, no Docker)

Usage: ./dev/dockerless/install.sh [options]

Options:
  --mode=php|nginx   Runtime: built-in PHP server (default) or user-space nginx+php-fpm
  --port=N           HTTP listen port (default 8080)
  --fresh            migrate --fresh (destructive)
  --demo             seed demo data (implies --fresh unless DB already migrated with --skip-migrate)
  --skip-assets      do not run bun install/build
  --skip-migrate     do not run migrations
  -h, --help         show this help

Environment:
  GEREQ_HOME   runtime prefix (default: ~/.local/share/gereq-dockerless)
  GEREQ_BIN    binaries dir    (default: ~/.local/bin)
EOF
}

for arg in "$@"; do
  case "${arg}" in
    --mode=*) MODE="${arg#*=}" ;;
    --port=*) PORT="${arg#*=}" ;;
    --fresh) FRESH=1 ;;
    --demo) DEMO=1; FRESH=1 ;;
    --skip-assets) SKIP_ASSETS=1 ;;
    --skip-migrate) SKIP_MIGRATE=1 ;;
    -h|--help) usage; exit 0 ;;
    *) die "Unknown option: ${arg}" ;;
  esac
done

GEREQ_PORT="${PORT}"
GEREQ_MODE="${MODE}"
export GEREQ_PORT GEREQ_MODE

case "${MODE}" in
  php|nginx) ;;
  *) die "--mode must be php or nginx" ;;
esac

log "Project root: ${ROOT}"
log "Runtime home: ${GEREQ_HOME}"
if system_is_immutable_outside_home; then
  ok "Host looks immutable outside /home (will not write to /usr or /etc)"
else
  warn "Host may allow system writes; dockerless still keeps runtime under \$HOME"
fi

# ── Toolchain ────────────────────────────────────────────────────────────────

install_composer() {
  if command -v composer >/dev/null 2>&1; then
    ok "composer: $(command -v composer)"
    return 0
  fi
  log "Installing Composer PHAR into ${GEREQ_BIN}"
  local tmp
  tmp="$(mktemp -d)"
  (
    cd "${tmp}"
    curl -fsSL https://getcomposer.org/installer -o installer.php
    php installer.php --install-dir="${GEREQ_BIN}" --filename=composer --quiet
  )
  rm -rf "${tmp}"
  ok "composer installed → ${GEREQ_BIN}/composer"
}

install_bun() {
  if command -v bun >/dev/null 2>&1; then
    ok "bun: $(command -v bun)"
    return 0
  fi
  log "Installing Bun into ~/.bun"
  curl -fsSL https://bun.sh/install | bash
  export PATH="${HOME}/.bun/bin:${PATH}"
  command -v bun >/dev/null 2>&1 || die "Bun install finished but bun is not on PATH"
  ok "bun installed → $(command -v bun)"
}

install_linuxbrew_php() {
  warn "System PHP missing or incomplete — trying Homebrew under \$HOME"
  local brew_bin=""
  if [[ -x "${HOME}/.linuxbrew/bin/brew" ]]; then
    brew_bin="${HOME}/.linuxbrew/bin/brew"
  elif [[ -x /home/linuxbrew/.linuxbrew/bin/brew ]]; then
    brew_bin="/home/linuxbrew/.linuxbrew/bin/brew"
  fi
  if [[ -z "${brew_bin}" ]]; then
    log "Installing Linuxbrew (writes only under home / linuxbrew prefix)"
    NONINTERACTIVE=1 /bin/bash -c "$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)"
    if [[ -x "${HOME}/.linuxbrew/bin/brew" ]]; then
      brew_bin="${HOME}/.linuxbrew/bin/brew"
    elif [[ -x /home/linuxbrew/.linuxbrew/bin/brew ]]; then
      brew_bin="/home/linuxbrew/.linuxbrew/bin/brew"
    else
      die "Linuxbrew install failed. Install PHP 8.4+ with gd,zip,mbstring,pdo_sqlite into \$HOME and re-run."
    fi
  fi
  eval "$("${brew_bin}" shellenv)"
  log "brew install php (may take a while)"
  "${brew_bin}" install php
  if [[ "${MODE}" == "nginx" ]]; then
    "${brew_bin}" list nginx >/dev/null 2>&1 || "${brew_bin}" install nginx
  fi
}

ensure_php() {
  if php_version_ok; then
    local missing
    missing="$(php_missing_exts | tr '\n' ' ' | xargs || true)"
    if [[ -z "${missing}" ]]; then
      ok "PHP $(php -r 'echo PHP_VERSION;') with required extensions"
      return 0
    fi
    warn "PHP found but missing extensions: ${missing}"
  else
    warn "PHP >= 8.4 not found on PATH"
  fi
  install_linuxbrew_php
  php_version_ok || die "PHP >= 8.4 still missing after Homebrew attempt"
  local missing2
  missing2="$(php_missing_exts | tr '\n' ' ' | xargs || true)"
  [[ -z "${missing2}" ]] || die "Still missing PHP extensions: ${missing2}"
  ok "PHP $(php -r 'echo PHP_VERSION;') ready"
}

ensure_nginx() {
  [[ "${MODE}" == "nginx" ]] || return 0
  if command -v nginx >/dev/null 2>&1; then
    ok "nginx: $(command -v nginx)"
    return 0
  fi
  install_linuxbrew_php
  command -v nginx >/dev/null 2>&1 || die "nginx not found; install into \$HOME or use --mode=php"
}

ensure_php
install_composer
install_bun
ensure_nginx
ensure_path_export

# Optional redis skip for composer platform check
COMPOSER_FLAGS=(install --no-interaction --prefer-dist)
if ! php_has_ext redis; then
  warn "ext-redis missing — using --ignore-platform-req=ext-redis (file/sync drivers are fine)"
  COMPOSER_FLAGS+=(--ignore-platform-req=ext-redis)
fi

log "composer ${COMPOSER_FLAGS[*]}"
(
  cd "${ROOT}"
  composer "${COMPOSER_FLAGS[@]}"
)

# ── .env (SQLite + file drivers — no Postgres/Redis) ─────────────────────────

write_dockerless_env() {
  local port="$1"
  if [[ -f "${ROOT}/.env" ]]; then
    ok ".env already exists (not overwritten)"
    return 0
  fi
  log "Generating .env (dockerless / SQLite profile)"
  if [[ -x "${ROOT}/bin/grq" ]]; then
    "${ROOT}/bin/grq" env init --port "${port}" || php "${ROOT}/gereq" env:init
  else
    php "${ROOT}/gereq" env:init
  fi
  # Force dockerless-friendly drivers even if schema defaults change later.
  local envf="${ROOT}/.env"
  set_env_key() {
    local key="$1" val="$2"
    if grep -qE "^${key}=" "${envf}"; then
      sed -i "s|^${key}=.*|${key}=${val}|" "${envf}"
    else
      printf '%s=%s\n' "${key}" "${val}" >> "${envf}"
    fi
  }
  set_env_key APP_ENV local
  set_env_key APP_DEBUG true
  set_env_key APP_URL "http://127.0.0.1:${port}"
  set_env_key DB_CONNECTION sqlite
  set_env_key DB_DATABASE storage/database.sqlite
  set_env_key CACHE_DRIVER file
  set_env_key SESSION_DRIVER file
  set_env_key QUEUE_DRIVER sync
  set_env_key TRUSTED_PROXIES '*'
  ok "Wrote dockerless .env overrides"
}

write_dockerless_env "${PORT}"

# Touch SQLite file
mkdir -p "${ROOT}/storage/database"
[[ -f "${ROOT}/storage/database.sqlite" ]] || : > "${ROOT}/storage/database.sqlite"

# ── Render user-space nginx / php-fpm configs ────────────────────────────────

render_runtime_configs() {
  mkdir -p "${GEREQ_HOME}/conf/php-fpm.d" "${GEREQ_HOME}/tmp"/{client_body,proxy,fastcgi,uwsgi,scgi}
  cp "${SCRIPT_DIR}/php/gereq.ini" "${GEREQ_HOME}/conf/gereq.ini"
  render_template "${SCRIPT_DIR}/php/php-fpm.conf.template" "${GEREQ_HOME}/conf/php-fpm.conf"
  render_template "${SCRIPT_DIR}/php/www.conf.template" "${GEREQ_HOME}/conf/php-fpm.d/gereq.conf"
  render_template "${SCRIPT_DIR}/nginx/nginx.conf.template" "${GEREQ_HOME}/conf/nginx.conf"
  render_template "${SCRIPT_DIR}/nginx/site.conf.template" "${GEREQ_HOME}/conf/site.conf"
  # Persist chosen mode/port for serve/up scripts
  cat > "${GEREQ_HOME}/env" <<EOF
export GEREQ_HOME='${GEREQ_HOME}'
export GEREQ_BIN='${GEREQ_BIN}'
export GEREQ_PORT='${PORT}'
export GEREQ_MODE='${MODE}'
export ROOT='${ROOT}'
EOF
  ok "Rendered configs in ${GEREQ_HOME}/conf"
}

render_runtime_configs

# ── Assets ───────────────────────────────────────────────────────────────────

if [[ "${SKIP_ASSETS}" -eq 0 ]]; then
  log "bun install && bun run build"
  (
    cd "${ROOT}"
    bun install
    bun run build
  )
else
  warn "Skipping assets (--skip-assets)"
fi

# ── Migrate ──────────────────────────────────────────────────────────────────

if [[ "${SKIP_MIGRATE}" -eq 0 ]]; then
  migrate_flags=(--no-interaction)
  if [[ "${FRESH}" -eq 1 ]]; then
    migrate_flags+=(--fresh --seed)
    if [[ "${DEMO}" -eq 0 ]]; then
      migrate_flags+=(--no-demo --no-sample)
    fi
  fi
  log "php gereq migrate ${migrate_flags[*]}"
  (
    cd "${ROOT}"
    php gereq migrate "${migrate_flags[@]}"
  )
else
  warn "Skipping migrate (--skip-migrate)"
fi

cat <<EOF

✓ Dockerless install complete

  Mode:    ${MODE}
  Prefix:  ${GEREQ_HOME}
  App URL: http://127.0.0.1:${PORT}/admin

Next:
  ./dev/dockerless/serve.sh              # start (php -S or nginx+fpm)
  ./dev/dockerless/serve.sh --hot        # + bun run dev (Vite HMR)
  ./dev/dockerless/down.sh               # stop background processes
  ./dev/dockerless/status.sh             # show pids / ports

Login: ADMIN_EMAIL / ADMIN_PASSWORD from .env
EOF
