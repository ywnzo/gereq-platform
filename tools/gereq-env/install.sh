#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")" && pwd)"
EXT_ID="gereq.gereq-env"
VERSION="0.1.0"

install_into() {
  local base="$1"
  local target="${base}/extensions/${EXT_ID}-${VERSION}"
  mkdir -p "${base}/extensions"
  rm -rf "${base}/extensions/${EXT_ID}-"*
  mkdir -p "$target"
  cp "$ROOT/package.json" "$ROOT/extension.js" "$ROOT/README.md" "$target/"

  python3 - "$base" "$EXT_ID" "$VERSION" <<'PY'
import json, sys, time
from pathlib import Path
base = Path(sys.argv[1])
eid = sys.argv[2]
version = sys.argv[3]
rel = f"{eid}-{version}"
p = base / "extensions" / "extensions.json"
data = json.loads(p.read_text()) if p.exists() else []
data = [e for e in data if e.get("identifier", {}).get("id") != eid]
data.append({
    "identifier": {"id": eid},
    "version": version,
    "location": {
        "$mid": 1,
        "path": str(base / "extensions" / rel),
        "scheme": "file",
    },
    "relativeLocation": rel,
    "metadata": {
        "installedTimestamp": int(time.time() * 1000),
        "pinned": True,
        "source": "vsix",
    },
})
p.write_text(json.dumps(data, separators=(",", ":")))
print(f"Installed {eid}@{version} → {base}")
PY
}

installed=0
for base in \
  "${HOME}/.cursor-server" \
  "${HOME}/.cursor" \
  "${HOME}/.vscode-server" \
  "${HOME}/.vscode"
do
  if [[ -d "$base" ]]; then
    install_into "$base"
    installed=1
  fi
done

if [[ "$installed" -eq 0 ]]; then
  echo "No Cursor/VS Code extensions dir found."
  echo "Manual: symlink tools/gereq-env into ~/.vscode/extensions/${EXT_ID}-${VERSION}"
  exit 1
fi

echo "Next: Developer: Reload Window"
