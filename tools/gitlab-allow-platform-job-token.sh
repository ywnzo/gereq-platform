#!/usr/bin/env bash
# Allow gereq/GEREQ_PLATFORM CI_JOB_TOKEN to clone every module-* project (inbound allowlist).
# One-time group admin setup — no per-developer keys.
#
# Usage:
#   export GITLAB_TOKEN=glpat-...   # needs api scope on the gereq group
#   bash tools/gitlab-allow-platform-job-token.sh
#
# Optional: GITLAB_URL=https://git.gereq.cz GITLAB_GROUP=gereq PLATFORM_PATH=gereq/GEREQ_PLATFORM
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
if [[ -z "${GITLAB_TOKEN:-}" && -f "$ROOT/.env" ]]; then
  # shellcheck disable=SC1091
  set -a
  # shellcheck disable=SC1090
  source <(grep -E '^(GITLAB_URL|GITLAB_TOKEN|GITLAB_GROUP)=' "$ROOT/.env" || true)
  set +a
fi

API="${GITLAB_URL:-https://git.gereq.cz}/api/v4"
TOKEN="${GITLAB_TOKEN:-}"
GROUP="${GITLAB_GROUP:-gereq}"
PLATFORM_PATH="${PLATFORM_PATH:-gereq/GEREQ_PLATFORM}"

if [[ -z "$TOKEN" ]]; then
  echo "GITLAB_TOKEN is empty (PAT with api scope on group ${GROUP})." >&2
  exit 1
fi

auth=(-H "PRIVATE-TOKEN: ${TOKEN}")
platform_enc="$(python3 -c "import urllib.parse; print(urllib.parse.quote('${PLATFORM_PATH}', safe=''))")"

echo "Resolving platform project ${PLATFORM_PATH}..."
platform_id="$(curl -fsS "${auth[@]}" "${API}/projects/${platform_enc}" | python3 -c "import sys,json; print(json.load(sys.stdin)['id'])")"
echo "Platform project id=${platform_id}"

echo "Listing ${GROUP}/module-* projects..."
page=1
ids=()
while true; do
  batch="$(curl -fsS "${auth[@]}" \
    "${API}/groups/$(python3 -c "import urllib.parse; print(urllib.parse.quote('${GROUP}', safe=''))")/projects?per_page=100&page=${page}&simple=true")"
  count="$(python3 -c "import sys,json; print(len(json.load(sys.stdin)))" <<<"$batch")"
  [[ "$count" -eq 0 ]] && break
  while IFS= read -r line; do
    ids+=("$line")
  done < <(python3 -c "
import sys, json
for p in json.load(sys.stdin):
    path = p.get('path', '')
    if path.startswith('module-'):
        print(f\"{p['id']}\t{p.get('path_with_namespace', path)}\")
" <<<"$batch")
  page=$((page + 1))
done

if [[ ${#ids[@]} -eq 0 ]]; then
  echo "No module-* projects found in group ${GROUP}." >&2
  exit 1
fi

ok=0
fail=0
for entry in "${ids[@]}"; do
  id="${entry%%$'\t'*}"
  path="${entry#*$'\t'}"
  # Inbound allowlist: target project allows CI_JOB_TOKEN from PLATFORM.
  code="$(curl -sS -o /tmp/gitlab-job-token-allow.json -w '%{http_code}' "${auth[@]}" \
    -X POST "${API}/projects/${id}/job_token_scope/allowlist" \
    --data-urlencode "target_project_id=${platform_id}" || true)"
  if [[ "$code" == "200" || "$code" == "201" ]]; then
    echo "OK  ${path} (#${id})"
    ok=$((ok + 1))
  elif [[ "$code" == "400" ]] && grep -qi 'already\|exist' /tmp/gitlab-job-token-allow.json 2>/dev/null; then
    echo "SKIP ${path} (#${id}) — already allowed"
    ok=$((ok + 1))
  else
    echo "FAIL ${path} (#${id}) HTTP ${code}: $(cat /tmp/gitlab-job-token-allow.json 2>/dev/null)" >&2
    fail=$((fail + 1))
  fi
done

echo "Done: ${ok} ok, ${fail} failed."
[[ "$fail" -eq 0 ]]
