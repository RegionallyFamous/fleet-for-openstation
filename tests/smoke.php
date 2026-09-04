<?php
/**
 * Tiny framework-free checks for Fleet's pure URL and plugin-status logic.
 *
 * @package FleetForOpenStation
 */

class WP_Error {
	public $code;
	public $message;
	public $data;

	public function __construct( $code = '', $message = '', $data = null ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}

	public function get_error_code() {
		return $this->code;
	}

	public function get_error_message() {
		return $this->message;
	}

	public function get_error_data() {
		return $this->data;
	}
}

function is_wp_error( $value ) {
	return $value instanceof WP_Error;
}

function __( $text ) {
	return $text;
}

function sanitize_text_field( $text ) {
	return trim( strip_tags( (string) $text ) );
}

function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $key ) );
}

function sanitize_user( $user ) {
	return preg_replace( '/[^a-z0-9_.@-]/i', '', (string) $user );
}

function wp_strip_all_tags( $text ) {
	return strip_tags( (string) $text );
}

function wp_json_encode( $value ) {
	return json_encode( $value );
}

function wp_parse_args( $args, $defaults = array() ) {
	return array_merge( $defaults, is_array( $args ) ? $args : array() );
}

function get_bloginfo( $show ) {
	return 'version' === $show ? '7.1' : '';
}

function untrailingslashit( $value ) {
	return rtrim( $value, '/\\' );
}

function trailingslashit( $value ) {
	return untrailingslashit( $value ) . '/';
}

function add_query_arg( $key, $value = null, $url = null ) {
	if ( is_array( $key ) ) {
		$url   = $value;
		$value = null;
	}
	$parts = parse_url( $url );
	$query = array();
	if ( ! empty( $parts['query'] ) ) {
		parse_str( $parts['query'], $query );
	}
	if ( is_array( $key ) ) {
		$query = array_merge( $query, $key );
	} else {
		$query[ $key ] = $value;
	}
	return $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['path'] ) ? $parts['path'] : '/' ) . '?' . http_build_query( $query );
}

function wp_salt() {
	return 'fleet-smoke-test-salt';
}

function wp_rand( $min, $max ) {
	unset( $max );
	return $min;
}

$fleet_test_user_meta    = array();
$fleet_test_meta_reads   = array();
$fleet_test_option_reads = array();
$fleet_test_options      = array();
$fleet_test_uuid         = 0;
$fleet_test_blog_id      = 1;
$fleet_test_blog_stack   = array();
$fleet_test_is_multisite = false;
$fleet_test_owner_race   = 0;
function get_current_user_id() {
	return 1;
}

function get_user_meta( $user_id, $key, $single = false ) {
	global $fleet_test_meta_reads, $fleet_test_user_meta;
	$fleet_test_meta_reads[] = array( (int) $user_id, (string) $key );
	$value = isset( $fleet_test_user_meta[ $user_id ][ $key ] ) ? $fleet_test_user_meta[ $user_id ][ $key ] : null;
	return $single ? ( null === $value ? '' : $value ) : ( null === $value ? array() : array( $value ) );
}

function get_current_blog_id() {
	global $fleet_test_blog_id;
	return $fleet_test_blog_id;
}

function is_multisite() {
	global $fleet_test_is_multisite;
	return $fleet_test_is_multisite;
}

function is_main_site( $site_id = null ) {
	global $fleet_test_blog_id;
	return null === $site_id ? 1 === $fleet_test_blog_id : 1 === (int) $site_id;
}

function get_main_site_id() {
	return 1;
}

function switch_to_blog( $blog_id ) {
	global $fleet_test_blog_id, $fleet_test_blog_stack;
	$fleet_test_blog_stack[] = $fleet_test_blog_id;
	$fleet_test_blog_id      = (int) $blog_id;
	return true;
}

function restore_current_blog() {
	global $fleet_test_blog_id, $fleet_test_blog_stack;
	if ( empty( $fleet_test_blog_stack ) ) {
		return false;
	}
	$fleet_test_blog_id = (int) array_pop( $fleet_test_blog_stack );
	return true;
}

function update_user_meta( $user_id, $key, $value, $previous = null ) {
	global $fleet_test_user_meta;
	$exists  = isset( $fleet_test_user_meta[ $user_id ][ $key ] );
	$current = $exists ? $fleet_test_user_meta[ $user_id ][ $key ] : '';
	if ( $exists && func_num_args() >= 4 && $current !== $previous ) {
		return false;
	}
	if ( $current === $value ) {
		return false;
	}
	$fleet_test_user_meta[ $user_id ][ $key ] = $value;
	return 1;
}

function add_user_meta( $user_id, $key, $value, $unique = false ) {
	global $fleet_test_user_meta;
	if ( $unique && isset( $fleet_test_user_meta[ $user_id ][ $key ] ) ) {
		return false;
	}
	$fleet_test_user_meta[ $user_id ][ $key ] = $value;
	return 1;
}

function delete_user_meta( $user_id, $key, $value = '' ) {
	global $fleet_test_user_meta;
	if ( ! isset( $fleet_test_user_meta[ $user_id ][ $key ] ) ) {
		return false;
	}
	if ( '' !== $value && $fleet_test_user_meta[ $user_id ][ $key ] !== $value ) {
		return false;
	}
	unset( $fleet_test_user_meta[ $user_id ][ $key ] );
	return true;
}

function metadata_exists( $type, $user_id, $key ) {
	global $fleet_test_user_meta;
	unset( $type );
	return isset( $fleet_test_user_meta[ $user_id ][ $key ] );
}

function wp_cache_delete() {
	return true;
}

function wp_generate_uuid4() {
	global $fleet_test_uuid;
	++$fleet_test_uuid;
	return sprintf( '00000000-0000-4000-8000-%012d', $fleet_test_uuid );
}

function get_option( $key, $default = false ) {
	global $fleet_test_blog_id, $fleet_test_option_reads, $fleet_test_options;
	$fleet_test_option_reads[] = array( $fleet_test_blog_id, (string) $key );
	$storage_key = $fleet_test_blog_id . ':' . $key;
	return array_key_exists( $storage_key, $fleet_test_options ) ? $fleet_test_options[ $storage_key ] : $default;
}

function add_option( $key, $value, $deprecated = '', $autoload = true ) {
	global $fleet_test_blog_id, $fleet_test_options, $fleet_test_owner_race;
	unset( $deprecated, $autoload );
	$storage_key = $fleet_test_blog_id . ':' . $key;
	if ( 'openstation_fleet_legacy_owner' === $key && $fleet_test_owner_race > 0 && ! array_key_exists( $storage_key, $fleet_test_options ) ) {
		$fleet_test_options[ $storage_key ] = $fleet_test_owner_race;
		$fleet_test_owner_race              = 0;
		return false;
	}
	if ( array_key_exists( $storage_key, $fleet_test_options ) ) {
		return false;
	}
	$fleet_test_options[ $storage_key ] = $value;
	return true;
}

function update_option( $key, $value, $autoload = null ) {
	global $fleet_test_blog_id, $fleet_test_options;
	unset( $autoload );
	$storage_key = $fleet_test_blog_id . ':' . $key;
	if ( isset( $fleet_test_options[ $storage_key ] ) && $fleet_test_options[ $storage_key ] === $value ) {
		return false;
	}
	$fleet_test_options[ $storage_key ] = $value;
	return true;
}

function delete_option( $key ) {
	global $fleet_test_blog_id, $fleet_test_options;
	$storage_key = $fleet_test_blog_id . ':' . $key;
	if ( ! array_key_exists( $storage_key, $fleet_test_options ) ) {
		return false;
	}
	unset( $fleet_test_options[ $storage_key ] );
	return true;
}

function maybe_serialize( $value ) {
	return is_array( $value ) || is_object( $value ) ? serialize( $value ) : $value;
}

function fleet_test_search_storage_key( $blog_id, $user_id, $site_id ) {
	return (int) $blog_id . ':openstation_fleet_search_' . (int) $user_id . '_' . substr( hash( 'sha256', sanitize_key( $site_id ) ), 0, 40 );
}

class Fleet_Test_WPDB {
	public $options = 'wp_options';

	public function delete( $table, $where, $format = null ) {
		global $fleet_test_blog_id, $fleet_test_options;
		unset( $table, $format );
		$key = isset( $where['option_name'] ) ? $where['option_name'] : '';
		$storage_key = $fleet_test_blog_id . ':' . $key;
		if ( ! array_key_exists( $storage_key, $fleet_test_options ) || maybe_serialize( $fleet_test_options[ $storage_key ] ) !== $where['option_value'] ) {
			return 0;
		}
		unset( $fleet_test_options[ $storage_key ] );
		return 1;
	}
}

$wpdb = new Fleet_Test_WPDB();

function get_users( $args ) {
	global $fleet_test_user_meta;
	$ids = array();
	foreach ( $fleet_test_user_meta as $user_id => $meta ) {
		if ( empty( $args['meta_key'] ) || array_key_exists( $args['meta_key'], $meta ) ) {
			$ids[] = (int) $user_id;
		}
	}
	return $ids;
}

function home_url() {
	return 'https://hub.example';
}

function site_url() {
	return 'https://hub.example/wp';
}


function wp_parse_url( $url, $component = -1 ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- This standalone WordPress test double must call native PHP.
	return parse_url( $url, $component );
}

function wp_parse_str( $string, &$array ) {
	parse_str( $string, $array );
}

function wp_remote_retrieve_response_code( $response ) {
	return isset( $response['response']['code'] ) ? (int) $response['response']['code'] : 0;
}

function wp_remote_retrieve_body( $response ) {
	return isset( $response['body'] ) ? (string) $response['body'] : '';
}

function wp_remote_retrieve_header( $response, $name ) {
	return isset( $response['headers'][ $name ] ) ? $response['headers'][ $name ] : '';
}

$fleet_test_remote_responses = array();
$fleet_test_remote_requests  = array();
function wp_safe_remote_request( $url, $args ) {
	global $fleet_test_remote_responses, $fleet_test_remote_requests;
	$fleet_test_remote_requests[] = array(
		'url'  => $url,
		'args' => $args,
	);
	return array_shift( $fleet_test_remote_responses );
}

define( 'ABSPATH', __DIR__ . '/' );
define( 'WPINC', 'wp-includes' );
require dirname( __DIR__ ) . '/includes/class-openstation-fleet.php';

$valid = OpenStation_Fleet::normalize_site_url( 'https://Example.com/client/' );
assert( 'https://example.com/client' === $valid );
assert( OpenStation_Fleet::normalize_site_url( 'http://example.com' ) instanceof WP_Error );
assert( OpenStation_Fleet::normalize_site_url( 'https://user:pass@example.com' ) instanceof WP_Error );
assert( OpenStation_Fleet::normalize_site_url( 'https://example.com/?redirect=evil' ) instanceof WP_Error );

$missing = OpenStation_Fleet::inspect_plugins( array() );
assert( 'missing' === $missing['status'] );

$inactive = OpenStation_Fleet::inspect_plugins(
	array(
		array(
			'plugin'     => 'desktop-mode/desktop-mode',
			'status'     => 'inactive',
			'version'    => '1.1.0',
			'textdomain' => 'desktop-mode',
		),
	)
);
assert( 'inactive' === $inactive['status'] );

$active = OpenStation_Fleet::inspect_plugins(
	array(
		array(
			'plugin'     => 'desktop-mode/desktop-mode',
			'status'     => 'network-active',
			'version'    => '1.1.0',
			'textdomain' => 'desktop-mode',
		),
	)
);
assert( 'active' === $active['status'] );

$class = new ReflectionClass( 'OpenStation_Fleet' );
$core_source = file_get_contents( dirname( __DIR__ ) . '/includes/class-openstation-fleet.php' );
assert( false !== strpos( $core_source, "'wp-abilities/v1/abilities/core/get-environment-info/run'" ) );
assert( false === strpos( $core_source, "'wp-abilities/v1/core/get-environment-info/run'" ) );
assert( false === OpenStation_Fleet::has_app_framework() );
assert( false === $class->hasConstant( 'MENU_SLUG' ) );
assert( false === $class->hasConstant( 'UPLOAD_TIMEOUT' ) );
assert( false === $class->hasConstant( 'CRON_BATCH_SIZE' ) );
assert( 'openstation_fleet_five_minutes' === $class->getConstant( 'CRON_SCHEDULE' ) );
assert( 2097152 === OpenStation_Fleet_REST_Client::RESPONSE_LIMIT );
$sealed = OpenStation_Fleet_Crypto::seal( 'application-password' );
assert( is_string( $sealed ) && 0 === strpos( $sealed, 'v1:' ) );
assert( 'application-password' === OpenStation_Fleet_Crypto::open( $sealed ) );
assert( OpenStation_Fleet_Crypto::open( $sealed . 'tampered' ) instanceof WP_Error );

assert(
	'https://example.com/wp-json/wp/v2/plugins/desktop-mode/desktop-mode' === OpenStation_Fleet_REST_Client::api_url(
		array(
			'site_url' => 'https://example.com',
			'rest_url' => 'https://example.com/wp-json/',
		),
		'wp/v2/plugins/desktop-mode/desktop-mode'
	)
);
$plain_url = OpenStation_Fleet_REST_Client::api_url(
	array(
		'site_url' => 'https://example.com',
		'rest_url' => 'https://example.com/?rest_route=/',
	),
	'wp/v2/plugins?context=edit'
);
parse_str( wp_parse_url( $plain_url, PHP_URL_QUERY ), $plain_query );
assert( '/wp/v2/plugins' === $plain_query['rest_route'] );
assert( 'edit' === $plain_query['context'] );

$is_hub = $class->getMethod( 'is_hub_site' );
if ( PHP_VERSION_ID < 80100 ) {
	$is_hub->setAccessible( true );
}
assert( true === $is_hub->invoke( null, 'https://hub.example' ) );
assert( true === $is_hub->invoke( null, 'https://hub.example/wp' ) );
assert( false === $is_hub->invoke( null, 'https://hub.example/client' ) );

$normalize_record = $class->getMethod( 'normalize_site_record' );
$capabilities     = $class->getMethod( 'discover_capabilities' );
$route_catalog    = $class->getMethod( 'api_route_catalog' );
$attention        = $class->getMethod( 'attention_reasons' );
$normalize_inbox  = $class->getMethod( 'normalize_inbox_summary' );
$collection       = $class->getMethod( 'collection_summary' );
$safe_api_route   = $class->getMethod( 'is_safe_api_route' );
if ( PHP_VERSION_ID < 80100 ) {
	$normalize_record->setAccessible( true );
	$capabilities->setAccessible( true );
	$route_catalog->setAccessible( true );
	$attention->setAccessible( true );
	$normalize_inbox->setAccessible( true );
	$collection->setAccessible( true );
	$safe_api_route->setAccessible( true );
}
$record = $normalize_record->invoke(
	null,
	array(
		'name'        => 'Client site',
		'openstation' => array( 'status' => 'active' ),
	)
);
assert( 0 === $record['health_checked'] );
assert( false === $record['agency']['favorite'] );
assert( array() === $record['agency']['tags'] );
assert( 0 === $record['inbox']['pending_comments']['count'] );
assert( ! isset( $record['search_index'] ) );
assert( ! isset( $record['search_index_state'] ) );
assert( 0 === $record['status_checked'] );
assert( 0 === $record['metadata_checked'] );
assert( 0 === $record['sync_failures'] );
assert( 0 === $record['next_retry'] );
$canonical_record = $normalize_record->invoke(
	null,
	array(
		'site_url' => 'https://canonical.example/client',
		'rest_url' => 'https://old.example/client/wp-json/',
	)
);
assert( 'https://canonical.example/client/wp-json/' === $canonical_record['rest_url'] );

$discovered = $capabilities->invoke(
	null,
	array(
		'routes'     => array(
			'/batch/v1'                                   => array(),
			'/wp/v2/search'                               => array(),
			'/wp/v2/posts'                                => array(),
			'/wp/v2/media'                                => array(),
			'/wp/v2/templates'                            => array(),
			'/wp/v2/global-styles/(?P<id>[\\d]+)'         => array(),
			'/wp-site-health/v1/tests/background-updates' => array(),
		),
		'namespaces' => array( 'wp/v2', 'wp-site-health/v1', 'wp-abilities/v1' ),
	)
);
assert( true === $discovered['posts'] );
assert( true === $discovered['batch'] );
assert( true === $discovered['abilities'] );
assert( true === $discovered['search'] );
assert( false === $discovered['comments'] );
assert( true === $discovered['site_health'] );
assert( true === $discovered['templates'] );
assert( true === $discovered['styles'] );
assert( 7 === $discovered['route_count'] );

$catalog = $route_catalog->invoke(
	null,
	array(
		'routes' => array(
			'/wp/v2/posts'   => array(
				'namespace' => 'wp/v2',
				'methods'   => array( 'GET', 'POST' ),
				'endpoints' => array(
					array(
						'methods' => array( 'GET' ),
						'args'    => array(
							'page'   => array(),
							'search' => array(),
						),
					),
				),
			),
			'/plugin/v1/run' => array(
				'namespace' => 'plugin/v1',
				'methods'   => array( 'POST', 'OPTIONS' ),
				'endpoints' => array(),
			),
		),
	)
);
assert( 2 === count( $catalog ) );
assert( '/plugin/v1/run' === $catalog[0]['route'] );
assert( array( 'POST' ) === $catalog[0]['methods'] );
assert( 2 === $catalog[1]['arg_count'] );
assert( false === $class->hasMethod( 'render_page' ) );
assert( false === $class->hasMethod( 'handle_connect' ) );
assert( false === $class->hasMethod( 'handle_finish_setup' ) );

$record['health'] = array(
	'loopback-requests' => array(
		'label'  => 'Loopback request failed',
		'status' => 'recommended',
	),
);
$reasons          = $attention->invoke( null, $record );
assert( 1 === count( $reasons ) );
assert( 'loopback-requests' === $reasons[0][0] );

$inbox = $normalize_inbox->invoke(
	null,
	array(
		'checked'          => 123,
		'pending_comments' => array(
			'count' => 9,
			'items' => array_fill( 0, 8, array( 'id' => 1 ) ),
		),
	)
);
assert( 123 === $inbox['checked'] );
assert( 9 === $inbox['pending_comments']['count'] );
assert( 5 === count( $inbox['pending_comments']['items'] ) );

$summary = $collection->invoke(
	null,
	array(
		'status'  => 200,
		'headers' => array( 'X-WP-Total' => '17' ),
		'body'    => array_fill( 0, 5, array( 'id' => 1 ) ),
		'error'   => '',
	)
);
assert( 17 === $summary['count'] );
assert( 5 === count( $summary['items'] ) );

assert( true === $safe_api_route->invoke( null, 'wp/v2/posts?context=edit' ) );
assert( true === $safe_api_route->invoke( null, '/plugin/v1/run' ) );
assert( false === $safe_api_route->invoke( null, 'https://example.com/wp-json/' ) );
assert( false === $safe_api_route->invoke( null, '../wp-admin/' ) );
assert( false === $safe_api_route->invoke( null, 'wp/v2/%2e%2e/users' ) );
assert( false === $safe_api_route->invoke( null, 'wp\\v2\\users' ) );
assert( false === $safe_api_route->invoke( null, 'wp%5cv2/users' ) );
assert( false === $safe_api_route->invoke( null, 'wp/v2/posts?_method=DELETE' ) );
assert( false === $safe_api_route->invoke( null, 'wp/v2/posts?%5Fmethod=DELETE' ) );
assert( false === $safe_api_route->invoke( null, 'wp/v2/posts?_METHOD=DELETE' ) );
assert( false === $safe_api_route->invoke( null, 'wp/v2/posts?_method%5B%5D=DELETE' ) );

$decoded = OpenStation_Fleet_REST_Client::decode(
	array(
		'response' => array( 'code' => 200 ),
		'body'     => '{"ok":true}',
	),
	'remote_error',
	'HTTP %d'
);
assert( array( 'ok' => true ) === $decoded );
$fleet_test_remote_requests  = array();
$fleet_test_remote_responses = array(
	array(
		'response' => array( 'code' => 200 ),
		'headers'  => array( 'x-wp-totalpages' => '1' ),
		'body'     => '[{"id":1},{"id":2}]',
	),
);
$page_envelope               = OpenStation_Fleet_REST_Client::request_envelope(
	array(
		'site_url'   => 'https://site.example',
		'rest_url'   => 'https://site.example/wp-json/',
		'user_login' => 'fleet-admin',
		'secret'     => $sealed,
	),
	'GET',
	'wp/v2/posts?per_page=2'
);
assert( 1 === $page_envelope['total_pages'] );
assert( 2 === count( $page_envelope['items'] ) );
$fleet_test_remote_responses = array(
	array(
		'response' => array( 'code' => 200 ),
		'headers'  => array(),
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Mirrors the RFC 7617 value that must be scrubbed from an untrusted response.
		'body'     => wp_json_encode(
			array(
				'secret'        => 'application-password',
				'authorization' => 'Basic ' . base64_encode( 'fleet-admin:application-password' ),
			)
		),
	),
);
$redacted_response = OpenStation_Fleet_REST_Client::request(
	array(
		'site_url'   => 'https://site.example',
		'rest_url'   => 'https://site.example/wp-json/',
		'user_login' => 'fleet-admin',
		'secret'     => $sealed,
	),
	'GET',
	'wp/v2/posts'
);
assert( '[redacted]' === $redacted_response['secret'] );
assert( '[redacted]' === $redacted_response['authorization'] );
assert(
	array() === OpenStation_Fleet_REST_Client::decode(
		array(
			'response' => array( 'code' => 204 ),
			'body'     => '',
		),
		'remote_error',
		'HTTP %d'
	)
);
assert(
	OpenStation_Fleet_REST_Client::decode(
		array(
			'response' => array( 'code' => 200 ),
			'body'     => '<html>not json</html>',
		),
		'remote_error',
		'HTTP %d'
	) instanceof WP_Error
);
$remote_error = OpenStation_Fleet_REST_Client::decode(
	array(
		'response' => array( 'code' => 403 ),
		'body'     => '{"message":"No access"}',
	),
	'remote_error',
	'HTTP %d'
);
assert( $remote_error instanceof WP_Error );
assert( 'remote_error' === $remote_error->get_error_code() );
assert( 'HTTP 403' === $remote_error->get_error_message() );
assert( array( 'status' => 403 ) === $remote_error->get_error_data() );

$request_args                = array(
	'method'      => 'GET',
	'redirection' => 0,
);
$fleet_test_remote_requests  = array();
$fleet_test_remote_responses = array(
	array(
		'response' => array( 'code' => 302 ),
		'headers'  => array( 'location' => 'https://other.example/wp-json/' ),
		'body'     => '',
	),
);
OpenStation_Fleet_REST_Client::safe_request( 'https://site.example/wp-json/', $request_args, 'https://site.example' );
assert( 1 === count( $fleet_test_remote_requests ) );

$fleet_test_remote_requests  = array();
$fleet_test_remote_responses = array(
	array(
		'response' => array( 'code' => 302 ),
		'headers'  => array( 'location' => '/wp-json/wp/v2/posts' ),
		'body'     => '',
	),
	array(
		'response' => array( 'code' => 200 ),
		'headers'  => array(),
		'body'     => '[]',
	),
);
OpenStation_Fleet_REST_Client::safe_request( 'https://site.example/wp-json/', $request_args, 'https://site.example' );
assert( 2 === count( $fleet_test_remote_requests ) );
assert( 'https://site.example/wp-json/wp/v2/posts' === $fleet_test_remote_requests[1]['url'] );

$fleet_test_remote_requests  = array();
$fleet_test_remote_responses = array(
	array(
		'response' => array( 'code' => 302 ),
		'headers'  => array( 'location' => '/wp-json/wp/v2/posts' ),
		'body'     => '',
	),
);
OpenStation_Fleet_REST_Client::safe_request( 'https://site.example/wp-json/', array( 'method' => 'POST' ), 'https://site.example' );
assert( 1 === count( $fleet_test_remote_requests ) );

$authorization_url = $class->getMethod( 'authorization_url' );
if ( PHP_VERSION_ID < 80100 ) {
	$authorization_url->setAccessible( true );
}
$callback  = 'https://hub.example/wp-admin/admin-post.php?action=openstation_fleet_authorized&state=1234&_wpnonce=nonce';
$authorize = $authorization_url->invoke(
	null,
	'https://client.example/wp-admin/authorize-application.php',
	array(
		'app_name'    => 'Fleet for OpenStation',
		'app_id'      => 'app-id',
		'success_url' => $callback,
		'reject_url'  => $callback . '&rejected=1',
	)
);
parse_str( wp_parse_url( $authorize, PHP_URL_QUERY ), $authorize_query );
assert( rawurldecode( $authorize_query['success_url'] ) === $callback );
assert( rawurldecode( $authorize_query['reject_url'] ) === $callback . '&rejected=1' );
assert( ! isset( $authorize_query['state'] ) );

$failure_message = $class->getMethod( 'authorization_failure_message' );
$revoke_new      = $class->getMethod( 'revoke_new_credential' );
if ( PHP_VERSION_ID < 80100 ) {
	$failure_message->setAccessible( true );
	$revoke_new->setAccessible( true );
}
assert( false !== strpos( $failure_message->invoke( null, 'storage_failed' ), 'revoked' ) );
assert( false !== strpos( $failure_message->invoke( null, 'storage_revoke_failed' ), 'did not confirm' ) );
assert( false !== strpos( $failure_message->invoke( null, 'encryption_failed' ), 'Application Passwords' ) );
$revoke_site = array(
	'site_url'       => 'https://cleanup.example',
	'rest_url'       => 'https://cleanup.example/wp-json/',
	'user_login'     => 'fleet-admin',
	'secret'         => $sealed,
	'credential_uuid' => 'cleanup-credential',
);
$fleet_test_remote_responses = array(
	array(
		'response' => array( 'code' => 204 ),
		'headers'  => array(),
		'body'     => '',
	),
);
assert( true === $revoke_new->invoke( null, $revoke_site ) );
$fleet_test_remote_responses = array(
	array(
		'response' => array( 'code' => 500 ),
		'headers'  => array(),
		'body'     => '{"message":"Cleanup failed"}',
	),
);
assert( false === $revoke_new->invoke( null, $revoke_site ) );

// The 0.7 aggregate migrates into compact, blog-scoped connection rows and
// generation-bound search rows without truncating the older compatibility index.
$legacy_search_index = array();
for ( $search_item_id = 1; $search_item_id <= 30; ++$search_item_id ) {
	$legacy_search_index[] = array(
		'id'    => $search_item_id,
		'title' => 'Legacy item ' . $search_item_id,
		'meta'  => 'post',
	);
}
$legacy_search_state = array(
	'generation' => 4,
	'items'      => array( 'preserve' => true ),
);
$fleet_test_user_meta[7]['openstation_fleet_sites'] = array(
	'alpha' => array(
		'name'               => 'Alpha',
		'site_url'           => 'https://alpha.example',
		'rest_url'           => 'https://alpha.example/wp-json/',
		'agency'             => array(
			'client_name' => 'Agency A',
			'favorite'    => true,
		),
		'openstation'        => array( 'status' => 'active' ),
		'search_index_state' => $legacy_search_state,
		'search_index'       => $legacy_search_index,
	),
	'beta'  => array(
		'name'        => 'Beta',
		'site_url'    => 'https://beta.example',
		'rest_url'    => 'https://beta.example/wp-json/',
		'openstation' => array( 'status' => 'active' ),
	),
);
$migrated = OpenStation_Fleet_Repository::all( 7, array( 'OpenStation_Fleet', 'normalize_site_record' ) );
assert( array( 'alpha', 'beta' ) === array_keys( $migrated ) );
assert( ! isset( $fleet_test_user_meta[7]['openstation_fleet_sites'] ) );
assert( isset( $fleet_test_user_meta[7]['openstation_fleet_site_blog_1_alpha'] ) );
assert( isset( $fleet_test_user_meta[7]['openstation_fleet_site_blog_1_beta'] ) );
assert( array( 'alpha', 'beta' ) === $fleet_test_user_meta[7]['openstation_fleet_site_ids_blog_1'] );
assert( ! isset( $fleet_test_user_meta[7]['openstation_fleet_site_blog_1_alpha']['search_index_state'] ) );
assert( ! isset( $fleet_test_user_meta[7]['openstation_fleet_site_blog_1_alpha']['search_index'] ) );
$alpha_search_key = fleet_test_search_storage_key( 1, 7, 'alpha' );
assert( isset( $fleet_test_options[ $alpha_search_key ] ) );
assert( ! isset( $fleet_test_user_meta[7]['openstation_fleet_search_blog_1_alpha'] ) );
$alpha_generation = $migrated['alpha']['connection_generation'];
$alpha_search     = OpenStation_Fleet_Repository::get_search_state( 7, 'alpha', $alpha_generation, array( 'OpenStation_Fleet', 'normalize_site_record' ) );
assert( $legacy_search_state === $alpha_search['search_index_state'] );
assert( $legacy_search_index === $alpha_search['search_index'] );
assert( $alpha_search['storage_revision'] > 0 );
assert( strlen( serialize( $fleet_test_user_meta[7]['openstation_fleet_site_blog_1_alpha'] ) ) < strlen( serialize( $fleet_test_options[ $alpha_search_key ] ) ) );

// Direct reads touch the compact row and index, never the large search blob.
$fleet_test_meta_reads   = array();
$fleet_test_option_reads = array();
$direct_alpha            = OpenStation_Fleet_Repository::get( 7, 'alpha', array( 'OpenStation_Fleet', 'normalize_site_record' ) );
assert( 'Alpha' === $direct_alpha['name'] );
foreach ( $fleet_test_meta_reads as $fleet_test_meta_read ) {
	assert( 0 !== strpos( $fleet_test_meta_read[1], 'openstation_fleet_search' ) );
}
foreach ( $fleet_test_option_reads as $fleet_test_option_read ) {
	assert( 0 !== strpos( $fleet_test_option_read[1], OpenStation_Fleet_Repository::search_option_prefix() ) );
}

// Updating Alpha does not rewrite Beta and preserves concurrent local fields.
$beta_before     = $fleet_test_user_meta[7]['openstation_fleet_site_blog_1_beta'];
$alpha           = $migrated['alpha'];
$alpha['name']   = 'Remote Alpha';
$alpha['error']  = 'Temporary error';
$alpha['agency'] = array( 'client_name' => 'Should not replace' );
$saved           = OpenStation_Fleet_Repository::save( 7, 'alpha', $alpha, array( 'error' ), array( 'OpenStation_Fleet', 'normalize_site_record' ) );
$after           = OpenStation_Fleet_Repository::all( 7, array( 'OpenStation_Fleet', 'normalize_site_record' ) );
assert( true === $saved );
assert( 'Alpha' === $after['alpha']['name'] );
assert( 'Agency A' === $after['alpha']['agency']['client_name'] );
assert( 'Temporary error' === $after['alpha']['error'] );
assert( $fleet_test_user_meta[7]['openstation_fleet_site_blog_1_beta'] === $beta_before );

$alpha['name']   = 'Local Alpha';
$alpha['agency'] = array( 'client_name' => 'Agency B' );
assert( true === OpenStation_Fleet_Repository::save( 7, 'alpha', $alpha, array( 'name', 'agency' ), array( 'OpenStation_Fleet', 'normalize_site_record' ) ) );
$after = OpenStation_Fleet_Repository::all( 7, array( 'OpenStation_Fleet', 'normalize_site_record' ) );
assert( 'Local Alpha' === $after['alpha']['name'] );
assert( 'Agency B' === $after['alpha']['agency']['client_name'] );
assert( array( 7 ) === OpenStation_Fleet_Repository::user_ids() );

// Search writes are tied to one connection generation. A disconnect erases
// them, and a stale response cannot attach itself to a later reconnection.
$beta_generation_a = $migrated['beta']['connection_generation'];
$beta_search_before = OpenStation_Fleet_Repository::get_search_state( 7, 'beta', $beta_generation_a, array( 'OpenStation_Fleet', 'normalize_site_record' ) );
assert(
	true === OpenStation_Fleet_Repository::save_search_state(
		7,
		'beta',
		$beta_generation_a,
		array( 'cursor' => 'generation-a' ),
		array(),
		array( 'OpenStation_Fleet', 'normalize_site_record' ),
		$beta_search_before['storage_revision']
	)
);
assert( true === OpenStation_Fleet_Repository::remove( 7, 'beta', $beta_generation_a ) );
$beta_search_key = fleet_test_search_storage_key( 1, 7, 'beta' );
assert( ! isset( $fleet_test_options[ $beta_search_key ] ) );
assert( array( 'alpha' ) === OpenStation_Fleet_Repository::site_ids( 7, array( 'OpenStation_Fleet', 'normalize_site_record' ) ) );
assert( false === OpenStation_Fleet_Repository::save( 7, 'beta', $beta_before, array(), array( 'OpenStation_Fleet', 'normalize_site_record' ) ) );

$beta_reconnected                          = $beta_before;
$beta_reconnected['credential_uuid']       = 'generation-b';
$beta_reconnected['connection_generation'] = 'generation-b';
assert( true === OpenStation_Fleet_Repository::save( 7, 'beta', $beta_reconnected, array(), array( 'OpenStation_Fleet', 'normalize_site_record' ), true ) );
assert( false === OpenStation_Fleet_Repository::save( 7, 'beta', $beta_reconnected, array(), array( 'OpenStation_Fleet', 'normalize_site_record' ), true ) );
assert( false === OpenStation_Fleet_Repository::save_search_state( 7, 'beta', $beta_generation_a, array( 'stale' => true ), array(), array( 'OpenStation_Fleet', 'normalize_site_record' ) ) );
$beta_empty_search = OpenStation_Fleet_Repository::get_search_state( 7, 'beta', 'generation-b', array( 'OpenStation_Fleet', 'normalize_site_record' ) );
assert( array() === $beta_empty_search['search_index_state'] );
assert( true === OpenStation_Fleet_Repository::save_search_state( 7, 'beta', 'generation-b', array( 'fresh' => true ), array(), array( 'OpenStation_Fleet', 'normalize_site_record' ), $beta_empty_search['storage_revision'] ) );
assert( false === OpenStation_Fleet_Repository::remove( 7, 'beta', $beta_generation_a ) );
assert( isset( $fleet_test_options[ $beta_search_key ] ) );

// Two refreshes may start from the same generation, but only the first write
// may advance its exact storage revision. The stale second result cannot rewind it.
$beta_refresh_a = OpenStation_Fleet_Repository::get_search_state( 7, 'beta', 'generation-b', array( 'OpenStation_Fleet', 'normalize_site_record' ) );
$beta_refresh_b = $beta_refresh_a;
assert( true === OpenStation_Fleet_Repository::save_search_state( 7, 'beta', 'generation-b', array( 'winner' => 'manual' ), array(), array( 'OpenStation_Fleet', 'normalize_site_record' ), $beta_refresh_a['storage_revision'] ) );
assert( false === OpenStation_Fleet_Repository::save_search_state( 7, 'beta', 'generation-b', array( 'winner' => 'stale-cron' ), array(), array( 'OpenStation_Fleet', 'normalize_site_record' ), $beta_refresh_b['storage_revision'] ) );
$beta_search_after_race = OpenStation_Fleet_Repository::get_search_state( 7, 'beta', 'generation-b', array( 'OpenStation_Fleet', 'normalize_site_record' ) );
assert( array( 'winner' => 'manual' ) === $beta_search_after_race['search_index_state'] );
$stale_beta                          = $beta_reconnected;
$stale_beta['connection_generation'] = $beta_generation_a;
$stale_beta['error']                 = 'Must not overwrite the new connection';
assert( false === OpenStation_Fleet_Repository::save( 7, 'beta', $stale_beta, array( 'error' ), array( 'OpenStation_Fleet', 'normalize_site_record' ) ) );
assert( 'generation-b' === OpenStation_Fleet_Repository::get( 7, 'beta', array( 'OpenStation_Fleet', 'normalize_site_record' ) )['connection_generation'] );

// The 0.8 fixed split-row layout migrates lazily and keeps embedded search.
$split_search = array( 'split' => array( 'all', 'records', 'survive' ) );
$fleet_test_user_meta[8]['openstation_fleet_site_ids'] = array( 'gamma' );
$fleet_test_user_meta[8]['openstation_fleet_storage_version'] = 1;
$fleet_test_user_meta[8]['openstation_fleet_site_gamma'] = array(
	'name'                  => 'Gamma',
	'site_url'              => 'https://gamma.example',
	'rest_url'              => 'https://gamma.example/wp-json/',
	'connection_generation' => 'gamma-generation',
	'search_index_state'    => $split_search,
);
$fleet_test_user_meta[8]['openstation_fleet_search_gamma'] = array(
	'connection_generation' => 'gamma-generation',
	'search_index'          => $legacy_search_index,
);
$fleet_test_user_meta[8]['openstation_fleet_search_blog_1_gamma'] = array(
	'connection_generation' => 'stale-generation',
	'search_index_state'    => array( 'stale' => true ),
);
$gamma = OpenStation_Fleet_Repository::get( 8, 'gamma', array( 'OpenStation_Fleet', 'normalize_site_record' ) );
assert( 'Gamma' === $gamma['name'] );
assert( ! isset( $gamma['search_index_state'] ) );
assert( ! isset( $fleet_test_user_meta[8]['openstation_fleet_site_gamma'] ) );
assert( ! isset( $fleet_test_user_meta[8]['openstation_fleet_search_gamma'] ) );
assert( isset( $fleet_test_user_meta[8]['openstation_fleet_site_blog_1_gamma'] ) );
assert( ! isset( $fleet_test_user_meta[8]['openstation_fleet_search_blog_1_gamma'] ) );
$gamma_search_key = fleet_test_search_storage_key( 1, 8, 'gamma' );
assert( isset( $fleet_test_options[ $gamma_search_key ] ) );
$gamma_search = OpenStation_Fleet_Repository::get_search_state( 8, 'gamma', 'gamma-generation', array( 'OpenStation_Fleet', 'normalize_site_record' ) );
assert( $split_search === $gamma_search['search_index_state'] );
assert( $legacy_search_index === $gamma_search['search_index'] );

// Usermeta is network-global, so every key includes the hub blog id. The first
// Fleet hub to access the old layout atomically adopts it, even on a subsite.
$fleet_test_is_multisite = true;
$fleet_test_user_meta[9]['openstation_fleet_sites'] = array(
	'legacy-client' => array(
		'name'                  => 'Legacy Hub Client',
		'site_url'              => 'https://legacy-client.example',
		'rest_url'              => 'https://legacy-client.example/wp-json/',
		'connection_generation' => 'legacy-generation',
	),
);
$fleet_test_user_meta[9]['openstation_fleet_activity'] = array( array( 'message' => 'Legacy activity' ) );
$fleet_test_user_meta[9]['openstation_fleet_app_id']   = 'legacy-app-id';
$fleet_test_blog_id = 2;
assert( array( 'legacy-client' ) === OpenStation_Fleet_Repository::site_ids( 9, array( 'OpenStation_Fleet', 'normalize_site_record' ) ) );
assert( 2 === $fleet_test_options['1:openstation_fleet_legacy_owner'] );
assert( ! isset( $fleet_test_user_meta[9]['openstation_fleet_sites'] ) );
assert( isset( $fleet_test_user_meta[9]['openstation_fleet_activity_blog_2'] ) );
assert( isset( $fleet_test_user_meta[9]['openstation_fleet_app_id_blog_2'] ) );
$subsite_activity_key = OpenStation_Fleet_Repository::activity_meta_key();
$subsite_app_key      = OpenStation_Fleet_Repository::app_id_meta_key();

$fleet_test_blog_id = 1;
assert( array() === OpenStation_Fleet_Repository::site_ids( 9, array( 'OpenStation_Fleet', 'normalize_site_record' ) ) );
assert( false === OpenStation_Fleet_Repository::get( 9, 'legacy-client', array( 'OpenStation_Fleet', 'normalize_site_record' ) ) );
$main_record = array(
	'name'                  => 'Main Site Client',
	'site_url'              => 'https://main-site-client.example',
	'rest_url'              => 'https://main-site-client.example/wp-json/',
	'connection_generation' => 'main-generation',
);
assert( true === OpenStation_Fleet_Repository::save( 9, 'main-site', $main_record, array(), array( 'OpenStation_Fleet', 'normalize_site_record' ), true ) );
assert( ! isset( $fleet_test_user_meta[9]['openstation_fleet_activity'] ) );
assert( ! isset( $fleet_test_user_meta[9]['openstation_fleet_app_id'] ) );
$main_activity_key = OpenStation_Fleet_Repository::activity_meta_key();
$main_app_key      = OpenStation_Fleet_Repository::app_id_meta_key();
assert( $main_activity_key !== $subsite_activity_key );
assert( $main_app_key !== $subsite_app_key );
$fleet_test_blog_id = 2;
assert( array( 'legacy-client' ) === OpenStation_Fleet_Repository::site_ids( 9, array( 'OpenStation_Fleet', 'normalize_site_record' ) ) );
assert( false === OpenStation_Fleet_Repository::get( 9, 'main-site', array( 'OpenStation_Fleet', 'normalize_site_record' ) ) );

// Both contenders may observe no owner. The main site's unique option_name
// lets only one add win; the loser rereads that winner and leaves legacy data alone.
unset( $fleet_test_options['1:openstation_fleet_legacy_owner'] );
$fleet_test_user_meta[10]['openstation_fleet_sites'] = array(
	'race-client' => array(
		'name'                  => 'Race Client',
		'site_url'              => 'https://race-client.example',
		'rest_url'              => 'https://race-client.example/wp-json/',
		'connection_generation' => 'race-generation',
	),
);
$fleet_test_owner_race = 3;
$fleet_test_blog_id    = 2;
assert( array() === OpenStation_Fleet_Repository::site_ids( 10, array( 'OpenStation_Fleet', 'normalize_site_record' ) ) );
assert( 2 === $fleet_test_blog_id );
assert( 3 === $fleet_test_options['1:openstation_fleet_legacy_owner'] );
assert( isset( $fleet_test_user_meta[10]['openstation_fleet_sites'] ) );
$fleet_test_blog_id = 3;
assert( array( 'race-client' ) === OpenStation_Fleet_Repository::site_ids( 10, array( 'OpenStation_Fleet', 'normalize_site_record' ) ) );
assert( 3 === $fleet_test_blog_id );
assert( ! isset( $fleet_test_user_meta[10]['openstation_fleet_sites'] ) );
$fleet_test_blog_id      = 1;
$fleet_test_is_multisite = false;
foreach ( array_keys( $fleet_test_options ) as $fleet_test_option_key ) {
	assert( false === strpos( $fleet_test_option_key, 'openstation_fleet_lock_' ) );
}

// Tier cadence and retry policy stay deterministic and bounded.
$policy_site = OpenStation_Fleet::normalize_site_record( array() );
assert( true === OpenStation_Fleet_Sync_Policy::due( $policy_site, 'status', 1000 ) );
$policy_site['status_checked'] = 900;
assert( false === OpenStation_Fleet_Sync_Policy::due( $policy_site, 'status', 1000 ) );
assert( true === OpenStation_Fleet_Sync_Policy::due( $policy_site, 'status', 1000, true ) );
$failed_once = OpenStation_Fleet_Sync_Policy::failed( $policy_site, 'Offline', 1000 );
assert( 1 === $failed_once['sync_failures'] );
assert( 1300 === $failed_once['next_retry'] );
assert( false === OpenStation_Fleet_Sync_Policy::due( $failed_once, 'status', 1299 ) );
$failed_twice = OpenStation_Fleet_Sync_Policy::failed( $failed_once, 'Still offline', 1300 );
assert( 2 === $failed_twice['sync_failures'] );
assert( 1900 === $failed_twice['next_retry'] );
$many_failures = $failed_twice;
for ( $failure = 0; $failure < 20; ++$failure ) {
	$many_failures = OpenStation_Fleet_Sync_Policy::failed( $many_failures, 'Offline', 2000 );
}
assert( $many_failures['next_retry'] <= 2000 + OpenStation_Fleet_Sync_Policy::MAX_BACKOFF );
$recovered = OpenStation_Fleet_Sync_Policy::succeeded( $failed_twice );
assert( 0 === $recovered['sync_failures'] );
assert( 0 === $recovered['next_retry'] );
assert( '' === $recovered['error'] );

// A public REST index is not proof that a saved credential still works.
// Failed identity checks retain the last inbox and enter retry backoff.
$refresh = $class->getMethod( 'refresh_site' );
if ( PHP_VERSION_ID < 80100 ) {
	$refresh->setAccessible( true );
}
$status_site = OpenStation_Fleet::normalize_site_record( array(
	'site_url' => 'https://site.example',
	'rest_url' => 'https://site.example/wp-json/',
	'user_login' => 'fleet-admin',
	'secret' => $sealed,
	'metadata_checked' => time(),
	'health_checked' => time(),
	'inbox' => array( 'drafts' => array( 'count' => 7 ) ),
) );
foreach ( array( 401, 403, 200 ) as $identity_status ) {
	$fleet_test_remote_responses = array(
		array( 'response' => array( 'code' => 200 ), 'body' => '{"name":"Public site","routes":{}}' ),
		array( 'response' => array( 'code' => $identity_status ), 'body' => '{"id":1,"capabilities":{"manage_options":false}}' ),
	);
	$status_result = $refresh->invoke( null, $status_site, false );
	assert( '' !== $status_result['error'] );
	assert( 0 === $status_result['status_checked'] );
	assert( $status_result['next_retry'] > time() );
	assert( 7 === $status_result['inbox']['drafts']['count'] );
}
$fleet_test_remote_responses = array(
	array( 'response' => array( 'code' => 200 ), 'body' => '{"name":"Public site","routes":{}}' ),
	array( 'response' => array( 'code' => 200 ), 'body' => '{"id":1,"capabilities":{"manage_options":true}}' ),
);
$status_result = $refresh->invoke( null, $status_site, false );
assert( '' === $status_result['error'] );
assert( $status_result['status_checked'] > 0 );
assert( 0 === $status_result['next_retry'] );

// The source editor preserves block markup and rejects malformed schedules/types.
$content_body = array(
	'title' => 'Launch draft',
	'content' => '<!-- wp:paragraph {"className":"custom"} --><p class="custom">A &amp; B</p><!-- /wp:paragraph -->',
	'excerpt' => '<p>Excerpt</p>',
	'slug' => 'launch-draft',
	'status' => 'draft',
	'date_gmt' => '',
);
$validated = OpenStation_Fleet_Content::body( $content_body );
assert( ! is_wp_error( $validated ) );
assert( $content_body['content'] === $validated['content'] );
assert( ! isset( $validated['date_gmt'] ) );
assert( is_wp_error( OpenStation_Fleet_Content::body( array_merge( $content_body, array( 'status' => 'future' ) ) ) ) );
assert( is_wp_error( OpenStation_Fleet_Content::body( array_merge( $content_body, array( 'date_gmt' => '2026-02-31T12:00:00' ) ) ) ) );
foreach ( array( "2026-12-31T14:30:00\0", '2026-12-31T14:30:00Z', '2026-12-31T25:30:00', str_repeat( '9', 200001 ) ) as $invalid_date ) {
	assert( is_wp_error( OpenStation_Fleet_Content::body( array_merge( $content_body, array( 'date_gmt' => $invalid_date ) ) ) ) );
}
assert( is_wp_error( OpenStation_Fleet_Content::body( array_merge( $content_body, array( 'content' => array( 'bad' ) ) ) ) ) );
assert( is_wp_error( OpenStation_Fleet_Content::body( array_merge( $content_body, array( 'title' => '' ) ) ) ) );
assert( is_wp_error( OpenStation_Fleet_Content::body( array_merge( $content_body, array( 'content' => str_repeat( 'a', 200001 ) ) ) ) ) );
assert( false === OpenStation_Fleet_Content::valid_type( '../users' ) );
$type_candidate = array( 'viewable' => true, 'name' => 'Events', 'supports' => array( 'title' => true, 'editor' => true ), 'capabilities' => array( 'edit_posts' => 'edit_events' ), 'rest_namespace' => 'studio/v1', 'rest_base' => 'events' );
assert( array() === OpenStation_Fleet_Content::types( array( 'event' => $type_candidate ), array() ) );
assert( 'studio/v1/events' === OpenStation_Fleet_Content::types( array( 'event' => $type_candidate ), array( 'edit_events' => true ) )['event']['route'] );
assert( 'studio/v1/events' === OpenStation_Fleet_Content::types( array( 'posts' => $type_candidate ), array( 'edit_events' => true ) )['wp_type_posts']['route'] );
assert( 'studio/v1/events' === OpenStation_Fleet_Content::types( array( 'pages' => $type_candidate ), array( 'edit_events' => true ) )['wp_type_pages']['route'] );
$type_candidate['rest_base'] = '../users';
assert( array() === OpenStation_Fleet_Content::types( array( 'event' => $type_candidate ), array( 'edit_events' => true ) ) );
$content_item = array( 'title' => array( 'raw' => 'Before' ), 'content' => array( 'raw' => 'One' ), 'modified_gmt' => '2026-09-04T12:00:00' );
$fingerprint = OpenStation_Fleet_Content::fingerprint( $content_item );
$content_item['content']['raw'] = 'Two';
assert( $fingerprint !== OpenStation_Fleet_Content::fingerprint( $content_item ) );

// A reconnect preserves the latest agency data and fences off stale writes.
$reconnect_record = array( 'site_url' => 'https://launch.example', 'connection_generation' => 'old-generation', 'agency' => array( 'notes' => 'Latest private note' ) );
$normalizer = array( 'OpenStation_Fleet', 'normalize_site_record' );
assert( true === OpenStation_Fleet_Repository::save( 27, 'launch', $reconnect_record, array(), $normalizer, true ) );
$replacement = array_merge( $reconnect_record, array( 'connection_generation' => 'new-generation', 'agency' => array( 'notes' => 'Stale note' ) ) );
assert( false === OpenStation_Fleet_Repository::reauthorize( 27, 'launch', 'wrong-generation', $replacement, $normalizer ) );
assert( true === OpenStation_Fleet_Repository::reauthorize( 27, 'launch', 'old-generation', $replacement, $normalizer ) );
assert( 'Latest private note' === OpenStation_Fleet_Repository::get( 27, 'launch', $normalizer )['agency']['notes'] );
assert( false === OpenStation_Fleet_Repository::save( 27, 'launch', $reconnect_record, array( 'error' ), $normalizer ) );
assert( false === OpenStation_Fleet_Repository::reauthorize( 27, 'launch', 'old-generation', $replacement, $normalizer ) );
assert( true === OpenStation_Fleet_Repository::remove( 27, 'launch', 'new-generation' ) );

// A new normalized field must not break compare-and-swap against the stored row.
assert( true === OpenStation_Fleet_Repository::save( 27, 'defaults', $reconnect_record, array(), $normalizer, true ) );
$site_key_method = new ReflectionMethod( 'OpenStation_Fleet_Repository', 'site_key' );
$raw_key = $site_key_method->invoke( null, 'defaults' );
$raw = get_user_meta( 27, $raw_key, true );
unset( $raw['views'], $raw['health_error'], $raw['health_attempted'] );
update_user_meta( 27, $raw_key, $raw );
$normalized = OpenStation_Fleet_Repository::get( 27, 'defaults', $normalizer );
$normalized['views'] = array( 'one' => array( 'name' => 'Editorial' ) );
assert( true === OpenStation_Fleet_Repository::save( 27, 'defaults', $normalized, array( 'views' ), $normalizer ) );
assert( 'Editorial' === OpenStation_Fleet_Repository::get( 27, 'defaults', $normalizer )['views']['one']['name'] );
assert( true === OpenStation_Fleet_Repository::remove( 27, 'defaults', 'old-generation' ) );

echo "Fleet smoke checks passed.\n";
