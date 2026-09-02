#!/usr/bin/env bash

set -euo pipefail

cd "$(dirname "$0")/.."

first_archive=$(mktemp)
trap 'rm -f "$first_archive"' EXIT

./bin/build.sh
cp dist/fleet-for-openstation.zip "$first_archive"
./bin/build.sh

if ! cmp -s "$first_archive" dist/fleet-for-openstation.zip; then
	echo "Build is not reproducible: successive archives differ." >&2
	exit 1
fi

checksum=$(shasum -a 256 dist/fleet-for-openstation.zip | awk '{ print $1 }')
echo "Reproducible build verified: ${checksum}"
