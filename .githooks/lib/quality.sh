#!/usr/bin/env bash
# Quality pipeline for git hooks (+ CI branch push via scripts/ci-quality-changed.sh).
# By default only on changed PHP / JS-TS surface files.
# Use GEREQ_HOOKS_QUALITY=full to mirror the MR/tag CI / AGENTS.md gate.
# shellcheck shell=bash

# Modes (GEREQ_HOOKS_QUALITY):
#   changed  — PHP tools on changed PHP + bunx tsc + bun run build when JS/TS surface touched
#   full|ci  — composer test + phpstan + phpstan:tests + cs + JS/TS gate (entire tree)
#   analyse  — composer analyse (entire tree; PHP only)
#   cs       — phpcs only (changed files when a path list is provided)
#   off|0|skip — skip quality checks

# $1 = default mode
# $2 = optional "push" — on push, GEREQ_HOOKS_SKIP_QUALITY is ignored (analyse must block push).
quality_mode_requested() {
  local default_mode="$1"
  local context="${2:-}"
  local raw="${GEREQ_HOOKS_QUALITY:-$default_mode}"

  if [[ -n "${GEREQ_HOOKS_SKIP_QUALITY:-}" && "${GEREQ_HOOKS_SKIP_QUALITY}" != "0" ]]; then
    if [[ "$context" == "push" ]]; then
      hook_info "quality: GEREQ_HOOKS_SKIP_QUALITY ignored on pre-push (analysis failures must block push)."
      hook_info "quality: emergency full bypass only: GEREQ_HOOKS_SKIP=1 git push ..."
    else
      echo "off"
      return
    fi
  fi

  case "$(printf '%s' "$raw" | tr '[:upper:]' '[:lower:]')" in
    ''|changed|delta|diff|incremental) echo "changed" ;;
    full|all|ci|pipeline) echo "full" ;;
    analyse|analyze|static) echo "analyse" ;;
    cs|phpcs|style) echo "cs" ;;
    off|0|skip|no|false|none)
      if [[ "$context" == "push" ]]; then
        hook_info "quality: GEREQ_HOOKS_QUALITY=off ignored on pre-push; using '$default_mode'"
        echo "$default_mode"
      else
        echo "off"
      fi
      ;;
    *)
      hook_info "quality: unknown GEREQ_HOOKS_QUALITY='$raw' (use changed|full|analyse|cs|off); falling back to '$default_mode'"
      echo "$default_mode"
      ;;
  esac
}

# Run a command; on failure print label and return 1 (never swallow).
quality_exec() {
  local label="$1"
  shift
  hook_info "quality: $label"
  if ! "$@"; then
    hook_info "quality: FAILED — $label (exit non-zero; refusing to continue)"
    return 1
  fi
  return 0
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

quality_bun_ready() {
  if ! command -v bun >/dev/null 2>&1; then
    hook_info "quality: ERROR: bun not found on PATH (JS/TS gate)"
    return 1
  fi
  if [[ ! -f package.json ]]; then
    hook_info "quality: ERROR: package.json missing (run hooks from repo root)"
    return 1
  fi
  if [[ ! -d node_modules ]]; then
    hook_info "quality: ERROR: node_modules/ missing — run: bun install"
    return 1
  fi
  return 0
}

# Return 0 if path list touches platform JS/TS surface (AGENTS.md frontend gate).
quality_js_surface_touched() {
  local list_file="$1"
  local path
  [[ -f "$list_file" ]] || return 1
  while IFS= read -r path || [[ -n "${path:-}" ]]; do
    [[ -z "${path:-}" ]] && continue
    case "$path" in
      resources/js/*|package.json|bun.lock|tsconfig.json)
        return 0
        ;;
      scripts/*.ts)
        return 0
        ;;
      scripts/*/*.ts|scripts/*/*/*.ts)
        return 0
        ;;
    esac
  done < <(sort -u "$list_file")
  return 1
}

# Platform JS/TS gate: bunx tsc --noEmit && bun run build (AGENTS.md).
quality_run_js() {
  quality_bun_ready || return 1
  quality_exec "bunx tsc --noEmit" bunx tsc --noEmit || return 1
  quality_exec "bun run build" bun run build || return 1
  return 0
}

# Paths changed in a push ref update (remote_sha..local_sha).
# Appends newline-separated paths to $1 (file). Args: outfile remote local_sha remote_sha
quality_append_push_paths() {
  local outfile="$1"
  local remote_name="$2"
  local local_sha="$3"
  local remote_sha="$4"
  local zero="0000000000000000000000000000000000000000"
  local base

  if [[ "$local_sha" == "$zero" ]]; then
    return 0
  fi

  if [[ "$remote_sha" != "$zero" ]]; then
    git diff --name-only --diff-filter=ACMRT "$remote_sha..$local_sha" >>"$outfile" 2>/dev/null || true
    return 0
  fi

  # New remote branch: prefer merge-base with remote HEAD, else commits not on any remote.
  base="$(git merge-base "$local_sha" "refs/remotes/${remote_name}/HEAD" 2>/dev/null || true)"
  if [[ -n "$base" ]]; then
    git diff --name-only --diff-filter=ACMRT "$base..$local_sha" >>"$outfile" 2>/dev/null || true
    return 0
  fi

  git log --pretty=format: --name-only --diff-filter=ACMRT "$local_sha" --not --remotes="${remote_name}" \
    >>"$outfile" 2>/dev/null || true
}

# Filter a path list file → existing PHP under quality surfaces. Prints paths.
quality_php_surface_from_file() {
  local list_file="$1"
  local path
  [[ -f "$list_file" ]] || return 0
  while IFS= read -r path || [[ -n "${path:-}" ]]; do
    [[ -z "${path:-}" ]] && continue
    case "$path" in
      src/*|modules/*|tests/*|integrations/*|bin/*) ;;
      *) continue ;;
    esac
    case "$path" in
      *.php) ;;
      *) continue ;;
    esac
    [[ -f "$path" ]] || continue
    printf '%s\n' "$path"
  done < <(sort -u "$list_file")
}

quality_run_cs_paths() {
  local -a files=("$@")
  if [[ "${#files[@]}" -eq 0 ]]; then
    hook_info "quality: cs — no changed PHP in scope, skip"
    return 0
  fi
  quality_exec "phpcs (${#files[@]} file(s))" ./vendor/bin/phpcs -- "${files[@]}" || return 1
  return 0
}

quality_run_phpstan_paths() {
  local -a files=("$@")
  local -a main=()
  local -a strict=()
  local f

  for f in "${files[@]}"; do
    case "$f" in
      src/Testing/*|tests/*)
        strict+=("$f")
        main+=("$f")
        ;;
      src/*|modules/*|bin/*)
        main+=("$f")
        ;;
      # integrations/ are not in phpstan.neon paths — cs/tests only
    esac
  done

  if [[ "${#main[@]}" -gt 0 ]]; then
    quality_exec "phpstan (${#main[@]} file(s))" \
      ./vendor/bin/phpstan analyse -c phpstan.neon --memory-limit=1G --no-progress -- "${main[@]}" \
      || return 1
  else
    hook_info "quality: phpstan — no matching changed files, skip"
  fi

  if [[ "${#strict[@]}" -gt 0 ]]; then
    quality_exec "phpstan:tests (${#strict[@]} file(s))" \
      ./vendor/bin/phpstan analyse -c phpstan-tests.neon --memory-limit=1G --no-progress -- "${strict[@]}" \
      || return 1
  else
    hook_info "quality: phpstan:tests — no changed tests/Testing files, skip"
  fi
  return 0
}

quality_run_phpunit_paths() {
  local -a files=("$@")
  local -a tests=()
  local f

  for f in "${files[@]}"; do
    case "$f" in
      *Test.php) tests+=("$f") ;;
    esac
  done

  if [[ "${#tests[@]}" -eq 0 ]]; then
    hook_info "quality: phpunit — no changed *Test.php files, skip"
    return 0
  fi

  quality_exec "phpunit (${#tests[@]} test file(s))" ./vendor/bin/phpunit -- "${tests[@]}" || return 1
  return 0
}

# Incremental gate on a newline-separated path list file.
quality_run_changed() {
  local list_file="$1"
  local tools="${2:-all}" # all | cs
  local -a files=()
  local path
  local ran_any=0

  while IFS= read -r path || [[ -n "${path:-}" ]]; do
    [[ -z "${path:-}" ]] && continue
    files+=("$path")
  done < <(quality_php_surface_from_file "$list_file")

  if [[ "${#files[@]}" -gt 0 ]]; then
    ran_any=1
    quality_composer_ready || return 1
    hook_info "quality: changed mode — ${#files[@]} PHP file(s)"
    quality_run_cs_paths "${files[@]}" || return 1

    if [[ "$tools" != "cs" ]]; then
      quality_run_phpstan_paths "${files[@]}" || return 1
      quality_run_phpunit_paths "${files[@]}" || return 1
    fi
  else
    hook_info "quality: no changed PHP under src|modules|tests|integrations|bin — skip PHP tools"
  fi

  if [[ "$tools" == "cs" ]]; then
    [[ "$ran_any" -eq 1 ]] || hook_info "quality: nothing to check (cs mode, no PHP)"
    return 0
  fi

  if quality_js_surface_touched "$list_file"; then
    ran_any=1
    hook_info "quality: JS/TS surface touched — platform frontend gate"
    quality_run_js || return 1
  else
    hook_info "quality: JS/TS surface unchanged — skip bun tsc/build"
  fi

  if [[ "$ran_any" -eq 0 ]]; then
    hook_info "quality: no PHP or JS/TS surface in path list — skip"
  fi
  return 0
}

# Full tree gate (CI / AGENTS.md). Each step must fail the function.
quality_run_full() {
  quality_composer_ready || return 1
  quality_exec "composer test" composer test || return 1
  quality_exec "composer phpstan" composer phpstan || return 1
  quality_exec "composer phpstan:tests" composer phpstan:tests || return 1
  quality_exec "composer cs" composer cs || return 1
  hook_info "quality: JS/TS platform gate"
  quality_run_js || return 1
  return 0
}

# Whole-tree static analysis only (must fail push/CI when red).
quality_run_analyse() {
  quality_composer_ready || return 1
  quality_exec "composer phpstan" composer phpstan || return 1
  quality_exec "composer phpstan:tests" composer phpstan:tests || return 1
  return 0
}

# Local stamp so pre-push can skip when pre-commit already verified the same tip.
# Stored under $GIT_DIR (never committed). Format: mode=<m>\nfingerprint=<hex>\n
quality_stamp_path() {
  local git_dir
  git_dir="$(git rev-parse --git-dir 2>/dev/null)" || return 1
  printf '%s\n' "${git_dir%/}/gereq-quality.stamp"
}

# Resolve blob OID for path: staged index (empty tip) or tip:path.
quality_blob_oid() {
  local path="$1"
  local tip="${2:-}"
  if [[ -n "$tip" ]]; then
    git rev-parse -q --verify "${tip}:${path}" 2>/dev/null || true
  else
    git rev-parse -q --verify ":${path}" 2>/dev/null || true
  fi
}

# Return 0 if path is in the incremental quality surface (PHP tools or JS/TS gate).
quality_path_in_surface() {
  local path="$1"
  case "$path" in
    resources/js/*|package.json|bun.lock|tsconfig.json)
      return 0
      ;;
    scripts/*)
      [[ "$path" == *.ts ]] && return 0
      return 1
      ;;
    src/*|modules/*|tests/*|integrations/*|bin/*)
      [[ "$path" == *.php ]] && return 0
      return 1
      ;;
  esac
  return 1
}

# Fingerprint for incremental modes: mode + sorted quality-surface path:blob lines.
# For analyse/full: mode + tree OID (tip^{tree} or index write-tree).
quality_fingerprint() {
  local mode="$1"
  local list_file="${2:-}"
  local tip="${3:-}"
  local path oid tree payload

  case "$mode" in
    analyse|full)
      if [[ -n "$tip" ]]; then
        tree="$(git rev-parse -q --verify "${tip}^{tree}" 2>/dev/null)" || return 1
      else
        tree="$(git write-tree 2>/dev/null)" || return 1
      fi
      printf '%s\n' "$mode" "$tree" | sha256sum | awk '{print $1}'
      return 0
      ;;
  esac

  if [[ -z "$list_file" || ! -f "$list_file" ]]; then
    return 1
  fi

  payload="$(
    printf '%s\n' "$mode"
    while IFS= read -r path || [[ -n "${path:-}" ]]; do
      [[ -z "${path:-}" ]] && continue
      quality_path_in_surface "$path" || continue
      oid="$(quality_blob_oid "$path" "$tip")"
      [[ -n "$oid" ]] || continue
      printf '%s %s\n' "$path" "$oid"
    done < <(LC_ALL=C sort -u "$list_file")
  )"
  printf '%s\n' "$payload" | sha256sum | awk '{print $1}'
}

quality_stamp_save() {
  local mode="$1"
  local fingerprint="$2"
  local stamp
  stamp="$(quality_stamp_path)" || return 0
  printf 'mode=%s\nfingerprint=%s\n' "$mode" "$fingerprint" >"$stamp"
}

# Return 0 if an existing stamp already covers this mode+fingerprint.
quality_stamp_covers() {
  local mode="$1"
  local fingerprint="$2"
  local stamp stamped_mode stamped_fp

  if [[ -n "${GEREQ_HOOKS_QUALITY_FORCE:-}" && "${GEREQ_HOOKS_QUALITY_FORCE}" != "0" ]]; then
    return 1
  fi

  stamp="$(quality_stamp_path)" || return 1
  [[ -f "$stamp" ]] || return 1

  stamped_mode="$(sed -n 's/^mode=//p' "$stamp" | head -n1)"
  stamped_fp="$(sed -n 's/^fingerprint=//p' "$stamp" | head -n1)"
  [[ -n "$stamped_mode" && -n "$stamped_fp" ]] || return 1
  [[ "$stamped_mode" == "$mode" && "$stamped_fp" == "$fingerprint" ]]
}

# $1 = mode, $2 = optional path-list file (required for changed/cs incremental).
# $3 = optional context: commit|push|ci
#      commit — always run, then stamp (feeds pre-push dedupe)
#      push   — skip when stamp matches; tip blobs via GEREQ_HOOKS_QUALITY_TIP
#      ci     — always run, never stamp
quality_run_pipeline() {
  local mode="$1"
  local list_file="${2:-}"
  local context="${3:-}"
  local tip="${GEREQ_HOOKS_QUALITY_TIP:-}"
  local fingerprint=""

  case "$mode" in
    off)
      hook_info "quality: skipped (GEREQ_HOOKS_QUALITY / GEREQ_HOOKS_SKIP_QUALITY)"
      return 0
      ;;
  esac

  if [[ "$context" == "push" ]]; then
    if fingerprint="$(quality_fingerprint "$mode" "$list_file" "$tip" 2>/dev/null)" \
      && [[ -n "$fingerprint" ]] \
      && quality_stamp_covers "$mode" "$fingerprint"; then
      hook_info "quality: mode=$mode — already verified at commit (stamp match) — skip"
      return 0
    fi
  fi

  hook_info "quality: mode=$mode"

  case "$mode" in
    changed)
      if [[ -z "$list_file" || ! -f "$list_file" ]]; then
        hook_info "quality: ERROR: changed mode needs a path list file"
        return 1
      fi
      quality_run_changed "$list_file" all || return 1
      ;;
    cs)
      if [[ -n "$list_file" && -f "$list_file" ]]; then
        quality_run_changed "$list_file" cs || return 1
      else
        quality_composer_ready || return 1
        hook_info "quality: composer cs (full tree)"
        composer cs || return 1
      fi
      ;;
    analyse)
      # Prefer explicit phpstan + phpstan:tests (composer analyse may omit tests suite).
      quality_run_analyse || return 1
      ;;
    full)
      quality_run_full || return 1
      ;;
    *)
      hook_info "quality: ERROR: internal unknown mode '$mode'"
      return 1
      ;;
  esac

  # Stamp successful local hook runs so the next push can skip the same work.
  if [[ "$context" == "commit" || "$context" == "push" ]]; then
    if fingerprint="$(quality_fingerprint "$mode" "$list_file" "$tip" 2>/dev/null)" \
      && [[ -n "$fingerprint" ]]; then
      quality_stamp_save "$mode" "$fingerprint"
    fi
  fi

  return 0
}
