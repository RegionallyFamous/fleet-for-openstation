#!/usr/bin/env bash

set -euo pipefail

cd "$(dirname "$0")/.."

find . -path './.git' -prune -o -path './dist' -prune -o -name '*.php' -print0 \
	| xargs -0 -n1 php -l >/dev/null
php -d zend.assertions=1 -d assert.exception=1 tests/smoke.php

stage_root=$(mktemp -d)
trap 'rm -rf "$stage_root"' EXIT

mkdir -p "$stage_root/openstation-fleet/includes" dist
cp openstation-fleet.php readme.txt LICENSE uninstall.php "$stage_root/openstation-fleet/"
cp includes/class-openstation-fleet.php "$stage_root/openstation-fleet/includes/"

rm -f dist/openstation-fleet.zip
( cd "$stage_root" && zip -qr "$OLDPWD/dist/openstation-fleet.zip" openstation-fleet )

echo "Built dist/openstation-fleet.zip"
