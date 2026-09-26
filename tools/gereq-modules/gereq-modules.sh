#!/usr/bin/env bash
# Deprecated launcher — use `bin/grq modules` instead.
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
exec "$ROOT/bin/grq" modules "$@"
