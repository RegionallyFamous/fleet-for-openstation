<?php
/**
 * Plugin Name:       Fleet for OpenStation
 * Plugin URI:        https://github.com/RegionallyFamous/fleet-for-openstation
 * Description:       Manage connected WordPress sites through WordPress Core Application Passwords and REST APIs inside OpenStation.
 * Version:           0.4.2
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Requires Plugins:  desktop-mode
 * Author:            OpenStation Contributors
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       fleet-for-openstation
 *
 * @package FleetForOpenStation
 */

defined( 'ABSPATH' ) || exit;

define( 'OPENSTATION_FLEET_VERSION', '0.4.2' );
define( 'OPENSTATION_FLEET_FILE', __FILE__ );
define( 'OPENSTATION_FLEET_DIR', plugin_dir_path( __FILE__ ) );
define( 'OPENSTATION_FLEET_URL', plugin_dir_url( __FILE__ ) );

require_once OPENSTATION_FLEET_DIR . 'includes/class-fleet-for-openstation.php';

register_deactivation_hook( __FILE__, array( 'OpenStation_Fleet', 'deactivate' ) );
OpenStation_Fleet::boot();
