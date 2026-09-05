<?php
/**
 * Plugin Name:       Fleet for OpenStation
 * Plugin URI:        https://github.com/RegionallyFamous/fleet-for-openstation
 * Description:       Manage connected WordPress sites through OpenStation's App Framework and WordPress Core REST APIs.
 * Version:           0.10.0-alpha.1
 * Requires at least: 7.1
 * Requires PHP:      8.3
 * Requires Plugins:  desktop-mode
 * Author:            OpenStation Contributors
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       fleet-for-openstation
 *
 * @package FleetForOpenStation
 */

defined( 'ABSPATH' ) || exit;

define( 'OPENSTATION_FLEET_VERSION', '0.10.0-alpha.1' );
define( 'OPENSTATION_FLEET_DIR', plugin_dir_path( __FILE__ ) );
define( 'OPENSTATION_FLEET_URL', plugin_dir_url( __FILE__ ) );

require_once OPENSTATION_FLEET_DIR . 'includes/class-openstation-fleet.php';
require_once OPENSTATION_FLEET_DIR . 'includes/class-openstation-fleet-app.php';

register_deactivation_hook( __FILE__, array( 'OpenStation_Fleet', 'deactivate' ) );
OpenStation_Fleet::boot();
