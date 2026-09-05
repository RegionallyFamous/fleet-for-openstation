<?php
/** Opt-in, encrypted crash checkpoints on the hub. Never a remote autosave.
 *
 * @package FleetForOpenStation
 */

defined( 'ABSPATH' ) || exit;

/** Isolated, expiring checkpoint storage using Core transients and user metadata. */
final class OpenStation_Fleet_Recovery {
	const INDEX = 'openstation_fleet_recovery_blog_';
	const LIMIT = 10;

	/**
	 * Read this user's bounded checkpoint index, dropping expired entries.
	 *
	 * @return array
	 */
	private static function index() {
		$index = get_user_meta( get_current_user_id(), self::INDEX . get_current_blog_id(), true );
		return is_array( $index ) ? array_filter(
			$index,
			static function ( $entry ) {
				return is_array( $entry ) && (int) ( $entry['expires'] ?? 0 ) > time();
			}
		) : array();
	}

	/**
	 * List encrypted checkpoints only for the live connection generation.
	 *
	 * @param array $site Live connection.
	 * @return array
	 */
	public static function listing( $site ) {
		$result = array();
		foreach ( self::index() as $key => $entry ) {
			$data = self::read( $site, $key );
			if ( ! is_wp_error( $data ) ) {
				$result[ $key ] = array(
					'title'      => $data['editor']['title'],
					'saved'      => $entry['saved'],
					'type'       => $data['editor']['content_type'],
					'content_id' => $data['editor']['content_id'],
				);
			}
		}
		return $result;
	}

	/**
	 * Load a checkpoint, enforcing current user and connection isolation.
	 *
	 * @param array  $site Live connection.
	 * @param string $key Checkpoint key.
	 * @return array|WP_Error
	 */
	public static function read( $site, $key ) {
		$entry  = self::index()[ $key ] ?? null;
		$sealed = $entry ? get_transient( $key ) : false;
		$plain  = is_string( $sealed ) ? OpenStation_Fleet_Crypto::open( $sealed ) : false;
		$data   = is_string( $plain ) ? json_decode( $plain, true ) : null;
		if ( ! is_array( $data ) || (int) ( $data['user'] ?? 0 ) !== get_current_user_id() || ( $data['site'] ?? '' ) !== $site['site_url'] || ( $data['generation'] ?? '' ) !== $site['connection_generation'] ) {
			return new WP_Error( 'fleet_recovery_missing', __( 'This checkpoint expired or belongs to a different connection. It cannot be restored.', 'fleet-for-openstation' ) );
		}
		return $data;
	}

	/**
	 * Save bounded source after explicit editor opt-in. Sequence prevents stale writes.
	 *
	 * @param array $site Live connection.
	 * @param array $values Editor source and checkpoint sequence.
	 * @return array|WP_Error
	 */
	public static function save( $site, $values ) {
		if ( ! in_array( $values['enabled'] ?? false, array( true, 'true' ), true ) || ! is_string( $values['request_id'] ?? null ) || ! wp_is_uuid( $values['request_id'] ) || ! OpenStation_Fleet_Content::valid_type( $values['content_type'] ?? '' ) ) {
			return new WP_Error( 'fleet_recovery_invalid', __( 'Enable recovery in an open editor first.', 'fleet-for-openstation' ) );
		}
		$editor = array();
		foreach ( array_merge( array( 'title', 'content', 'excerpt', 'slug', 'status', 'date_gmt', 'content_type', 'fingerprint', 'request_id', 'original_status' ), OpenStation_Fleet_Content::PUBLISHING ) as $field ) {
			if ( isset( $values[ $field ] ) ) {
				if ( ! is_string( $values[ $field ] ) || strlen( $values[ $field ] ) > 200000 ) {
					return new WP_Error( 'fleet_recovery_size', __( 'Recovery supports up to 200 KB of source per draft.', 'fleet-for-openstation' ) );
				}
				$editor[ $field ] = $values[ $field ];
			}
		}
		$editor['content_id'] = absint( $values['content_id'] ?? 0 );
		$editor['title']      = $editor['title'] ?? '';
		$sequence             = max( 0, (int) ( $values['sequence'] ?? 0 ) );
		$key                  = 'fleet_recovery_' . get_current_blog_id() . '_' . get_current_user_id() . '_' . hash( 'sha256', $site['site_url'] . $site['connection_generation'] . $editor['content_type'] . $editor['content_id'] . $editor['request_id'] );
		$lock_key             = 'fleet_recovery_index';
		$lock                 = OpenStation_Fleet_Repository::acquire_lock( $lock_key, get_current_user_id() );
		if ( false === $lock ) {
			return new WP_Error( 'fleet_recovery_busy', __( 'Recovery is busy. The next heartbeat will retry.', 'fleet-for-openstation' ) );
		}
		try {
			if ( get_transient( $key . '_deleted' ) ) {
				return new WP_Error( 'fleet_recovery_deleted', __( 'This draft checkpoint was deleted or saved. Reopen the editor before enabling recovery again.', 'fleet-for-openstation' ) );
			}
			$existing = self::read( $site, $key );
			if ( ! is_wp_error( $existing ) && $existing['sequence'] >= $sequence ) {
				return array(
					'key'   => $key,
					'saved' => $existing['saved'],
				);
			}
			$data = array(
				'user'       => get_current_user_id(),
				'site'       => $site['site_url'],
				'generation' => $site['connection_generation'],
				'editor'     => $editor,
				'sequence'   => $sequence,
				'saved'      => time(),
			);
			$json = wp_json_encode( $data );
			if ( strlen( (string) $json ) > 200000 ) {
				return new WP_Error( 'fleet_recovery_size', __( 'Recovery supports up to 200 KB of source per draft. Save to WordPress or copy the source.', 'fleet-for-openstation' ) );
			}
			$sealed = OpenStation_Fleet_Crypto::seal( $json );
			if ( is_wp_error( $sealed ) || ! set_transient( $key, $sealed, 7 * DAY_IN_SECONDS ) ) {
				return new WP_Error( 'fleet_recovery_storage', __( 'Recovery could not save a checkpoint. Keep this window open and save or copy your source.', 'fleet-for-openstation' ) );
			}
			$index = self::index();
			unset( $index[ $key ] );
			$index[ $key ] = array(
				'saved'   => time(),
				'expires' => time() + 7 * DAY_IN_SECONDS,
			);
			$excess        = count( $index ) - self::LIMIT;
			while ( $excess-- > 0 ) {
				$old = array_key_first( $index );
				delete_transient( $old );
				unset( $index[ $old ] );
			}
			if ( ! update_user_meta( get_current_user_id(), self::INDEX . get_current_blog_id(), $index ) && get_user_meta( get_current_user_id(), self::INDEX . get_current_blog_id(), true ) !== $index ) {
				delete_transient( $key );
				return new WP_Error( 'fleet_recovery_storage', __( 'The recovery index could not be saved.', 'fleet-for-openstation' ) );
			}
			return array(
				'key'   => $key,
				'saved' => time(),
			);
		} finally {
			OpenStation_Fleet_Repository::release_lock( $lock );
		}
	}

	/**
	 * Remove only a checkpoint reachable through this user's private index.
	 *
	 * @param string $key Checkpoint key.
	 * @return bool
	 */
	public static function remove( $key ) {
		if ( ! is_string( $key ) || ! preg_match( '/\Afleet_recovery_' . get_current_blog_id() . '_' . get_current_user_id() . '_[a-f0-9]{64}\z/', $key ) ) {
			return false;
		}
		$lock = OpenStation_Fleet_Repository::acquire_lock( 'fleet_recovery_index', get_current_user_id() );
		if ( false === $lock ) {
			return false; }
		try {
			$index = self::index();
			if ( ! get_transient( $key . '_deleted' ) && ! set_transient( $key . '_deleted', true, 7 * DAY_IN_SECONDS ) ) {
				return false; }
			delete_transient( $key );
			unset( $index[ $key ] );
			update_user_meta( get_current_user_id(), self::INDEX . get_current_blog_id(), $index );
			return true;
		} finally {
			OpenStation_Fleet_Repository::release_lock( $lock );
		}
	}

	/**
	 * Retire a saved editor, including a heartbeat that has not arrived yet.
	 *
	 * @param array $site Connection.
	 * @param array $values Successfully saved editor values.
	 * @return bool
	 */
	public static function complete( $site, $values ) {
		if ( ! is_string( $values['request_id'] ?? null ) || ! wp_is_uuid( $values['request_id'] ) || ! OpenStation_Fleet_Content::valid_type( $values['content_type'] ?? '' ) ) {
			return true;
		}
		$key = 'fleet_recovery_' . get_current_blog_id() . '_' . get_current_user_id() . '_' . hash( 'sha256', $site['site_url'] . $site['connection_generation'] . $values['content_type'] . absint( $values['content_id'] ?? 0 ) . $values['request_id'] );
		return self::remove( $key );
	}

	/** Erase encrypted checkpoints without decrypting or impersonating their owner.
	 *
	 * @param int $user_id Owner id.
	 */
	public static function erase( $user_id ) {
		$index = get_user_meta( $user_id, self::INDEX . get_current_blog_id(), true );
		foreach ( is_array( $index ) ? $index : array() as $key => $entry ) {
			if ( is_string( $key ) && 0 === strpos( $key, 'fleet_recovery_' . get_current_blog_id() . '_' . (int) $user_id . '_' ) ) {
				delete_transient( $key );
			}
		}
		delete_user_meta( $user_id, self::INDEX . get_current_blog_id() );
	}

	/** Export source through WordPress's verified personal-data export workflow.
	 *
	 * @param int $user_id Export owner.
	 * @return array
	 */
	public static function export( $user_id ) {
		$index  = get_user_meta( $user_id, self::INDEX . get_current_blog_id(), true );
		$result = array();
		foreach ( is_array( $index ) ? array_slice( $index, 0, self::LIMIT, true ) : array() as $key => $entry ) {
			$sealed = get_transient( $key );
			$plain  = is_string( $sealed ) ? OpenStation_Fleet_Crypto::open( $sealed ) : null;
			$data   = is_string( $plain ) ? json_decode( $plain, true ) : null;
			if ( ! is_array( $data ) || (int) ( $data['user'] ?? 0 ) !== (int) $user_id || (int) ( $entry['expires'] ?? 0 ) <= time() ) {
				continue; }
			$result[] = array(
				'group_id'    => 'fleet-recovery',
				'group_label' => __( 'Fleet draft recovery', 'fleet-for-openstation' ),
				'item_id'     => $key,
				'data'        => array(
					array(
						'name'  => __( 'Draft checkpoint', 'fleet-for-openstation' ),
						'value' => wp_json_encode( $data['editor'] ),
					),
				),
			);
		}
		return $result;
	}
}
