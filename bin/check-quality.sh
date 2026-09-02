#!/usr/bin/env bash

set -euo pipefail

cd "$(dirname "$0")/.."

if [[ ! -x vendor/bin/phpcs || ! -x vendor/bin/phpstan ]]; then
	echo "Development dependencies are missing. Run: composer install" >&2
	exit 1
fi

vendor/bin/phpcs --standard=phpcs.xml.dist
vendor/bin/phpstan analyse --configuration=phpstan.neon.dist --no-progress --memory-limit=1G
