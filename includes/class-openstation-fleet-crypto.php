<?php
/**
 * Credential encryption for Fleet connections.
 *
 * @package FleetForOpenStation
 */

defined( 'ABSPATH' ) || exit;

/**
 * Seals Application Passwords using WordPress's authentication salt.
 */
final class OpenStation_Fleet_Crypto {
	/**
	 * Encrypt a secret for storage.
	 *
	 * @param string $secret Plaintext secret.
	 * @return string|WP_Error
	 */
	public static function seal( $secret ) {
		self::load_sodium();
		if ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
			return new WP_Error( 'openstation_fleet_no_crypto', __( 'This server cannot securely store the remote credential.', 'fleet-for-openstation' ) );
		}

		try {
			$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		} catch ( Exception $exception ) {
			return new WP_Error( 'openstation_fleet_no_randomness', __( 'This server cannot securely generate credential encryption keys.', 'fleet-for-openstation' ) );
		}
		$cipher = sodium_crypto_secretbox( (string) $secret, $nonce, self::key() );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary ciphertext needs a text-safe storage encoding.
		return 'v1:' . base64_encode( $nonce . $cipher );
	}

	/**
	 * Decrypt a stored secret.
	 *
	 * @param string $sealed Encrypted payload.
	 * @return string|WP_Error
	 */
	public static function open( $sealed ) {
		self::load_sodium();
		if ( ! function_exists( 'sodium_crypto_secretbox_open' ) || 0 !== strpos( (string) $sealed, 'v1:' ) ) {
			return self::invalid_secret();
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decode the plugin's documented ciphertext envelope.
		$payload = base64_decode( substr( $sealed, 3 ), true );
		if ( false === $payload || strlen( $payload ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return self::invalid_secret();
		}

		$nonce  = substr( $payload, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = substr( $payload, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$secret = sodium_crypto_secretbox_open( $cipher, $nonce, self::key() );
		return false === $secret ? self::invalid_secret() : $secret;
	}

	/**
	 * Load WordPress's sodium compatibility layer when necessary.
	 */
	private static function load_sodium() {
		if ( function_exists( 'sodium_crypto_secretbox' ) ) {
			return;
		}
		$compat = ABSPATH . WPINC . '/sodium_compat/autoload.php';
		if ( file_exists( $compat ) ) {
			require_once $compat;
		}
	}

	/**
	 * Derive a site-specific binary key without persisting another secret.
	 *
	 * @return string
	 */
	private static function key() {
		return hash_hmac( 'sha256', 'fleet-for-openstation', wp_salt( 'auth' ), true );
	}

	/**
	 * Return the deliberately non-specific decryption failure.
	 *
	 * @return WP_Error
	 */
	private static function invalid_secret() {
		return new WP_Error( 'openstation_fleet_invalid_secret', __( 'The stored credential cannot be read. Reconnect this site.', 'fleet-for-openstation' ) );
	}
}
