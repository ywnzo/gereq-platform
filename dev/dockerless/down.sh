#!/usr/bin/env bash
# Stop dockerless background processes (nginx, php-fpm, bun watch, queue, php -S).
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/common.sh
source "${SCRIPT_DIR}/lib/common.sh"

if [[ -f "${GEREQ_HOME}/env" ]]; then
  # shellcheck disable=SC1090
  source "${GEREQ_HOME}/env"
fi

log "Stopping dockerless processes under ${GEREQ_HOME}"

# nginx (own pid file)
if [[ -f "${GEREQ_HOME}/run/nginx.pid" ]]; then
  pid="$(cat "${GEREQ_HOME}/run/nginx.pid")"
  if kill -0 "${pid}" 2>/dev/null; then
    if command -v nginx >/dev/null 2>&1; then
      nginx -e "${GEREQ_HOME}/logs/nginx-error.log" \
        -p "${GEREQ_HOME}/" -c conf/nginx.conf -s stop 2>/dev/null || kill "${pid}" 2>/dev/null || true
    else
      kill "${pid}" 2>/dev/null || true
    fi
    ok "Stopped nginx (pid ${pid})"
  fi
  rm -f "${GEREQ_HOME}/run/nginx.pid"
fi

# php-fpm
if [[ -f "${GEREQ_HOME}/run/php-fpm.pid" ]]; then
  pid="$(cat "${GEREQ_HOME}/run/php-fpm.pid")"
  if kill -0 "${pid}" 2>/dev/null; then
    kill "${pid}" 2>/dev/null || true
    ok "Stopped php-fpm (pid ${pid})"
  fi
  rm -f "${GEREQ_HOME}/run/php-fpm.pid" "${GEREQ_HOME}/run/php-fpm.sock"
fi

stop_pid_name php-server
stop_pid_name bun-watch
stop_pid_name vite
stop_pid_name queue

ok "Dockerless stack stopped"
