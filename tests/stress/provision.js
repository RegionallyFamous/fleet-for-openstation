/** Create 30 independent, disposable WordPress installs under an explicit Studio lab. */
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const { execFileSync } = require( 'node:child_process' );
const { randomBytes } = require( 'node:crypto' );
const { runSiteWp } = require( '../e2e/wordpress-fixture' );
const root = process.env.FLEET_STRESS_ROOT;
const origin = process.env.FLEET_STRESS_URL;
const openstation = process.env.FLEET_STRESS_OPENSTATION;
if ( process.env.FLEET_STRESS_WRITES !== '1' || ! root || ! origin || ! openstation || ! root.endsWith( '/fleet-launch-load' ) || ! /^https:\/\/[^/]+\.wp\.local$/.test( origin ) ) {
	throw new Error( 'Explicit disposable fleet-launch-load Studio root, local HTTPS URL, OpenStation source, and write opt-in required.' );
}
const copy = ( from, to ) => fs.cpSync( from, to, { recursive: true, mode: fs.constants.COPYFILE_FICLONE, errorOnExist: true, force: false } );
function configureRuntime( target, url ) {
	// Studio pins its root URL before WP loads; use each fixture's actual URL.
	// These are test-hosting adapters, not Fleet endpoints or production code.
	fs.writeFileSync( path.join( target, 'wp-content/mu-plugins/fleet-stress.php' ), `<?php
if ( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && 'https' === $_SERVER['HTTP_X_FORWARDED_PROTO'] ) { $_SERVER['HTTPS'] = 'on'; }
add_filter( 'pre_option_home', static function() { return '${ url }'; } );
add_filter( 'pre_option_siteurl', static function() { return '${ url }'; } );
add_filter( 'rest_authentication_errors', static function( $result ) {
  return get_option( 'fleet_stress_unavailable' ) ? new WP_Error( 'fleet_stress_offline', 'Isolated load-test outage.', array( 'status' => 503 ) ) : $result;
} );
` );
}
for ( let i = 1; i <= 30; i++ ) {
	const name = `site-${ String( i ).padStart( 2, '0' ) }`;
	const target = path.join( root, name );
	if ( fs.existsSync( target ) ) {
		if ( ! fs.existsSync( path.join( target, '.fleet-stress-fixture' ) ) ) { throw new Error( 'Refusing to alter an unmarked existing directory.' ); }
		if ( ! fs.existsSync( path.join( target, 'wp-content/themes' ) ) ) { copy( path.join( root, 'wp-content/themes' ), path.join( target, 'wp-content/themes' ) ); }
		configureRuntime( target, `${ origin }/${ name }` );
		console.log( `${ name }: already provisioned` );
		continue;
	}
	fs.mkdirSync( target );
	for ( const item of fs.readdirSync( root, { withFileTypes: true } ) ) {
		if ( item.isFile() && item.name.endsWith( '.php' ) ) { copy( path.join( root, item.name ), path.join( target, item.name ) ); }
	}
	for ( const item of [ 'wp-admin', 'wp-includes' ] ) { copy( path.join( root, item ), path.join( target, item ) ); }
	fs.mkdirSync( path.join( target, 'wp-content/mu-plugins' ), { recursive: true } );
	copy( path.join( root, 'wp-content/db.php' ), path.join( target, 'wp-content/db.php' ) );
	copy( path.join( root, 'wp-content/mu-plugins/sqlite-database-integration' ), path.join( target, 'wp-content/mu-plugins/sqlite-database-integration' ) );
	copy( openstation, path.join( target, 'wp-content/plugins/desktop-mode' ) );
	copy( path.join( root, 'wp-content/themes' ), path.join( target, 'wp-content/themes' ) );
	configureRuntime( target, `${ origin }/${ name }` );
	const configPath = path.join( target, 'wp-config.php' );
	let config = fs.readFileSync( configPath, 'utf8' );
	config = config.replace( /put your unique phrase here/g, () => randomBytes( 48 ).toString( 'hex' ) );
	fs.writeFileSync( configPath, config );
	try {
		execFileSync( 'wp', [ `--path=${ target }`, 'core', 'install', `--url=${ origin }/${ name }`, `--title=Load Lab ${ String( i ).padStart( 2, '0' ) }`, '--admin_user=fleet_load_admin', `--admin_password=${ randomBytes( 32 ).toString( 'hex' ) }`, '--admin_email=fleet-load@example.test', '--skip-email' ], { stdio: 'pipe', timeout: 60_000, env: { ...process.env, WP_CLI_PHP_ARGS: '-d error_reporting=24575' } } );
	} catch {
		throw new Error( `${ name }: installation failed (credential-bearing command intentionally omitted).` );
	}
	runSiteWp( target, `
update_option( 'permalink_structure', '' );
require_once ABSPATH . 'wp-admin/includes/plugin.php';
activate_plugin( 'desktop-mode/desktop-mode.php' );
for ( $i = 1; $i <= 36; $i++ ) {
  wp_insert_post( array( 'post_title' => sprintf( 'Client ${ i } article %02d', $i ), 'post_content' => '<!-- wp:paragraph --><p>Independent site load fixture.</p><!-- /wp:paragraph -->', 'post_status' => 'publish', 'post_author' => 1 ) );
}
echo 'FLEET_E2E_JSON:true';` );
	fs.writeFileSync( path.join( target, '.fleet-stress-fixture' ), JSON.stringify( { index: i, url: `${ origin }/${ name }` } ) );
	console.log( `${ name }: independent database ready` );
}
