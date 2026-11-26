#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DIST_DIR="$ROOT_DIR/dist"
ARCHIVE_NAME="${1:-sports-teams.zip}"
ARCHIVE_PATH="$DIST_DIR/$ARCHIVE_NAME"

mkdir -p "$DIST_DIR"
cd "$ROOT_DIR"
rm -f "$ARCHIVE_PATH" "$ARCHIVE_PATH.sha256"

# The installable archive contains only the runtime files, at the archive root.
zip -X "$ARCHIVE_PATH" plugin.json Plugin.php LICENSE

if command -v sha256sum >/dev/null 2>&1; then
  sha256sum "$ARCHIVE_PATH" | awk '{print $1}' > "$ARCHIVE_PATH.sha256"
else
  shasum -a 256 "$ARCHIVE_PATH" | awk '{print $1}' > "$ARCHIVE_PATH.sha256"
fi

echo "Created $ARCHIVE_PATH"
echo "SHA-256: $(cat "$ARCHIVE_PATH.sha256")"
