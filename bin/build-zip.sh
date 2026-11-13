#!/usr/bin/env bash
# Builds dist/wp-affiliate-captain-<version>.zip (installable plugin ZIP).
# Usage: bin/build-zip.sh [expected-version]
set -euo pipefail
cd "$(dirname "$0")/.."

SLUG=wp-affiliate-captain
HEADER_VERSION=$(sed -n 's/^ \* Version: *\([0-9A-Za-z.\-]*\).*/\1/p' wp-affiliate-captain.php)
CONST_VERSION=$(sed -n "s/^define( 'WPAC_VERSION', '\([^']*\)' );/\1/p" wp-affiliate-captain.php)

if [ "$HEADER_VERSION" != "$CONST_VERSION" ]; then
	echo "Version mismatch: header $HEADER_VERSION vs WPAC_VERSION $CONST_VERSION" >&2
	exit 1
fi
README_TAG=$(sed -n 's/^Stable tag: *//p' readme.txt | tr -d '\r')
if [ "$README_TAG" != "$HEADER_VERSION" ]; then
	echo "Version mismatch: readme.txt Stable tag $README_TAG vs plugin $HEADER_VERSION" >&2
	exit 1
fi
if [ -n "${1:-}" ] && [ "${1#v}" != "$HEADER_VERSION" ]; then
	echo "Version mismatch: expected ${1#v}, plugin is $HEADER_VERSION" >&2
	exit 1
fi

php tests/run.php

BUILD=$(mktemp -d)
trap 'rm -rf "$BUILD"' EXIT
mkdir -p "$BUILD/$SLUG" dist
cp -R wp-affiliate-captain.php uninstall.php readme.txt README.md src assets blocks languages docs tools "$BUILD/$SLUG/"
find "$BUILD/$SLUG" -name '.DS_Store' -delete
for f in $(find "$BUILD/$SLUG" -name '*.php'); do php -l "$f" >/dev/null; done

OUT="dist/$SLUG-$HEADER_VERSION.zip"
rm -f "$OUT"
(cd "$BUILD" && zip -qr - "$SLUG") > "$OUT"
echo "Built $OUT ($(du -h "$OUT" | cut -f1))"
