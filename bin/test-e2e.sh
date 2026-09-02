#!/usr/bin/env bash

set -euo pipefail

if [[ "${FLEET_E2E:-0}" != "1" ]]; then
	printf '%s\n' 'Fleet browser tests skipped. Set FLEET_E2E=1 to run against an existing local test fleet.'
	exit 0
fi

repo_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
hub_path="${FLEET_E2E_HUB_PATH:-${HOME}/Studio/fleet-hub}"

if ! command -v wp >/dev/null 2>&1; then
	printf '%s\n' 'Fleet browser tests require WP-CLI in PATH.' >&2
	exit 1
fi

if [[ ! -f "${hub_path}/wp-config.php" ]]; then
	printf 'Fleet browser tests could not find a WordPress test hub at %s. Set FLEET_E2E_HUB_PATH.\n' "${hub_path}" >&2
	exit 1
fi

if [[ ! -d "${repo_dir}/node_modules/@playwright/test" ]]; then
	printf '%s\n' 'Fleet browser test dependencies are missing. Run npm ci first.' >&2
	exit 1
fi

if ! wp --path="${hub_path}" plugin is-active desktop-mode >/dev/null 2>&1; then
	printf '%s\n' 'Fleet browser tests require the OpenStation (desktop-mode) plugin to be active on the hub.' >&2
	exit 1
fi

if ! wp --path="${hub_path}" plugin is-active fleet-for-openstation >/dev/null 2>&1; then
	printf '%s\n' 'Fleet browser tests require Fleet for OpenStation to be active on the hub.' >&2
	exit 1
fi

if ! wp --path="${hub_path}" eval 'exit( class_exists( "OpenStation_Fleet_Repository" ) ? 0 : 1 );' >/dev/null 2>&1; then
	printf '%s\n' 'Fleet browser tests require the current per-site repository build to be deployed on the hub.' >&2
	exit 1
fi

export FLEET_E2E_HUB_PATH="${hub_path}"
exec "${repo_dir}/node_modules/.bin/playwright" test --config="${repo_dir}/tests/e2e/playwright.config.js" "$@"
