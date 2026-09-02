<?php
/**
 * Scheduling and retry policy for Fleet synchronization.
 *
 * @package FleetForOpenStation
 */

defined( 'ABSPATH' ) || exit;

/**
 * Pure timing policy kept separate from network orchestration.
 */
final class OpenStation_Fleet_Sync_Policy {
	const STATUS_INTERVAL   = 300;
	const METADATA_INTERVAL = 3600;
	const HEALTH_INTERVAL   = 21600;
	const MAX_BACKOFF       = 21600;
	const RUN_BUDGET        = 40;

	/**
	 * Whether a tier is due.
	 *
	 * @param array  $site Site record.
	 * @param string $tier Tier name.
	 * @param int    $now Current timestamp.
	 * @param bool   $force Ignore cadence and backoff.
	 * @return bool
	 */
	public static function due( $site, $tier, $now, $force = false ) {
		if ( $force ) {
			return true;
		}
		if ( ! empty( $site['next_retry'] ) && (int) $site['next_retry'] > $now ) {
			return false;
		}
		$map = array(
			'status'   => array( 'status_checked', self::STATUS_INTERVAL ),
			'metadata' => array( 'metadata_checked', self::METADATA_INTERVAL ),
			'health'   => array( 'health_checked', self::HEALTH_INTERVAL ),
		);
		if ( ! isset( $map[ $tier ] ) ) {
			return false;
		}
		return empty( $site[ $map[ $tier ][0] ] ) || (int) $site[ $map[ $tier ][0] ] <= $now - $map[ $tier ][1];
	}

	/**
	 * Record an error with exponential backoff and bounded jitter.
	 *
	 * @param array  $site Site record.
	 * @param string $message Error message.
	 * @param int    $now Current timestamp.
	 * @return array
	 */
	public static function failed( $site, $message, $now ) {
		$failures              = min( 16, max( 0, isset( $site['sync_failures'] ) ? (int) $site['sync_failures'] : 0 ) + 1 );
		$base                  = min( self::MAX_BACKOFF, self::STATUS_INTERVAL * ( 2 ** min( 7, $failures - 1 ) ) );
		$jitter                = wp_rand( 0, max( 1, (int) floor( $base / 4 ) ) );
		$site['sync_failures'] = $failures;
		$site['next_retry']    = $now + min( self::MAX_BACKOFF, $base + $jitter );
		$site['error']         = sanitize_text_field( $message );
		return $site;
	}

	/**
	 * Clear retry state after a successful status pass.
	 *
	 * @param array $site Site record.
	 * @return array
	 */
	public static function succeeded( $site ) {
		$site['sync_failures'] = 0;
		$site['next_retry']    = 0;
		$site['error']         = '';
		return $site;
	}
}
