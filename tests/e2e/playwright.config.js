const { defineConfig } = require( '@playwright/test' );

module.exports = defineConfig( {
	testDir: __dirname,
	fullyParallel: false,
	workers: 1,
	retries: process.env.CI ? 1 : 0,
	timeout: 45_000,
	expect: {
		timeout: 10_000,
	},
	reporter: process.env.CI ? 'line' : 'list',
	outputDir: 'test-results',
	use: {
		browserName: 'chromium',
		headless: process.env.FLEET_E2E_HEADED !== '1',
		ignoreHTTPSErrors: true,
		screenshot: 'only-on-failure',
		// Authentication cookies are generated in memory and must never land in a trace artifact.
		trace: 'off',
		viewport: { width: 1440, height: 900 },
	},
} );
