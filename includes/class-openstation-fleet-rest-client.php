<?php
/**
 * Safe authenticated WordPress Core REST client.
 *
 * @package FleetForOpenStation
 */

defined( 'ABSPATH' ) || exit;

/**
 * Keeps credentials, redirects, payload limits, and JSON validation together.
 */
final class OpenStation_Fleet_REST_Client {
	const REQUEST_TIMEOUT = 12;
	const RESPONSE_LIMIT  = 2097152;

	/**
	 * Send an authenticated Core REST request.
	 *
	 * @param array      $site Site record.
	 * @param string     $method HTTP method.
	 * @param string     $path REST route.
	 * @param array|null $body Optional JSON body.
	 * @param int|null   $timeout Optional request timeout in seconds.
	 * @return array|WP_Error
	 */
	public static function request( $site, $method, $path, $body = null, $timeout = null ) {
		$response = self::send( $site, $method, $path, $body, $timeout );
		// translators: %d: remote HTTP status code.
		return self::decode( $response, 'openstation_fleet_remote_error', __( 'The remote site returned HTTP %d.', 'fleet-for-openstation' ) );
	}

	/**
	 * Send a collection request while preserving Core pagination metadata.
	 *
	 * @param array      $site Site record.
	 * @param string     $method HTTP method.
	 * @param string     $path REST route.
	 * @param array|null $body Optional JSON body.
	 * @param int|null   $timeout Optional request timeout in seconds.
	 * @return array|WP_Error
	 */
	public static function request_envelope( $site, $method, $path, $body = null, $timeout = null ) {
		$response = self::send( $site, $method, $path, $body, $timeout );
		// translators: %d: remote HTTP status code.
		$items = self::decode( $response, 'openstation_fleet_remote_error', __( 'The remote site returned HTTP %d.', 'fleet-for-openstation' ) );
		if ( is_wp_error( $items ) ) {
			return $items;
		}

		$total_pages = (int) wp_remote_retrieve_header( $response, 'x-wp-totalpages' );
		$envelope    = array( 'items' => $items );
		if ( '' !== wp_remote_retrieve_header( $response, 'x-wp-total' ) ) {
			$envelope['total'] = max( 0, (int) wp_remote_retrieve_header( $response, 'x-wp-total' ) );
		}
		if ( $total_pages >= 0 && '' !== wp_remote_retrieve_header( $response, 'x-wp-totalpages' ) ) {
			$envelope['total_pages'] = $total_pages;
		}
		return $envelope;
	}

	/**
	 * Send one authenticated request and retain the complete HTTP response.
	 *
	 * @param array      $site Site record.
	 * @param string     $method HTTP method.
	 * @param string     $path REST route.
	 * @param array|null $body Optional JSON body.
	 * @param int|null   $timeout Optional request timeout in seconds.
	 * @return array|WP_Error
	 */
	private static function send( $site, $method, $path, $body = null, $timeout = null ) {
		$credential = OpenStation_Fleet_Crypto::open( isset( $site['secret'] ) ? (string) $site['secret'] : '' );
		if ( is_wp_error( $credential ) ) {
			return $credential;
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- RFC 7617 HTTP Basic authentication requires this encoding.
		$authorization = 'Basic ' . base64_encode( (string) $site['user_login'] . ':' . $credential );
		$timeout       = null === $timeout ? self::REQUEST_TIMEOUT : max( 1, min( self::REQUEST_TIMEOUT, (int) $timeout ) );
		$args          = array(
			'method'              => strtoupper( (string) $method ),
			'timeout'             => $timeout,
			'redirection'         => 0,
			'limit_response_size' => self::RESPONSE_LIMIT,
			'headers'             => array(
				'Accept'        => 'application/json',
				'Authorization' => $authorization,
			),
		);
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
			$args['data_format']             = 'body';
		}

		$response = self::safe_request( self::api_url( $site, $path ), $args, $site['site_url'] );
		if ( ! is_wp_error( $response ) && isset( $response['body'] ) && is_string( $response['body'] ) ) {
			$response['body'] = str_replace( array( $credential, $authorization, substr( $authorization, 6 ) ), '[redacted]', $response['body'] );
		}
		$credential    = null;
		$authorization = null;
		unset( $args['headers']['Authorization'] );
		return $response;
	}

	/**
	 * Follow one same-origin HTTPS redirect without weakening method safety.
	 *
	 * @param string $url Endpoint URL.
	 * @param array  $args HTTP arguments.
	 * @param string $site_url Managed site URL.
	 * @return array|WP_Error
	 */
	public static function safe_request( $url, $args, $site_url ) {
		$response = wp_safe_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( ! in_array( $code, array( 301, 302, 307, 308 ), true ) ) {
			return $response;
		}
		$method = isset( $args['method'] ) ? strtoupper( (string) $args['method'] ) : 'GET';
		if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) && ! in_array( $code, array( 307, 308 ), true ) ) {
			return $response;
		}

		$location = (string) wp_remote_retrieve_header( $response, 'location' );
		if ( 0 === strpos( $location, '//' ) ) {
			return $response;
		}
		if ( 0 === strpos( $location, '/' ) ) {
			$location = self::origin( $site_url ) . $location;
		}
		if ( 0 !== strpos( $location, 'https://' ) || self::origin( $location ) !== self::origin( $site_url ) ) {
			return $response;
		}
		return wp_safe_remote_request( $location, $args );
	}

	/**
	 * Decode a successful JSON REST response or return a useful WP_Error.
	 *
	 * @param array|WP_Error $response HTTP response.
	 * @param string         $error_code Error code.
	 * @param string         $http_message HTTP message template.
	 * @return array|WP_Error
	 */
	public static function decode( $response, $error_code, $http_message ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code     = wp_remote_retrieve_response_code( $response );
		$raw      = wp_remote_retrieve_body( $response );
		$has_body = '' !== trim( (string) $raw );
		$data     = $has_body ? json_decode( $raw, true ) : array();
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( $error_code, sprintf( $http_message, $code ), array( 'status' => $code ) );
		}
		if ( ( $has_body && JSON_ERROR_NONE !== json_last_error() ) || ! is_array( $data ) ) {
			return new WP_Error( 'openstation_fleet_invalid_response', __( 'The remote site returned an invalid JSON response.', 'fleet-for-openstation' ) );
		}
		return $data;
	}

	/**
	 * Build the remote REST URL for pretty or plain permalinks.
	 *
	 * @param array  $site Site record.
	 * @param string $path REST path.
	 * @return string
	 */
	public static function api_url( $site, $path ) {
		$path = ltrim( (string) $path, '/' );
		if ( false !== strpos( (string) $site['rest_url'], 'rest_route=' ) ) {
			$parts = explode( '?', $path, 2 );
			$url   = add_query_arg( 'rest_route', '/' . $parts[0], trailingslashit( $site['site_url'] ) );
			if ( isset( $parts[1] ) ) {
				parse_str( $parts[1], $query );
				$url = add_query_arg( $query, $url );
			}
			return $url;
		}
		return trailingslashit( (string) $site['rest_url'] ) . $path;
	}

	/**
	 * Return a URL origin.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private static function origin( $url ) {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}
		return strtolower( $parts['scheme'] ) . '://' . strtolower( $parts['host'] ) . ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' );
	}
}
