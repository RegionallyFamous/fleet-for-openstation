<?php
// Disposable lab only. Independent databases/content/salts on distinct HTTPS ports.
$site = getenv( 'FLEET_LAB_SITE' );
if ( false === $site || '' === $site ) {
    $site = (int) ( $_SERVER['SERVER_PORT'] ?? 18443 ) - 18443;
}
$site = (int) $site;
if ( $site < 0 || $site > 100 ) { exit( 'Unknown lab origin.' ); }
$secret = trim( file_get_contents( '/run/fleet-db-secret' ) );
$restore = PHP_SAPI === 'cli' ? getenv( 'FLEET_LAB_RESTORE_DB' ) : false;
if ( $restore && ( 0 !== $site || ! preg_match( '/^fleet_restore_[a-f0-9]{16}$/D', $restore ) ) ) { exit( 'Invalid isolated restore database.' ); }
define( 'DB_NAME', $restore ?: 'fleet_' . $site );
define( 'DB_USER', 'root' );
define( 'DB_PASSWORD', $secret );
define( 'DB_HOST', 0 === $site % 2 ? 'mariadb' : 'mysql' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );
foreach ( array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT' ) as $key ) {
    define( $key, hash_hmac( 'sha256', $key . ':' . $site, $secret ) );
}
define( 'WP_HOME', 'https://localhost:' . ( 18443 + $site ) );
define( 'WP_SITEURL', WP_HOME );
define( 'WP_CONTENT_DIR', '/fleet/sites/site-' . $site . '/wp-content' );
define( 'WP_CONTENT_URL', WP_HOME . '/wp-content' );
define( 'DISABLE_WP_CRON', true );
define( 'WP_ENVIRONMENT_TYPE', 'local' );
define( 'FS_METHOD', 'direct' );
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_DISPLAY', false );
define( 'WP_DEBUG_LOG', false );
define( 'WP_MEMORY_LIMIT', '256M' );
$table_prefix = 'wp_';
$_SERVER['HTTPS'] = 'on';
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/var/www/html/' ); }
require_once ABSPATH . 'wp-settings.php';
