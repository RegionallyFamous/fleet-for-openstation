<?php
/**
 * Plugin Name:       Fleet for OpenStation
 * Plugin URI:        https://github.com/RegionallyFamous/fleet-for-openstation
 * Description:       Connect WordPress sites and install OpenStation from one WordPress-native fleet screen.
 * Version:           0.1.1
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

define( 'OPENSTATION_FLEET_VERSION', '0.1.1' );
define( 'OPENSTATION_FLEET_FILE', __FILE__ );
define( 'OPENSTATION_FLEET_DIR', plugin_dir_path( __FILE__ ) );

require_once OPENSTATION_FLEET_DIR . 'includes/class-fleet-for-openstation.php';

OpenStation_Fleet::boot();
