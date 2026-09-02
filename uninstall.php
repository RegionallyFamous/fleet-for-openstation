<?php
/**
 * Remove local Fleet metadata. Remote Application Passwords must be revoked
 * with Disconnect before uninstalling.
 *
 * @package FleetForOpenStation
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/class-openstation-fleet-repository.php';

$openstation_fleet_blog_ids = is_multisite()
	? get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	)
	: array( get_current_blog_id() );
$openstation_fleet_switched = false;

foreach ( $openstation_fleet_blog_ids as $openstation_fleet_blog_id ) {
	if ( is_multisite() ) {
		switch_to_blog( (int) $openstation_fleet_blog_id );
		$openstation_fleet_switched = true;
	}

	$openstation_fleet_user_ids = OpenStation_Fleet_Repository::user_ids();
	foreach ( array( OpenStation_Fleet_Repository::activity_meta_key(), OpenStation_Fleet_Repository::app_id_meta_key() ) as $openstation_fleet_meta_key ) {
		$openstation_fleet_user_ids = array_merge(
			$openstation_fleet_user_ids,
			get_users(
				array(
					'fields'   => 'ids',
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Uninstall must find every Fleet owner for this hub blog.
					'meta_key' => $openstation_fleet_meta_key,
				)
			)
		);
	}
	foreach ( array_unique( array_map( 'intval', $openstation_fleet_user_ids ) ) as $openstation_fleet_user_id ) {
		OpenStation_Fleet_Repository::uninstall_user_data( $openstation_fleet_user_id );
	}

	wp_clear_scheduled_hook( 'openstation_fleet_scheduled_check' );
	delete_option( 'openstation_fleet_scheduled_check_lock' );
	delete_option( 'openstation_fleet_scheduled_check_cursor' );

	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall must enumerate dynamic Fleet keys before deleting them through Core APIs.
	$openstation_fleet_meta_keys = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT DISTINCT meta_key FROM {$wpdb->usermeta} WHERE meta_key LIKE %s OR meta_key LIKE %s",
			$wpdb->esc_like( 'openstation_fleet_site_blog_' . get_current_blog_id() . '_' ) . '%',
			$wpdb->esc_like( 'openstation_fleet_search_blog_' . get_current_blog_id() . '_' ) . '%'
		)
	);
	foreach ( $openstation_fleet_meta_keys as $openstation_fleet_meta_key ) {
		delete_metadata( 'user', 0, $openstation_fleet_meta_key, '', true );
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall must enumerate expired hashed lock options before removing them through Core.
	$openstation_fleet_runtime_options = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( 'openstation_fleet_lock_' ) . '%',
			$wpdb->esc_like( OpenStation_Fleet_Repository::search_option_prefix() ) . '%'
		)
	);
	foreach ( $openstation_fleet_runtime_options as $openstation_fleet_runtime_option ) {
		delete_option( $openstation_fleet_runtime_option );
	}

	if ( $openstation_fleet_switched ) {
		restore_current_blog();
		$openstation_fleet_switched = false;
	}
}

if ( is_multisite() ) {
	$openstation_fleet_owner_switched = (int) get_current_blog_id() !== (int) get_main_site_id();
	if ( $openstation_fleet_owner_switched ) {
		switch_to_blog( get_main_site_id() );
	}
	try {
		delete_option( OpenStation_Fleet_Repository::LEGACY_OWNER_OPTION );
	} finally {
		if ( $openstation_fleet_owner_switched ) {
			restore_current_blog();
		}
	}
}

global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- A final prefix sweep removes orphaned rows from interrupted older migrations.
$openstation_fleet_meta_keys = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT DISTINCT meta_key FROM {$wpdb->usermeta} WHERE meta_key LIKE %s",
		$wpdb->esc_like( 'openstation_fleet_' ) . '%'
	)
);
foreach ( $openstation_fleet_meta_keys as $openstation_fleet_meta_key ) {
	delete_metadata( 'user', 0, $openstation_fleet_meta_key, '', true );
}
