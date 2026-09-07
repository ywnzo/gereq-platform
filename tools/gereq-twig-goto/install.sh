#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")" && pwd)"
EXT_ID="gereq.twig-goto"
VERSION="0.3.4"
TARGET="${HOME}/.cursor-server/extensions/${EXT_ID}-${VERSION}"

# odstraň starší verze
rm -rf "${HOME}/.cursor-server/extensions/${EXT_ID}-"*

mkdir -p "$TARGET"
cp "$ROOT/package.json" "$ROOT/extension.js" "$ROOT/README.md" "$TARGET/"
cp "$ROOT/index-templates.php" "$TARGET/" 2>/dev/null || true

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
print("Next: Developer: Reload Window")
PY
