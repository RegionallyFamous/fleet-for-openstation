<?php
/** Explicit hub-side delegation. No impersonation and no copied credentials.
 *
 * @package FleetForOpenStation
 */

defined( 'ABSPATH' ) || exit;

/** Every delegated access is re-resolved against the owner's live grant. */
final class OpenStation_Fleet_Access {
	const INDEX = 'openstation_fleet_shared_blog_';

	/** Current user's bounded list of shared connection references.
	 *
	 * @return array
	 */
	public static function ids() {
		$ids = get_user_meta( get_current_user_id(), self::INDEX . get_current_blog_id(), true );
		return is_array( $ids ) ? array_keys( array_slice( $ids, 0, 200, true ) ) : array();
	}

	/**
	 * Resolve a shared alias and enforce membership, owner capability and generation.
	 *
	 * @param string $id Shared connection alias.
	 * @return array|null
	 */
	public static function resolve( $id ) {
		if ( ! current_user_can( 'read' ) || ! is_string( $id ) || ! preg_match( '/\Ashared_([1-9][0-9]*)_([a-f0-9]{16})\z/', $id, $match ) || ! user_can( (int) $match[1], 'manage_options' ) ) {
			return null;
		}
		$site  = OpenStation_Fleet_Repository::get( (int) $match[1], $match[2], array( 'OpenStation_Fleet', 'normalize_site_record' ) );
		$grant = is_array( $site ) ? ( $site['sharing'][ get_current_user_id() ] ?? null ) : null;
		if ( ! is_array( $grant ) || ( $grant['generation'] ?? '' ) !== $site['connection_generation'] || ! in_array( $grant['role'] ?? '', array( 'reader', 'editor', 'operator' ), true ) ) {
			return null;
		}
		$site['_fleet_owner']  = (int) $match[1];
		$site['_fleet_source'] = $match[2];
		$site['_fleet_alias']  = $id;
		$site['_fleet_role']   = $grant['role'];
		// Owner-private agency notes, saved views and people indexes are not delegated.
		$site['agency'] = OpenStation_Fleet::normalize_site_record( array() )['agency'];
		$site['views']  = array();
		unset( $site['sharing'] );
		return $site;
	}

	/**
	 * Enforce native workflow roles. Explorer and credential management stay private.
	 *
	 * @param array  $site Resolved connection.
	 * @param string $action Read section or mutation name.
	 * @param bool   $write Whether this mutates state.
	 * @return bool
	 */
	public static function allowed( $site, $action, $write = false ) {
		if ( ! is_array( $site ) ) {
			return false; }
		if ( empty( $site['_fleet_alias'] ) ) {
			return current_user_can( 'manage_options' ); }
		$live = self::resolve( $site['_fleet_alias'] );
		if ( ! $live || $live['connection_generation'] !== $site['connection_generation'] ) {
			return false; }
		$role = $live['_fleet_role'];
		if ( ! $write ) {
			return in_array( $action, array( 'overview', 'content', 'content-types', 'media', 'comments' ), true ) || ( 'operator' === $role && in_array( $action, array( 'settings', 'plugins', 'users', 'design' ), true ) );
		}
		if ( 'reader' === $role ) {
			return false; }
		return in_array( $action, array( 'content', 'trash-content', 'media', 'upload-media', 'comment', 'reply-comment', 'comment-batch', 'recovery' ), true ) || ( 'operator' === $role && in_array( $action, array( 'settings', 'plugin', 'install-plugin', 'create-user', 'user', 'refresh' ), true ) );
	}

	/**
	 * Owner-only share/revoke; the recipient index is a hint, never authority.
	 *
	 * @param string $id Owned connection id.
	 * @param int    $recipient Existing hub account.
	 * @param string $role Role, or revoke.
	 * @return array|WP_Error
	 */
	public static function update( $id, $recipient, $role ) {
		$owner     = get_current_user_id();
		$recipient = (int) $recipient;
		$site      = OpenStation_Fleet_Repository::get( $owner, $id, array( 'OpenStation_Fleet', 'normalize_site_record' ) );
		if ( ! current_user_can( 'manage_options' ) || ! $site || $recipient < 1 || $recipient === $owner || ( 'revoke' !== $role && ( ! user_can( $recipient, 'read' ) || ( is_multisite() && ! is_user_member_of_blog( $recipient ) ) ) ) || ! in_array( $role, array( 'reader', 'editor', 'operator', 'revoke' ), true ) ) {
			return new WP_Error( 'fleet_share_forbidden', __( 'Only the connection owner can share with an existing account on this hub.', 'fleet-for-openstation' ) );
		}
		$lock = OpenStation_Fleet_Repository::acquire_lock( 'sharing', $owner, $id );
		if ( false === $lock ) {
			return new WP_Error( 'fleet_share_busy', __( 'Sharing is being changed. Try again.', 'fleet-for-openstation' ) ); }
		$index_lock = OpenStation_Fleet_Repository::acquire_lock( 'sharing_index', $recipient );
		if ( false === $index_lock ) {
			OpenStation_Fleet_Repository::release_lock( $lock );
			return new WP_Error( 'fleet_share_busy', __( 'This account’s shared sites are being changed. Try again.', 'fleet-for-openstation' ) );
		}
		try {
			$site = OpenStation_Fleet_Repository::get( $owner, $id, array( 'OpenStation_Fleet', 'normalize_site_record' ) );
			if ( ! $site ) {
				return new WP_Error( 'fleet_share_missing', __( 'The connection was removed.', 'fleet-for-openstation' ) ); }
			$sharing = is_array( $site['sharing'] ?? null ) ? $site['sharing'] : array();
			$key     = self::INDEX . get_current_blog_id();
			$alias   = 'shared_' . $owner . '_' . $id;
			$index   = get_user_meta( $recipient, $key, true );
			if ( 'revoke' !== $role && is_array( $index ) && ! isset( $index[ $alias ] ) && count( $index ) >= 200 ) {
				return new WP_Error( 'fleet_share_limit', __( 'This account already has 200 shared sites. Revoke one before adding another.', 'fleet-for-openstation' ) );
			}
			if ( 'revoke' === $role ) {
				unset( $sharing[ $recipient ] );
			} else {
				$sharing[ $recipient ] = array(
					'role'       => $role,
					'generation' => $site['connection_generation'],
				);
				if ( count( $sharing ) > 100 ) {
					return new WP_Error( 'fleet_share_limit', __( 'A connection can be shared with up to 100 hub accounts.', 'fleet-for-openstation' ) ); }
			}
			$site['sharing'] = $sharing;
			if ( ! OpenStation_Fleet_Repository::save( $owner, $id, $site, array( 'sharing' ), array( 'OpenStation_Fleet', 'normalize_site_record' ) ) ) {
				return new WP_Error( 'fleet_share_storage', __( 'Sharing could not be saved.', 'fleet-for-openstation' ) );
			}
			for ( $attempt = 0; $attempt < 5; ++$attempt ) {
				$previous = get_user_meta( $recipient, $key, true );
				$index    = is_array( $previous ) ? $previous : array();
				if ( 'revoke' === $role ) {
					unset( $index[ $alias ] );
				} else {
					$index[ $alias ] = true; }
				if ( count( $index ) > 200 ) {
					return new WP_Error( 'fleet_share_limit', __( 'This account already has 200 shared sites. Revoke one before adding another.', 'fleet-for-openstation' ) ); }
				if ( $index === $previous || update_user_meta( $recipient, $key, $index, $previous ) ) {
					return array( 'role' => $role ); }
				wp_cache_delete( $recipient, 'user_meta' );
			}
			return new WP_Error( 'fleet_share_index', __( 'The grant changed, but its launcher index could not be saved. Retry the same sharing change.', 'fleet-for-openstation' ) );
		} finally {
			OpenStation_Fleet_Repository::release_lock( $index_lock );
			OpenStation_Fleet_Repository::release_lock( $lock );
		}
	}

	/** Owner-only member list, without credentials.
	 *
	 * @param string $id Connection id.
	 * @return array
	 */
	public static function members( $id ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return array(); }
		$site   = OpenStation_Fleet_Repository::get( get_current_user_id(), $id, array( 'OpenStation_Fleet', 'normalize_site_record' ) );
		$result = array();
		foreach ( $site['sharing'] ?? array() as $user_id => $grant ) {
			$user = get_userdata( (int) $user_id );
			if ( $user && $grant['generation'] === $site['connection_generation'] ) {
				$result[] = array(
					'login' => $user->user_login,
					'name'  => $user->display_name,
					'role'  => $grant['role'],
				);
			}
		}
		return $result;
	}

	/** Clean up grants on Core account deletion.
	 *
	 * @param int $user_id Deleted recipient.
	 */
	public static function delete_user( $user_id ) {
		self::erase( $user_id );
	}

	/** Remove a recipient's grants through Core account deletion/privacy erasure.
	 *
	 * @param int $user_id Recipient id, not an impersonated current user.
	 * @return bool Whether every grant was removed.
	 */
	public static function erase( $user_id ) {
		$complete = true;
		foreach ( OpenStation_Fleet_Repository::user_ids() as $owner ) {
			foreach ( OpenStation_Fleet_Repository::all( $owner, array( 'OpenStation_Fleet', 'normalize_site_record' ) ) as $id => $site ) {
				if ( isset( $site['sharing'][ $user_id ] ) ) {
					$lock = OpenStation_Fleet_Repository::acquire_lock( 'sharing', $owner, $id );
					if ( false === $lock ) {
						$complete = false;
						continue;
					}
					try {
						$site = OpenStation_Fleet_Repository::get( $owner, $id, array( 'OpenStation_Fleet', 'normalize_site_record' ) );
						if ( $site ) {
							unset( $site['sharing'][ $user_id ] );
							$complete = OpenStation_Fleet_Repository::save( $owner, $id, $site, array( 'sharing' ), array( 'OpenStation_Fleet', 'normalize_site_record' ) ) && $complete;
						}
					} finally {
						OpenStation_Fleet_Repository::release_lock( $lock );
					}
				}
			}
		}
		if ( $complete ) {
			delete_user_meta( $user_id, self::INDEX . get_current_blog_id() );
		}
		return $complete;
	}

	/** Export this recipient's sharing references without opening any credential.
	 *
	 * @param int $user_id Export owner.
	 * @return array
	 */
	public static function export( $user_id ) {
		$index = get_user_meta( $user_id, self::INDEX . get_current_blog_id(), true );
		return is_array( $index ) && $index ? array(
			array(
				'group_id'    => 'fleet-team',
				'group_label' => __( 'Fleet team access', 'fleet-for-openstation' ),
				'item_id'     => 'fleet-team-' . (int) $user_id,
				'data'        => array(
					array(
						'name'  => __( 'Shared connection references', 'fleet-for-openstation' ),
						'value' => implode( ', ', array_keys( $index ) ),
					),
				),
			),
		) : array();
	}
}
