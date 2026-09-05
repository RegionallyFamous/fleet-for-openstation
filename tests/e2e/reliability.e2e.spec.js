const { test, expect } = require( '@playwright/test' );
const { loadWordPressFixture, runSiteWp } = require( './wordpress-fixture' );
const fixture = loadWordPressFixture();
const hub = process.env.FLEET_E2E_HUB_PATH;
const managed = process.env.FLEET_E2E_MANAGED_PATH;
const php = ( value ) => `json_decode( base64_decode( '${ Buffer.from( JSON.stringify( value ) ).toString( 'base64' ) }' ), true )`;
const tinyPng = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=';
let site;
test.beforeAll( () => {
	if ( process.env.FLEET_E2E_WRITES !== '1' || ! managed ) { throw new Error( 'Reliability tests require explicit disposable-fixture write authorization.' ); }
	const url = runSiteWp( managed, "echo 'FLEET_E2E_JSON:' . wp_json_encode( home_url() );" );
	site = fixture.sites.find( ( item ) => item.site_url.replace( /\/$/, '' ) === url.replace( /\/$/, '' ) );
	if ( ! site ) { throw new Error( 'The managed fixture is not connected to this hub.' ); }
} );

async function windowTab( page, tab = 'content' ) {
	await page.context().addCookies( fixture.cookies );
	await page.goto( `${ fixture.hubUrl }/wp-admin/admin.php?page=openstation` );
	await page.waitForFunction( () => window.wp?.os?.windowManager );
	await page.evaluate( () => window.wp.os.windowManager.getAll().forEach( ( win ) => win.close() ) );
	await page.waitForFunction( () => ! document.querySelector( '.os-window' ) );
	await page.evaluate( ( id ) => window.wp.os.openNewWindow( 'fleet-site', { params: { site_id: id } } ), site.id );
	await page.locator( '.fleet-native-site-header:visible' ).first().waitFor();
	await page.evaluate( async () => { await window.wp.os.windowManager.getAll()[ 0 ].whenContentReady(); await window.wp.os.loadComponents( [ 'os-form', 'os-table' ] ); } );
	await page.locator( `.os-window__tab[data-panel="${ tab }"]` ).click();
	const panel = page.locator( `os-tabpanel[for="${ tab }"]` );
	await expect( panel.locator( '[data-os-app]' ) ).not.toHaveAttribute( 'aria-busy', 'true' );
	return panel;
}

test( 'publishing fields preserve block source and detect metadata-only conflicts', () => {
	const ids = runSiteWp( managed, `$id = wp_insert_post( array( 'post_title' => 'Fleet publishing matrix', 'post_status' => 'draft', 'post_author' => 1 ) ); $cat = wp_insert_term( 'Fleet category ' . wp_generate_uuid4(), 'category' ); $tag = wp_insert_term( 'Fleet tag ' . wp_generate_uuid4(), 'post_tag' ); echo 'FLEET_E2E_JSON:' . wp_json_encode( array( 'post' => $id, 'category' => $cat['term_id'], 'tag' => $tag['term_id'] ) );` );
	try {
		const result = runSiteWp( hub, `wp_set_current_user( ${ fixture.userId } ); $item = OpenStation_Fleet::app_site_data( '${ site.id }', 'content', array( 'type' => 'posts', 'selected' => ${ ids.post } ) )['item']; $v = array_merge( OpenStation_Fleet_Content::editable( $item ), array( 'content_type' => 'posts', 'content_id' => ${ ids.post }, 'fingerprint' => OpenStation_Fleet_Content::fingerprint( $item ), 'request_id' => wp_generate_uuid4(), 'author' => '1', 'featured_media' => '0', 'categories' => '${ ids.category }', 'tags' => '${ ids.tag }', 'comment_status' => 'closed', 'ping_status' => 'closed', 'content' => '<!-- wp:paragraph --><p>Source preserved.</p><!-- /wp:paragraph -->' ) ); $r = OpenStation_Fleet::app_action( '${ site.id }', 'content', $v ); echo 'FLEET_E2E_JSON:' . wp_json_encode( array( 'ok' => ! is_wp_error( $r ), 'error' => is_wp_error( $r ) ? $r->get_error_code() : '' ) );` );
		expect( result ).toEqual( { ok: true, error: '' } );
		const stored = runSiteWp( managed, `$p = get_post( ${ ids.post } ); echo 'FLEET_E2E_JSON:' . wp_json_encode( array( 'content' => $p->post_content, 'comments' => $p->comment_status, 'ping' => $p->ping_status, 'categories' => wp_get_post_categories( $p->ID ), 'tags' => wp_get_post_tags( $p->ID, array( 'fields' => 'ids' ) ) ) );` );
		expect( stored ).toEqual( { content: '<!-- wp:paragraph --><p>Source preserved.</p><!-- /wp:paragraph -->', comments: 'closed', ping: 'closed', categories: [ ids.category ], tags: [ ids.tag ] } );
		const fingerprint = runSiteWp( hub, `wp_set_current_user( ${ fixture.userId } ); $p = OpenStation_Fleet::app_site_data( '${ site.id }', 'content', array( 'type' => 'posts', 'selected' => ${ ids.post } ) )['item']; echo 'FLEET_E2E_JSON:' . wp_json_encode( OpenStation_Fleet_Content::fingerprint( $p ) );` );
		runSiteWp( managed, `wp_update_post( array( 'ID' => ${ ids.post }, 'comment_status' => 'open' ) ); echo 'FLEET_E2E_JSON:true';` );
		const changed = runSiteWp( hub, `wp_set_current_user( ${ fixture.userId } ); $p = OpenStation_Fleet::app_site_data( '${ site.id }', 'content', array( 'type' => 'posts', 'selected' => ${ ids.post } ) )['item']; echo 'FLEET_E2E_JSON:' . wp_json_encode( OpenStation_Fleet_Content::fingerprint( $p ) );` );
		expect( changed ).not.toBe( fingerprint );
	} finally { runSiteWp( managed, `wp_delete_post( ${ ids.post }, true ); wp_delete_term( ${ ids.category }, 'category' ); wp_delete_term( ${ ids.tag }, 'post_tag' ); echo 'FLEET_E2E_JSON:true';` ); }
} );

test( 'name-based publishing picker keeps unsaved text while searching', async ( { page } ) => {
	const panel = await windowTab( page );
	await panel.getByRole( 'button', { name: 'New post', exact: true } ).click();
	const editor = panel.locator( '.fleet-native-editor' );
	await editor.getByLabel( 'Title', { exact: true } ).fill( 'Picker unsaved source' );
	await editor.getByLabel( 'Content source', { exact: true } ).fill( '<p>Do not discard.</p>' );
	await editor.getByText( 'Publishing options', { exact: true } ).click();
	await editor.locator( '.fleet-native-picker-open[data-kind="author"]' ).click();
	const picker = panel.locator( '.fleet-native-publishing-picker' );
	await expect( picker ).toBeVisible();
	await expect( editor.getByLabel( 'Title', { exact: true } ) ).toHaveValue( 'Picker unsaved source' );
	await picker.locator( '.fleet-native-pick-item' ).first().click();
	await expect( editor.locator( 'input[name="author"]' ) ).not.toHaveValue( '' );
	await expect( editor.getByLabel( 'Content source', { exact: true } ) ).toHaveValue( '<p>Do not discard.</p>' );
} );

test( 'Core Heartbeat checkpoints are opt-in, encrypted and recover after a reload', async ( { page } ) => {
	let key = '';
	const title = `Recovery test ${ Date.now() }`;
	try {
		const panel = await windowTab( page );
		await panel.getByRole( 'button', { name: 'New post', exact: true } ).click();
		const editor = panel.locator( '.fleet-native-editor' );
		await editor.getByLabel( 'Title', { exact: true } ).fill( title );
		await editor.getByLabel( 'Content source', { exact: true } ).fill( '<p>Encrypted crash recovery source.</p>' );
		await editor.locator( '.fleet-native-recovery-enable' ).check();
		await expect( editor.locator( '.fleet-native-recovery-status' ) ).toContainText( 'Recovery copy saved', { timeout: 35000 } );
		const checkpoint = runSiteWp( hub, `wp_set_current_user( ${ fixture.userId } ); $all = OpenStation_Fleet::recovery( '${ site.id }' ); $match = array(); foreach ( $all as $key => $item ) { if ( $item['title'] === ${ php( title ) } ) { $match = array( 'key' => $key, 'encrypted' => strpos( get_transient( $key ), 'Encrypted crash recovery source' ) === false, 'count' => count( $all ) ); } } echo 'FLEET_E2E_JSON:' . wp_json_encode( $match );` );
		key = checkpoint.key;
		expect( checkpoint.encrypted ).toBe( true );
		const recovered = await windowTab( page );
		await recovered.getByText( 'Recover an unsaved draft', { exact: true } ).click();
		await recovered.getByRole( 'button', { name: 'Check for recoverable drafts', exact: true } ).click();
		await recovered.locator( `.fleet-native-recovery-row:has(os-button[os-arg-key="${ key }"])` ).getByRole( 'button', { name: 'Recover', exact: true } ).click();
		await expect( recovered.locator( '.fleet-native-editor' ).getByLabel( 'Content source', { exact: true } ) ).toHaveValue( '<p>Encrypted crash recovery source.</p>' );
		expect( runSiteWp( managed, `echo 'FLEET_E2E_JSON:' . wp_json_encode( count( get_posts( array( 'title' => ${ php( title ) }, 'post_status' => 'any' ) ) ) );` ) ).toBe( 0 );
	} finally { if ( key ) { runSiteWp( hub, `wp_set_current_user( ${ fixture.userId } ); OpenStation_Fleet::recovery( '${ site.id }', 'delete', '${ key }' ); echo 'FLEET_E2E_JSON:true';` ); } }
} );

test( 'uploads a real image to the selected site, not the hub', async ( { page } ) => {
	const name = `fleet-upload-${ Date.now() }.png`;
	let attachment = 0;
	try {
		const panel = await windowTab( page, 'media' );
		await panel.locator( 'input[type="file"]' ).setInputFiles( { name, mimeType: 'image/png', buffer: Buffer.from( tinyPng, 'base64' ) } );
		await panel.getByRole( 'button', { name: 'Upload file', exact: true } ).click();
		await page.getByRole( 'dialog' ).getByRole( 'button', { name: 'Upload', exact: true } ).click();
		await expect( panel ).toContainText( 'File uploaded to this site’s media library.', { timeout: 25000 } );
		attachment = runSiteWp( managed, `$p = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'name' => '${ name.slice( 0, -4 ) }' ) ); echo 'FLEET_E2E_JSON:' . wp_json_encode( $p ? $p[0]->ID : 0 );` );
		expect( attachment ).toBeGreaterThan( 0 );
		expect( runSiteWp( hub, `echo 'FLEET_E2E_JSON:' . wp_json_encode( count( get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'name' => '${ name.slice( 0, -4 ) }' ) ) ) );` ) ).toBe( 0 );
	} finally {
		runSiteWp( managed, `$items = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'name' => '${ name.slice( 0, -4 ) }' ) ); foreach ( $items as $p ) { wp_delete_attachment( $p->ID, true ); } echo 'FLEET_E2E_JSON:true';` );
	}
} );

test( 'reply create is bounded and refuses an uncertain retry', () => {
	const result = runSiteWp( hub, `wp_set_current_user( ${ fixture.userId } ); $count = 0; add_filter( 'pre_http_request', static function ( $pre, $args, $url ) use ( &$count ) { if ( 'POST' === $args['method'] ) { ++$count; return new WP_Error( 'timeout', 'Simulated uncertain write.' ); } if ( false !== strpos( rawurldecode( $url ), 'comments/123' ) ) { return array( 'response' => array( 'code' => 200 ), 'headers' => array(), 'body' => wp_json_encode( array( 'id' => 123, 'post' => 1, 'status' => 'approved' ) ) ); } return $pre; }, 10, 3 ); $v = array( 'comment_id' => 123, 'content' => 'Do not send twice', 'request_id' => wp_generate_uuid4() ); $first = OpenStation_Fleet::app_action( '${ site.id }', 'reply-comment', $v ); $second = OpenStation_Fleet::app_action( '${ site.id }', 'reply-comment', $v ); echo 'FLEET_E2E_JSON:' . wp_json_encode( array( 'writes' => $count, 'first' => $first->get_error_code(), 'second' => $second->get_error_code() ) );` );
		expect( result ).toEqual( { writes: 1, first: 'fleet_create_uncertain', second: 'fleet_create_uncertain' } );
} );

test( 'reviewed comment batch reports conflicts and never repeats completed writes', () => {
	const ids = runSiteWp( managed, `$p = wp_insert_post( array( 'post_title' => 'Fleet batch fixture', 'post_status' => 'publish' ) ); $a = wp_insert_comment( array( 'comment_post_ID' => $p, 'comment_content' => 'One', 'comment_approved' => 0 ) ); $b = wp_insert_comment( array( 'comment_post_ID' => $p, 'comment_content' => 'Two', 'comment_approved' => 0 ) ); echo 'FLEET_E2E_JSON:' . wp_json_encode( array( 'post' => $p, 'a' => $a, 'b' => $b ) );` );
	try {
		const review = runSiteWp( hub, `wp_set_current_user( ${ fixture.userId } ); $r = OpenStation_Fleet::comment_batch( '${ site.id }', array( 'status' => 'trash', 'ids' => array( ${ ids.a }, ${ ids.b } ) ) ); if ( is_wp_error( $r ) ) { throw new Exception( $r->get_error_message() ); } echo 'FLEET_E2E_JSON:' . wp_json_encode( $r );` );
		runSiteWp( managed, `wp_set_comment_status( ${ ids.b }, 'approve' ); echo 'FLEET_E2E_JSON:true';` );
		const result = runSiteWp( hub, `wp_set_current_user( ${ fixture.userId } ); $r = OpenStation_Fleet::comment_batch( '${ site.id }', ${ php( review ) }, true ); $again = OpenStation_Fleet::comment_batch( '${ site.id }', ${ php( review ) }, true ); echo 'FLEET_E2E_JSON:' . wp_json_encode( array( 'result' => $r, 'again' => $again ) );` );
		expect( result.result.results[ ids.a ] ).toBe( 'Updated.' );
		expect( result.result.results[ ids.b ] ).toContain( 'changed since review' );
		expect( result.again ).toEqual( result.result );
	} finally { runSiteWp( managed, `wp_delete_comment( ${ ids.a }, true ); wp_delete_comment( ${ ids.b }, true ); wp_delete_post( ${ ids.post }, true ); echo 'FLEET_E2E_JSON:true';` ); }
} );

test( 'shared roles cannot bypass native action checks or survive revocation', () => {
	const result = runSiteWp( hub, `
wp_set_current_user( ${ fixture.userId } );
$u = wp_insert_user( array( 'user_login' => 'fleet_team_' . wp_generate_password( 8, false ), 'user_pass' => wp_generate_password( 32 ), 'role' => 'subscriber' ) );
if ( is_wp_error( $u ) ) { throw new Exception( 'Fixture user failed.' ); }
$alias = 'shared_${ fixture.userId }_${ site.id }';
try {
 $grant = OpenStation_Fleet_Access::update( '${ site.id }', $u, 'reader' );
 wp_set_current_user( $u ); $visible = OpenStation_Fleet::app_site( $alias );
 $denied = OpenStation_Fleet::app_action( $alias, 'content', array() );
 $api = OpenStation_Fleet::app_site_data( $alias, 'api' );
 $notes = OpenStation_Fleet::app_site_data( $alias, 'agency' );
 $own = OpenStation_Fleet::app_site( '${ site.id }' );
 wp_set_current_user( ${ fixture.userId } ); OpenStation_Fleet_Access::update( '${ site.id }', $u, 'editor' );
 wp_set_current_user( $u ); $record = OpenStation_Fleet_Access::resolve( $alias );
 $editor = OpenStation_Fleet_Access::allowed( $record, 'content', true );
 $settings = OpenStation_Fleet_Access::allowed( $record, 'settings', true );
 wp_set_current_user( ${ fixture.userId } ); OpenStation_Fleet_Access::update( '${ site.id }', $u, 'revoke' );
 wp_set_current_user( $u ); $revoked = OpenStation_Fleet::app_site( $alias );
 $stale = OpenStation_Fleet_Access::allowed( $record, 'content', true );
 echo 'FLEET_E2E_JSON:' . wp_json_encode( array( 'grant' => ! is_wp_error( $grant ), 'role' => $visible['access_role'] ?? '', 'write_denied' => is_wp_error( $denied ), 'api_denied' => is_wp_error( $api ), 'notes_denied' => is_wp_error( $notes ), 'owner_id_denied' => is_wp_error( $own ), 'editor' => $editor, 'settings_denied' => ! $settings, 'revoked' => is_wp_error( $revoked ), 'stale_denied' => ! $stale ) );
} finally { wp_set_current_user( ${ fixture.userId } ); OpenStation_Fleet_Access::update( '${ site.id }', $u, 'revoke' ); require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user( $u ); }
` );
	expect( result ).toEqual( { grant: true, role: 'reader', write_denied: true, api_denied: true, notes_denied: true, owner_id_denied: true, editor: true, settings_denied: true, revoked: true, stale_denied: true } );
} );

test( 'reply and reviewed bulk moderation work through the actual native controls', async ( { page } ) => {
	const ids = runSiteWp( managed, `$p = wp_insert_post( array( 'post_title' => 'Fleet native moderation fixture', 'post_status' => 'publish', 'comment_status' => 'open' ) ); $c = wp_insert_comment( array( 'comment_post_ID' => $p, 'comment_author' => 'Fleet workflow fixture', 'comment_content' => 'A real UI reply target.', 'comment_approved' => 1 ) ); echo 'FLEET_E2E_JSON:' . wp_json_encode( array( 'post' => $p, 'comment' => $c ) );` );
	try {
		const panel = await windowTab( page, 'comments' );
		const reply = panel.locator( `.fleet-native-reply:has(input[name="comment_id"][value="${ ids.comment }"])` );
		await reply.locator( '..' ).locator( 'summary' ).click();
		await reply.getByLabel( 'Reply', { exact: true } ).fill( 'Thanks for visiting our studio.' );
		await reply.getByRole( 'button', { name: 'Send reply', exact: true } ).click();
		await page.getByRole( 'dialog' ).getByRole( 'button', { name: 'Confirm', exact: true } ).click();
		await expect( panel ).toContainText( 'Reply saved on WordPress.' );
		expect( runSiteWp( managed, `echo 'FLEET_E2E_JSON:' . wp_json_encode( count( get_comments( array( 'parent' => ${ ids.comment }, 'status' => 'all' ) ) ) );` ) ).toBe( 1 );
		await panel.getByText( 'Moderate several comments', { exact: true } ).click();
		const bulk = panel.locator( '.fleet-native-bulk-comments' );
		await bulk.locator( `input[type="checkbox"][value="${ ids.comment }"]` ).check();
		await bulk.locator( 'os-select[name="status"]' ).evaluate( ( element ) => { element.value = 'hold'; } );
		await bulk.getByRole( 'button', { name: 'Review selected comments', exact: true } ).click();
		await expect( panel.locator( '.fleet-native-comment-batch' ) ).toContainText( 'Fleet workflow fixture' );
		await panel.getByRole( 'button', { name: 'Confirm moderation', exact: true } ).click();
		await expect( panel.locator( '.fleet-native-comment-batch' ) ).toContainText( 'Updated.' );
		expect( runSiteWp( managed, `echo 'FLEET_E2E_JSON:' . wp_json_encode( wp_get_comment_status( ${ ids.comment } ) );` ) ).toBe( 'unapproved' );
	} finally { runSiteWp( managed, `foreach ( get_comments( array( 'post_id' => ${ ids.post }, 'status' => 'all' ) ) as $c ) { wp_delete_comment( $c->comment_ID, true ); } wp_delete_post( ${ ids.post }, true ); echo 'FLEET_E2E_JSON:true';` ); }
} );

test( 'a delegated reader gets a real read-only window and an open window rejects revocation', async ( { page } ) => {
	const user = runSiteWp( hub, `wp_set_current_user( ${ fixture.userId } ); $u = wp_insert_user( array( 'user_login' => 'fleet_reader_' . wp_generate_password( 8, false ), 'user_pass' => wp_generate_password( 32 ), 'role' => 'subscriber' ) ); if ( is_wp_error( $u ) ) { throw new Exception( 'Fixture failed.' ); } update_user_meta( $u, 'desktop_mode_mode', '1' ); OpenStation_Fleet_Access::update( '${ site.id }', $u, 'reader' ); echo 'FLEET_E2E_JSON:' . wp_json_encode( $u );` );
	try {
		const cookies = runSiteWp( hub, `$expires = time() + 600; echo 'FLEET_E2E_JSON:' . wp_json_encode( array( array( 'name' => SECURE_AUTH_COOKIE, 'value' => wp_generate_auth_cookie( ${ user }, $expires, 'secure_auth' ) ), array( 'name' => LOGGED_IN_COOKIE, 'value' => wp_generate_auth_cookie( ${ user }, $expires, 'logged_in' ) ) ) );` );
		await page.context().addCookies( cookies.map( ( cookie ) => ( { ...cookie, url: fixture.hubUrl, secure: true, httpOnly: true, sameSite: 'Lax' } ) ) );
		await page.goto( `${ fixture.hubUrl }/wp-admin/admin.php?page=openstation` );
		await page.waitForFunction( () => window.wp?.os?.windowManager );
		await page.evaluate( ( id ) => window.wp.os.openNewWindow( 'fleet-site', { params: { site_id: id } } ), `shared_${ fixture.userId }_${ site.id }` );
		await page.locator( '.fleet-native-site-header:visible' ).first().waitFor();
		await page.locator( '.os-window__tab[data-panel="content"]' ).click();
		const panel = page.locator( 'os-tabpanel[for="content"]' );
		await panel.locator( '.fleet-native-content-list' ).waitFor();
		await expect( panel.getByRole( 'button', { name: 'New post', exact: true } ) ).toHaveCount( 0 );
		await panel.locator( '.fleet-native-content-list' ).getByRole( 'row' ).nth( 1 ).click();
		await expect( panel ).toContainText( 'Read-only access' );
		await expect( panel.getByRole( 'button', { name: 'Save to WordPress', exact: true } ) ).toHaveCount( 0 );
		runSiteWp( hub, `wp_set_current_user( ${ fixture.userId } ); OpenStation_Fleet_Access::update( '${ site.id }', ${ user }, 'revoke' ); echo 'FLEET_E2E_JSON:true';` );
		await panel.getByRole( 'button', { name: 'Back to list', exact: true } ).click();
		await expect( page.getByText( 'The window could not update: You are not allowed to use this window.', { exact: true } ) ).toBeVisible();
	} finally { runSiteWp( hub, `wp_set_current_user( ${ fixture.userId } ); OpenStation_Fleet_Access::update( '${ site.id }', ${ user }, 'revoke' ); require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user( ${ user } ); echo 'FLEET_E2E_JSON:true';` ); }
} );
