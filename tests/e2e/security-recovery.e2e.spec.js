/** Real WordPress storage/permission tests; only disposable records are mutated. */
const { test, expect } = require( '@playwright/test' );
const { loadWordPressFixture, runSiteWp } = require( './wordpress-fixture' );
const fixture = loadWordPressFixture();
const hub = process.env.FLEET_E2E_HUB_PATH;
const site = fixture.sites[ 0 ];
test.beforeAll( () => { if ( process.env.FLEET_E2E_WRITES !== '1' ) { throw new Error( 'Disposable write authorization required.' ); } } );

test( 'recovery isolates users, connections and salts; orders checkpoints and retires saved editors', () => {
	const result = runSiteWp( hub, `
wp_set_current_user( ${ fixture.userId } );
$site = OpenStation_Fleet_Repository::get( ${ fixture.userId }, '${ site.id }', array( 'OpenStation_Fleet', 'normalize_site_record' ) );
$u = wp_insert_user( array( 'user_login' => 'fleet_recovery_' . wp_generate_password( 8, false ), 'user_pass' => wp_generate_password( 32 ), 'role' => 'administrator' ) );
if ( is_wp_error( $u ) ) { throw new Exception( 'Fixture creation failed.' ); }
try {
 wp_set_current_user( $u );
 $v = array( 'enabled' => true, 'request_id' => wp_generate_uuid4(), 'content_type' => 'posts', 'content_id' => 0, 'title' => 'Private source', 'content' => 'secret checkpoint source', 'status' => 'draft', 'sequence' => 2 );
 $saved = OpenStation_Fleet_Recovery::save( $site, $v );
 if ( is_wp_error( $saved ) ) { throw new Exception( 'Checkpoint failed.' ); }
 $v['sequence'] = 1; $v['content'] = 'stale source'; OpenStation_Fleet_Recovery::save( $site, $v );
 $ordered = OpenStation_Fleet_Recovery::read( $site, $saved['key'] )['editor']['content'] === 'secret checkpoint source';
 $changed = $site; $changed['connection_generation'] = wp_generate_uuid4();
 $generation = is_wp_error( OpenStation_Fleet_Recovery::read( $changed, $saved['key'] ) );
 $changed = $site; $changed['site_url'] .= '/different';
 $origin = is_wp_error( OpenStation_Fleet_Recovery::read( $changed, $saved['key'] ) );
 wp_set_current_user( ${ fixture.userId } ); $user = is_wp_error( OpenStation_Fleet_Recovery::read( $site, $saved['key'] ) ); wp_set_current_user( $u );
 $salt = static function( $value ) { return 'different-disposable-test-salt'; }; add_filter( 'salt', $salt );
 $rotated = is_wp_error( OpenStation_Fleet_Recovery::read( $site, $saved['key'] ) ); remove_filter( 'salt', $salt );
 $restored = ! is_wp_error( OpenStation_Fleet_Recovery::read( $site, $saved['key'] ) );
 $encrypted = false === strpos( get_transient( $saved['key'] ), 'secret checkpoint source' );
 $completed = OpenStation_Fleet_Recovery::complete( $site, $v ); $v['sequence'] = 3;
 $late = OpenStation_Fleet_Recovery::save( $site, $v );
 $retired = is_wp_error( $late ) && 'fleet_recovery_deleted' === $late->get_error_code() && array() === OpenStation_Fleet_Recovery::listing( $site );
 delete_transient( $saved['key'] . '_deleted' );
 for ( $i = 0; $i < 12; ++$i ) { $v['request_id'] = wp_generate_uuid4(); OpenStation_Fleet_Recovery::save( $site, $v ); }
 $bounded = count( OpenStation_Fleet_Recovery::listing( $site ) ) === 10;
 echo 'FLEET_E2E_JSON:' . wp_json_encode( compact( 'ordered', 'generation', 'origin', 'user', 'rotated', 'restored', 'encrypted', 'completed', 'retired', 'bounded' ) );
} finally { OpenStation_Fleet_Recovery::erase( $u ); wp_set_current_user( ${ fixture.userId } ); require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user( $u ); }
` );
	expect( Object.values( result ) ).toEqual( Array( 10 ).fill( true ) );
} );

test( 'malformed and oversized uploads are rejected before any remote request', () => {
	const result = runSiteWp( hub, `wp_set_current_user( ${ fixture.userId } ); $requests = 0; add_filter( 'pre_http_request', static function( $pre ) use ( &$requests ) { ++$requests; return new WP_Error( 'unexpected_request', 'No request expected.' ); } ); $cases = array( array( 'filename' => 'payload.php', 'bytes' => base64_encode( '<?php echo 1;' ) ), array( 'filename' => 'wrong.png', 'bytes' => base64_encode( 'not an image' ) ), array( 'filename' => 'too-large.png', 'bytes' => str_repeat( 'A', 2800000 ) ), array( 'filename' => 'invalid.png', 'bytes' => '!!not-base64!!' ) ); $codes = array(); foreach( $cases as $v ) { $v['request_id'] = wp_generate_uuid4(); $r = OpenStation_Fleet::app_action( '${ site.id }', 'upload-media', $v ); $codes[] = is_wp_error( $r ) ? $r->get_error_code() : ''; } echo 'FLEET_E2E_JSON:' . wp_json_encode( array( 'codes' => $codes, 'requests' => $requests ) );` );
	expect( result ).toEqual( { codes: [ 'fleet_upload_type', 'fleet_upload_image', 'fleet_upload_size', 'fleet_upload_type' ], requests: 0 } );
} );

test( 'a busy content item refuses a second writer before fetching or changing WordPress', () => {
	const result = runSiteWp( hub, `wp_set_current_user( ${ fixture.userId } ); $site = OpenStation_Fleet_Repository::get( ${ fixture.userId }, '${ site.id }', array( 'OpenStation_Fleet', 'normalize_site_record' ) ); $key = hash( 'sha256', $site['site_url'] . ':posts:123' ); $lock = OpenStation_Fleet_Repository::acquire_lock( 'content_write', 0, $key ); $requests = 0; add_filter( 'pre_http_request', static function( $pre ) use ( &$requests ) { ++$requests; return $pre; } ); try { $r = OpenStation_Fleet::app_action( '${ site.id }', 'content', array( 'content_type' => 'posts', 'content_id' => 123 ) ); echo 'FLEET_E2E_JSON:' . wp_json_encode( array( 'code' => is_wp_error( $r ) ? $r->get_error_code() : '', 'requests' => $requests ) ); } finally { OpenStation_Fleet_Repository::release_lock( $lock ); }` );
	expect( result ).toEqual( { code: 'fleet_content_busy', requests: 0 } );
} );

test( 'sharing capacity cannot create a hidden grant and restricted accounts can be revoked', () => {
	const result = runSiteWp( hub, `wp_set_current_user( ${ fixture.userId } ); $u = wp_insert_user( array( 'user_login' => 'fleet_capacity_' . wp_generate_password( 8, false ), 'user_pass' => wp_generate_password( 32 ), 'role' => 'subscriber' ) ); if ( is_wp_error( $u ) ) { throw new Exception( 'Fixture failed.' ); } try { $key = OpenStation_Fleet_Access::INDEX . get_current_blog_id(); update_user_meta( $u, $key, array_fill_keys( range( 1, 200 ), true ) ); $r = OpenStation_Fleet_Access::update( '${ site.id }', $u, 'editor' ); $site = OpenStation_Fleet_Repository::get( ${ fixture.userId }, '${ site.id }', array( 'OpenStation_Fleet', 'normalize_site_record' ) ); $limit = is_wp_error( $r ) && ! isset( $site['sharing'][ $u ] ); delete_user_meta( $u, $key ); OpenStation_Fleet_Access::update( '${ site.id }', $u, 'editor' ); $account = get_userdata( $u ); $account->set_role( '' ); $revoke = ! is_wp_error( OpenStation_Fleet_Access::update( '${ site.id }', $u, 'revoke' ) ); echo 'FLEET_E2E_JSON:' . wp_json_encode( compact( 'limit', 'revoke' ) ); } finally { OpenStation_Fleet_Access::update( '${ site.id }', $u, 'revoke' ); require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user( $u ); }` );
	expect( result ).toEqual( { limit: true, revoke: true } );
} );
