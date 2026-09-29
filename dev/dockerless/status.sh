#!/usr/bin/env bash
# Show dockerless runtime status (pids, ports, paths).
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/common.sh
source "${SCRIPT_DIR}/lib/common.sh"

if [[ -f "${GEREQ_HOME}/env" ]]; then
  # shellcheck disable=SC1090
  source "${GEREQ_HOME}/env"
fi

echo "GEREQ dockerless status"
echo "  ROOT:       ${ROOT}"
echo "  GEREQ_HOME: ${GEREQ_HOME}"
echo "  MODE:       ${GEREQ_MODE:-?}"
echo "  PORT:       ${GEREQ_PORT:-?}"
echo

show() {
  local name="$1" file="$2"
  if [[ -f "${file}" ]]; then
    local pid
    pid="$(cat "${file}")"
    if kill -0 "${pid}" 2>/dev/null; then
      printf '  %-12s running  pid=%s\n' "${name}" "${pid}"
    else
      printf '  %-12s stale    pidfile=%s\n' "${name}" "${file}"
    fi
  else
    printf '  %-12s stopped\n' "${name}"
  fi
}

show nginx "${GEREQ_HOME}/run/nginx.pid"
show php-fpm "${GEREQ_HOME}/run/php-fpm.pid"
show php-server "$(pid_file php-server)"
show bun-watch "$(pid_file bun-watch)"
show vite "$(pid_file vite)"
show queue "$(pid_file queue)"

echo
if command -v ss >/dev/null 2>&1 && [[ -n "${GEREQ_PORT:-}" ]]; then
  echo "Listeners on :${GEREQ_PORT}:"
  ss -ltnp "sport = :${GEREQ_PORT}" 2>/dev/null || true
fi
