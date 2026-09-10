#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")" && pwd)"
EXT_ID="gereq.work-monitor"
VERSION="0.3.0"
TARGET="${HOME}/.cursor-server/extensions/${EXT_ID}-${VERSION}"

echo "→ building UI"
(
  cd "$ROOT/ui"
  if command -v bun >/dev/null 2>&1; then
    bun install
    bun run build
  else
    npm install
    npm run build
  fi
)

rm -rf "${HOME}/.cursor-server/extensions/${EXT_ID}-"*
mkdir -p "$TARGET"
# copy extension without UI node_modules
cp "$ROOT/package.json" "$ROOT/extension.js" "$ROOT/README.md" "$TARGET/"
cp -r "$ROOT/lib" "$TARGET/"
mkdir -p "$TARGET/ui"
cp -r "$ROOT/ui/dist" "$TARGET/ui/"

python3 - <<PY
import json, time
from pathlib import Path
p = Path.home() / ".cursor-server/extensions/extensions.json"
data = json.loads(p.read_text()) if p.exists() else []
eid = "${EXT_ID}"
data = [e for e in data if e.get("identifier", {}).get("id") != eid]
data.append({
    "identifier": {"id": eid},
    "version": "${VERSION}",
    "location": {
        "\$mid": 1,
        "path": str(Path.home() / ".cursor-server/extensions/${EXT_ID}-${VERSION}"),
        "scheme": "file",
    },
    "relativeLocation": "${EXT_ID}-${VERSION}",
    "metadata": {
        "installedTimestamp": int(time.time() * 1000),
        "pinned": True,
        "source": "vsix",
    },
})
p.write_text(json.dumps(data, separators=(",", ":")))
print("Installed ${EXT_ID}@${VERSION}")
print("Next: Developer: Reload Window → http://127.0.0.1:8772")
PY
