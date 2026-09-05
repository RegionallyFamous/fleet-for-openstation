const { defineConfig } = require( '@playwright/test' );

module.exports = defineConfig( {
	testDir: __dirname,
	fullyParallel: false,
	workers: 1,
	retries: process.env.CI ? 1 : 0,
	// A retry is diagnostic evidence, not permission to ship a flaky gate.
	failOnFlakyTests: true,
	timeout: 45_000,
	expect: {
		timeout: 10_000,
	},
	reporter: process.env.CI ? 'line' : 'list',
	outputDir: process.env.FLEET_E2E_OUTPUT || 'test-results',
	projects: ( process.env.FLEET_E2E_BROWSERS || 'chromium' ).split( ',' ).map( ( browserName ) => {
		if ( ! [ 'chromium', 'firefox', 'webkit' ].includes( browserName ) ) { throw new Error( 'Unsupported test browser.' ); }
		return { name: browserName, use: { browserName, launchOptions: browserName === 'chromium' ? require( '../lab/browser-options' )() : {} } };
	} ),
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
