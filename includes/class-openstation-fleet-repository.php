<?php
/**
 * Per-user, per-site persistence for Fleet.
 *
 * @package FleetForOpenStation
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stores compact connections and large search indexes in independent rows.
 */
final class OpenStation_Fleet_Repository {
	const AGGREGATE_META       = 'openstation_fleet_sites';
	const INDEX_META           = 'openstation_fleet_site_ids';
	const VERSION_META         = 'openstation_fleet_storage_version';
	const SITE_PREFIX          = 'openstation_fleet_site_';
	const SEARCH_PREFIX        = 'openstation_fleet_search_';
	const SEARCH_OPTION_PREFIX = 'openstation_fleet_search_';
	const ACTIVITY_META        = 'openstation_fleet_activity';
	const APP_ID_META          = 'openstation_fleet_app_id';
	const LEGACY_OWNER_OPTION  = 'openstation_fleet_legacy_owner';
	const STORAGE_VERSION      = 3;
	const LOCK_PREFIX          = 'openstation_fleet_lock_';
	const LOCK_TTL             = 120;

	/**
	 * Read every compact connection for operations that need the whole fleet.
	 *
	 * @param int      $user_id    WordPress user id.
	 * @param callable $normalizer Connection normalizer.
	 */
	public static function all( $user_id, $normalizer ) {
		$sites = array();
		foreach ( self::site_ids( $user_id, $normalizer ) as $site_id ) {
			$site = self::get_unmigrated( $user_id, $site_id, $normalizer );
			if ( is_array( $site ) ) {
				$sites[ $site_id ] = $site;
			}
		}
		return $sites;
	}

	/**
	 * Return the compact id index, performing storage migration once.
	 *
	 * @param int      $user_id    WordPress user id.
	 * @param callable $normalizer Connection normalizer.
	 */
	public static function site_ids( $user_id, $normalizer ) {
		$user_id = (int) $user_id;
		self::migrate( $user_id, $normalizer );
		return self::ids_unmigrated( $user_id );
	}

	/**
	 * Fetch one compact connection without loading siblings or search state.
	 *
	 * @param int      $user_id    WordPress user id.
	 * @param string   $site_id    Fleet site id.
	 * @param callable $normalizer Connection normalizer.
	 */
	public static function get( $user_id, $site_id, $normalizer ) {
		$user_id = (int) $user_id;
		$site_id = sanitize_key( $site_id );
		if ( '' === $site_id ) {
			return false;
		}
		self::migrate( $user_id, $normalizer );
		return self::get_unmigrated( $user_id, $site_id, $normalizer );
	}

	/**
	 * Read one generation-bound search blob.
	 *
	 * @param int      $user_id    WordPress user id.
	 * @param string   $site_id    Fleet site id.
	 * @param string   $generation Connection generation.
	 * @param callable $normalizer Connection normalizer.
	 */
	public static function get_search_state( $user_id, $site_id, $generation, $normalizer ) {
		$user_id    = (int) $user_id;
		$site_id    = sanitize_key( $site_id );
		$generation = sanitize_text_field( (string) $generation );
		$empty      = self::empty_search();
		$site       = self::get( $user_id, $site_id, $normalizer );
		if ( ! is_array( $site ) || '' === $generation || ! hash_equals( (string) $site['connection_generation'], $generation ) ) {
			return $empty;
		}
		$stored = get_option( self::search_option_key( $user_id, $site_id ), null );
		if ( ! is_array( $stored ) || empty( $stored['connection_generation'] ) || ! hash_equals( $generation, (string) $stored['connection_generation'] ) ) {
			return $empty;
		}
		return self::normalize_search( $stored );
	}

	/**
	 * Persist search state only while the same connection generation exists.
	 *
	 * @param int      $user_id     WordPress user id.
	 * @param string   $site_id     Fleet site id.
	 * @param string   $generation  Connection generation.
	 * @param array    $state       Incremental search state.
	 * @param array    $legacy_index Compatibility search records.
	 * @param callable $normalizer  Connection normalizer.
	 * @param int|null $revision    Expected storage revision from the read.
	 */
	public static function save_search_state( $user_id, $site_id, $generation, $state, $legacy_index, $normalizer, $revision = null ) {
		$user_id    = (int) $user_id;
		$site_id    = sanitize_key( $site_id );
		$generation = sanitize_text_field( (string) $generation );
		if ( '' === $site_id || '' === $generation ) {
			return false;
		}
		self::migrate( $user_id, $normalizer );
		$lock = self::acquire_lock( 'user', $user_id );
		if ( false === $lock ) {
			return false;
		}
		try {
			$site = self::get_unmigrated( $user_id, $site_id, $normalizer );
			if ( ! is_array( $site ) || ! hash_equals( (string) $site['connection_generation'], $generation ) ) {
				return false;
			}
			return self::write_search_unlocked(
				$user_id,
				$site_id,
				array(
					'connection_generation' => $generation,
					'search_index_state'    => is_array( $state ) ? $state : array(),
					'search_index'          => is_array( $legacy_index ) ? $legacy_index : array(),
				),
				null === $revision ? null : max( 0, (int) $revision )
			);
		} finally {
			self::release_lock( $lock );
		}
	}

	/**
	 * Save one compact connection record.
	 *
	 * @param int      $user_id       WordPress user id.
	 * @param string   $site_id       Fleet site id.
	 * @param array    $site          Incoming connection.
	 * @param array    $replace_fields Fields to replace.
	 * @param callable $normalizer    Connection normalizer.
	 * @param bool     $allow_create  Whether a new row may be created.
	 */
	public static function save( $user_id, $site_id, $site, $replace_fields, $normalizer, $allow_create = false ) {
		$user_id = (int) $user_id;
		$site_id = sanitize_key( $site_id );
		if ( '' === $site_id ) {
			return false;
		}
		self::migrate( $user_id, $normalizer );
		$lock = self::acquire_lock( 'user', $user_id );
		if ( false === $lock ) {
			return false;
		}
		try {
			$key      = self::site_key( $site_id );
			$current  = get_user_meta( $user_id, $key, true );
			$incoming = self::compact( $site, $normalizer );
			$created  = false;
			if ( ! is_array( $current ) ) {
				if ( ! $allow_create || false === add_user_meta( $user_id, $key, $incoming, true ) ) {
					return false;
				}
				delete_option( self::search_option_key( $user_id, $site_id ) );
				$created = true;
			} elseif ( $allow_create ) {
				return false;
			} else {
				$stored_current     = $current;
				$current            = self::compact( $current, $normalizer );
				$current_generation = (string) $current['connection_generation'];
				$next_generation    = (string) $incoming['connection_generation'];
				if ( '' !== $current_generation && ! hash_equals( $current_generation, $next_generation ) ) {
					return false;
				}
				$next = $current;
				foreach ( array_unique( array_map( 'sanitize_key', (array) $replace_fields ) ) as $field ) {
					if ( array_key_exists( $field, $incoming ) ) {
						$next[ $field ] = $incoming[ $field ];
					}
				}
				if ( $current !== $next && false === update_user_meta( $user_id, $key, $next, $stored_current ) ) {
					return false;
				}
			}
			$ids     = self::ids_unmigrated( $user_id );
			$ids[]   = $site_id;
			$indexed = self::write_index_unlocked( $user_id, $ids );
			if ( ! $indexed && $created ) {
				delete_user_meta( $user_id, $key, $incoming );
			}
			return $indexed;
		} finally {
			self::release_lock( $lock );
		}
	}

	/**
	 * Replace a verified credential without dropping agency data or a newer connection.
	 *
	 * @param int      $user_id WordPress user id.
	 * @param string   $site_id Site id.
	 * @param string   $generation Expected old generation.
	 * @param array    $site Verified replacement.
	 * @param callable $normalizer Record normalizer.
	 * @return bool
	 */
	public static function reauthorize( $user_id, $site_id, $generation, $site, $normalizer ) {
		$lock = self::acquire_lock( 'user', $user_id );
		if ( false === $lock ) {
			return false;
		}
		try {
			$key     = self::site_key( sanitize_key( $site_id ) );
			$current = get_user_meta( $user_id, $key, true );
			if ( ! is_array( $current ) || empty( $current['connection_generation'] ) || ! hash_equals( (string) $current['connection_generation'], (string) $generation ) ) {
				return false;
			}
			$site['agency'] = isset( $current['agency'] ) ? $current['agency'] : array();
			$site['views']  = isset( $current['views'] ) ? $current['views'] : array();
			$next           = self::compact( $site, $normalizer );
			if ( false === update_user_meta( $user_id, $key, $next, $current ) ) {
				return false;
			}
			delete_option( self::search_option_key( $user_id, $site_id ) );
			return true;
		} finally {
			self::release_lock( $lock );
		}
	}

	/**
	 * Delete a connection and its search state under the same lease.
	 *
	 * @param int    $user_id    WordPress user id.
	 * @param string $site_id    Fleet site id.
	 * @param string $generation Expected connection generation, when known.
	 */
	public static function remove( $user_id, $site_id, $generation = '' ) {
		$user_id    = (int) $user_id;
		$site_id    = sanitize_key( $site_id );
		$generation = sanitize_text_field( (string) $generation );
		$lock       = self::acquire_lock( 'user', $user_id );
		if ( false === $lock ) {
			return false;
		}
		try {
			$current = get_user_meta( $user_id, self::site_key( $site_id ), true );
			if ( '' !== $generation && ( ! is_array( $current ) || empty( $current['connection_generation'] ) || ! hash_equals( $generation, (string) $current['connection_generation'] ) ) ) {
				return false;
			}
			$deleted = delete_user_meta( $user_id, self::site_key( $site_id ) );
			delete_option( self::search_option_key( $user_id, $site_id ) );
			$ids = array_values( array_diff( self::ids_unmigrated( $user_id ), array( $site_id ) ) );
			return self::write_index_unlocked( $user_id, $ids ) && ( $deleted || ! metadata_exists( 'user', $user_id, self::site_key( $site_id ) ) );
		} finally {
			self::release_lock( $lock );
		}
	}

	/** Return users with current or legacy Fleet data for this hub blog. */
	public static function user_ids() {
		$keys = array( self::index_key(), self::aggregate_key() );
		if ( self::may_migrate_unscoped() ) {
			$keys[] = self::INDEX_META;
			$keys[] = self::AGGREGATE_META;
		}
		$ids = array();
		foreach ( array_unique( $keys ) as $key ) {
			$found = get_users(
				array(
					'fields'   => 'ids',
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- The indexed key scopes cron to Fleet users on this hub blog.
					'meta_key' => $key,
				)
			);
			$ids   = array_merge( $ids, array_map( 'intval', $found ) );
		}
		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/**
	 * Remove repository metadata after every live connection is disconnected.
	 *
	 * @param int $user_id WordPress user id.
	 */
	public static function delete_all( $user_id ) {
		$user_id = (int) $user_id;
		$lock    = self::acquire_lock( 'user', $user_id );
		if ( false === $lock ) {
			return false;
		}
		try {
			if ( ! empty( self::ids_unmigrated( $user_id ) ) ) {
				return false;
			}
			delete_user_meta( $user_id, self::index_key() );
			delete_user_meta( $user_id, self::aggregate_key() );
			delete_user_meta( $user_id, self::version_key() );
			return true;
		} finally {
			self::release_lock( $lock );
		}
	}

	/**
	 * Delete every scoped Fleet row for one user during plugin uninstall.
	 *
	 * @param int $user_id WordPress user id.
	 */
	public static function uninstall_user_data( $user_id ) {
		$user_id = (int) $user_id;
		$ids     = self::ids_unmigrated( $user_id );
		if ( self::may_migrate_unscoped() ) {
			$legacy_ids = get_user_meta( $user_id, self::INDEX_META, true );
			$ids        = array_merge( $ids, is_array( $legacy_ids ) ? $legacy_ids : array() );
		}
		foreach ( array_unique( array_map( 'sanitize_key', $ids ) ) as $site_id ) {
			delete_user_meta( $user_id, self::site_key( $site_id ) );
			delete_option( self::search_option_key( $user_id, $site_id ) );
			delete_user_meta( $user_id, self::search_meta_key( $site_id ) );
			if ( self::may_migrate_unscoped() ) {
				delete_user_meta( $user_id, self::SITE_PREFIX . $site_id );
				delete_user_meta( $user_id, self::SEARCH_PREFIX . $site_id );
			}
		}
		foreach ( array( self::index_key(), self::aggregate_key(), self::version_key(), self::activity_meta_key(), self::app_id_meta_key() ) as $key ) {
			delete_user_meta( $user_id, $key );
		}
		if ( self::may_migrate_unscoped() ) {
			foreach ( array( self::INDEX_META, self::AGGREGATE_META, self::VERSION_META, self::ACTIVITY_META, self::APP_ID_META ) as $key ) {
				delete_user_meta( $user_id, $key );
			}
		}
	}

	/** Return the blog-scoped activity key used by the facade. */
	public static function activity_meta_key() {
		return self::scoped( self::ACTIVITY_META );
	}

	/** Return the blog-scoped app-id key used by the facade. */
	public static function app_id_meta_key() {
		return self::scoped( self::APP_ID_META );
	}

	/** Return the per-blog option prefix used for lazy search blobs. */
	public static function search_option_prefix() {
		return self::SEARCH_OPTION_PREFIX;
	}

	/**
	 * Migrate 0.7 aggregates and 0.8 split rows without dropping search data.
	 *
	 * @param int      $user_id    WordPress user id.
	 * @param callable $normalizer Connection normalizer.
	 */
	private static function migrate( $user_id, $normalizer ) {
		$aggregate = get_user_meta( $user_id, self::aggregate_key(), true );
		$version   = (int) get_user_meta( $user_id, self::version_key(), true );
		$legacy    = self::may_migrate_unscoped() ? get_user_meta( $user_id, self::AGGREGATE_META, true ) : array();
		if ( self::STORAGE_VERSION <= $version && empty( $aggregate ) && empty( $legacy ) ) {
			return;
		}
		$lock = self::acquire_lock( 'user', $user_id );
		if ( false === $lock ) {
			return;
		}
		try {
			$aggregate  = get_user_meta( $user_id, self::aggregate_key(), true );
			$aggregate  = is_array( $aggregate ) ? $aggregate : array();
			$legacy     = self::may_migrate_unscoped() ? get_user_meta( $user_id, self::AGGREGATE_META, true ) : array();
			$legacy     = is_array( $legacy ) ? $legacy : array();
			$aggregate  = array_replace( $legacy, $aggregate );
			$ids        = self::ids_unmigrated( $user_id );
			$legacy_ids = self::may_migrate_unscoped() ? get_user_meta( $user_id, self::INDEX_META, true ) : array();
			$legacy_ids = is_array( $legacy_ids ) ? $legacy_ids : array();
			$ids        = array_values( array_unique( array_merge( $ids, $legacy_ids, array_map( 'sanitize_key', array_keys( $aggregate ) ) ) ) );
			$complete   = true;
			foreach ( $ids as $site_id ) {
				$site_id = sanitize_key( $site_id );
				if ( '' === $site_id ) {
					continue;
				}
				$key            = self::site_key( $site_id );
				$split          = get_user_meta( $user_id, $key, true );
				$legacy_split   = self::may_migrate_unscoped() ? get_user_meta( $user_id, self::SITE_PREFIX . $site_id, true ) : array();
				$legacy_search  = self::may_migrate_unscoped() ? get_user_meta( $user_id, self::SEARCH_PREFIX . $site_id, true ) : array();
				$scoped_search  = get_user_meta( $user_id, self::search_meta_key( $site_id ), true );
				$aggregate_site = isset( $aggregate[ $site_id ] ) && is_array( $aggregate[ $site_id ] ) ? $aggregate[ $site_id ] : array();
				$source         = is_array( $split ) ? $split : ( is_array( $legacy_split ) ? $legacy_split : $aggregate_site );
				if ( empty( $source ) ) {
					$complete = false;
					continue;
				}
				$connection    = self::compact( $source, $normalizer );
				$generation    = $connection['connection_generation'];
				$stored_search = get_option( self::search_option_key( $user_id, $site_id ), null );
				$search        = self::extract_search( array(), $generation );
				if ( is_array( $stored_search ) && ! empty( $stored_search['connection_generation'] ) && hash_equals( (string) $generation, (string) $stored_search['connection_generation'] ) ) {
					$search = self::merge_search( $search, $stored_search, $generation );
				}
				foreach ( array( $scoped_search, $split, $legacy_search, $legacy_split, $aggregate_site ) as $search_source ) {
					$search = self::merge_search( $search, is_array( $search_source ) ? $search_source : array(), $generation );
				}
				$has_search = ! empty( $search['search_index_state'] ) || ! empty( $search['search_index'] );
				if ( is_array( $split ) && $has_search && ! self::write_search_unlocked( $user_id, $site_id, $search ) ) {
					$complete = false;
					continue;
				}
				if ( ! is_array( $split ) ) {
					if ( false === add_user_meta( $user_id, $key, $connection, true ) ) {
						$complete = false;
						continue;
					}
				} elseif ( $split !== $connection && false === update_user_meta( $user_id, $key, $connection, $split ) ) {
					$complete = false;
					continue;
				}
				if ( ! is_array( $split ) && $has_search ) {
					if ( ! self::write_search_unlocked( $user_id, $site_id, $search ) ) {
						delete_user_meta( $user_id, $key, $connection );
						$complete = false;
						continue;
					}
				} elseif ( ! $has_search && is_array( $stored_search ) && ( empty( $stored_search['connection_generation'] ) || ! hash_equals( (string) $generation, (string) $stored_search['connection_generation'] ) ) ) {
					delete_option( self::search_option_key( $user_id, $site_id ) );
				}
				delete_user_meta( $user_id, self::search_meta_key( $site_id ) );
				if ( self::may_migrate_unscoped() ) {
					delete_user_meta( $user_id, self::SITE_PREFIX . $site_id );
					delete_user_meta( $user_id, self::SEARCH_PREFIX . $site_id );
				}
			}
			$complete = self::write_index_unlocked( $user_id, $ids ) && $complete;
			if ( $complete ) {
				delete_user_meta( $user_id, self::aggregate_key() );
				update_user_meta( $user_id, self::version_key(), self::STORAGE_VERSION );
				self::migrate_auxiliary_unlocked( $user_id );
				if ( self::may_migrate_unscoped() ) {
					delete_user_meta( $user_id, self::AGGREGATE_META );
					delete_user_meta( $user_id, self::INDEX_META );
					delete_user_meta( $user_id, self::VERSION_META );
				}
			}
		} finally {
			self::release_lock( $lock );
		}
	}

	/**
	 * Move old activity and app-id rows to this hub blog's namespace.
	 *
	 * @param int $user_id WordPress user id.
	 */
	private static function migrate_auxiliary_unlocked( $user_id ) {
		if ( ! self::may_migrate_unscoped() ) {
			return;
		}
		foreach ( array( self::ACTIVITY_META, self::APP_ID_META ) as $base ) {
			$scoped = self::scoped( $base );
			$legacy = get_user_meta( $user_id, $base, true );
			if ( ! metadata_exists( 'user', $user_id, $scoped ) && '' !== $legacy && array() !== $legacy ) {
				add_user_meta( $user_id, $scoped, $legacy, true );
			}
			delete_user_meta( $user_id, $base );
		}
	}

	/**
	 * Read one compact record after migration has run.
	 *
	 * @param int      $user_id    WordPress user id.
	 * @param string   $site_id    Fleet site id.
	 * @param callable $normalizer Connection normalizer.
	 */
	private static function get_unmigrated( $user_id, $site_id, $normalizer ) {
		if ( ! in_array( $site_id, self::ids_unmigrated( $user_id ), true ) ) {
			return false;
		}
		$site = get_user_meta( $user_id, self::site_key( $site_id ), true );
		return is_array( $site ) ? self::compact( $site, $normalizer ) : false;
	}

	/**
	 * Normalize a connection and strip fields owned by the search row.
	 *
	 * @param array    $site       Connection record.
	 * @param callable $normalizer Connection normalizer.
	 */
	private static function compact( $site, $normalizer ) {
		$site = call_user_func( $normalizer, is_array( $site ) ? $site : array() );
		unset( $site['search_index_state'], $site['search_index'] );
		if ( empty( $site['connection_generation'] ) ) {
			$site['connection_generation'] = wp_generate_uuid4();
		}
		$site['connection_generation'] = sanitize_text_field( (string) $site['connection_generation'] );
		return $site;
	}

	/**
	 * Extract search data before compacting an older connection row.
	 *
	 * @param array  $site       Older connection or search row.
	 * @param string $generation Connection generation.
	 */
	private static function extract_search( $site, $generation ) {
		return array(
			'connection_generation' => sanitize_text_field( (string) $generation ),
			'search_index_state'    => isset( $site['search_index_state'] ) && is_array( $site['search_index_state'] ) ? $site['search_index_state'] : array(),
			'search_index'          => isset( $site['search_index'] ) && is_array( $site['search_index'] ) ? $site['search_index'] : array(),
		);
	}

	/**
	 * Fill missing search fields from another legacy storage source.
	 *
	 * @param array  $search     Current merged search data.
	 * @param array  $source     Another legacy source.
	 * @param string $generation Connection generation.
	 */
	private static function merge_search( $search, $source, $generation ) {
		if ( ! empty( $source['connection_generation'] ) && ! hash_equals( (string) $generation, (string) $source['connection_generation'] ) ) {
			return $search;
		}
		$candidate = self::extract_search( $source, $generation );
		foreach ( array( 'search_index_state', 'search_index' ) as $field ) {
			if ( empty( $search[ $field ] ) && ! empty( $candidate[ $field ] ) ) {
				$search[ $field ] = $candidate[ $field ];
			}
		}
		return $search;
	}

	/** Return an empty public search payload. */
	private static function empty_search() {
		return array(
			'search_index_state' => array(),
			'search_index'       => array(),
			'storage_revision'   => 0,
		);
	}

	/**
	 * Normalize a stored search row without exposing its generation marker.
	 *
	 * @param array $stored Stored search row.
	 */
	private static function normalize_search( $stored ) {
		return array(
			'search_index_state' => isset( $stored['search_index_state'] ) && is_array( $stored['search_index_state'] ) ? $stored['search_index_state'] : array(),
			'search_index'       => isset( $stored['search_index'] ) && is_array( $stored['search_index'] ) ? $stored['search_index'] : array(),
			'storage_revision'   => isset( $stored['storage_revision'] ) ? max( 0, (int) $stored['storage_revision'] ) : 0,
		);
	}

	/**
	 * Store a complete search row while the user mutation lock is held.
	 *
	 * @param int      $user_id WordPress user id.
	 * @param string   $site_id Fleet site id.
	 * @param array    $search            Search row.
	 * @param int|null $expected_revision Expected revision for compare-and-swap.
	 */
	private static function write_search_unlocked( $user_id, $site_id, $search, $expected_revision = null ) {
		$key              = self::search_option_key( $user_id, $site_id );
		$current          = get_option( $key, null );
		$current          = is_array( $current ) ? $current : array();
		$current_revision = isset( $current['storage_revision'] ) ? max( 0, (int) $current['storage_revision'] ) : 0;
		$next             = array(
			'connection_generation' => sanitize_text_field( isset( $search['connection_generation'] ) ? (string) $search['connection_generation'] : '' ),
			'search_index_state'    => isset( $search['search_index_state'] ) && is_array( $search['search_index_state'] ) ? $search['search_index_state'] : array(),
			'search_index'          => isset( $search['search_index'] ) && is_array( $search['search_index'] ) ? $search['search_index'] : array(),
			'storage_revision'      => $current_revision,
		);
		if ( $current === $next ) {
			return true;
		}
		if ( null !== $expected_revision && $current_revision !== (int) $expected_revision ) {
			return false;
		}
		$next['storage_revision'] = $current_revision + 1;
		return empty( $current )
			? add_option( $key, $next, '', false )
			: update_option( $key, $next, false );
	}

	/**
	 * Read a normalized id index without triggering migration.
	 *
	 * @param int $user_id WordPress user id.
	 */
	private static function ids_unmigrated( $user_id ) {
		$ids = get_user_meta( (int) $user_id, self::index_key(), true );
		return is_array( $ids ) ? array_values( array_unique( array_filter( array_map( 'sanitize_key', $ids ) ) ) ) : array();
	}

	/**
	 * Persist a compact sorted index while the caller owns the user lock.
	 *
	 * @param int   $user_id WordPress user id.
	 * @param array $ids     Fleet site ids.
	 */
	private static function write_index_unlocked( $user_id, $ids ) {
		$ids = array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) $ids ) ) ) );
		sort( $ids, SORT_STRING );
		$current = self::ids_unmigrated( $user_id );
		$merged  = array_values( array_unique( array_merge( $current, $ids ) ) );
		$merged  = array_values(
			array_filter(
				$merged,
				static function ( $id ) use ( $user_id, $ids ) {
					return in_array( $id, $ids, true ) || metadata_exists( 'user', $user_id, self::site_key( $id ) );
				}
			)
		);
		sort( $merged, SORT_STRING );
		if ( $merged === $current ) {
			return true;
		}
		$key = self::index_key();
		return metadata_exists( 'user', $user_id, $key )
			? false !== update_user_meta( $user_id, $key, $merged, get_user_meta( $user_id, $key, true ) )
			: false !== add_user_meta( $user_id, $key, $merged, true );
	}

	/**
	 * Acquire a short Core option lease for one repository mutation scope.
	 *
	 * @param string $scope   Mutation scope.
	 * @param int    $user_id WordPress user id.
	 * @param string $site_id Optional Fleet site id.
	 */
	public static function acquire_lock( $scope, $user_id, $site_id = '' ) {
		$key = self::LOCK_PREFIX . substr( hash( 'sha256', sanitize_key( $scope ) . ':' . (int) $user_id . ':' . sanitize_key( $site_id ) ), 0, 40 );
		for ( $attempt = 0; $attempt < 5; ++$attempt ) {
			$token = wp_generate_uuid4();
			$value = array(
				'token'   => $token,
				'expires' => time() + self::LOCK_TTL,
			);
			if ( add_option( $key, $value, '', false ) ) {
				$owned = get_option( $key, array() );
				if ( is_array( $owned ) && isset( $owned['token'] ) && hash_equals( $token, (string) $owned['token'] ) ) {
					return array(
						'key'   => $key,
						'token' => $token,
					);
				}
			}
			$current = get_option( $key, array() );
			if ( is_array( $current ) && ! empty( $current['expires'] ) && (int) $current['expires'] <= time() && self::delete_lock_if_matches( $key, $current ) ) {
				continue;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.sleep_usleep -- A tiny bounded wait avoids failing ordinary simultaneous window saves.
			usleep( 20000 );
		}
		return false;
	}

	/**
	 * Release a repository lease only when this request still owns it.
	 *
	 * @param array $lock Lock token returned by acquire_lock().
	 */
	public static function release_lock( $lock ) {
		if ( empty( $lock['key'] ) || empty( $lock['token'] ) ) {
			return;
		}
		$current = get_option( $lock['key'], array() );
		if ( is_array( $current ) && isset( $current['token'] ) && hash_equals( (string) $current['token'], (string) $lock['token'] ) ) {
			self::delete_lock_if_matches( $lock['key'], $current );
		}
	}

	/**
	 * Atomically delete an option lease only if its complete value matches.
	 *
	 * @param string $key   Option key.
	 * @param array  $value Expected option value.
	 */
	private static function delete_lock_if_matches( $key, $value ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Core cannot atomically compare a lease token before deletion.
		$deleted = $wpdb->delete(
			$wpdb->options,
			array(
				'option_name'  => $key,
				'option_value' => maybe_serialize( $value ),
			),
			array( '%s', '%s' )
		);
		if ( $deleted ) {
			wp_cache_delete( $key, 'options' );
			return true;
		}
		return false;
	}

	/**
	 * Whether fixed legacy usermeta may be claimed by this hub blog.
	 *
	 * On multisite, the first Fleet hub to access the legacy layout atomically
	 * adopts it. This avoids assuming that the network main site was the hub.
	 */
	private static function may_migrate_unscoped() {
		if ( ! is_multisite() ) {
			return true;
		}
		$blog_id      = self::blog_id();
		$main_site_id = (int) get_main_site_id();
		$switched     = $main_site_id !== $blog_id;
		if ( $switched ) {
			switch_to_blog( $main_site_id );
		}
		try {
			$owner = (int) get_option( self::LEGACY_OWNER_OPTION, 0 );
			if ( $owner > 0 ) {
				return $blog_id === $owner;
			}
			if ( add_option( self::LEGACY_OWNER_OPTION, $blog_id, '', false ) ) {
				return true;
			}
			return (int) get_option( self::LEGACY_OWNER_OPTION, 0 ) === $blog_id;
		} finally {
			if ( $switched ) {
				restore_current_blog();
			}
		}
	}

	/**
	 * Add the current hub blog identity to a usermeta key.
	 *
	 * @param string $base Base usermeta key.
	 */
	private static function scoped( $base ) {
		return $base . '_blog_' . self::blog_id();
	}

	/** Return the blog-scoped aggregate migration key. */
	private static function aggregate_key() {
		return self::scoped( self::AGGREGATE_META );
	}

	/** Return the blog-scoped compact id index key. */
	private static function index_key() {
		return self::scoped( self::INDEX_META );
	}

	/** Return the blog-scoped storage version key. */
	private static function version_key() {
		return self::scoped( self::VERSION_META );
	}

	/**
	 * Return one blog-scoped compact connection key.
	 *
	 * @param string $site_id Fleet site id.
	 */
	private static function site_key( $site_id ) {
		return rtrim( self::SITE_PREFIX, '_' ) . '_blog_' . self::blog_id() . '_' . sanitize_key( $site_id );
	}

	/**
	 * Return one blog-scoped search key.
	 *
	 * @param string $site_id Fleet site id.
	 */
	private static function search_meta_key( $site_id ) {
		return rtrim( self::SEARCH_PREFIX, '_' ) . '_blog_' . self::blog_id() . '_' . sanitize_key( $site_id );
	}

	/**
	 * Return one per-blog, per-user lazy search option key.
	 *
	 * @param int    $user_id WordPress user id.
	 * @param string $site_id Fleet site id.
	 */
	private static function search_option_key( $user_id, $site_id ) {
		return self::SEARCH_OPTION_PREFIX . (int) $user_id . '_' . substr( hash( 'sha256', sanitize_key( $site_id ) ), 0, 40 );
	}

	/** Return the positive current hub blog id used in scoped keys. */
	private static function blog_id() {
		$blog_id = (int) get_current_blog_id();
		return max( 1, $blog_id );
	}
}
