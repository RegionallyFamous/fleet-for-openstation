/** Disposable multi-origin MySQL/MariaDB lab. Generated secrets remain in ignored runtime/. */
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const { execFileSync } = require( 'node:child_process' );
const { randomBytes } = require( 'node:crypto' );
const root = path.join( __dirname, 'runtime' );
const count = Number( process.env.FLEET_LAB_COUNT || 2 );
const posts = Number( process.env.FLEET_LAB_POSTS || 50 );
const dependency = process.env.FLEET_LAB_OPENSTATION_ZIP;
if ( process.env.FLEET_LAB_WRITES !== '1' || ! [ 2, 30, 50, 100 ].includes( count ) || ! Number.isInteger( posts ) || posts < 1 || posts > 10000 || ! dependency || ! fs.existsSync( dependency ) ) {
	throw new Error( 'Set FLEET_LAB_WRITES=1, count 2/30/50/100, posts 1–10000 and the pinned OpenStation ZIP.' );
}
if ( fs.existsSync( root ) && ! fs.existsSync( path.join( root, '.fleet-lab' ) ) ) { throw new Error( 'Refusing an unmarked lab directory.' ); }
fs.mkdirSync( root, { recursive: true, mode: 0o700 } );
// The web worker must traverse public fixture assets. The generated database
// secret remains mode 0600 and is copied into the container by its root entrypoint.
fs.chmodSync( root, 0o755 );
fs.writeFileSync( path.join( root, '.fleet-lab' ), 'disposable-fleet-lab-v1\n' );
const run = ( bin, args, options = {} ) => execFileSync( bin, args, { encoding: 'utf8', stdio: [ 'ignore', 'pipe', 'pipe' ], timeout: 300000, ...options } );
const secretPath = path.join( root, 'secret' );
if ( ! fs.existsSync( secretPath ) ) { fs.writeFileSync( secretPath, randomBytes( 32 ).toString( 'hex' ), { mode: 0o600 } ); }
if ( ! fs.existsSync( path.join( root, 'tls.key' ) ) ) {
	run( 'openssl', [ 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '7', '-keyout', path.join( root, 'tls.key' ), '-out', path.join( root, 'tls.crt' ), '-subj', '/CN=localhost', '-addext', 'subjectAltName=DNS:localhost' ] );
}
let apache = 'ServerName localhost\n';
for ( let i = 0; i <= count; i++ ) {
	apache += `Listen ${ 18443 + i }\n<VirtualHost *:${ 18443 + i }>\nSSLEngine on\nSSLCertificateFile /fleet/tls.crt\nSSLCertificateKeyFile /fleet/tls.key\nDocumentRoot /var/www/html\nAlias /wp-content /fleet/sites/site-${ i }/wp-content\n<Directory /fleet/sites/site-${ i }/wp-content>\nRequire all granted\nAllowOverride All\n</Directory>\n</VirtualHost>\n`;
}
fs.writeFileSync( path.join( root, 'apache.conf' ), apache );
const compose = {
	name: 'fleet-reliability-lab',
	services: {
		mariadb: { image: 'mariadb:11.4@sha256:611a2fcc5fa7c6ceb8644c6f74b25ede004ff6c3a6b38c8f8c23d3bbf6c26430', environment: { MARIADB_ROOT_PASSWORD_FILE: '/run/secrets/fleet-lab' }, volumes: [ `${ secretPath }:/run/secrets/fleet-lab:ro`, 'mariadb:/var/lib/mysql' ] },
		mysql: { image: 'mysql:8.4@sha256:b3b90af2a6552ae30c266fdb7d5dd55f3afb72404bb78d37fe8a23eb857fd3fb', environment: { MYSQL_ROOT_PASSWORD_FILE: '/run/secrets/fleet-lab' }, volumes: [ `${ secretPath }:/run/secrets/fleet-lab:ro`, 'mysql:/var/lib/mysql' ] },
		wordpress: { build: { context: __dirname }, volumes: [ `${ root }:/fleet`, `${ secretPath }:/run/secrets/fleet-lab:ro` ], ports: [ `127.0.0.1:18443-${ 18443 + count }:18443-${ 18443 + count }` ], depends_on: [ 'mariadb', 'mysql' ] },
	}, volumes: { mariadb: {}, mysql: {} },
};
const config = path.join( root, 'compose.json' );
fs.writeFileSync( config, JSON.stringify( compose, null, 2 ) );
run( 'docker', [ 'compose', '-f', config, 'up', '-d', '--build' ], { stdio: 'inherit' } );
const container = run( 'docker', [ 'compose', '-f', config, 'ps', '-q', 'wordpress' ] ).trim();
const wp = ( i, args ) => run( 'docker', [ 'exec', '-e', `FLEET_LAB_SITE=${ i }`, container, 'wp', '--allow-root', '--path=/var/www/html', ...args ] );
const sleep = ( ms ) => new Promise( ( resolve ) => setTimeout( resolve, ms ) );

( async () => {
	fs.mkdirSync( path.join( root, 'plugins' ), { recursive: true } );
	run( 'unzip', [ '-qo', path.resolve( dependency ), '-d', path.join( root, 'plugins' ) ] );
	run( 'unzip', [ '-qo', path.resolve( __dirname, '../../dist/fleet-for-openstation.zip' ), '-d', path.join( root, 'plugins' ) ] );
	if ( ! fs.existsSync( path.join( root, 'plugins/desktop-mode/desktop-mode.php' ) ) ) { throw new Error( 'The dependency ZIP must contain desktop-mode/desktop-mode.php.' ); }
	const names = [ 'Cedar Street Studio', 'Harbor Arts Journal', 'Northline Architecture' ];
	const themes = run( 'docker', [ 'exec', container, 'ls', '/usr/src/wordpress/wp-content/themes' ] ).trim().split( /\s+/ ).filter( ( name ) => name.startsWith( 'twenty' ) );
	for ( let i = 0; i <= count; i++ ) {
		const content = path.join( root, `sites/site-${ i }/wp-content` );
		fs.mkdirSync( path.join( content, 'mu-plugins' ), { recursive: true } );
		fs.mkdirSync( path.join( content, 'plugins' ), { recursive: true } );
		fs.mkdirSync( path.join( content, 'themes' ), { recursive: true } );
		for ( const theme of themes ) {
			if ( ! fs.readdirSync( path.join( content, 'themes' ) ).includes( theme ) ) { fs.symlinkSync( `/var/www/html/wp-content/themes/${ theme }`, path.join( content, 'themes', theme ) ); }
		}
		fs.copyFileSync( path.join( __dirname, 'fixture.php' ), path.join( content, 'mu-plugins/fleet-lab.php' ) );
		fs.copyFileSync( path.join( __dirname, '../e2e/fixtures/custom-types.php' ), path.join( content, 'mu-plugins/fleet-modern-test-types.php' ) );
		for ( const plugin of i === 0 ? [ 'desktop-mode', 'fleet-for-openstation' ] : [ 'desktop-mode' ] ) {
			const target = path.join( content, 'plugins', plugin );
			if ( plugin === 'fleet-for-openstation' ) {
				// App::style resolves public paths beneath WP_CONTENT_DIR. Test a
				// normal ZIP install here, not an out-of-tree development symlink.
				if ( fs.existsSync( target ) || fs.readdirSync( path.dirname( target ) ).includes( plugin ) ) {
					if ( fs.lstatSync( target ).isSymbolicLink() ) { fs.unlinkSync( target ); }
				}
				run( 'unzip', [ '-qo', path.resolve( __dirname, '../../dist/fleet-for-openstation.zip' ), '-d', path.dirname( target ) ] );
			} else if ( ! fs.readdirSync( path.dirname( target ) ).includes( plugin ) ) { fs.symlinkSync( `/fleet/plugins/${ plugin }`, target ); }
		}
		let ready = false;
		for ( let retry = 0; retry < 30; retry++ ) {
			try { wp( i, [ 'db', 'create', '--defaults' ] ); ready = true; break; } catch { try { wp( i, [ 'db', 'check', '--defaults' ] ); ready = true; break; } catch {} }
			await sleep( 2000 );
		}
		if ( ! ready ) { throw new Error( `Database ${ i } did not become ready.` ); }
		let installed = false;
		try { wp( i, [ 'core', 'is-installed' ] ); installed = true; } catch {}
		if ( ! installed ) {
			try { wp( i, [ 'core', 'install', `--url=https://localhost:${ 18443 + i }`, `--title=${ names[ i ] || `Client Studio ${ String( i ).padStart( 3, '0' ) }` }`, '--admin_user=fleet_lab_admin', `--admin_password=${ randomBytes( 32 ).toString( 'hex' ) }`, '--admin_email=fleet-lab@example.test', '--skip-email' ] ); } catch { throw new Error( `Site ${ i } installation failed; credential-bearing command omitted.` ); }
		}
		wp( i, [ 'eval', `require_once ABSPATH . 'wp-admin/includes/plugin.php'; activate_plugin( 'desktop-mode/desktop-mode.php' ); ${ i === 0 ? "activate_plugin( 'fleet-for-openstation/fleet-for-openstation.php' );" : '' } update_option( 'permalink_structure', '' ); wp_set_current_user( 1 ); $existing = (int) get_option( 'fleet_lab_seeded', 0 ); for ( $n = $existing; $n < ${ posts }; ++$n ) { wp_insert_post( array( 'post_title' => sprintf( 'Studio ${ i } article %05d', $n ), 'post_content' => '<!-- wp:paragraph --><p>' . str_repeat( 'Agency test content. ', 20 ) . '</p><!-- /wp:paragraph -->', 'post_status' => 0 === $n % 10 ? 'draft' : 'publish', 'post_author' => 1 ) ); } update_option( 'fleet_lab_seeded', ${ posts } );` ] );
		wp( i, [ 'user', 'meta', 'update', '1', 'desktop_mode_mode', '1' ] );
		console.log( `site-${ i }: ${ i % 2 ? 'MySQL' : 'MariaDB' }, independent database and origin, ${ posts } posts` );
	}
	run( 'docker', [ 'exec', container, 'chown', '-R', 'www-data:www-data', '/fleet/sites' ] );
	const mapping = {};
	for ( let i = 0; i <= count; i++ ) { mapping[ path.join( root, `sites/site-${ i }` ) ] = i; }
	fs.writeFileSync( path.join( root, 'runner.json' ), JSON.stringify( { container, mapping } ) );
	// Lab-only direct fixture approval: Core mints per-target application passwords;
	// PHP sends them directly to Fleet inside the container, never to Node/artifacts.
	wp( 0, [ 'eval', `$count = ${ count }; wp_set_current_user( 1 ); for ( $i = 1; $i <= $count; ++$i ) { $url = 'https://localhost:' . ( 18443 + $i ); $id = substr( hash( 'sha256', $url ), 0, 16 ); if ( OpenStation_Fleet_Repository::get( 1, $id, array( 'OpenStation_Fleet', 'normalize_site_record' ) ) ) { continue; } $command = 'FLEET_LAB_SITE=' . (int) $i . ' wp --allow-root --path=/var/www/html eval ' . escapeshellarg( '$r = WP_Application_Passwords::create_new_application_password( 1, array( "name" => "Fleet isolated reliability lab" ) ); echo wp_json_encode( array( "password" => $r[0], "uuid" => $r[1]["uuid"] ) );' ) . ' 2>/dev/null'; $credential = json_decode( shell_exec( $command ), true ); if ( ! is_array( $credential ) ) { throw new Exception( 'Credential fixture failed.' ); } $record = OpenStation_Fleet::normalize_site_record( array( 'site_url' => $url, 'rest_url' => $url . '/index.php?rest_route=/', 'name' => 'Client Studio ' . $i, 'user_login' => 'fleet_lab_admin', 'secret' => OpenStation_Fleet_Crypto::seal( $credential['password'] ), 'credential_uuid' => $credential['uuid'], 'connection_generation' => wp_generate_uuid4(), 'setup_status' => 'ready' ) ); if ( ! OpenStation_Fleet_Repository::save( 1, $id, $record, array(), array( 'OpenStation_Fleet', 'normalize_site_record' ), true ) ) { throw new Exception( 'Connection fixture failed.' ); } unset( $credential ); }` ] );
	console.log( `Lab ready: ${ count } targets. No Fleet plugin or custom Fleet endpoints on targets.` );
} )().catch( () => { console.error( 'Lab provisioning failed. Inspect the marked local fixture; sensitive command output was withheld.' ); process.exitCode = 1; } );
