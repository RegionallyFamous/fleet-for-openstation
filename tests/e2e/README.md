# Fleet browser tests

These Playwright tests exercise Fleet in a real OpenStation shell. They are deliberately opt-in because they need an existing three-site test environment: one Fleet hub and at least two connected WordPress sites.

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

The default suite does not write remote content. Explicit `FLEET_E2E_WRITES=1` plus `FLEET_E2E_MANAGED_PATH=/absolute/disposable-managed-site` enables credential, content, timezone, publishing/recovery, and saved-view round trips with scoped cleanup. These tests create temporary users/posts/views and must never run against production. Fixture discovery can perform Fleet's metadata migration; window operations update the test user's OpenStation session.

For the custom-type case, copy `tests/e2e/fixtures/custom-types.php` to the opted-in target's `wp-content/mu-plugins/fleet-modern-test-types.php`. It remains inert outside a test run. The test enables its Core-generated type routes, verifies a custom namespace and a restricted type, then removes its content and enable flag. This fixture is never packaged in Fleet. Without it that one case is explicitly skipped.

## CI behavior

`npm run test:e2e` exits successfully with an explicit skip unless `FLEET_E2E=1` is set. This keeps ordinary CI safe when no WordPress Studio environment is available. A future mandatory CI job should provision a hub and two managed WordPress sites before opting in; the current suite should remain a local or manually dispatched integration test.
