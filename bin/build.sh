#!/usr/bin/env bash

set -euo pipefail

cd "$(dirname "$0")/.."

find . -path './.git' -prune -o -path './dist' -prune -o -name '*.php' -print0 \
	| xargs -0 -n1 php -l >/dev/null
php -d zend.assertions=1 -d assert.exception=1 tests/smoke.php

stage_root=$(mktemp -d)
trap 'rm -rf "$stage_root"' EXIT

mkdir -p "$stage_root/fleet-for-openstation/includes" dist
cp fleet-for-openstation.php readme.txt LICENSE uninstall.php "$stage_root/fleet-for-openstation/"
cp includes/class-fleet-for-openstation.php "$stage_root/fleet-for-openstation/includes/"

rm -f dist/fleet-for-openstation.zip
( cd "$stage_root" && zip -qr "$OLDPWD/dist/fleet-for-openstation.zip" fleet-for-openstation )

echo "Built dist/fleet-for-openstation.zip"
