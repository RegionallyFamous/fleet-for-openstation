/** Real Core approval + REST load test. No tokens, cookies, traces, or callback URLs in artifacts. */
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const assert = require( 'node:assert/strict' );
const { randomUUID } = require( 'node:crypto' );
const { chromium } = require( '@playwright/test' );
const { runSiteWp } = require( '../e2e/wordpress-fixture' );
const root = process.env.FLEET_STRESS_ROOT;
const hubPath = process.env.FLEET_E2E_HUB_PATH;
const runner = process.env.FLEET_LAB_RUNNER ? JSON.parse( fs.readFileSync( process.env.FLEET_LAB_RUNNER, 'utf8' ) ) : null;
const count = Number( process.env.FLEET_STRESS_COUNT || 30 );
const dockerLab = runner && runner.mapping?.[ hubPath ] === 0 && fs.existsSync( path.join( path.dirname( process.env.FLEET_LAB_RUNNER ), '.fleet-lab' ) );
if ( process.env.FLEET_STRESS_WRITES !== '1' || ( ! dockerLab && ! root?.endsWith( '/fleet-launch-load' ) ) || ! hubPath || ! [ 30, 50, 100 ].includes( count ) ) {
	throw new Error( 'Explicit local lab, hub path, and write opt-in required.' );
}
const fixtures = Array.from( { length: count }, ( _, index ) => {
	if ( dockerLab ) {
		const directory = Object.keys( runner.mapping ).find( ( entry ) => runner.mapping[ entry ] === index + 1 );
		assert.ok( directory, 'Provision every independent target before running load tests.' );
		return { directory, index: index + 1, url: `https://localhost:${ 18444 + index }` };
	}
	const directory = path.join( root, `site-${ String( index + 1 ).padStart( 2, '0' ) }` );
	return { ...JSON.parse( fs.readFileSync( path.join( directory, '.fleet-stress-fixture' ), 'utf8' ) ), directory };
} );
const resultsDirectory = path.join( __dirname, 'results', `${ dockerLab ? 'multi-origin' : 'studio' }-${ count }` );
fs.mkdirSync( resultsDirectory, { recursive: true } );
const sessionFile = path.join( resultsDirectory, 'session.json' );
const resumed = fs.existsSync( sessionFile ) ? JSON.parse( fs.readFileSync( sessionFile, 'utf8' ) ) : null;
const appId = resumed?.appId || randomUUID();
const report = { started: new Date().toISOString(), topology: dockerLab ? `${ count } independent WordPress databases and HTTPS origins; alternating MySQL 8.4/MariaDB 11.4; shared Core files and PHP pool; not independent hosting or WAN latency.` : `${ count } independent WordPress SQLite installs; same local HTTPS origin, separate databases; one Studio PHP pool for targets, separate hub pool; not a WAN benchmark.`, connections: [], checkpoints: [], faults: {}, ...( resumed?.report || {} ), passed: false };
const faultOption = dockerLab ? 'fleet_lab_offline' : 'fleet_stress_unavailable';
const isCheckpoint = ( index ) => [ 10, 20, 30, 50, 100 ].includes( index ) || index === count;
if ( report.failure ) {
	report.previous_failures = [ ...( report.previous_failures || [] ), report.failure ];
	delete report.failure;
}
let stage = 'creating isolated administrator';
const hub = resumed?.hub || runSiteWp( hubPath, `
$id = wp_insert_user( array( 'user_login' => 'fleet_load_' . wp_generate_password( 10, false ), 'user_pass' => wp_generate_password( 32 ), 'role' => 'administrator' ) );
if ( is_wp_error( $id ) ) { throw new Exception( 'Could not create load-test administrator.' ); }
update_user_meta( $id, OpenStation_Fleet_Repository::app_id_meta_key(), '${ appId }' );
echo 'FLEET_E2E_JSON:' . wp_json_encode( array( 'id' => $id, 'url' => home_url(), 'fleet' => OPENSTATION_FLEET_VERSION, 'wordpress' => get_bloginfo( 'version' ), 'php' => PHP_VERSION ) );` );
assert.equal( runSiteWp( hubPath, `$user = get_userdata( ${ Number( hub.id ) } ); echo 'FLEET_E2E_JSON:' . wp_json_encode( $user && 0 === strpos( $user->user_login, 'fleet_load_' ) && '${ appId }' === get_user_meta( $user->ID, OpenStation_Fleet_Repository::app_id_meta_key(), true ) );` ), true, 'Refusing an unowned load-test session.' );
fs.writeFileSync( sessionFile, JSON.stringify( { hub, appId, report } ) );
const persist = () => fs.writeFileSync( sessionFile, JSON.stringify( { hub, appId, report } ) );
report.versions = { fleet: hub.fleet, wordpress: hub.wordpress, cliPhp: hub.php };
const wp = ( php ) => runSiteWp( hubPath, `wp_set_current_user( ${ hub.id } ); ${ php }` );
const sites = () => wp( `$out = array(); foreach( OpenStation_Fleet_Repository::all( ${ hub.id }, array( 'OpenStation_Fleet', 'normalize_site_record' ) ) as $id => $site ) { $out[] = array( 'id' => $id, 'url' => $site['site_url'], 'name' => $site['name'], 'setup' => $site['setup_status'], 'error' => ! empty( $site['error'] ), 'retry' => $site['next_retry'], 'checked' => $site['status_checked'] ); } echo 'FLEET_E2E_JSON:' . wp_json_encode( $out );` );
const percentile = ( values, percent ) => [ ...values ].sort( ( a, b ) => a - b )[ Math.max( 0, Math.ceil( values.length * percent ) - 1 ) ];
const browserErrors = [];
async function main() {
	const browser = await chromium.launch( require( '../lab/browser-options' )() );
	const context = await browser.newContext( { ignoreHTTPSErrors: true, viewport: { width: 1440, height: 960 } } );
	const page = await context.newPage();
	page.setDefaultTimeout( 30_000 );
	page.on( 'pageerror', () => browserErrors.push( stage ) );
	const cookieCache = new Map();
	async function authenticate( directory, url, id = 1 ) {
		const key = `${ directory }:${ id }`;
		if ( ! cookieCache.has( key ) ) {
			cookieCache.set( key, runSiteWp( directory, `$expires = time() + 7200; echo 'FLEET_E2E_JSON:' . wp_json_encode( array( array( 'name' => SECURE_AUTH_COOKIE, 'value' => wp_generate_auth_cookie( ${ id }, $expires, 'secure_auth' ) ), array( 'name' => LOGGED_IN_COOKIE, 'value' => wp_generate_auth_cookie( ${ id }, $expires, 'logged_in' ) ) ) );` ) );
		}
		// Re-add the same session: rotating its token would invalidate the nonce
		// already loaded in the hub's open desktop.
		const cookies = cookieCache.get( key );
		await context.addCookies( cookies.map( ( cookie ) => ( { ...cookie, url, secure: true, httpOnly: true, sameSite: 'Lax' } ) ) );
	}
	async function closeFleetWindows() {
		await page.evaluate( async () => {
			for ( const win of window.wp.os.windowManager.getAll() ) {
				if ( [ 'fleet-for-openstation', 'fleet-site' ].includes( win.config?.baseId ) ) { await win.close(); }
			}
		} );
		await page.waitForFunction( () => ! window.wp.os.windowManager.getAll().some( ( win ) => [ 'fleet-for-openstation', 'fleet-site' ].includes( win.config?.baseId ) ) );
		// Unregistration precedes the close animation. Do not accidentally fill
		// the retiring window's form on fast local hosts.
		await page.waitForFunction( () => ! document.querySelector( '.os-window [data-os-app="fleet-for-openstation"], .os-window [data-os-app="fleet-site"]' ) );
	}
	async function openHub() {
		const previousStage = stage;
		stage = `${ previousStage }: close windows`;
		await closeFleetWindows();
		stage = `${ previousStage }: open hub`;
		await page.evaluate( () => window.wp.os.openNewWindow( 'fleet-for-openstation', { source: 'fleet-load-test' } ) );
		await page.evaluate( async () => {
			await window.wp.os.windowManager.getAll().find( ( win ) => win.config?.baseId === 'fleet-for-openstation' )?.whenContentReady();
		} );
		stage = `${ previousStage }: wait for hub form`;
		await page.locator( '.fleet-native-connect' ).first().waitFor( { state: 'attached' } );
		stage = `${ previousStage }: wait for hub components`;
		await page.evaluate( async () => {
			await window.wp.os.windowManager.getAll().find( ( win ) => win.config?.baseId === 'fleet-for-openstation' )?.whenContentReady();
			await window.wp.os.loadComponents( [ 'os-form', 'os-text-field' ] );
		} );
		stage = `${ previousStage }: wait for hub action`;
		await page.waitForFunction( () => ! document.querySelector( '[data-os-app="fleet-for-openstation"][aria-busy="true"]' ) );
	}
	async function checkpoint( count ) {
		stage = `checkpoint ${ count }`;
		const snapshot = sites();
		assert.equal( snapshot.length, count );
		assert.equal( new Set( snapshot.map( ( site ) => site.url ) ).size, count );
		assert.ok( snapshot.every( ( site ) => site.setup === 'ready' && ! site.error ) );
		const measured = wp( `$times = array(); $requests = 0; add_filter( 'pre_http_request', static function( $pre ) use ( &$requests ) { ++$requests; return $pre; } ); for ( $i = 0; $i < 10; $i++ ) { $start = microtime( true ); $model = OpenStation_Fleet::app_hub_data(); $times[] = round( ( microtime( true ) - $start ) * 1000, 2 ); } echo 'FLEET_E2E_JSON:' . wp_json_encode( array( 'times_ms' => $times, 'remote_requests' => $requests, 'model_bytes' => strlen( wp_json_encode( $model ) ), 'peak_php_bytes' => memory_get_peak_usage( true ) ) );` );
		assert.equal( measured.remote_requests, 0 );
		const renders = [];
		for ( let repeat = 0; repeat < 5; repeat++ ) {
			const start = performance.now();
			await openHub();
			await page.waitForFunction( ( total ) => document.querySelectorAll( '.fleet-native-site-card' ).length === total, count );
			renders.push( Math.round( performance.now() - start ) );
		}
		const cards = page.locator( '.fleet-native-site-card' );
		assert.equal( await cards.count(), count );
		// Three simultaneously open sites must load content from their own independent database.
		await closeFleetWindows();
		const windowsStart = performance.now();
		for ( const site of snapshot.slice( -3 ) ) {
			await page.evaluate( ( id ) => window.wp.os.openNewWindow( 'fleet-site', { source: 'fleet-load-test', params: { site_id: id } } ), site.id );
			await page.waitForFunction( ( id ) => window.wp.os.windowManager.getAll().find( ( win ) => win.config?.params?.site_id === id )?.element?.querySelector( '.fleet-native-site-header' ), site.id );
			const elementId = await page.evaluate( ( id ) => window.wp.os.windowManager.getAll().find( ( win ) => win.config?.params?.site_id === id ).element.id, site.id );
			const win = page.locator( `#${ elementId }` );
			await win.locator( '.os-window__tab[data-panel="content"]' ).click();
			await win.locator( 'os-tabpanel[for="content"] .fleet-native-content-list' ).waitFor();
			const rows = await win.locator( '.fleet-native-content-list' ).getByRole( 'row' ).allTextContents();
			const fixture = fixtures.find( ( entry ) => entry.url === site.url );
			assert.ok( rows.join( ' ' ).includes( `${ dockerLab ? 'Studio' : 'Client' } ${ fixture.index } article` ) );
		}
		const windowsMs = Math.round( performance.now() - windowsStart );
		const entry = { count, hub_model_p50_ms: percentile( measured.times_ms, 0.5 ), hub_model_p95_ms: percentile( measured.times_ms, 0.95 ), hub_render_p50_ms: percentile( renders, 0.5 ), hub_render_p95_ms: percentile( renders, 0.95 ), three_site_content_windows_ms: windowsMs, hub_remote_requests: measured.remote_requests, hub_model_bytes: measured.model_bytes, peak_php_bytes: measured.peak_php_bytes };
		report.checkpoints.push( entry );
		persist();
		console.log( JSON.stringify( entry ) );
	}
	try {
		await authenticate( hubPath, hub.url, hub.id );
		console.log( `Testing ${ count } independent targets with real Core approval.` );
		await page.goto( `${ hub.url }/wp-admin/admin.php?page=openstation` );
		const enable = page.getByRole( 'button', { name: 'Enable it now', exact: true } );
		if ( await enable.isVisible() ) { await enable.click(); }
		await page.waitForFunction( () => typeof window.wp?.os?.openNewWindow === 'function' );
		await page.evaluate( () => new Promise( ( resolve ) => window.wp.os.ready( resolve ) ) );
		// A resumed desktop can still be restoring windows. Finish those mounts
		// before closing/reusing their ids; do not race the shell's restoration.
		await page.evaluate( () => Promise.all( window.wp.os.windowManager.getAll().map( ( win ) => win.whenContentReady() ) ) );
		for ( const fixture of fixtures ) {
			// Cookies are not port-scoped. In the localhost multi-origin lab,
			// retaining every target's cookies overflows Apache's header limit.
			// Keep this isolated browser's current target + unchanged hub session;
			// do not rotate tokens or weaken the server's header/security limits.
			if ( dockerLab ) { await context.clearCookies(); }
			await authenticate( hubPath, hub.url, hub.id );
			await authenticate( fixture.directory, fixture.url );
			const pending = sites().find( ( site ) => site.url === fixture.url && site.setup !== 'ready' );
			if ( pending ) {
				stage = `resume approved setup ${ fixture.index }`;
				await closeFleetWindows();
				await page.evaluate( ( id ) => window.wp.os.openNewWindow( 'fleet-site', { params: { site_id: id } } ), pending.id );
				await page.getByRole( 'button', { name: 'Finish setup', exact: true } ).click();
				await page.waitForFunction( () => ! Array.from( document.querySelectorAll( '.fleet-native-setup' ) ).some( ( node ) => node.getBoundingClientRect().height > 0 ) );
				assert.ok( sites().some( ( site ) => site.url === fixture.url && site.setup === 'ready' ) );
			}
			if ( sites().some( ( site ) => site.url === fixture.url && site.setup === 'ready' ) ) {
				if ( ! report.connections.some( ( item ) => item.site === fixture.index ) ) { report.connections.push( { site: fixture.index, resumed: true } ); }
				if ( isCheckpoint( fixture.index ) && sites().length === fixture.index && ! report.checkpoints.some( ( item ) => item.count === fixture.index ) ) { await checkpoint( fixture.index ); }
				continue;
			}
			stage = `connecting site ${ fixture.index }`;
			await openHub();
			// The populated hub keeps Connect inside a disclosure.
			const disclosure = page.locator( '.fleet-native-connect-details' );
			stage = `site ${ fixture.index }: reveal connection form`;
			if ( await disclosure.count() ) { await disclosure.locator( 'summary' ).click(); }
			const connect = page.locator( '.fleet-native-connect' ).first();
			stage = `site ${ fixture.index }: enter URL`;
			const start = performance.now();
			await connect.locator( 'os-text-field[name="site_url"] input' ).fill( fixture.url );
			await connect.getByRole( 'button', { name: 'Check connection', exact: true } ).click();
			stage = `site ${ fixture.index }: preflight`;
			await page.getByText( 'Ready for approval', { exact: true } ).waitFor();
			await page.getByRole( 'button', { name: 'Continue to WordPress', exact: true } ).click();
			stage = `site ${ fixture.index }: native approval screen`;
			await page.locator( '#approve' ).waitFor();
			const welcome = page.getByRole( 'button', { name: 'Got it', exact: true } );
			if ( await welcome.isVisible() ) { await welcome.click(); }
			stage = `site ${ fixture.index }: approving`;
			await page.locator( '#approve' ).click();
			stage = `site ${ fixture.index }: callback verification`;
			await page.locator( '.fleet-native-setup:visible' ).first().waitFor();
			stage = `site ${ fixture.index }: finishing setup`;
			await page.getByRole( 'button', { name: 'Finish setup', exact: true } ).click();
			await page.waitForFunction( () => ! Array.from( document.querySelectorAll( '.fleet-native-setup' ) ).some( ( node ) => node.getBoundingClientRect().height > 0 ) );
			const ms = Math.round( performance.now() - start );
			assert.ok( sites().some( ( site ) => site.url === fixture.url && site.setup === 'ready' ) );
			report.connections.push( { site: fixture.index, approval_and_setup_ms: ms } );
			persist();
			console.log( `Connected and verified ${ fixture.index }/${ count } (${ ms } ms)` );
			if ( isCheckpoint( fixture.index ) ) { await checkpoint( fixture.index ); }
		}
		stage = 'mixed failures and scheduled checks';
		for ( const fixture of fixtures.slice( 0, 2 ) ) { runSiteWp( fixture.directory, `update_option( '${ faultOption }', true ); echo 'FLEET_E2E_JSON:true';` ); }
		runSiteWp( fixtures[ 2 ].directory, `$items = WP_Application_Passwords::get_user_application_passwords( 1 ); foreach( $items as $item ) { if ( '${ appId }' === ( $item['app_id'] ?? '' ) ) { WP_Application_Passwords::delete_application_password( 1, $item['uuid'] ); } } echo 'FLEET_E2E_JSON:true';` );
		// Force this test user's status tier due. Leave other users' state untouched.
		wp( `foreach( OpenStation_Fleet_Repository::all( ${ hub.id }, array( 'OpenStation_Fleet', 'normalize_site_record' ) ) as $id => $site ) { $site['status_checked'] = 1; $site['next_retry'] = 0; OpenStation_Fleet_Repository::save( ${ hub.id }, $id, $site, array( 'status_checked', 'next_retry' ), array( 'OpenStation_Fleet', 'normalize_site_record' ) ); } echo 'FLEET_E2E_JSON:true';` );
		const cronRuns = [];
		for ( let pass = 0; pass < Math.ceil( count / 10 ) + 20; pass++ ) {
			cronRuns.push( wp( `$start = microtime( true ); OpenStation_Fleet::run_scheduled_checks(); echo 'FLEET_E2E_JSON:' . wp_json_encode( round( microtime( true ) - $start, 3 ) );` ) );
			const state = sites();
			report.faults = { unavailable: 2, revoked: 1, healthy: state.filter( ( site ) => ! site.error && site.checked > 1 ).length, backoff: state.filter( ( site ) => site.error && site.retry > Date.now() / 1000 ).length, cron_runs_seconds: cronRuns };
			persist();
			if ( state.filter( ( site ) => site.error && site.retry > Date.now() / 1000 ).length === 3 && state.filter( ( site ) => ! site.error && site.checked > 1 ).length === count - 3 ) { break; }
		}
		const failed = sites();
		assert.equal( failed.filter( ( site ) => site.error && site.retry > Date.now() / 1000 ).length, 3 );
		assert.equal( failed.filter( ( site ) => ! site.error && site.checked > 1 ).length, count - 3 );
		assert.ok( cronRuns.every( ( seconds ) => seconds < 45 ) );
		await openHub();
		await page.waitForFunction( ( total ) => document.querySelectorAll( '.fleet-native-site-card' ).length === total, count );
		assert.equal( await page.locator( '.fleet-native-site-card [data-tone="danger"]' ).count(), 3 );
		report.faults = { unavailable: 2, revoked: 1, healthy: count - 3, backoff: 3, cron_runs_seconds: cronRuns };
		assert.equal( browserErrors.length, 0 );
		report.passed = true;
		delete report.resumable_session;
	} catch ( error ) {
		console.log( JSON.stringify( { stage, page_path: new URL( page.url() ).pathname, windows: await page.evaluate( () => window.wp.os.windowManager.getAll().map( ( win ) => ( { id: win.id, app: win.config?.baseId, busy: win.element?.querySelector( '[aria-busy="true"]' ) !== null } ) ) ), forms: await page.locator( '.fleet-native-connect' ).count(), notices: await page.locator( '.fleet-native-notice, .fleet-native-setup, .fleet-native-connection-review' ).allTextContents() } ) );
		throw error;
	} finally {
		await browser.close();
	}
}
const run = process.env.FLEET_STRESS_CLEANUP === '1' ? Promise.resolve() : main();
run.catch( ( error ) => {
	// Playwright errors can contain a callback URL. Save only safe stage + type.
	report.failure = { stage, type: error.name };
	console.error( `Load test failed at: ${ stage } (${ error.name }). No credential-bearing debug output recorded.` );
	process.exitCode = 1;
} ).finally( () => {
	const cleanupErrors = [];
	if ( report.passed || process.env.FLEET_STRESS_CLEANUP === '1' ) {
	for ( const fixture of fixtures ) {
		try {
			runSiteWp( fixture.directory, `delete_option( '${ faultOption }' ); foreach( WP_Application_Passwords::get_user_application_passwords( 1 ) as $item ) { if ( '${ appId }' === ( $item['app_id'] ?? '' ) ) { WP_Application_Passwords::delete_application_password( 1, $item['uuid'] ); } } echo 'FLEET_E2E_JSON:true';` );
		} catch { cleanupErrors.push( fixture.index ); }
	}
	try { wp( `require_once ABSPATH . 'wp-admin/includes/user.php'; OpenStation_Fleet_Repository::delete_all( ${ hub.id } ); wp_delete_user( ${ hub.id } ); echo 'FLEET_E2E_JSON:true';` ); } catch { cleanupErrors.push( 'hub-user' ); }
	if ( cleanupErrors.length === 0 ) { fs.unlinkSync( sessionFile ); }
	} else {
		report.resumable_session = true;
		persist();
	}
	report.cleanup_errors = cleanupErrors;
	report.ended = new Date().toISOString();
	fs.writeFileSync( path.join( resultsDirectory, `load-${ Date.now() }.json` ), JSON.stringify( report, null, 2 ) + '\n' );
	if ( cleanupErrors.length ) { process.exitCode = 1; }
	console.log( JSON.stringify( { passed: report.passed, connections: report.connections.length, cleanup_errors: cleanupErrors } ) );
} );
