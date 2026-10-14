#!/usr/bin/env bash
# Quality pipeline shared by git hooks — mirrors AGENTS.md gate and .gitlab-ci.yml.
# shellcheck shell=bash

# Modes (GEREQ_HOOKS_QUALITY):
#   full     — composer test + phpstan + phpstan:tests + cs  (default pre-push)
#   analyse  — composer analyse (= phpstan + phpstan:tests + cs)
#   cs       — composer cs only (default pre-commit when PHP staged)
#   off|0|skip — skip quality checks

quality_mode_requested() {
  local default_mode="$1"
  local raw="${GEREQ_HOOKS_QUALITY:-$default_mode}"
  # Dedicated skip flag (keeps repo-guard hooks active).
  if [[ -n "${GEREQ_HOOKS_SKIP_QUALITY:-}" && "${GEREQ_HOOKS_SKIP_QUALITY}" != "0" ]]; then
    echo "off"
    return
  fi
  case "$(printf '%s' "$raw" | tr '[:upper:]' '[:lower:]')" in
    ''|full|all|ci|pipeline) echo "full" ;;
    analyse|analyze|static) echo "analyse" ;;
    cs|phpcs|style) echo "cs" ;;
    off|0|skip|no|false|none) echo "off" ;;
    *)
      hook_info "quality: unknown GEREQ_HOOKS_QUALITY='$raw' (use full|analyse|cs|off); falling back to '$default_mode'"
      echo "$default_mode"
      ;;
  esac
}

quality_composer_ready() {
  if ! command -v composer >/dev/null 2>&1; then
    hook_info "quality: ERROR: composer not found on PATH"
    return 1
  fi
  if [[ ! -f composer.json ]]; then
    hook_info "quality: ERROR: composer.json missing (run hooks from repo root)"
    return 1
  fi
  if [[ ! -d vendor ]]; then
    hook_info "quality: ERROR: vendor/ missing — run: composer install"
    return 1
  fi
  return 0
}

# Return 0 if any staged path looks like PHP quality surface.
quality_staged_php_paths() {
  git diff --cached --name-only --diff-filter=ACMRT \
    | grep -E '\.php$' \
    | grep -E '^(src|modules|tests|integrations|bin)/' \
    || true
}

# Fast path: phpcs only on staged PHP files (falls back to full cs if none listed).
quality_run_cs_staged() {
  local -a files=()
  local path
  while IFS= read -r path || [[ -n "${path:-}" ]]; do
    [[ -z "${path:-}" ]] && continue
    [[ -f "$path" ]] || continue
    files+=("$path")
  done < <(quality_staged_php_paths)

  if [[ "${#files[@]}" -eq 0 ]]; then
    hook_info "quality: no staged PHP under src|modules|tests|integrations|bin — skipping cs"
    return 0
  fi

  hook_info "quality: composer cs (${#files[@]} staged PHP file(s))"
  composer cs -- "${files[@]}"
}

# Full / analyse / cs pipeline (same commands as CI analyse + test stages).
quality_run_pipeline() {
  local mode="$1"

  case "$mode" in
    off)
      hook_info "quality: skipped (GEREQ_HOOKS_QUALITY / GEREQ_HOOKS_SKIP_QUALITY)"
      return 0
      ;;
  esac

  if ! quality_composer_ready; then
    return 1
  fi

  hook_info "quality: mode=$mode (mirrors AGENTS.md / .gitlab-ci.yml)"

  case "$mode" in
    cs)
      if [[ "${2:-}" == "staged" ]]; then
        quality_run_cs_staged
      else
        hook_info "quality: composer cs"
        composer cs
      fi
      ;;
    analyse)
      hook_info "quality: composer analyse  (phpstan + phpstan:tests + cs)"
      composer analyse
      ;;
    full)
      hook_info "quality: composer test"
      composer test
      hook_info "quality: composer phpstan"
      composer phpstan
      hook_info "quality: composer phpstan:tests"
      composer phpstan:tests
      hook_info "quality: composer cs"
      composer cs
      ;;
    *)
      hook_info "quality: ERROR: internal unknown mode '$mode'"
      return 1
      ;;
  esac
}
