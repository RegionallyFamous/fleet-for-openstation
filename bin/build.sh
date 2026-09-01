#!/usr/bin/env bash

set -euo pipefail

cd "$(dirname "$0")/.."

find . -path './.git' -prune -o -path './dist' -prune -o -name '*.php' -print0 \
	| xargs -0 -n1 php -l >/dev/null
php -d zend.assertions=1 -d assert.exception=1 tests/smoke.php
node --check assets/fleet-app.js

stage_root=$(mktemp -d)
trap 'rm -rf "$stage_root"' EXIT

mkdir -p "$stage_root/fleet-for-openstation/includes" "$stage_root/fleet-for-openstation/assets" dist
cp fleet-for-openstation.php readme.txt LICENSE uninstall.php "$stage_root/fleet-for-openstation/"
cp includes/class-fleet-for-openstation.php includes/class-openstation-fleet-app.php "$stage_root/fleet-for-openstation/includes/"
cp assets/fleet-app.css assets/fleet-app.js "$stage_root/fleet-for-openstation/assets/"
cp -R apps "$stage_root/fleet-for-openstation/"

test ! -e "$stage_root/fleet-for-openstation/assets/admin.css"
test ! -e "$stage_root/fleet-for-openstation/assets/admin.js"
test ! -e "$stage_root/fleet-for-openstation/includes/classic.php"

rm -f dist/fleet-for-openstation.zip
( cd "$stage_root" && zip -qr "$OLDPWD/dist/fleet-for-openstation.zip" fleet-for-openstation )

echo "Built dist/fleet-for-openstation.zip"
