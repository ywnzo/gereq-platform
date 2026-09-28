#!/usr/bin/env bash
# Alias for serve.sh — start dockerless stack.
exec "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/serve.sh" "$@"
