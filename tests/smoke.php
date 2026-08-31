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

function wp_salt() {
	return 'fleet-smoke-test-salt';
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
			'plugin'     => 'desktop-mode/desktop-mode.php',
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
			'plugin'     => 'desktop-mode/desktop-mode.php',
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
$sealed = $seal->invoke( null, 'application-password' );
assert( is_string( $sealed ) && 0 === strpos( $sealed, 'v1:' ) );
assert( 'application-password' === $open->invoke( null, $sealed ) );
assert( $open->invoke( null, $sealed . 'tampered' ) instanceof WP_Error );

echo "Fleet smoke checks passed.\n";
