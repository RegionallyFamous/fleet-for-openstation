<?php
/**
 * Tiny framework-free checks for Fleet's pure URL and plugin-status logic.
 */

class WP_Error {
	public $code;
	public $message;

	public function __construct( $code = '', $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}
}

function is_wp_error( $value ) {
	return $value instanceof WP_Error;
}

function __( $text ) {
	return $text;
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
