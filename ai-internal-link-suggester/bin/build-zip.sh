#!/usr/bin/env bash
# Build the WordPress.org-ready zip: no dev files, no tests, no node_modules or vendor.
# Usage: bash bin/build-zip.sh   →   ../dist/ai-internal-link-suggester.zip
set -euo pipefail

SLUG="ai-internal-link-suggester"
SRC="$(cd "$(dirname "$0")/.." && pwd)"
OUT="$(cd "$SRC/.." && pwd)/dist"
STAGE="$OUT/$SLUG"

if [ ! -f "$SRC/build/index.js" ]; then
	echo "build/index.js is missing. Run: npm install && npm run build" >&2
	exit 1
fi

rm -rf "$STAGE" "$OUT/$SLUG.zip"
mkdir -p "$STAGE"

# Turn .distignore into rsync excludes.
EXCLUDES=()
while IFS= read -r line || [ -n "$line" ]; do
	line="${line%%#*}"
	line="$(echo "$line" | xargs)"
	[ -z "$line" ] && continue
	EXCLUDES+=("--exclude=$line")
done < "$SRC/.distignore"

rsync -a "${EXCLUDES[@]}" "$SRC/" "$STAGE/"

# Safety net: nothing from these may ever ship.
for forbidden in node_modules vendor tests bin .git composer.json phpunit.xml.dist phpcs.xml.dist; do
	if [ -e "$STAGE/$forbidden" ]; then
		echo "Refusing to build: $forbidden would be shipped." >&2
		exit 1
	fi
done

(cd "$OUT" && zip -qr "$SLUG.zip" "$SLUG" -x "*.DS_Store")
echo "Built $OUT/$SLUG.zip"
(cd "$OUT" && unzip -l "$SLUG.zip" | tail -1)
