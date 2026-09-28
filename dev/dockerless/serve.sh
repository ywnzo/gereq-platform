#!/usr/bin/env bash
# Start GEREQ without Docker (PHP built-in server or user-space nginx+php-fpm).
#
# Usage:
#   ./dev/dockerless/serve.sh
#   ./dev/dockerless/serve.sh --hot
#   ./dev/dockerless/serve.sh --mode=nginx --port=8080
#   ./dev/dockerless/serve.sh --queue
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/common.sh
source "${SCRIPT_DIR}/lib/common.sh"

# Restore last install choices when present.
if [[ -f "${GEREQ_HOME}/env" ]]; then
  # shellcheck disable=SC1090
  source "${GEREQ_HOME}/env"
fi

HOT=0
QUEUE=0
DETACH=0
MODE="${GEREQ_MODE:-php}"
PORT="${GEREQ_PORT:-8080}"

usage() {
  cat <<'EOF'
Start GEREQ dockerless.

  --mode=php|nginx   override runtime mode
  --port=N           HTTP port
  --hot              start bun run dev (Vite HMR)
  --queue            start php gereq queue:work (when QUEUE_DRIVER=file)
  -d, --detach       background PHP built-in server (nginx mode is always daemonized)
  -h, --help
EOF
}

for arg in "$@"; do
  case "${arg}" in
    --mode=*) MODE="${arg#*=}" ;;
    --port=*) PORT="${arg#*=}" ;;
    --hot) HOT=1 ;;
    --queue) QUEUE=1 ;;
    -d|--detach) DETACH=1 ;;
    -h|--help) usage; exit 0 ;;
    *) die "Unknown option: ${arg}" ;;
  esac
done

GEREQ_PORT="${PORT}"
export GEREQ_PORT
require_cmd php

# Persist last chosen mode/port for status/down.
cat > "${GEREQ_HOME}/env" <<EOF
export GEREQ_HOME='${GEREQ_HOME}'
export GEREQ_BIN='${GEREQ_BIN}'
export GEREQ_PORT='${PORT}'
export GEREQ_MODE='${MODE}'
export ROOT='${ROOT}'
EOF

# Re-render configs when port/mode changed.
render_template "${SCRIPT_DIR}/php/php-fpm.conf.template" "${GEREQ_HOME}/conf/php-fpm.conf"
render_template "${SCRIPT_DIR}/php/www.conf.template" "${GEREQ_HOME}/conf/php-fpm.d/gereq.conf"
render_template "${SCRIPT_DIR}/nginx/nginx.conf.template" "${GEREQ_HOME}/conf/nginx.conf"
render_template "${SCRIPT_DIR}/nginx/site.conf.template" "${GEREQ_HOME}/conf/site.conf"
mkdir -p "${GEREQ_HOME}/tmp"/{client_body,proxy,fastcgi,uwsgi,scgi}

chosen="$(find_open_port "${PORT}")" || die "No free port near ${PORT}"
if [[ "${chosen}" != "${PORT}" ]]; then
  warn "Port ${PORT} busy — using ${chosen}"
  PORT="${chosen}"
  GEREQ_PORT="${PORT}"
  export GEREQ_PORT
  render_template "${SCRIPT_DIR}/nginx/nginx.conf.template" "${GEREQ_HOME}/conf/nginx.conf"
  render_template "${SCRIPT_DIR}/nginx/site.conf.template" "${GEREQ_HOME}/conf/site.conf"
fi

start_hot() {
  [[ "${HOT}" -eq 1 ]] || return 0
  require_cmd bun
  if is_running vite; then
    ok "Vite already running (pid $(read_pid vite))"
    return 0
  fi
  log "Starting bun run dev (Vite HMR)"
  (
    cd "${ROOT}"
    nohup bun run dev >"${GEREQ_HOME}/logs/vite.log" 2>&1 &
    echo $! >"$(pid_file vite)"
  )
  ok "Vite pid $(read_pid vite) — log ${GEREQ_HOME}/logs/vite.log"
}

start_queue() {
  [[ "${QUEUE}" -eq 1 ]] || return 0
  if is_running queue; then
    ok "Queue already running (pid $(read_pid queue))"
    return 0
  fi
  log "Starting queue worker"
  (
    cd "${ROOT}"
    nohup php gereq queue:work >"${GEREQ_HOME}/logs/queue.log" 2>&1 &
    echo $! >"$(pid_file queue)"
  )
  ok "Queue pid $(read_pid queue)"
}

start_nginx_mode() {
  require_cmd nginx
  require_cmd php-fpm

  if is_running php-fpm || { [[ -f "${GEREQ_HOME}/run/php-fpm.pid" ]] && kill -0 "$(cat "${GEREQ_HOME}/run/php-fpm.pid")" 2>/dev/null; }; then
    ok "php-fpm already running"
  else
    log "Starting php-fpm (user socket)"
    # -D forces daemonize; do not pass -F/--nodaemonize (that blocks the shell).
    php-fpm -y "${GEREQ_HOME}/conf/php-fpm.conf" -D
    sleep 0.3
    [[ -f "${GEREQ_HOME}/run/php-fpm.pid" ]] || die "php-fpm failed to start — see ${GEREQ_HOME}/logs/php-fpm.log"
    ok "php-fpm pid $(cat "${GEREQ_HOME}/run/php-fpm.pid")"
  fi

  local nginx_err="${GEREQ_HOME}/logs/nginx-error.log"
  if [[ -f "${GEREQ_HOME}/run/nginx.pid" ]] && kill -0 "$(cat "${GEREQ_HOME}/run/nginx.pid")" 2>/dev/null; then
    log "Reloading nginx"
    nginx -e "${nginx_err}" -p "${GEREQ_HOME}/" -c conf/nginx.conf -s reload
  else
    log "Starting nginx -p ${GEREQ_HOME}"
    # -e overrides compile-time default /var/log/nginx/error.log (not writable).
    nginx -e "${nginx_err}" -p "${GEREQ_HOME}/" -c conf/nginx.conf
  fi
  ok "nginx listening on :${PORT}"
}

start_php_mode() {
  if is_running php-server; then
    warn "PHP server already running (pid $(read_pid php-server)) — stop with ./dev/dockerless/down.sh"
    return 0
  fi
  log "Starting php gereq serve --port=${PORT}"
  if [[ "${DETACH}" -eq 1 ]]; then
    (
      cd "${ROOT}"
      nohup php gereq serve --host=127.0.0.1 --port="${PORT}" \
        >"${GEREQ_HOME}/logs/php-server.log" 2>&1 &
      echo $! >"$(pid_file php-server)"
    )
    ok "PHP server pid $(read_pid php-server) — http://127.0.0.1:${PORT}/admin"
  else
    start_hot
    start_queue
    cat <<EOF
GEREQ dockerless (php -S)
  Admin: http://127.0.0.1:${PORT}/admin
  Stop:  Ctrl+C  (or ./dev/dockerless/down.sh for background helpers)
EOF
    cd "${ROOT}"
    exec php gereq serve --host=127.0.0.1 --port="${PORT}"
  fi
}

start_hot
start_queue

case "${MODE}" in
  nginx) start_nginx_mode
    cat <<EOF

✓ nginx + php-fpm up
  Admin: http://127.0.0.1:${PORT}/admin
  Logs:  ${GEREQ_HOME}/logs/
  Stop:  ./dev/dockerless/down.sh
EOF
    ;;
  php) start_php_mode ;;
  *) die "Unknown mode: ${MODE}" ;;
esac
