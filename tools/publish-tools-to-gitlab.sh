#!/usr/bin/env bash
# Publish tools/gereq-* as standalone GitLab projects (push-to-create).
# Usage: bash tools/publish-tools-to-gitlab.sh
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
HOST="${GITLAB_HOST:-git.gereq.cz}"
GROUP="${GITLAB_GROUP:-gereq}"

# local_dir|gitlab_path|short description
TOOLS=(
  "gereq-env|tool-env|GEREQ Env Schema — .env autocomplete from env.schema.toml (Cursor/VS Code)"
  "gereq-i18n|tool-i18n|GEREQ i18n — translation key hover/autocomplete/goto (Cursor/VS Code)"
  "gereq-permissions|tool-permissions|GEREQ Permissions — permission key hover/autocomplete/goto (Cursor/VS Code)"
  "gereq-twig-goto|tool-twig-goto|GEREQ Twig IDE — Twig object hover/completion/goto (Cursor/VS Code)"
  "gereq-monitor|tool-pulse|GEREQ Pulse — Toggl-style work tracking from the editor (Cursor/VS Code)"
  "gereq-modules|tool-modules|GEREQ Modules — TUI comparing module versions with GitLab tags"
)

publish_one() {
  local dir="$1" path="$2" desc="$3"
  local src="$ROOT/tools/$dir"
  local remote="git@${HOST}:${GROUP}/${path}.git"
  local tmp
  tmp="$(mktemp -d)"
  echo "→ ${GROUP}/${path}"
  echo "  $desc"

  # Copy sources (honor .gitignore via rsync exclude patterns + tool gitignore)
  rsync -a \
    --exclude node_modules \
    --exclude ui/node_modules \
    --exclude ui/dist \
    --exclude gereq-modules \
    --exclude '.git' \
    --exclude '*.log' \
    "$src/" "$tmp/"

  (
    cd "$tmp"
    git init -q
    git checkout -q -b main
    git add -A
    git -c user.email="dev@gereq.cz" -c user.name="GEREQ" commit -q -m "Initial import: ${dir}

${desc}
"
    if git ls-remote "$remote" HEAD &>/dev/null; then
      git remote add origin "$remote"
      git push -u origin main --force
    else
      git push -u "$remote" main
      git remote add origin "$remote" 2>/dev/null || true
    fi
  )

  rm -rf "$tmp"
  echo "  ✓ https://${HOST}/${GROUP}/${path}"
}

for row in "${TOOLS[@]}"; do
  IFS='|' read -r dir path desc <<<"$row"
  publish_one "$dir" "$path" "$desc"
done

echo
echo "Done. Set project description + avatar (logo.png) in GitLab UI, or export GITLAB_TOKEN and re-run:"
echo "  bash tools/publish-tools-to-gitlab.sh --meta"
echo
echo "Cleanup leftover test project if present:"
echo "  https://${HOST}/${GROUP}/tool-push-create-test"
