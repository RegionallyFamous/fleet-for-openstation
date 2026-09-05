# Disposable reliability lab

This is test infrastructure, never part of the plugin ZIP. It binds only to loopback and uses distinct HTTPS ports, databases, salts and content directories. Targets alternate MySQL 8.4 and MariaDB 11.4. Core files and the PHP/Apache pool are shared: **this is not independent hosting or WAN certification**.

Prerequisites: Docker, Node, PHP, WP-CLI-compatible platform utilities, and a ZIP built from the exact OpenStation commit pinned in `.github/workflows/validate.yml`. The 0.10 release currently pins the head of [OpenStation PR #763](https://github.com/WordPress/openstation/pull/763); replace that pin with the released containing commit after it lands. Run from the Fleet repository:

```sh
npm ci
npx playwright install chromium firefox webkit
./bin/build.sh
FLEET_LAB_WRITES=1 FLEET_LAB_COUNT=100 FLEET_LAB_POSTS=1000 \
FLEET_LAB_OPENSTATION_ZIP=/absolute/path/to/fleet-lab-openstation.zip \
node tests/lab/provision.js

export FLEET_LAB_RUNNER="$PWD/tests/lab/runtime/runner.json"
export FLEET_E2E_HUB_PATH="$PWD/tests/lab/runtime/sites/site-0"
export FLEET_E2E_MANAGED_PATH="$PWD/tests/lab/runtime/sites/site-1"
FLEET_E2E_WRITES=1 FLEET_E2E_USER_ID=1 \
FLEET_E2E_BROWSERS=chromium,firefox,webkit \
npx playwright test --config=tests/e2e/playwright.config.js

FLEET_STRESS_WRITES=1 FLEET_STRESS_COUNT=100 node tests/stress/run.js
```

The provisioner seeds one private administrator with connections for browser/soak tests. The stress runner creates a different, temporary owner and performs actual Core approval/setup for every target. It measures at 10/20/30/50/100 sites, verifies simultaneous content windows, injects two unavailable targets plus one revoked credential, and verifies healthy progress/backoff. Successful cleanup revokes only that run's credentials and removes its temporary owner. Failed runs preserve a non-secret ownership journal; rerun to resume, or explicitly add `FLEET_STRESS_CLEANUP=1` to clean up. Do not discard the journal first.

For smaller labs use count `2`, `30` or `50`; the stress runner supports `30`, `50`, `100`. Endurance requires `100`. Generated runtime data, certificates, secrets, reports and ownership journals are ignored by Git. Do not publish the runtime directory, raw databases, headers, cookies or Core approval callback URLs. The isolated Chromium process trusts only the lab certificate's public-key pin; the machine's trust store and production TLS/SSRF settings are unchanged. Approval cookies are retained only for the current target and the unchanged hub session because cookies are not port-scoped on localhost.

## Upgrade and actual database restoration

Run only after browser/load tests finish, with no soak in progress:

```sh
mkdir -p tests/lab/runtime/upgrade
curl --fail --location --output tests/lab/runtime/upgrade/fleet-for-openstation.zip \
  https://github.com/RegionallyFamous/fleet-for-openstation/releases/download/v0.8.0/fleet-for-openstation.zip
FLEET_LAB_WRITES=1 node tests/lab/upgrade.js
```

The script verifies the published ZIP's pinned SHA-256, installs 0.8.0 then the current ZIP, checks encrypted connection/generation/agency preservation and a real authenticated Core read, exports the hub, and imports that dump into a newly named clone database. It boots Fleet against the clone and verifies the original database is untouched. A salt-loss check must fail closed; restoring the original salts must work. Only the temporary clone and private dump are removed. This does not simulate loss of a whole machine or prove another hosting provider's backup tooling.

## Endurance

**Current dependency gate:** released OpenStation 1.1.6 does not include the saved-instance correction. With PR #763 commit `a48d37b8bfdc6453056eb9345ff610c396a71e15`, `FLEET_LAB_WRITES=1 node tests/lab/restore-regression.js` restores both saved Fleet windows and opens a third window with the requested site. The long run has not been restarted or completed. Keep the regression mandatory, and do not claim endurance coverage from this focused pass.

Do not redeploy, reprovision, alter fixtures or rebuild a different ZIP while this is running:

```sh
FLEET_SOAK_WRITES=1 node tests/lab/soak.js --smoke
FLEET_SOAK_WRITES=1 FLEET_SOAK_HOURS=48 node tests/lab/soak.js
```

Use `72` for a longer run. The short three-cycle check validates the harness, **not endurance**. The full run keeps a real browser open, rotates three windows, reads all 100 sites every five minutes, runs bounded scheduled checks, and creates/reads/trashes one unique disposable draft approximately hourly. It checks build pinning, JavaScript errors, DOM/heap bounds, and continuity. Host suspension beyond 20 minutes fails continuous coverage. Keep Docker and the computer running.

Read `runtime/soak.json` for progress. A running process, a short smoke check, or a partially elapsed run is not a pass. A previous full-run report must be deliberately archived before another run. Failures list only the safe stage/type and any exact canary needing cleanup. Never blindly retry an uncertain create.

The soak checks that the saved native desktop finishes restoring before reusing window IDs. Waiting only for shell APIs or the first window is not sufficient. The dedicated restore regression uses a temporary hub owner and encrypted baseline references, sends no remote mutations, verifies both restored instances and the next requested site's identity, then deletes only the temporary owner. It does not revoke the baseline credentials.

## Stop the lab

After the soak ends, stop only this project with `docker compose -f tests/lab/runtime/compose.json stop`. Fixtures are retained. `down --volumes` deletes the lab databases and is used only in disposable CI jobs. Nothing here should target a real client site, live OpenStation repository, production database or user-owned browser profile.
