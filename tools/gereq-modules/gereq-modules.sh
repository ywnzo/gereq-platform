#!/usr/bin/env bash
# Spouštěč TUI / plain porovnání modulů vs GitLab.
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
export PATH="/usr/local/go/bin:${PATH:-}"
BIN="${DIR}/gereq-modules"
if [[ ! -x "$BIN" ]] || [[ "$DIR/main.go" -nt "$BIN" ]]; then
  (cd "$DIR" && go build -o gereq-modules .)
fi
exec "$BIN" "$@"
