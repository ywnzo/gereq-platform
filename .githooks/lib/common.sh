#!/usr/bin/env bash
# Shared helpers for GEREQ platform git hooks.
# shellcheck shell=bash

REPO_GUARD_SKIP_ENV="${GEREQ_HOOKS_SKIP:-${SKIP_REPO_GUARD:-}}"

hook_die() {
  echo "$*" >&2
  exit 1
}

hook_info() {
  echo "$*" >&2
}

hook_skip_requested() {
  [[ -n "$REPO_GUARD_SKIP_ENV" && "$REPO_GUARD_SKIP_ENV" != "0" ]]
}

path_lower() {
  printf '%s' "$1" | tr '[:upper:]' '[:lower:]'
}

path_basename() {
  local path="$1"
  printf '%s' "${path##*/}"
}

# Return 0 if path looks like a secret / credential file that must never be committed.
is_blocked_secret_path() {
  local path="$1"
  local base lower
  base="$(path_basename "$path")"
  lower="$(path_lower "$base")"

  case "$lower" in
    .env|.env.*|.envrc|.envrc.*)
      case "$lower" in
        .env.example|.env.sample|.env.dist|.env.testing|.env.test)
          return 1
          ;;
      esac
      return 0
      ;;
    id_rsa|id_dsa|id_ecdsa|id_ed25519|*.pem|*.p12|*.pfx|*.key|*.keystore|*.jks)
      return 0
      ;;
    credentials.json|service-account*.json|*-credentials.json|auth.json)
      return 0
      ;;
  esac

  case "$(path_lower "$path")" in
    *.pem|*.p12|*.pfx|*.key|*.keystore|*.jks)
      return 0
      ;;
    docker/nginx/certs/*)
      [[ "$base" == ".gitignore" ]] && return 1
      return 0
      ;;
  esac

  return 1
}

# Return 0 if path is a generated / vendor / local runtime dump that must not enter git.
is_blocked_junk_path() {
  local path="$1"
  case "$path" in
    vendor/*|node_modules/*|*/node_modules/*|public/build/*|public/storefront/*|public/kdeaco-admin/*| \
    storage/logs/*|storage/cache/*|storage/sessions/*|storage/uploads/*|storage/framework/*| \
    storage/tmp/*|storage/modules/*|storage/database.sqlite*|*.log|.phpunit.cache/*| \
    .phpstan-tmp/*|.cache/*|var/*)
      return 0
      ;;
  esac
  case "$(path_basename "$path")" in
    .DS_Store|Thumbs.db)
      return 0
      ;;
  esac
  return 1
}

# Max blob size in bytes (default 5 MiB). Override with GEREQ_HOOKS_MAX_BYTES.
max_blob_bytes() {
  echo "${GEREQ_HOOKS_MAX_BYTES:-5242880}"
}
