<?php
/**
 * Remove local Fleet metadata. Remote OAuth grants and bootstrap Application
 * Passwords must be revoked with Disconnect before uninstalling.
 *
 * @package FleetForOpenStation
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_metadata( 'user', 0, 'openstation_fleet_sites', '', true );
delete_metadata( 'user', 0, 'openstation_fleet_app_id', '', true );
