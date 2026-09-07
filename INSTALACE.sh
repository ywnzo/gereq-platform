#!/usr/bin/env bash
# RealEstates demo — rozbalte zip a spusťte:
#   ./INSTALACE.sh
#   ./INSTALACE.sh --serve
set -euo pipefail
cd "$(dirname "$0")"
chmod +x bin/install-demo 2>/dev/null || true
exec ./bin/install-demo "$@"
