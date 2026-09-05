<?php
// Test hosting adapter, never part of the distributable plugin.
// ONLY the closed localhost port range is exempted from public HTTPS checks.
add_filter( 'http_request_host_is_external', static function ( $external, $host ) {
    return 'localhost' === $host ? true : $external;
}, 10, 2 );
add_filter( 'http_allowed_safe_ports', static function ( $ports ) {
    return array_merge( $ports, range( 18443, 18543 ) );
} );
add_filter( 'http_request_args', static function ( $args, $url ) {
    $parts = wp_parse_url( $url );
    if ( 'localhost' === ( $parts['host'] ?? '' ) && ( $parts['port'] ?? 0 ) >= 18443 && ( $parts['port'] ?? 0 ) <= 18543 ) {
        $args['sslverify'] = false;
    }
    return $args;
}, 10, 2 );
add_filter( 'rest_authentication_errors', static function ( $error ) {
    return get_option( 'fleet_lab_offline' ) ? new WP_Error( 'fleet_lab_offline', 'Injected lab outage.', array( 'status' => 503 ) ) : $error;
} );
