#!/usr/bin/env bash

set -euo pipefail

cd "$(dirname "$0")/.."

find . -path './.git' -prune -o -path './dist' -prune -o -path './node_modules' -prune \
	-o -path './vendor' -prune -o -path './plugin-check-build' -prune \
	-o -name '*.php' -print0 \
	| xargs -0 -n1 php -l >/dev/null
php bin/check-version.php
php -d zend.assertions=1 -d assert.exception=1 tests/smoke.php
php -d zend.assertions=1 -d assert.exception=1 tests/search-index.php
node --check assets/fleet-app.js

stage_root=$(mktemp -d)
trap 'rm -rf "$stage_root"' EXIT

mkdir -p "$stage_root/fleet-for-openstation/includes" "$stage_root/fleet-for-openstation/assets" dist
cp fleet-for-openstation.php readme.txt LICENSE uninstall.php "$stage_root/fleet-for-openstation/"
cp includes/*.php "$stage_root/fleet-for-openstation/includes/"
cp assets/fleet-app.css assets/fleet-app.js "$stage_root/fleet-for-openstation/assets/"
cp -R apps "$stage_root/fleet-for-openstation/"

test ! -e "$stage_root/fleet-for-openstation/assets/admin.css"
test ! -e "$stage_root/fleet-for-openstation/assets/admin.js"
test ! -e "$stage_root/fleet-for-openstation/includes/classic.php"
test -e "$stage_root/fleet-for-openstation/includes/class-openstation-fleet.php"
test ! -e "$stage_root/fleet-for-openstation/includes/class-fleet-for-openstation.php"

# Make the archive independent of checkout umask, source mtimes, locale, and
# filesystem traversal order. ZIP's earliest portable timestamp is 1980-01-01.
find "$stage_root/fleet-for-openstation" -type d -exec chmod 0755 {} +
find "$stage_root/fleet-for-openstation" -type f -exec chmod 0644 {} +
TZ=UTC find "$stage_root/fleet-for-openstation" -exec touch -t 198001010000 {} +

rm -f dist/fleet-for-openstation.zip
archive_path="$(pwd)/dist/fleet-for-openstation.zip"
(
	cd "$stage_root"
	LC_ALL=C find fleet-for-openstation -type f -print \
		| LC_ALL=C sort \
		| env ZIPOPT='' TZ=UTC zip -X -q "$archive_path" -@
)

echo "Built dist/fleet-for-openstation.zip"
