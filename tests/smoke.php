<?php
/**
 * Tiny framework-free checks for Fleet's pure URL and plugin-status logic.
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
	return 'version' === $show ? '6.8.2' : '';
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

function home_url() {
	return 'https://hub.example';
}

function site_url() {
	return 'https://hub.example/wp';
}

function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
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
require dirname( __DIR__ ) . '/includes/class-fleet-for-openstation.php';

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

$class  = new ReflectionClass( 'OpenStation_Fleet' );
assert( false === OpenStation_Fleet::has_app_framework() );
assert( false === $class->hasConstant( 'MENU_SLUG' ) );
assert( false === $class->hasConstant( 'UPLOAD_TIMEOUT' ) );
assert( 2 === $class->getConstant( 'CRON_BATCH_SIZE' ) );
assert( 2097152 === $class->getConstant( 'RESPONSE_LIMIT' ) );
$seal   = $class->getMethod( 'seal_secret' );
$open   = $class->getMethod( 'open_secret' );
if ( PHP_VERSION_ID < 80100 ) {
	$seal->setAccessible( true );
	$open->setAccessible( true );
}
$sealed = $seal->invoke( null, 'application-password' );
assert( is_string( $sealed ) && 0 === strpos( $sealed, 'v1:' ) );
assert( 'application-password' === $open->invoke( null, $sealed ) );
assert( $open->invoke( null, $sealed . 'tampered' ) instanceof WP_Error );

$api = $class->getMethod( 'api_url' );
if ( PHP_VERSION_ID < 80100 ) {
	$api->setAccessible( true );
}
assert(
	'https://example.com/wp-json/wp/v2/plugins/desktop-mode/desktop-mode' === $api->invoke(
		null,
		array(
			'site_url' => 'https://example.com',
			'rest_url' => 'https://example.com/wp-json/',
		),
		'wp/v2/plugins/desktop-mode/desktop-mode'
	)
);
$plain_url = $api->invoke(
	null,
	array(
		'site_url' => 'https://example.com',
		'rest_url' => 'https://example.com/?rest_route=/',
	),
	'wp/v2/plugins?context=edit'
);
parse_str( parse_url( $plain_url, PHP_URL_QUERY ), $plain_query );
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
$attention       = $class->getMethod( 'attention_reasons' );
$normalize_inbox  = $class->getMethod( 'normalize_inbox_summary' );
$collection       = $class->getMethod( 'collection_summary' );
$normalize_search = $class->getMethod( 'normalize_search_results' );
$safe_api_route   = $class->getMethod( 'is_safe_api_route' );
$decode_response  = $class->getMethod( 'decode_remote_response' );
$safe_request     = $class->getMethod( 'safe_authenticated_request' );
if ( PHP_VERSION_ID < 80100 ) {
	$normalize_record->setAccessible( true );
	$capabilities->setAccessible( true );
	$route_catalog->setAccessible( true );
	$attention->setAccessible( true );
	$normalize_inbox->setAccessible( true );
	$collection->setAccessible( true );
	$normalize_search->setAccessible( true );
	$safe_api_route->setAccessible( true );
	$decode_response->setAccessible( true );
	$safe_request->setAccessible( true );
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
assert( array() === $record['search_index'] );
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
			'/batch/v1'                                    => array(),
			'/wp/v2/search'                                => array(),
			'/wp/v2/posts'                                 => array(),
			'/wp/v2/media'                                 => array(),
			'/wp/v2/templates'                             => array(),
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
			'/wp/v2/posts' => array(
				'namespace' => 'wp/v2',
				'methods'   => array( 'GET', 'POST' ),
				'endpoints' => array(
					array(
						'methods' => array( 'GET' ),
						'args'    => array( 'page' => array(), 'search' => array() ),
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
$reasons = $attention->invoke( null, $record );
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

$search_results = $normalize_search->invoke(
	null,
	array(
		'content' => array( array( 'id' => 7, 'title' => 'Hello <em>world</em>', 'subtype' => 'post' ) ),
		'posts'   => array( array( 'id' => 7, 'title' => array( 'rendered' => 'Hello world' ), 'status' => 'publish' ) ),
		'pages'   => array( array( 'id' => 8, 'title' => array( 'rendered' => 'Agency notes' ), 'status' => 'draft' ) ),
		'users'   => array( array( 'name' => 'Ada', 'roles' => array( 'editor' ) ) ),
		'comments' => array( array( 'author_name' => 'Grace', 'content' => array( 'rendered' => '<p>Good post</p>' ) ) ),
		'media'   => array( array( 'title' => array( 'rendered' => 'Photo' ), 'mime_type' => 'image/jpeg' ) ),
	)
);
assert( 5 === count( $search_results ) );
assert( 'Hello world' === $search_results[0]['title'] );
assert( 'Agency notes' === $search_results[1]['title'] );
assert( 'users' === $search_results[2]['section'] );
assert( 'comments' === $search_results[3]['section'] );
assert( 'media' === $search_results[4]['section'] );

assert( true === $safe_api_route->invoke( null, 'wp/v2/posts?context=edit' ) );
assert( true === $safe_api_route->invoke( null, '/plugin/v1/run' ) );
assert( false === $safe_api_route->invoke( null, 'https://example.com/wp-json/' ) );
assert( false === $safe_api_route->invoke( null, '../wp-admin/' ) );
assert( false === $safe_api_route->invoke( null, 'wp/v2/%2e%2e/users' ) );
assert( false === $safe_api_route->invoke( null, 'wp\\v2\\users' ) );
assert( false === $safe_api_route->invoke( null, 'wp%5cv2/users' ) );

$decoded = $decode_response->invoke(
	null,
	array(
		'response' => array( 'code' => 200 ),
		'body'     => '{"ok":true}',
	),
	'remote_error',
	'HTTP %d'
);
assert( array( 'ok' => true ) === $decoded );
assert(
	array() === $decode_response->invoke(
		null,
		array(
			'response' => array( 'code' => 204 ),
			'body'     => '',
		),
		'remote_error',
		'HTTP %d'
	)
);
assert(
	$decode_response->invoke(
		null,
		array(
			'response' => array( 'code' => 200 ),
			'body'     => '<html>not json</html>',
		),
		'remote_error',
		'HTTP %d'
	) instanceof WP_Error
);
$remote_error = $decode_response->invoke(
	null,
	array(
		'response' => array( 'code' => 403 ),
		'body'     => '{"message":"No access"}',
	),
	'remote_error',
	'HTTP %d'
);
assert( $remote_error instanceof WP_Error );
assert( 'remote_error' === $remote_error->get_error_code() );
assert( 'No access' === $remote_error->get_error_message() );
assert( array( 'status' => 403 ) === $remote_error->get_error_data() );

$request_args = array(
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
$safe_request->invoke( null, 'https://site.example/wp-json/', $request_args, 'https://site.example' );
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
$safe_request->invoke( null, 'https://site.example/wp-json/', $request_args, 'https://site.example' );
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
$safe_request->invoke( null, 'https://site.example/wp-json/', array( 'method' => 'POST' ), 'https://site.example' );
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
parse_str( parse_url( $authorize, PHP_URL_QUERY ), $authorize_query );
assert( $callback === rawurldecode( $authorize_query['success_url'] ) );
assert( $callback . '&rejected=1' === rawurldecode( $authorize_query['reject_url'] ) );
assert( ! isset( $authorize_query['state'] ) );

echo "Fleet smoke checks passed.\n";
