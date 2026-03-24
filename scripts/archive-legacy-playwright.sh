#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PARENT_DIR="$(cd "$ROOT_DIR/.." && pwd)"
STAMP="$(date +%Y%m%d-%H%M%S)"
ARCHIVE_DIR="$PARENT_DIR/legacy-playwright-archive/$STAMP"

mkdir -p "$ARCHIVE_DIR"

# Move legacy scripts and artifacts from parent folder (non-destructive archive)
shopt -s nullglob
for pattern in "$PARENT_DIR"/debug-*.mjs "$PARENT_DIR"/check-*.mjs "$PARENT_DIR"/test-*.mjs "$PARENT_DIR"/*.png "$PARENT_DIR"/*.csv; do
  if [[ -e "$pattern" ]]; then
    mv "$pattern" "$ARCHIVE_DIR"/
  fi
done

echo "Legacy artifacts archived in: $ARCHIVE_DIR"
