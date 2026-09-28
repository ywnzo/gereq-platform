#!/usr/bin/env bash
# Shared helpers for GEREQ dockerless (home-only) tooling.
# shellcheck disable=SC2034

set -euo pipefail

DOCKERLESS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ROOT="$(cd "${DOCKERLESS_DIR}/../.." && pwd)"

# Runtime prefix under $HOME — writable on immutable OS (read-only outside /home).
GEREQ_HOME="${GEREQ_HOME:-${HOME}/.local/share/gereq-dockerless}"
GEREQ_BIN="${GEREQ_BIN:-${HOME}/.local/bin}"
GEREQ_PORT="${GEREQ_PORT:-8080}"
GEREQ_FPM_PORT="${GEREQ_FPM_PORT:-9080}"
GEREQ_MODE="${GEREQ_MODE:-php}" # php | nginx

mkdir -p \
  "${GEREQ_HOME}"/{run,logs,tmp,conf/php-fpm.d} \
  "${GEREQ_BIN}" \
  "${ROOT}/storage"/{cache,logs,sessions,tmp/queue,uploads,invoices,database}

export PATH="${GEREQ_BIN}:${HOME}/.bun/bin:${HOME}/.linuxbrew/bin:/home/linuxbrew/.linuxbrew/bin:${PATH}"

log()  { printf '→ %s\n' "$*"; }
ok()   { printf '✓ %s\n' "$*"; }
warn() { printf '! %s\n' "$*" >&2; }
die()  { printf '✗ %s\n' "$*" >&2; exit 1; }

is_writable_dir() {
  local d="$1"
  [[ -d "$d" ]] || return 1
  local probe="${d}/.gereq-write-probe.$$"
  if touch "${probe}" 2>/dev/null; then
    rm -f "${probe}"
    return 0
  fi
  return 1
}

system_is_immutable_outside_home() {
  # Heuristic: /usr and /etc exist but are not writable by the current user.
  if ! is_writable_dir /usr && ! is_writable_dir /etc; then
    return 0
  fi
  return 1
}

require_cmd() {
  command -v "$1" >/dev/null 2>&1 || die "Missing command: $1"
}

php_bin() {
  command -v php
}

php_version_ok() {
  local v
  v="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null || true)"
  [[ -n "$v" ]] || return 1
  # Require PHP >= 8.4
  php -r 'exit(version_compare(PHP_VERSION, "8.4.0", ">=") ? 0 : 1);' 2>/dev/null
}

php_has_ext() {
  php -m 2>/dev/null | grep -qi "^${1}$"
}

php_missing_exts() {
  local need=(gd zip mbstring json pdo pdo_sqlite)
  local missing=()
  local e
  for e in "${need[@]}"; do
    php_has_ext "$e" || missing+=("$e")
  done
  printf '%s\n' "${missing[@]-}"
}

ensure_path_export() {
  local marker='# gereq-dockerless'
  local rc="${HOME}/.bashrc"
  local line='export PATH="$HOME/.local/bin:$HOME/.bun/bin:$HOME/.linuxbrew/bin:/home/linuxbrew/.linuxbrew/bin:$PATH"'
  touch "${rc}"
  if ! grep -qF "${marker}" "${rc}" 2>/dev/null; then
    {
      echo ""
      echo "${marker}"
      echo "${line}"
    } >> "${rc}"
    ok "Appended PATH export to ${rc}"
  fi
}

pid_file() {
  echo "${GEREQ_HOME}/run/$1.pid"
}

write_pid() {
  echo "$2" > "$(pid_file "$1")"
}

read_pid() {
  local f
  f="$(pid_file "$1")"
  [[ -f "$f" ]] || return 1
  cat "$f"
}

is_running() {
  local name="$1" pid
  pid="$(read_pid "${name}" 2>/dev/null || true)"
  [[ -n "${pid}" ]] && kill -0 "${pid}" 2>/dev/null
}

stop_pid_name() {
  local name="$1" pid
  pid="$(read_pid "${name}" 2>/dev/null || true)"
  if [[ -n "${pid}" ]] && kill -0 "${pid}" 2>/dev/null; then
    kill "${pid}" 2>/dev/null || true
    # Give graceful shutdown a moment; escalate if needed.
    for _ in 1 2 3 4 5; do
      kill -0 "${pid}" 2>/dev/null || break
      sleep 0.2
    done
    if kill -0 "${pid}" 2>/dev/null; then
      kill -9 "${pid}" 2>/dev/null || true
    fi
    ok "Stopped ${name} (pid ${pid})"
  fi
  rm -f "$(pid_file "${name}")"
}

port_free() {
  local port="$1"
  if command -v ss >/dev/null 2>&1; then
    ! ss -ltn "sport = :${port}" 2>/dev/null | grep -q ":${port}"
  else
    ! (echo >/dev/tcp/127.0.0.1/"${port}") 2>/dev/null
  fi
}

find_open_port() {
  local start="$1" p
  for ((p = start; p < start + 21; p++)); do
    if port_free "${p}"; then
      echo "${p}"
      return 0
    fi
  done
  return 1
}

render_template() {
  # Replace @VAR@ placeholders from environment.
  local src="$1" dest="$2"
  local content
  content="$(cat "${src}")"
  content="${content//@ROOT@/${ROOT}}"
  content="${content//@GEREQ_HOME@/${GEREQ_HOME}}"
  content="${content//@GEREQ_PORT@/${GEREQ_PORT}}"
  content="${content//@USER@/${USER}}"
  content="${content//@HOME@/${HOME}}"
  printf '%s\n' "${content}" > "${dest}"
}
