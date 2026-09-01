<?php
/**
 * Remove local Fleet metadata. Remote Application Passwords must be revoked
 * with Disconnect before uninstalling.
 *
 * @package FleetForOpenStation
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_metadata( 'user', 0, 'openstation_fleet_sites', '', true );
delete_metadata( 'user', 0, 'openstation_fleet_activity', '', true );
delete_metadata( 'user', 0, 'openstation_fleet_app_id', '', true );
wp_clear_scheduled_hook( 'openstation_fleet_scheduled_check' );
delete_option( 'openstation_fleet_scheduled_check_lock' );
delete_option( 'openstation_fleet_scheduled_check_cursor' );
