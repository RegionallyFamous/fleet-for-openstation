<?php
/**
 * Remove local Fleet metadata. Remote Application Passwords must be revoked
 * with the Disconnect action before uninstalling.
 *
 * @package FleetForOpenStation
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_metadata( 'user', 0, 'openstation_fleet_sites', '', true );
delete_metadata( 'user', 0, 'openstation_fleet_app_id', '', true );
