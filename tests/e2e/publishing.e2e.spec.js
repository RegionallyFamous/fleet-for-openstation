const { test, expect } = require( '@playwright/test' );
const AxeBuilder = require( '@axe-core/playwright' ).default;
const { loadWordPressFixture, runSiteWp } = require( './wordpress-fixture' );
const fixture = loadWordPressFixture();
const hubPath = process.env.FLEET_E2E_HUB_PATH;
const managedPath = process.env.FLEET_E2E_MANAGED_PATH;
const phpValue = ( value ) => `json_decode( base64_decode( '${ Buffer.from( JSON.stringify( value ) ).toString( 'base64' ) }' ), true )`;

function target() {
	test.skip( process.env.FLEET_E2E_WRITES !== '1' || ! managedPath, 'Requires explicit disposable-site write opt-in.' );
	const url = runSiteWp( managedPath, "echo 'FLEET_E2E_JSON:' . wp_json_encode( home_url() );" );
	const site = fixture.sites.find( ( item ) => item.site_url.replace( /\/$/, '' ) === url.replace( /\/$/, '' ) );
	expect( site ).toBeTruthy();
	return site;
}

async function contentWindow( page, site ) {
	await page.context().addCookies( fixture.cookies );
	await page.goto( `${ fixture.hubUrl }/wp-admin/admin.php?page=openstation` );
	await page.waitForFunction( () => window.wp?.os?.windowManager );
	await page.evaluate( () => window.wp.os.windowManager.getAll().forEach( ( win ) => win.close() ) );
	await page.waitForFunction( () => ! document.querySelector( '.os-window' ) );
	await page.evaluate( ( id ) => window.wp.os.openNewWindow( 'fleet-site', { params: { site_id: id }, source: 'fleet-publishing-test' } ), site.id );
	await page.locator( '.fleet-native-site-header:visible' ).first().waitFor();
	await page.evaluate( async () => {
		await window.wp.os.windowManager.getAll()[ 0 ].whenContentReady();
		await window.wp.os.loadComponents( [ 'os-form', 'os-table' ] );
	} );
	await page.locator( '.os-window__tab[data-panel="content"]' ).click();
	const panel = page.locator( 'os-tabpanel[for="content"]' );
	await panel.locator( '.fleet-native-filters' ).waitFor();
	await ready( panel );
	return panel;
}

const ready = ( panel ) => expect( panel.locator( '[data-os-app]' ) ).not.toHaveAttribute( 'aria-busy', 'true', { timeout: 30_000 } );
const choose = ( panel, name, value ) => panel.locator( `os-select[name="${ name }"]` ).evaluate( ( el, next ) => { el.value = next; }, value );
function removePost( id ) { if ( id ) { runSiteWp( managedPath, `wp_delete_post( ${ Number( id ) }, true ); echo 'FLEET_E2E_JSON:true';` ); } }

test( 'publishing requires review, preserves edits, and writes only after confirmation', async ( { page } ) => {
	const site = target();
	const title = `Fleet review ${ Date.now() }`;
	let id = 0;
	try {
		const panel = await contentWindow( page, site );
		await panel.getByRole( 'button', { name: 'New post', exact: true } ).click();
		const editor = panel.locator( '.fleet-native-editor' );
		await editor.getByLabel( 'Title', { exact: true } ).fill( title );
		await editor.getByLabel( 'Content source', { exact: true } ).fill( '<!-- wp:paragraph --><p>Reviewed source.</p><!-- /wp:paragraph -->' );
		await choose( editor, 'status', 'publish' );
		await editor.getByRole( 'button', { name: 'Save to WordPress', exact: true } ).click();
		await expect( panel.locator( '.fleet-native-publish-review' ) ).toContainText( site.site_url );
		const accessibility = await new AxeBuilder( { page } ).include( '.fleet-native-publish-review' ).withTags( [ 'wcag2a', 'wcag2aa' ] ).analyze();
		expect( accessibility.violations ).toEqual( [] );
		expect( runSiteWp( managedPath, `$items = get_posts( array( 'title' => ${ phpValue( title ) }, 'post_status' => 'any' ) ); echo 'FLEET_E2E_JSON:' . wp_json_encode( count( $items ) );` ) ).toBe( 0 );
		await panel.getByRole( 'button', { name: 'Keep editing', exact: true } ).click();
		await expect( editor.getByLabel( 'Title', { exact: true } ) ).toHaveValue( title );
		await editor.getByRole( 'button', { name: 'Save to WordPress', exact: true } ).click();
		await panel.getByRole( 'button', { name: 'Confirm and save', exact: true } ).click();
		await ready( panel );
		await expect( panel ).toContainText( 'Saved on WordPress.' );
		const stored = runSiteWp( managedPath, `$items = get_posts( array( 'title' => ${ phpValue( title ) }, 'post_status' => 'any' ) ); echo 'FLEET_E2E_JSON:' . wp_json_encode( array( 'id' => $items[0]->ID, 'status' => $items[0]->post_status, 'count' => count( $items ) ) );` );
		id = stored.id;
		expect( stored ).toEqual( { id, status: 'publish', count: 1 } );
	} finally {
		if ( ! id ) { id = runSiteWp( managedPath, `$items = get_posts( array( 'title' => ${ phpValue( title ) }, 'post_status' => 'any' ) ); echo 'FLEET_E2E_JSON:' . wp_json_encode( $items ? $items[0]->ID : 0 );` ); }
		removePost( id );
	}
} );

test( 'review signatures reject changed fields, expiry and cross-site replay before writing', () => {
	const site = target();
	const result = runSiteWp( hubPath, `
wp_set_current_user( ${ fixture.userId } );
$values = array( 'content_type' => 'posts', 'content_id' => 0, 'request_id' => wp_generate_uuid4(), 'fingerprint' => '', 'title' => 'Review protection', 'content' => '', 'excerpt' => '', 'slug' => '', 'status' => 'publish', 'date_gmt' => '' );
$review = OpenStation_Fleet::content_review( '${ site.id }', $values );
if ( is_wp_error( $review ) ) { throw new Exception( $review->get_error_message() ); }
$values['review_token'] = $review['token']; $values['review_expires'] = $review['expires'];
$record = OpenStation_Fleet_Repository::get( ${ fixture.userId }, '${ site.id }', array( 'OpenStation_Fleet', 'normalize_site_record' ) );
$valid = OpenStation_Fleet_Content::reviewed( $record, $values );
$changed = $values; $changed['content'] = 'Not reviewed';
$expired = $values; $expired['review_expires'] = time() - 1;
$other = $record; $other['site_url'] = 'https://other.example';
$rotated = $record; $rotated['connection_generation'] = 'changed';
$writes = 0; add_filter( 'pre_http_request', static function( $pre, $args ) use ( &$writes ) { if ( 'POST' === $args['method'] ) { ++$writes; return new WP_Error( 'unexpected_write' ); } return $pre; }, 10, 2 );
$attempt = OpenStation_Fleet::app_action( '${ site.id }', 'content', $changed );
echo 'FLEET_E2E_JSON:' . wp_json_encode( array( 'valid' => $valid, 'changed' => OpenStation_Fleet_Content::reviewed( $record, $changed ), 'expired' => OpenStation_Fleet_Content::reviewed( $record, $expired ), 'other_site' => OpenStation_Fleet_Content::reviewed( $other, $values ), 'rotated' => OpenStation_Fleet_Content::reviewed( $rotated, $values ), 'code' => is_wp_error( $attempt ) ? $attempt->get_error_code() : '', 'writes' => $writes ) );` );
	expect( result ).toEqual( { valid: true, changed: false, expired: false, other_site: false, rotated: false, code: 'fleet_review_required', writes: 0 } );
} );

test( 'compares and recovers a revision through the ordinary reviewed save', async ( { page } ) => {
	const site = target();
	const title = `Fleet recovery ${ Date.now() }`;
	const created = runSiteWp( managedPath, `
$id = wp_insert_post( array( 'post_title' => ${ phpValue( title ) }, 'post_content' => '<p>Original source.</p>', 'post_status' => 'publish' ) );
wp_save_post_revision( $id );
wp_update_post( array( 'ID' => $id, 'post_content' => '<p>Later source.</p>' ) );
$revisions = wp_get_post_revisions( $id ); $original = 0; foreach ( $revisions as $r ) { if ( '<p>Original source.</p>' === $r->post_content ) { $original = $r->ID; break; } }
echo 'FLEET_E2E_JSON:' . wp_json_encode( array( 'id' => $id, 'revision' => $original ) );` );
	try {
		expect( created.revision ).toBeGreaterThan( 0 );
		const panel = await contentWindow( page, site );
		await panel.locator( '.fleet-native-content-list' ).getByRole( 'row' ).filter( { hasText: title } ).click();
		await panel.getByRole( 'button', { name: 'Revision history', exact: true } ).click();
		await panel.locator( `os-button[os-arg-revision_id="${ created.revision }"]` ).click();
		await expect( panel.locator( '.fleet-native-diff' ).first() ).toContainText( 'Original' );
		expect( runSiteWp( managedPath, `echo 'FLEET_E2E_JSON:' . wp_json_encode( get_post( ${ created.id } )->post_content );` ) ).toBe( '<p>Later source.</p>' );
		await panel.getByRole( 'button', { name: 'Use this revision', exact: true } ).click();
		const editor = panel.locator( '.fleet-native-editor' );
		await expect( editor.getByLabel( 'Content source', { exact: true } ) ).toHaveValue( '<p>Original source.</p>' );
		await expect( editor ).toContainText( 'Unsaved changes' );
		await editor.getByRole( 'button', { name: 'Save to WordPress', exact: true } ).click();
		await panel.getByRole( 'button', { name: 'Confirm and save', exact: true } ).click();
		await ready( panel );
		await expect( panel ).toContainText( 'Saved on WordPress.' );
		const result = runSiteWp( managedPath, `$p = get_post( ${ created.id } ); echo 'FLEET_E2E_JSON:' . wp_json_encode( array( 'content' => $p->post_content, 'status' => $p->post_status ) );` );
		expect( result ).toEqual( { content: '<p>Original source.</p>', status: 'publish' } );
	} finally { removePost( created.id ); }
} );

test( 'saved work views persist, restore filters, stay site-scoped and can be removed', async ( { page } ) => {
	const site = target();
	const name = `Editorial review ${ Date.now() }`;
	let key = '';
	try {
		const panel = await contentWindow( page, site );
		const filters = panel.locator( '.fleet-native-filters' );
		await choose( filters, 'status', 'pending' );
		await filters.getByLabel( 'Search this site', { exact: true } ).fill( 'Harbor' );
		await filters.getByRole( 'button', { name: 'Apply filters', exact: true } ).click();
		await ready( panel );
		await panel.locator( '.fleet-native-saved-views summary' ).click();
		await panel.getByLabel( 'View name', { exact: true } ).fill( name );
		await panel.getByRole( 'button', { name: 'Save view', exact: true } ).click();
		await ready( panel );
		const saved = runSiteWp( hubPath, `wp_set_current_user( ${ fixture.userId } ); $views = OpenStation_Fleet::work_views( '${ site.id }' ); $key = ''; foreach ( $views as $id => $view ) { if ( $view['name'] === ${ phpValue( name ) } ) { $key = $id; } } echo 'FLEET_E2E_JSON:' . wp_json_encode( array( 'key' => $key, 'view' => $views[$key] ) );` );
		key = saved.key;
		expect( saved.view.status ).toBe( 'pending' );
		await page.reload();
		const reloaded = await contentWindow( page, site );
		await reloaded.locator( '.fleet-native-saved-views summary' ).click();
		await reloaded.getByRole( 'button', { name, exact: true } ).click();
		await ready( reloaded );
		expect( await reloaded.locator( 'os-select[name="status"]' ).evaluate( ( el ) => el.value ) ).toBe( 'pending' );
		await expect( reloaded.getByLabel( 'Search this site', { exact: true } ) ).toHaveValue( 'Harbor' );
		const sibling = fixture.sites.find( ( item ) => item.id !== site.id );
		expect( runSiteWp( hubPath, `wp_set_current_user( ${ fixture.userId } ); $views = OpenStation_Fleet::work_views( '${ sibling.id }' ); echo 'FLEET_E2E_JSON:' . wp_json_encode( isset( $views['${ key }'] ) );` ) ).toBe( false );
		await reloaded.getByRole( 'button', { name: `Remove ${ name }`, exact: true } ).click();
		await ready( reloaded );
		expect( runSiteWp( hubPath, `wp_set_current_user( ${ fixture.userId } ); $views = OpenStation_Fleet::work_views( '${ site.id }' ); echo 'FLEET_E2E_JSON:' . wp_json_encode( isset( $views['${ key }'] ) );` ) ).toBe( false );
	} finally {
		if ( ! key ) { key = require( 'node:crypto' ).createHash( 'sha256' ).update( name ).digest( 'hex' ).slice( 0, 16 ); }
		runSiteWp( hubPath, `wp_set_current_user( ${ fixture.userId } ); OpenStation_Fleet::work_views( '${ site.id }', 'delete', array( 'view_id' => '${ key }' ) ); echo 'FLEET_E2E_JSON:true';` );
	}
} );

test( 'discovers a namespaced Core custom type and excludes a restricted type', async ( { page } ) => {
	const site = target();
	const enabled = runSiteWp( managedPath, "echo 'FLEET_E2E_JSON:' . wp_json_encode( file_exists( WPMU_PLUGIN_DIR . '/fleet-modern-test-types.php' ) );" );
	test.skip( ! enabled, 'Requires the explicitly installed, inert Studio custom-type fixture.' );
	const marker = require( 'node:crypto' ).randomUUID();
	const title = `Fleet event ${ Date.now() }`;
	let id = 0;
	try {
		runSiteWp( managedPath, `update_option( 'fleet_e2e_custom_types', '${ marker }', false ); echo 'FLEET_E2E_JSON:true';` );
		runSiteWp( hubPath, `wp_set_current_user( ${ fixture.userId } ); $m = new ReflectionMethod( 'OpenStation_Fleet', 'invalidate_read_cache' ); $m->invoke( null, '${ site.id }' ); echo 'FLEET_E2E_JSON:true';` );
		const panel = await contentWindow( page, site );
		const select = panel.locator( 'os-select[name="type"]' );
		const options = await select.evaluate( ( el ) => el.textContent );
		expect( options ).toContain( 'Studio events' );
		expect( options ).not.toContain( 'Restricted events' );
		await choose( panel, 'type', 'fleet_event' );
		await panel.getByRole( 'button', { name: 'Apply filters', exact: true } ).click();
		await panel.getByRole( 'button', { name: 'New item', exact: true } ).click();
		await panel.locator( '.fleet-native-editor' ).getByLabel( 'Title', { exact: true } ).fill( title );
		await panel.getByRole( 'button', { name: 'Save to WordPress', exact: true } ).click();
		await ready( panel );
		await expect( panel ).toContainText( 'Saved on WordPress.' );
		const item = runSiteWp( managedPath, `$items = get_posts( array( 'post_type' => 'fleet_event', 'post_status' => 'any', 'title' => ${ phpValue( title ) } ) ); echo 'FLEET_E2E_JSON:' . wp_json_encode( array( 'id' => $items[0]->ID, 'type' => $items[0]->post_type ) );` );
		id = item.id;
		expect( item.type ).toBe( 'fleet_event' );
	} finally {
		if ( ! id ) { id = runSiteWp( managedPath, `$items = get_posts( array( 'post_type' => 'fleet_event', 'post_status' => 'any', 'title' => ${ phpValue( title ) } ) ); echo 'FLEET_E2E_JSON:' . wp_json_encode( $items ? $items[0]->ID : 0 );` ); }
		removePost( id );
		runSiteWp( managedPath, `if ( get_option( 'fleet_e2e_custom_types' ) === '${ marker }' ) { delete_option( 'fleet_e2e_custom_types' ); } echo 'FLEET_E2E_JSON:true';` );
		runSiteWp( hubPath, `wp_set_current_user( ${ fixture.userId } ); $m = new ReflectionMethod( 'OpenStation_Fleet', 'invalidate_read_cache' ); $m->invoke( null, '${ site.id }' ); echo 'FLEET_E2E_JSON:true';` );
	}
} );

test( 'site-week filtering supports the current Core fixed-offset timezone setting', async ( { page } ) => {
	const site = target();
	const panel = await contentWindow( page, site );
	await choose( panel, 'period', 'week' );
	await panel.getByRole( 'button', { name: 'Apply filters', exact: true } ).click();
	await ready( panel );
	await expect( panel.locator( '.fleet-native-content-list' ) ).toBeVisible();
	await expect( panel ).not.toContainText( 'unsupported timezone' );
} );

test( 'health partial failure retains failed findings and clears stale status on recovery', () => {
	const site = target();
	const result = runSiteWp( hubPath, `
wp_set_current_user( ${ fixture.userId } );
$site = OpenStation_Fleet_Repository::get( ${ fixture.userId }, '${ site.id }', array( 'OpenStation_Fleet', 'normalize_site_record' ) );
$site['status_checked'] = time(); $site['metadata_checked'] = time(); $site['health_checked'] = 0; $site['next_retry'] = 0; $site['health_error'] = '';
$site['health'] = array( 'background-updates' => array( 'status' => 'critical', 'label' => 'Old failure' ), 'loopback-requests' => array( 'status' => 'critical', 'label' => 'Resolved failure' ) );
$fail = true;
add_filter( 'pre_http_request', static function( $pre, $args, $url ) use ( &$fail ) {
 if ( false !== strpos( $url, 'wp-site-health' ) ) {
  if ( $fail && false !== strpos( $url, 'background-updates' ) ) { return new WP_Error( 'http_request_failed', 'Injected timeout' ); }
  $body = array( 'status' => 'good', 'label' => 'Verified' );
 } else { $body = array( 'routes' => array( '/wp-site-health/v1/tests/background-updates' => array() ) ); }
 return array( 'response' => array( 'code' => 200 ), 'headers' => array(), 'body' => wp_json_encode( $body ) );
}, 10, 3 );
$method = new ReflectionMethod( 'OpenStation_Fleet', 'refresh_site' );
$partial = $method->invoke( null, $site, false );
$fail = false; $retry = $partial; $retry['health_attempted'] = 0;
$recovered = $method->invoke( null, $retry, false );
echo 'FLEET_E2E_JSON:' . wp_json_encode( array( 'retained' => $partial['health']['background-updates']['status'], 'updated' => $partial['health']['loopback-requests']['status'], 'stale' => '' !== $partial['health_error'], 'checked' => $partial['health_checked'], 'attempted' => $partial['health_attempted'] > 0, 'recovered' => '' === $recovered['health_error'] && $recovered['health_checked'] > 0 && count( $recovered['health'] ) === 4 ) );` );
	expect( result ).toEqual( { retained: 'critical', updated: 'good', stale: true, checked: 0, attempted: true, recovered: true } );
} );

test( 'a remote edit after review is rejected without overwriting the newer post', () => {
	const site = target();
	const id = runSiteWp( managedPath, "$id = wp_insert_post( array( 'post_title' => 'Fleet reviewed conflict', 'post_status' => 'publish', 'post_content' => '<p>Original.</p>' ) ); echo 'FLEET_E2E_JSON:' . wp_json_encode( $id );" );
	try {
		const reviewed = runSiteWp( hubPath, `wp_set_current_user( ${ fixture.userId } ); $data = OpenStation_Fleet::app_site_data( '${ site.id }', 'content', array( 'type' => 'posts', 'selected' => ${ id } ) ); $v = array_merge( OpenStation_Fleet_Content::editable( $data['item'] ), array( 'content_type' => 'posts', 'content_id' => ${ id }, 'request_id' => wp_generate_uuid4(), 'fingerprint' => OpenStation_Fleet_Content::fingerprint( $data['item'] ) ) ); $v['content'] = '<p>Reviewed proposed change.</p>'; $r = OpenStation_Fleet::content_review( '${ site.id }', $v ); if ( is_wp_error( $r ) ) { throw new Exception( $r->get_error_message() ); } $v['review_token'] = $r['token']; $v['review_expires'] = $r['expires']; echo 'FLEET_E2E_JSON:' . wp_json_encode( $v );` );
		runSiteWp( managedPath, `wp_update_post( array( 'ID' => ${ id }, 'post_content' => '<p>Another author saved this.</p>' ) ); echo 'FLEET_E2E_JSON:true';` );
		const result = runSiteWp( hubPath, `wp_set_current_user( ${ fixture.userId } ); $r = OpenStation_Fleet::app_action( '${ site.id }', 'content', ${ phpValue( reviewed ) } ); echo 'FLEET_E2E_JSON:' . wp_json_encode( is_wp_error( $r ) ? $r->get_error_code() : '' );` );
		expect( result ).toBe( 'fleet_content_conflict' );
		expect( runSiteWp( managedPath, `echo 'FLEET_E2E_JSON:' . wp_json_encode( get_post( ${ id } )->post_content );` ) ).toBe( '<p>Another author saved this.</p>' );
	} finally { removePost( id ); }
} );

test( 'two unsaved site windows have independent close guards', async ( { page } ) => {
	const site = target();
	await contentWindow( page, site );
	await page.locator( 'os-tabpanel[for="content"]' ).getByRole( 'button', { name: 'New post', exact: true } ).click();
	await page.locator( '.fleet-native-editor' ).getByLabel( 'Title', { exact: true } ).fill( 'First unsaved window' );
	const firstId = await page.locator( '.os-window' ).getAttribute( 'id' );
	const sibling = fixture.sites.find( ( item ) => item.id !== site.id );
	await page.evaluate( ( id ) => window.wp.os.openNewWindow( 'fleet-site', { params: { site_id: id } } ), sibling.id );
	await page.waitForFunction( ( id ) => window.wp.os.windowManager.getAll().some( ( win ) => win.config?.params?.site_id === id && win.element.querySelector( '.fleet-native-site-header' ) ), sibling.id );
	const secondId = await page.evaluate( ( id ) => window.wp.os.windowManager.getAll().find( ( win ) => win.config?.params?.site_id === id ).element.id, sibling.id );
	const second = page.locator( `#${ secondId }` );
	await second.locator( '.os-window__tab[data-panel="content"]' ).click();
	await second.getByRole( 'button', { name: 'New post', exact: true } ).click();
	await second.locator( '.fleet-native-editor' ).getByLabel( 'Title', { exact: true } ).fill( 'Second unsaved window' );
	await second.getByRole( 'button', { name: 'Close', exact: true } ).click();
	await page.getByRole( 'button', { name: 'Discard changes', exact: true } ).click();
	await expect( second ).toHaveCount( 0 );
	const first = page.locator( `#${ firstId }` );
	await expect( first.locator( '.fleet-native-editor' ).getByLabel( 'Title', { exact: true } ) ).toHaveValue( 'First unsaved window' );
	await first.getByRole( 'button', { name: 'Close', exact: true } ).click();
	await expect( page.getByRole( 'button', { name: 'Discard changes', exact: true } ) ).toBeVisible();
	await page.getByRole( 'button', { name: 'Cancel', exact: true } ).click();
	await expect( first ).toBeVisible();
} );
