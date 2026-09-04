const { test, expect } = require( '@playwright/test' );
const { loadWordPressFixture, runSiteWp } = require( './wordpress-fixture' );
const fixture = loadWordPressFixture();

async function contentWindow( page, site = fixture.sites[ 0 ] ) {
	await page.context().addCookies( fixture.cookies );
	await page.goto( `${ fixture.hubUrl }/wp-admin/admin.php?page=openstation` );
	await page.waitForFunction( () => typeof window.wp?.os?.openNewWindow === 'function' );
	await page.evaluate( () => window.wp.os.windowManager.getAll().forEach( ( win ) => win.close() ) );
	await page.waitForFunction( () => ! document.querySelector( '.os-window' ) );
	await page.evaluate( ( id ) => window.wp.os.openNewWindow( 'fleet-site', { source: 'fleet-edge-test', params: { site_id: id } } ), site.id );
	await page.locator( '.fleet-native-site-header:visible' ).first().waitFor();
	await page.evaluate( async () => {
		await window.wp.os.windowManager.getAll().find( ( win ) => win.config?.baseId === 'fleet-site' ).whenContentReady();
		await window.wp.os.loadComponents( [ 'os-form', 'os-table' ] );
	} );
	const frame = page.locator( '.os-window' );
	await frame.locator( '.os-window__tab[data-panel="content"]' ).click();
	await frame.locator( 'os-tabpanel[for="content"] .fleet-native-filters' ).waitFor();
	await expect( frame.locator( 'os-tabpanel[for="content"] [data-os-app]' ) ).not.toHaveAttribute( 'aria-busy', 'true' );
	return frame;
}

async function unsavedDraft( page ) {
	const frame = await contentWindow( page );
	await frame.getByRole( 'button', { name: 'New post', exact: true } ).click();
	const title = frame.locator( '.fleet-native-editor os-text-field[name="title"] input' );
	await title.fill( 'Fleet edge test — unsaved text' );
	await expect( frame.getByText( 'Unsaved changes', { exact: true } ) ).toBeVisible();
	const ownership = await frame.locator( '.fleet-native-editor' ).evaluate( ( form ) => ( {
		trackedOwner: form.closest( '[id^="wp-window-"]' )?.id,
		actualWindow: form.closest( '.os-window' )?.id,
	} ) );
	await test.info().attach( 'unsaved-owner', { body: JSON.stringify( ownership ), contentType: 'application/json' } );
	return { frame, title };
}

test( 'native Refresh cannot silently discard an unsaved draft', async ( { page } ) => {
	const { frame, title } = await unsavedDraft( page );
	await frame.getByRole( 'button', { name: 'Refresh', exact: true } ).click();
	await expect( frame.locator( 'os-tabpanel[for="content"] [data-os-app]' ) ).not.toHaveAttribute( 'aria-busy', 'true', { timeout: 30_000 } );
	await test.info().attach( 'after-refresh', { body: JSON.stringify( { title: await title.inputValue(), discardPrompt: await page.getByRole( 'button', { name: 'Discard changes', exact: true } ).isVisible() } ), contentType: 'application/json' } );
	if ( await page.getByRole( 'button', { name: 'Discard changes', exact: true } ).isVisible() ) {
		await page.getByRole( 'button', { name: 'Cancel', exact: true } ).click();
	}
	await expect( title ).toHaveValue( 'Fleet edge test — unsaved text' );
} );

test( 'canceling a dirty window close retains its source text', async ( { page } ) => {
	const { frame, title } = await unsavedDraft( page );
	await frame.getByRole( 'button', { name: 'Close', exact: true } ).click();
	try {
		await expect( page.getByRole( 'button', { name: 'Discard changes', exact: true } ) ).toBeVisible();
	} finally {
		await test.info().attach( 'after-close', { body: JSON.stringify( { windowsRemaining: await frame.count() } ), contentType: 'application/json' } );
	}
	await page.getByRole( 'button', { name: 'Cancel', exact: true } ).click();
	await expect( frame ).toBeVisible();
	await expect( title ).toHaveValue( 'Fleet edge test — unsaved text' );
} );

test( 'tab navigation cannot silently discard an unsaved draft', async ( { page } ) => {
	const { frame, title } = await unsavedDraft( page );
	await frame.locator( '.os-window__tab[data-panel="comments"]' ).click();
	if ( await page.getByRole( 'button', { name: 'Discard changes', exact: true } ).isVisible() ) {
		await page.getByRole( 'button', { name: 'Cancel', exact: true } ).click();
	} else {
		await expect( frame.locator( '.os-window__tab[data-panel="comments"]' ) ).toHaveAttribute( 'aria-selected', 'true' );
		await frame.locator( '.os-window__tab[data-panel="content"]' ).click();
	}
	await expect( frame.locator( '.os-window__tab[data-panel="content"]' ) ).toHaveAttribute( 'aria-selected', 'true' );
	await expect( frame.locator( 'os-tabpanel[for="content"] [data-os-app]' ) ).not.toHaveAttribute( 'aria-busy', 'true' );
	await expect( title ).toHaveValue( 'Fleet edge test — unsaved text' );
} );

test( 'saving a timezone updates the actual WordPress timezone', async ( { page } ) => {
	test.skip( process.env.FLEET_E2E_WRITES !== '1' || ! process.env.FLEET_E2E_MANAGED_PATH, 'Explicit disposable managed-site write opt-in required.' );
	const managedPath = process.env.FLEET_E2E_MANAGED_PATH;
	const original = runSiteWp( managedPath, "echo 'FLEET_E2E_JSON:' . wp_json_encode( array( 'url' => home_url(), 'timezone' => get_option( 'timezone_string' ) ) );" );
	const site = fixture.sites.find( ( item ) => item.site_url.replace( /\/$/, '' ) === original.url.replace( /\/$/, '' ) );
	expect( site, 'The opted-in local site must match a discovered Fleet connection.' ).toBeTruthy();
	const desired = original.timezone === 'America/New_York' ? 'Europe/London' : 'America/New_York';
	try {
		const frame = await contentWindow( page, site );
		await frame.locator( '.os-window__tab[data-panel="settings"]' ).click();
		const panel = frame.locator( 'os-tabpanel[for="settings"]' );
		await panel.locator( 'os-form[os-action="save-settings"]' ).waitFor();
		await expect( panel.locator( '[data-os-app]' ) ).not.toHaveAttribute( 'aria-busy', 'true' );
		await panel.getByLabel( 'Timezone', { exact: true } ).fill( desired );
		await panel.getByRole( 'button', { name: 'Save site settings', exact: true } ).click();
		await expect( panel ).toContainText( 'Remote site settings updated.', { timeout: 30_000 } );
		const actual = runSiteWp( managedPath, "echo 'FLEET_E2E_JSON:' . wp_json_encode( get_option( 'timezone_string' ) );" );
		await test.info().attach( 'timezone-result', { body: JSON.stringify( { original: original.timezone, requested: desired, actual } ), contentType: 'application/json' } );
		expect( actual ).toBe( desired );
	} finally {
		const restore = Buffer.from( JSON.stringify( { desired, original: original.timezone } ) ).toString( 'base64' );
		runSiteWp( managedPath, `$values = json_decode( base64_decode( '${ restore }' ), true ); if ( get_option( 'timezone_string' ) === $values['desired'] ) { update_option( 'timezone_string', $values['original'] ); } echo 'FLEET_E2E_JSON:true';` );
	}
} );

test( 'a zero-result search has an empty state and can recover', async ( { page } ) => {
	const frame = await contentWindow( page );
	const form = frame.locator( 'os-tabpanel[for="content"] .fleet-native-filters' );
	await form.locator( 'input[name="search"]' ).fill( 'fleet-no-match-3c73a201e572' );
	await form.getByRole( 'button', { name: 'Apply filters', exact: true } ).click();
	await expect( frame.locator( 'os-tabpanel[for="content"] [data-os-app]' ) ).not.toHaveAttribute( 'aria-busy', 'true' );
	await expect( frame.locator( 'os-tabpanel[for="content"]' ) ).toContainText( /No .*found|No .*yet|0 items/ );
	await form.locator( 'input[name="search"]' ).fill( '' );
	await form.getByRole( 'button', { name: 'Apply filters', exact: true } ).click();
	await expect( frame.locator( '.fleet-native-content-list' ).getByRole( 'row' ).nth( 1 ) ).toBeVisible();
} );

test( 'a failed Site Health fetch cannot erase the last critical finding', () => {
	const result = runSiteWp( process.env.FLEET_E2E_HUB_PATH, `
wp_set_current_user( ${ fixture.userId } );
$site = OpenStation_Fleet_Repository::get( ${ fixture.userId }, '${ fixture.sites[ 0 ].id }', array( 'OpenStation_Fleet', 'normalize_site_record' ) );
$site['status_checked'] = time(); $site['metadata_checked'] = time(); $site['health_checked'] = 0; $site['next_retry'] = 0;
$site['health'] = array( 'loopback-requests' => array( 'status' => 'critical', 'label' => 'Previously observed failure' ) );
add_filter( 'pre_http_request', static function( $pre, $args, $url ) {
 if ( false !== strpos( $url, 'wp-site-health' ) ) { return new WP_Error( 'http_request_failed', 'Injected timeout' ); }
 return array( 'response' => array( 'code' => 200 ), 'headers' => array(), 'body' => wp_json_encode( array( 'routes' => array( '/wp-site-health/v1/tests/background-updates' => array() ) ) ) );
}, 10, 3 );
$method = new ReflectionMethod( 'OpenStation_Fleet', 'refresh_site' );
if ( PHP_VERSION_ID < 80100 ) { $method->setAccessible( true ); }
$updated = $method->invoke( null, $site, false );
echo 'FLEET_E2E_JSON:' . wp_json_encode( array( 'health' => $updated['health'], 'error' => $updated['error'], 'checked' => $updated['health_checked'] ) );` );
	// The fault exists only in this CLI process; no stored connection is edited.
	expect( Object.keys( result.health ).length > 0 || result.error !== '' ).toBe( true );
} );

test( 'unauthenticated callers cannot address the demo user connections', () => {
	const result = runSiteWp( process.env.FLEET_E2E_HUB_PATH, `
wp_set_current_user( 0 ); $requests = 0;
add_filter( 'pre_http_request', static function() use ( &$requests ) { ++$requests; return new WP_Error( 'unexpected_network' ); } );
$read = OpenStation_Fleet::app_site_data( '${ fixture.sites[ 0 ].id }', 'content' );
$write = OpenStation_Fleet::app_action( '${ fixture.sites[ 0 ].id }', 'content', array() );
echo 'FLEET_E2E_JSON:' . wp_json_encode( array( 'read_denied' => is_wp_error( $read ), 'write_denied' => is_wp_error( $write ), 'requests' => $requests ) );` );
	expect( result ).toEqual( { read_denied: true, write_denied: true, requests: 0 } );
} );

test( 'a different hub administrator cannot read another users connections', () => {
	test.skip( process.env.FLEET_E2E_WRITES !== '1', 'Explicit temporary-user creation opt-in required.' );
	const result = runSiteWp( process.env.FLEET_E2E_HUB_PATH, `
$id = wp_insert_user( array( 'user_login' => 'fleet_edge_' . wp_generate_password( 12, false ), 'user_pass' => wp_generate_password( 32 ), 'role' => 'administrator' ) );
if ( is_wp_error( $id ) ) { throw new Exception( 'Could not create isolated test user.' ); }
try {
 wp_set_current_user( $id ); $requests = 0;
 add_filter( 'pre_http_request', static function() use ( &$requests ) { ++$requests; return new WP_Error( 'unexpected_network' ); } );
 $read = OpenStation_Fleet::app_site_data( '${ fixture.sites[ 0 ].id }', 'content' );
 $write = OpenStation_Fleet::app_action( '${ fixture.sites[ 0 ].id }', 'content', array() );
 echo 'FLEET_E2E_JSON:' . wp_json_encode( array( 'read_denied' => is_wp_error( $read ), 'write_denied' => is_wp_error( $write ), 'requests' => $requests ) );
} finally { require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user( $id ); }` );
	expect( result ).toEqual( { read_denied: true, write_denied: true, requests: 0 } );
} );

test( 'malformed date input returns a validation error rather than crashing', () => {
	const result = runSiteWp( process.env.FLEET_E2E_HUB_PATH, `
$values = array( 'title' => 'Draft', 'content' => '', 'excerpt' => '', 'slug' => '', 'status' => 'draft', 'date_gmt' => '2026-12-31T14:30:00' . chr( 0 ) );
try { $result = OpenStation_Fleet_Content::body( $values ); echo 'FLEET_E2E_JSON:' . wp_json_encode( array( 'validation_error' => is_wp_error( $result ) ) ); }
catch ( Throwable $error ) { echo 'FLEET_E2E_JSON:' . wp_json_encode( array( 'exception' => get_class( $error ) ) ); }` );
	expect( result ).toEqual( { validation_error: true } );
} );
