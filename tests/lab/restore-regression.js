/** Release gate: saved multi-instance windows must restore and keep their site. */
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const assert = require( 'node:assert/strict' );
const { chromium } = require( '@playwright/test' );
const root = path.join( __dirname, 'runtime' );
if ( process.env.FLEET_LAB_WRITES !== '1' || ! fs.existsSync( path.join( root, '.fleet-lab' ) ) || fs.existsSync( path.join( root, 'soak.lock' ) ) ) { throw new Error( 'Use an idle, marked disposable lab.' ); }
process.env.FLEET_LAB_RUNNER = path.join( root, 'runner.json' );
const { runSiteWp } = require( '../e2e/wordpress-fixture' );
const hub = path.join( root, 'sites/site-0' );
let user;
let browser;
const report = { passed: false, started: new Date().toISOString() };
( async () => {
	// Only duplicate encrypted baseline fixture references inside PHP. No new
	// remote credentials or remote writes; cleanup must NOT revoke the originals.
	user = runSiteWp( hub, `$id = wp_insert_user( array( 'user_login' => 'fleet_restore_' . wp_generate_password( 10, false ), 'user_pass' => wp_generate_password( 32 ), 'role' => 'administrator' ) ); if ( is_wp_error( $id ) ) { throw new Exception( 'Fixture failed.' ); } update_user_meta( $id, 'desktop_mode_mode', 1 ); $sites = array_slice( OpenStation_Fleet_Repository::all( 1, array( 'OpenStation_Fleet', 'normalize_site_record' ) ), 0, 3, true ); foreach( $sites as $key => $site ) { OpenStation_Fleet_Repository::save( $id, $key, $site, array(), array( 'OpenStation_Fleet', 'normalize_site_record' ), true ); } $keys = array_keys( $sites ); $session = openstation_empty_session(); $session['windows'] = array(); foreach( array_slice( $keys, 0, 2 ) as $n => $key ) { $session['windows'][] = array( 'id' => $n ? 'fleet-site-2' : 'fleet-site', 'baseId' => 'fleet-site', 'native' => true, 'url' => '#fleet-site', 'title' => 'Fleet restore fixture', 'params' => array( 'site_id' => $key ), 'x' => 20 + $n * 100, 'y' => 50, 'width' => 880, 'height' => 700, 'state' => 'normal', 'desktopId' => 'desktop-1' ); } $session['updated'] = (int) ( microtime( true ) * 1000 ); openstation_save_session( $id, $session ); echo 'FLEET_E2E_JSON:' . wp_json_encode( array( 'id' => $id, 'sites' => $keys, 'saved' => count( openstation_get_session( $id )['windows'] ) ) );` );
	assert.equal( user.saved, 2 );
	browser = await chromium.launch( require( './browser-options' )() );
	const context = await browser.newContext( { ignoreHTTPSErrors: true, viewport: { width: 1440, height: 960 } } );
	const cookies = runSiteWp( hub, `$expires = time() + 600; echo 'FLEET_E2E_JSON:' . wp_json_encode( array( array( 'name' => SECURE_AUTH_COOKIE, 'value' => wp_generate_auth_cookie( ${ user.id }, $expires, 'secure_auth' ) ), array( 'name' => LOGGED_IN_COOKIE, 'value' => wp_generate_auth_cookie( ${ user.id }, $expires, 'logged_in' ) ) ) );` );
	await context.addCookies( cookies.map( ( cookie ) => ( { ...cookie, url: 'https://localhost:18443', secure: true, httpOnly: true } ) ) );
	const page = await context.newPage();
	page.setDefaultTimeout( 15000 );
	await page.goto( 'https://localhost:18443/wp-admin/admin.php?page=openstation' );
	await page.locator( '.fleet-native-site-header' ).first().waitFor();
	try { await page.waitForFunction( () => window.wp.os.windowManager.getAll().filter( ( win ) => win.config?.baseId === 'fleet-site' ).length === 2, null, { timeout: 10000 } ); } catch {}
	const snapshot = () => page.evaluate( () => window.wp.os.windowManager.getAll().filter( ( win ) => win.config?.baseId === 'fleet-site' ).map( ( win ) => ( { id: win.id, site: win.config.params?.site_id } ) ) );
	report.restored = await snapshot();
	report.expected_saved_sites = user.sites.slice( 0, 2 );
	const requested = user.sites[ 2 ] || user.sites[ 0 ];
	await page.evaluate( ( id ) => window.wp.os.openNewWindow( 'fleet-site', { params: { site_id: id }, source: 'fleet-restore-regression' } ), requested );
	await page.waitForFunction( ( count ) => window.wp.os.windowManager.getAll().filter( ( win ) => win.config?.baseId === 'fleet-site' ).length > count, report.restored.length );
	report.requested_new_site = requested;
	report.after_new_window = await snapshot();
	const created = report.after_new_window.find( ( win ) => ! report.restored.some( ( previous ) => previous.id === win.id ) );
	report.restored_all = report.expected_saved_sites.every( ( id ) => report.restored.some( ( win ) => win.site === id ) );
	report.new_window_target_correct = created?.site === requested;
	report.passed = report.restored_all && report.new_window_target_correct;
	if ( ! report.passed ) { process.exitCode = 1; }
} )().catch( ( error ) => { report.failure = error.name; process.exitCode = 1; } ).finally( async () => {
	if ( browser ) { await browser.close(); }
	if ( user?.id ) { runSiteWp( hub, `$u = get_userdata( ${ user.id } ); if ( ! $u || 0 !== strpos( $u->user_login, 'fleet_restore_' ) ) { throw new Exception( 'Unowned cleanup target.' ); } OpenStation_Fleet_Repository::delete_all( $u->ID ); require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user( $u->ID ); echo 'FLEET_E2E_JSON:true';` ); }
	report.ended = new Date().toISOString();
	fs.writeFileSync( path.join( root, 'restore-regression.json' ), JSON.stringify( report, null, 2 ) + '\n' );
	console.log( JSON.stringify( report ) );
} );
