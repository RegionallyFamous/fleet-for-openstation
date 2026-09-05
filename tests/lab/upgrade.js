/** Real ZIP upgrade and database restoration into a disposable, separate clone. */
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const assert = require( 'node:assert/strict' );
const { execFileSync } = require( 'node:child_process' );
const { createHash, randomBytes } = require( 'node:crypto' );
const root = path.join( __dirname, 'runtime' );
const oldZip = path.join( root, 'upgrade/fleet-for-openstation.zip' );
const currentZip = path.resolve( __dirname, '../../dist/fleet-for-openstation.zip' );
if ( process.env.FLEET_LAB_WRITES !== '1' || ! fs.existsSync( path.join( root, '.fleet-lab' ) ) || fs.existsSync( path.join( root, 'soak.lock' ) ) ) { throw new Error( 'Only a quiescent, marked disposable lab may be upgraded.' ); }
assert.equal( createHash( 'sha256' ).update( fs.readFileSync( oldZip ) ).digest( 'hex' ), '16c4587da8fcaa9a7aa25c688a77a47cf67fc8284f71c6d9f0531d7791e55f0f' );
process.env.FLEET_LAB_RUNNER = path.join( root, 'runner.json' );
const runner = JSON.parse( fs.readFileSync( process.env.FLEET_LAB_RUNNER, 'utf8' ) );
const { runSiteWp } = require( '../e2e/wordpress-fixture' );
const hub = path.join( root, 'sites/site-0' );
const wp = ( code ) => runSiteWp( hub, `wp_set_current_user( 1 ); ${ code }` );
const plugin = path.join( hub, 'wp-content/plugins/fleet-for-openstation' );
const run = ( command, args ) => execFileSync( command, args, { stdio: [ 'ignore', 'pipe', 'pipe' ], timeout: 60000 } );
const install = ( zip ) => {
	run( 'unzip', [ '-qo', zip, '-d', path.dirname( plugin ) ] );
	run( 'docker', [ 'exec', runner.container, 'find', '/fleet/sites/site-0/wp-content/plugins/fleet-for-openstation', '-type', 'f', '-exec', 'touch', '{}', '+' ] );
	run( 'docker', [ 'exec', runner.container, 'apachectl', 'graceful' ] );
};
const report = { started: new Date().toISOString(), passed: false, build_sha256: createHash( 'sha256' ).update( fs.readFileSync( currentZip ) ).digest( 'hex' ) };
const restoreDb = `fleet_restore_${ randomBytes( 8 ).toString( 'hex' ) }`;
const backupDir = fs.mkdtempSync( path.join( root, 'restore-' ) );
fs.chmodSync( backupDir, 0o700 );
const dump = `/fleet/${ path.basename( backupDir ) }/hub.sql`;
let cloneCreated = false;
const db = ( args, clone = false ) => run( 'docker', [ 'exec', '-e', 'FLEET_LAB_SITE=0', ...( clone ? [ '-e', `FLEET_LAB_RESTORE_DB=${ restoreDb }` ] : [] ), runner.container, 'wp', '--allow-root', '--path=/var/www/html', ...args ] );
try {
	// An older lab image may not understand the clone selector. Refuse all
	// database operations unless the running config is this exact audited one.
	const configHash = createHash( 'sha256' ).update( fs.readFileSync( path.join( __dirname, 'wp-config.php' ) ) ).digest( 'hex' );
	assert.equal( String( run( 'docker', [ 'exec', runner.container, 'sha256sum', '/var/www/html/wp-config.php' ] ) ).split( /\s+/ )[ 0 ], configHash, 'Rebuild the lab image after changing its database configuration.' );
	install( oldZip );
	const before = wp( `$sites = OpenStation_Fleet_Repository::all( 1, array( 'OpenStation_Fleet', 'normalize_site_record' ) ); $hashes = array(); foreach( $sites as $id => $site ) { $hashes[$id] = hash( 'sha256', $site['secret'] . $site['connection_generation'] . wp_json_encode( $site['agency'] ) ); } update_option( 'fleet_lab_upgrade_hashes', $hashes, false ); echo 'FLEET_E2E_JSON:' . wp_json_encode( array( 'version' => OPENSTATION_FLEET_VERSION, 'count' => count( $sites ) ) );` );
	assert.equal( before.version, '0.8.0' ); assert.ok( before.count >= 2 ); report.before = before;
	install( currentZip );
	const after = wp( `$sites = OpenStation_Fleet_Repository::all( 1, array( 'OpenStation_Fleet', 'normalize_site_record' ) ); $hashes = array(); foreach( $sites as $id => $site ) { $hashes[$id] = hash( 'sha256', $site['secret'] . $site['connection_generation'] . wp_json_encode( $site['agency'] ) ); } $same = $hashes === get_option( 'fleet_lab_upgrade_hashes' ); $first = reset( $sites ); $r = OpenStation_Fleet_REST_Client::request( $first, 'GET', 'wp/v2/users/me?context=edit&_fields=id' ); echo 'FLEET_E2E_JSON:' . wp_json_encode( array( 'version' => OPENSTATION_FLEET_VERSION, 'count' => count( $sites ), 'preserved' => $same, 'authenticated' => ! is_wp_error( $r ) && ! empty( $r['id'] ) ) );` );
	assert.equal( after.count, before.count ); assert.equal( after.preserved, true ); assert.equal( after.authenticated, true ); report.after = after;
	// Model a restored serialized encrypted record with and without the original salts.
	// No secrets, database dumps, or plaintext credentials leave PHP.
	const restore = wp( `$sites = OpenStation_Fleet_Repository::all( 1, array( 'OpenStation_Fleet', 'normalize_site_record' ) ); $site = reset( $sites ); $backup = maybe_unserialize( maybe_serialize( $site ) ); $good = ! is_wp_error( OpenStation_Fleet_Crypto::open( $backup['secret'] ) ); $different = static function( $salt ) { return 'unrelated-replacement-lab-salt'; }; add_filter( 'salt', $different ); $failed = is_wp_error( OpenStation_Fleet_Crypto::open( $backup['secret'] ) ); remove_filter( 'salt', $different ); $restored = ! is_wp_error( OpenStation_Fleet_Crypto::open( $backup['secret'] ) ); echo 'FLEET_E2E_JSON:' . wp_json_encode( compact( 'good', 'failed', 'restored' ) );` );
	assert.deepEqual( restore, { good: true, failed: true, restored: true } ); report.encrypted_record_restore = restore;
	// Export the hub, import into a new database, and boot the real plugin there.
	// The original database is never overwritten and no remote writes are sent.
	db( [ 'db', 'export', dump, '--defaults', '--single-transaction' ] );
	db( [ 'db', 'create', '--defaults' ], true ); cloneCreated = true;
	db( [ 'db', 'import', dump, '--defaults' ], true );
	const restored = String( db( [ 'eval', `if ( DB_NAME !== '${ restoreDb }' ) { throw new Exception( 'Wrong restore target.' ); } wp_set_current_user( 1 ); $sites = OpenStation_Fleet_Repository::all( 1, array( 'OpenStation_Fleet', 'normalize_site_record' ) ); $hashes = array(); foreach( $sites as $id => $site ) { $hashes[$id] = hash( 'sha256', $site['secret'] . $site['connection_generation'] . wp_json_encode( $site['agency'] ) ); } if ( $hashes !== get_option( 'fleet_lab_upgrade_hashes' ) ) { throw new Exception( 'Restored records changed.' ); } $first = reset( $sites ); $r = OpenStation_Fleet_REST_Client::request( $first, 'GET', 'wp/v2/users/me?context=edit&_fields=id' ); if ( is_wp_error( $r ) || empty( $r['id'] ) ) { throw new Exception( 'Restored authentication failed.' ); } update_option( 'fleet_lab_clone_only', '${ restoreDb }', false ); echo 'FLEET_RESTORE_OK';` ], true ) );
	assert.ok( restored.includes( 'FLEET_RESTORE_OK' ) );
	assert.equal( wp( `echo 'FLEET_E2E_JSON:' . wp_json_encode( false === get_option( 'fleet_lab_clone_only', false ) );` ), true );
	report.database_clone_restore = { passed: true, connections: after.count, authenticated_core_read: true, original_database_untouched: true };
	report.passed = true;
} catch ( error ) {
	report.failure = error.name; process.exitCode = 1;
} finally {
	if ( cloneCreated ) { db( [ 'db', 'drop', '--yes', '--defaults' ], true ); }
	const hostDump = path.join( backupDir, 'hub.sql' );
	if ( fs.existsSync( hostDump ) ) { fs.unlinkSync( hostDump ); }
	fs.rmdirSync( backupDir );
	install( currentZip );
	wp( `delete_option( 'fleet_lab_upgrade_hashes' ); echo 'FLEET_E2E_JSON:true';` );
	report.ended = new Date().toISOString(); fs.writeFileSync( path.join( root, 'upgrade-result.json' ), JSON.stringify( report, null, 2 ) + '\n' );
	console.log( JSON.stringify( report ) );
}
