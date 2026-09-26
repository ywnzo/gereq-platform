#!/usr/bin/env bash
# Set GitLab project description + avatar for published tools.
# Requires GITLAB_TOKEN in env or platform .env
# Usage: bash tools/gitlab-set-tool-meta.sh
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

if [[ -z "${GITLAB_TOKEN:-}" && -f .env ]]; then
  # shellcheck disable=SC1091
  set -a
  # Load only GITLAB_* without sourcing whole .env (may have spaces)
  while IFS= read -r line; do
    case "$line" in
      GITLAB_URL=*|GITLAB_TOKEN=*|GITLAB_GROUP=*)
        key="${line%%=*}"
        val="${line#*=}"
        val="${val%\"}"; val="${val#\"}"
        val="${val%\'}"; val="${val#\'}"
        export "$key=$val"
        ;;
    esac
  done < .env
  set +a
fi

API="${GITLAB_URL:-https://git.gereq.cz}/api/v4"
GROUP="${GITLAB_GROUP:-gereq}"
TOKEN="${GITLAB_TOKEN:-}"

if [[ -z "$TOKEN" ]]; then
  echo "GITLAB_TOKEN is empty — set it in .env and re-run." >&2
  exit 1
fi

TOOLS=(
  "gereq-env|tool-env|GEREQ Env Schema — .env autocomplete from env.schema.toml (Cursor/VS Code)"
  "gereq-i18n|tool-i18n|GEREQ i18n — translation key hover/autocomplete/goto (Cursor/VS Code)"
  "gereq-permissions|tool-permissions|GEREQ Permissions — permission key hover/autocomplete/goto (Cursor/VS Code)"
  "gereq-twig-goto|tool-twig-goto|GEREQ Twig IDE — Twig object hover/completion/goto (Cursor/VS Code)"
  "gereq-monitor|tool-pulse|GEREQ Pulse — Toggl-style work tracking from the editor (Cursor/VS Code)"
  "gereq-modules|tool-modules|GEREQ Modules — TUI comparing module versions with GitLab tags"
)

for row in "${TOOLS[@]}"; do
  IFS='|' read -r dir path desc <<<"$row"
  enc="$(python3 -c "import urllib.parse; print(urllib.parse.quote('${GROUP}/${path}', safe=''))")"
  echo "→ ${GROUP}/${path}"

  curl -sS --request PUT \
    --header "PRIVATE-TOKEN: $TOKEN" \
    --data-urlencode "description=${desc}" \
    --data-urlencode "name=$(python3 -c "print('''$desc'''.split('—')[0].strip())")" \
    "${API}/projects/${enc}" >/tmp/gl-proj.json

  logo="$ROOT/tools/$dir/logo.png"
  if [[ -f "$logo" ]]; then
    curl -sS --request PUT \
      --header "PRIVATE-TOKEN: $TOKEN" \
      --form "avatar=@${logo}" \
      "${API}/projects/${enc}" >/tmp/gl-avatar.json
    echo "  ✓ description + avatar"
  else
    echo "  ✓ description (no logo.png)"
  fi
done

# Optional: delete accidental push-to-create test project
if curl -sS --header "PRIVATE-TOKEN: $TOKEN" \
  "${API}/projects/$(python3 -c "import urllib.parse; print(urllib.parse.quote('${GROUP}/tool-push-create-test', safe=''))")" \
  | python3 -c 'import json,sys; d=json.load(sys.stdin); raise SystemExit(0 if d.get("id") else 1)' 2>/dev/null; then
  echo "→ deleting gereq/tool-push-create-test"
  curl -sS --request DELETE --header "PRIVATE-TOKEN: $TOKEN" \
    "${API}/projects/$(python3 -c "import urllib.parse; print(urllib.parse.quote('${GROUP}/tool-push-create-test', safe=''))")" >/dev/null
  echo "  ✓ removed"
fi

echo "Done."
