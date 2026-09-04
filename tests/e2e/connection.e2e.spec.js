const { test, expect } = require( '@playwright/test' );
const { runSiteWp } = require( './wordpress-fixture' );
test.use( { screenshot: 'off', trace: 'off' } );

test( 'approves, repairs a revoked credential, and disconnects an isolated Fleet', async ( { page } ) => {
	test.skip( process.env.FLEET_E2E_WRITES !== '1', 'Explicit local-fixture write opt-in required.' );
	test.setTimeout( 120_000 );
	// No screenshots or traces of the Core credential callback, even on failure.
	test.info().annotations.push( { type: 'security', description: 'Credential callback: do not capture network traces.' } );
	const hubPath = process.env.FLEET_E2E_HUB_PATH;
	const managedPath = process.env.FLEET_E2E_MANAGED_PATH;
	const appId = require( 'node:crypto' ).randomUUID();
	const hub = runSiteWp( hubPath, `
$id = wp_insert_user( array( 'user_login' => 'fleet_e2e_' . wp_generate_password( 10, false ), 'user_pass' => wp_generate_password( 32 ), 'role' => 'administrator' ) );
if ( is_wp_error( $id ) ) { throw new Exception( 'Could not create isolated test administrator.' ); }
update_user_meta( $id, OpenStation_Fleet_Repository::app_id_meta_key(), '${ appId }' );
echo 'FLEET_E2E_JSON:' . wp_json_encode( array( 'id' => $id, 'url' => home_url() ) );` );
	const managed = runSiteWp( managedPath, `
$ids = get_users( array( 'role' => 'administrator', 'fields' => 'ID', 'number' => 1 ) );
echo 'FLEET_E2E_JSON:' . wp_json_encode( array( 'id' => (int) $ids[0], 'url' => home_url() ) );` );
	const credentials = () => runSiteWp( managedPath, `
$items = WP_Application_Passwords::get_user_application_passwords( ${ managed.id } );
echo 'FLEET_E2E_JSON:' . wp_json_encode( array_values( array_map( static function( $item ) { return $item['uuid']; }, array_filter( $items, static function( $item ) { return isset( $item['app_id'] ) && '${ appId }' === $item['app_id']; } ) ) ) );` );
	async function authenticate( path, site ) {
		const cookies = runSiteWp( path, `
$expires = time() + 600;
echo 'FLEET_E2E_JSON:' . wp_json_encode( array(
array( 'name' => SECURE_AUTH_COOKIE, 'value' => wp_generate_auth_cookie( ${ site.id }, $expires, 'secure_auth' ) ),
array( 'name' => LOGGED_IN_COOKIE, 'value' => wp_generate_auth_cookie( ${ site.id }, $expires, 'logged_in' ) ) ) );` );
		await page.context().addCookies( cookies.map( ( cookie ) => ( { ...cookie, url: site.url, secure: true, httpOnly: true, sameSite: 'Lax' } ) ) );
	}
	async function shell() {
		await page.goto( `${ hub.url }/wp-admin/admin.php?page=openstation` );
		const enable = page.getByRole( 'button', { name: 'Enable it now', exact: true } );
		if ( await enable.isVisible() ) {
			await enable.click();
		}
		await page.waitForFunction( () => typeof window.wp?.os?.openNewWindow === 'function' );
	}
	async function approve() {
		await page.getByRole( 'button', { name: 'Continue to WordPress', exact: true } ).click();
		await page.locator( '#approve' ).waitFor();
		await page.locator( '#approve' ).click();
		// Wait on the final shell DOM, not a callback URL containing a credential.
		await page.locator( '.fleet-native-setup:visible' ).first().waitFor( { timeout: 30_000 } );
	}
	try {
		await authenticate( hubPath, hub );
		await authenticate( managedPath, managed );
		await shell();
		await page.evaluate( () => window.wp.os.openNewWindow( 'fleet-for-openstation', { source: 'fleet-e2e' } ) );
		const connect = page.locator( '.fleet-native-connect' ).first();
		await connect.locator( 'os-text-field[name="site_url"] input' ).fill( managed.url );
		await connect.getByRole( 'button', { name: 'Check connection', exact: true } ).click();
		await expect( page.getByText( 'Ready for approval', { exact: true } ) ).toBeVisible();
		await expect( page.getByText( /administrator-level access, not a limited OAuth scope/ ) ).toBeVisible();
		await approve();
		expect( credentials() ).toHaveLength( 1 );
		await page.getByRole( 'button', { name: 'Finish setup', exact: true } ).click();
		await expect( page.locator( '.fleet-native-setup:visible' ) ).toHaveCount( 0, { timeout: 30_000 } );
		const saved = runSiteWp( hubPath, `
wp_set_current_user( ${ hub.id } );
$sites = OpenStation_Fleet_Repository::all( ${ hub.id }, array( 'OpenStation_Fleet', 'normalize_site_record' ) );
$key = array_key_first( $sites );
OpenStation_Fleet::app_action( $key, 'agency', array( 'client_name' => 'Isolated launch test', 'notes' => 'Preserve across reconnect' ) );
echo 'FLEET_E2E_JSON:' . wp_json_encode( array( 'id' => $key, 'uuid' => $sites[$key]['credential_uuid'] ) );` );
		// Revoke only this test user's remote credential, never an existing agency connection.
		runSiteWp( managedPath, `WP_Application_Passwords::delete_application_password( ${ managed.id }, '${ saved.uuid }' ); echo 'FLEET_E2E_JSON:true';` );
		const revoked = runSiteWp( hubPath, `wp_set_current_user( ${ hub.id } ); $r = OpenStation_Fleet::app_action( '${ saved.id }', 'refresh' ); $s = OpenStation_Fleet_Repository::get( ${ hub.id }, '${ saved.id }', array( 'OpenStation_Fleet', 'normalize_site_record' ) ); echo 'FLEET_E2E_JSON:' . wp_json_encode( array( 'failed' => is_wp_error( $r ), 'backoff' => $s['next_retry'] > time(), 'notes' => $s['agency']['notes'] ) );` );
		expect( revoked ).toEqual( { failed: true, backoff: true, notes: 'Preserve across reconnect' } );
		await page.getByRole( 'button', { name: 'Window actions', exact: true } ).last().click();
		await page.getByRole( 'menuitem', { name: 'Repair connection', exact: true } ).click();
		await approve();
		expect( credentials() ).toHaveLength( 1 );
		const repaired = runSiteWp( hubPath, `
$site = OpenStation_Fleet_Repository::get( ${ hub.id }, '${ saved.id }', array( 'OpenStation_Fleet', 'normalize_site_record' ) );
echo 'FLEET_E2E_JSON:' . wp_json_encode( array( 'uuid' => $site['credential_uuid'], 'notes' => $site['agency']['notes'] ) );` );
		expect( repaired.uuid ).not.toBe( saved.uuid );
		expect( repaired.notes ).toBe( 'Preserve across reconnect' );
		await page.getByRole( 'button', { name: 'Finish setup', exact: true } ).click();
		await expect( page.locator( '.fleet-native-setup:visible' ) ).toHaveCount( 0, { timeout: 30_000 } );
		await page.getByRole( 'button', { name: 'Window actions', exact: true } ).last().click();
		await page.getByRole( 'menuitem', { name: 'Disconnect site', exact: true } ).click();
		await page.getByRole( 'button', { name: 'Disconnect', exact: true } ).click();
		await expect.poll( credentials ).toHaveLength( 0 );
		expect( runSiteWp( hubPath, `echo 'FLEET_E2E_JSON:' . wp_json_encode( count( OpenStation_Fleet_Repository::all( ${ hub.id }, array( 'OpenStation_Fleet', 'normalize_site_record' ) ) ) );` ) ).toBe( 0 );
	} finally {
		for ( const uuid of credentials() ) {
			runSiteWp( managedPath, `WP_Application_Passwords::delete_application_password( ${ managed.id }, '${ uuid }' ); echo 'FLEET_E2E_JSON:true';` );
		}
		runSiteWp( hubPath, `require_once ABSPATH . 'wp-admin/includes/user.php'; OpenStation_Fleet_Repository::delete_all( ${ hub.id } ); wp_delete_user( ${ hub.id } ); echo 'FLEET_E2E_JSON:true';` );
	}
} );
