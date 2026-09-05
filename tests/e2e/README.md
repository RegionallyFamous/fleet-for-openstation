# Fleet browser tests

These Playwright tests exercise Fleet in a real OpenStation shell. Local execution is opt-in because it writes to disposable fixtures. CI provisions its own HTTPS hub and managed sites and must run the suite.

## Run locally

```bash
npm ci
npx playwright install chromium
FLEET_E2E=1 npm run test:e2e
```

The default hub is `$HOME/Studio/fleet-hub`. Point the harness at another test hub when needed:

```bash
FLEET_E2E=1 \
FLEET_E2E_HUB_PATH=/absolute/path/to/hub \
FLEET_E2E_HUB_URL=https://hub.example.test \
npm run test:e2e
```

`FLEET_E2E_USER_ID` can select a particular administrator. Otherwise the harness uses the first administrator who owns at least two Fleet connections. Set `FLEET_E2E_HEADED=1` to watch Chromium run.

The harness requires WP-CLI, HTTPS URLs, active `desktop-mode` and `fleet-for-openstation` plugins on the hub, and two reachable connected sites. It generates short-lived WordPress authentication cookies in memory with WP-CLI. Passwords, Application Passwords, cookies, and browser storage state are never written to the repository or test report.

The complete suite requires explicit `FLEET_E2E_WRITES=1` plus `FLEET_E2E_MANAGED_PATH=/absolute/disposable-managed-site`. It covers credential, content, timezone, publishing/recovery, uploads, replies, moderation, team roles, and saved-view round trips with scoped cleanup. These tests create temporary users/posts/views and must never run against production. Fixture discovery can perform Fleet's metadata migration; window operations update the test user's OpenStation session. Use `FLEET_E2E_BROWSERS=chromium,firefox,webkit` for all engines and `FLEET_E2E_OUTPUT` to separate concurrent runs' artifacts.

For the custom-type case, copy `tests/e2e/fixtures/custom-types.php` to the opted-in target's `wp-content/mu-plugins/fleet-modern-test-types.php`. It remains inert outside a test run. The test enables its Core-generated type routes, verifies a custom namespace and a restricted type, then removes its content and enable flag. This fixture is never packaged in Fleet. Without it that one case is explicitly skipped.

## Documentation screenshots

Use only an authenticated disposable demo hub with at least two connected sites:

```sh
FLEET_SCREENSHOTS=1 \
FLEET_E2E_HUB_PATH=/absolute/path/to/disposable-hub \
node tests/e2e/screenshots.js
```

The script captures real OpenStation windows without approving a connection or confirming a remote content write. It fails if the Fleet CSS or JavaScript served by the hub differs from the working tree. Never capture an Application Password approval screen or callback URL. Inspect all ten output files in `assets/screenshots/` at native size and as README thumbnails before publishing.

## CI behavior

The `browser` job in `.github/workflows/validate.yml` installs the actual Fleet ZIP and a pinned, read-only OpenStation build into disposable Docker WordPress fixtures. It invokes Playwright directly with writes enabled and all three browser engines; it cannot silently take the local wrapper's skip path. WordPress 7.1 runs on PHP 8.3, with independent MySQL 8.4 and MariaDB 11.4 targets. PHP 8.5 static/build validation runs separately.

The lab uses a narrowly trusted self-signed certificate, a fixture-only localhost HTTP adapter, separate databases/salts/content directories, and no Fleet plugin or Fleet endpoints on targets. None of those adapters ships in the runtime ZIP. Chromium trusts only the fixture certificate's public-key pin inside its isolated browser process; the OS trust store is untouched.

The exact WebKit warning about OpenStation's `interactive-widget` viewport setting is annotated as an upstream progressive-enhancement warning. Other console and JavaScript errors still fail.

For 30/50/100 origins and continuous endurance, see `tests/lab/provision.js`, `tests/stress/run.js`, `tests/lab/soak.js`, and the wiki's Reliability Milestone. The soak has a separate three-cycle `--smoke` mode that is never labeled an endurance pass.
