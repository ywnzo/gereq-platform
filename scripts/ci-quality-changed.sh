#!/usr/bin/env bash
# GitLab CI — incremental quality on PHP / JS-TS files changed in this push
# (same gate as local hooks with GEREQ_HOOKS_QUALITY=changed).
# shellcheck shell=bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

# shellcheck source=../.githooks/lib/common.sh
source "$ROOT/.githooks/lib/common.sh"
# shellcheck source=../.githooks/lib/quality.sh
source "$ROOT/.githooks/lib/quality.sh"

list_file="$(mktemp)"
trap 'rm -f "$list_file"' EXIT

zero="0000000000000000000000000000000000000000"
before="${CI_COMMIT_BEFORE_SHA:-$zero}"
sha="${CI_COMMIT_SHA:-HEAD}"
default_branch="${CI_DEFAULT_BRANCH:-dev}"

append_diff() {
  local from="$1"
  local to="$2"
  git diff --name-only --diff-filter=ACMRT "$from..$to" >>"$list_file" 2>/dev/null || true
}

ensure_ref() {
  local ref="$1"
  git rev-parse --verify "$ref" >/dev/null 2>&1 && return 0
  git fetch --no-tags --depth=200 origin "$ref" >/dev/null 2>&1 || true
  git rev-parse --verify "origin/${ref}" >/dev/null 2>&1 \
    || git rev-parse --verify "$ref" >/dev/null 2>&1
}

range_from=""
range_to=""

if [[ "$before" != "$zero" ]] && git cat-file -e "${before}^{commit}" 2>/dev/null; then
  hook_info "ci-quality: diff ${before:0:8}..${sha:0:8}"
  append_diff "$before" "$sha"
  range_from="$before"
  range_to="$sha"
else
  # New branch / missing before SHA: range against merge-base with default branch.
  ensure_ref "$default_branch" || true
  base="$(
    git merge-base "$sha" "origin/${default_branch}" 2>/dev/null \
      || git merge-base "$sha" "$default_branch" 2>/dev/null \
      || true
  )"
  if [[ -n "${base:-}" ]]; then
    hook_info "ci-quality: new tip — diff merge-base(${default_branch}) ${base:0:8}..${sha:0:8}"
    append_diff "$base" "$sha"
    range_from="$base"
    range_to="$sha"
  else
    hook_info "ci-quality: fallback — files in tip commit ${sha:0:8}"
    git diff-tree --no-commit-id --name-only --diff-filter=ACMRT -r "$sha" >>"$list_file" || true
    range_to="$sha"
    range_from="$(git rev-parse -q --verify "${sha}^" 2>/dev/null || true)"
  fi
fi

if [[ -n "$range_from" && -n "$range_to" ]]; then
  quality_append_gitlink_paths "$list_file" "$range_from" "$range_to"
fi

quality_run_pipeline changed "$list_file"
