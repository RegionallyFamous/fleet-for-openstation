<?php
/**
 * Global OpenStation functions known to PHPStan.
 *
 * This file is loaded only by PHPStan. It is never shipped with the plugin.
 *
 * @package FleetForOpenStation
 */

/**
 * Build an OpenStation shell URL.
 *
 * @param string $target Target path inside WordPress.
 * @param bool   $intent Whether the shell should foreground the target.
 * @return string
 */
function openstation_shell_url( $target = '', $intent = false ) {
	return '';
}
