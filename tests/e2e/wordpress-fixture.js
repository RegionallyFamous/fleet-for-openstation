const { execFileSync } = require( 'node:child_process' );
const { URL } = require( 'node:url' );

const hubPath = process.env.FLEET_E2E_HUB_PATH;

function runWp( args ) {
	return execFileSync( 'wp', [ `--path=${ hubPath }`, ...args ], {
		encoding: 'utf8',
		stdio: [ 'ignore', 'pipe', 'pipe' ],
		env: {
			...process.env,
			WP_CLI_PHP_ARGS: '-d error_reporting=24575',
		},
	} );
}

function parseMarkedJson( output ) {
	const marker = 'FLEET_E2E_JSON:';
	const line = output
		.split( /\r?\n/ )
		.find( ( candidate ) => candidate.startsWith( marker ) );
	if ( ! line ) {
		throw new Error( 'WP-CLI did not return the expected Fleet test fixture.' );
	}
	return JSON.parse( line.slice( marker.length ) );
}

function discoverFleet() {
	const requestedUser = String( process.env.FLEET_E2E_USER_ID || '' );
	if ( requestedUser && ! /^\d+$/.test( requestedUser ) ) {
		throw new Error( 'FLEET_E2E_USER_ID must be a numeric WordPress user ID.' );
	}
	const requestedId = requestedUser ? Number( requestedUser ) : 0;
	const php = `
$requested = ${ requestedId };
$user_ids = $requested ? array( $requested ) : OpenStation_Fleet_Repository::user_ids();
sort( $user_ids, SORT_NUMERIC );
$fixture = null;
foreach ( $user_ids as $user_id ) {
	$user = get_user_by( 'id', $user_id );
	if ( ! $user || ! user_can( $user, 'manage_options' ) ) {
		continue;
	}
	$aggregate = get_user_meta( $user->ID, OpenStation_Fleet_Repository::AGGREGATE_META, true );
	$aggregate_count = is_array( $aggregate ) ? count( $aggregate ) : 0;
	wp_set_current_user( $user->ID );
	$sites = OpenStation_Fleet_Repository::all(
		$user->ID,
		array( 'OpenStation_Fleet', 'normalize_site_record' )
	);
	if ( ! is_array( $sites ) || count( $sites ) < 2 ) {
		continue;
	}
	$safe_sites = array();
	foreach ( $sites as $id => $site ) {
		if ( ! is_array( $site ) ) {
			continue;
		}
		$safe_sites[] = array(
			'id' => sanitize_key( $id ),
			'name' => sanitize_text_field( isset( $site['name'] ) ? $site['name'] : $id ),
			'site_url' => esc_url_raw( isset( $site['site_url'] ) ? $site['site_url'] : '' ),
			'rest_url' => esc_url_raw( isset( $site['rest_url'] ) ? $site['rest_url'] : '' ),
			'credential_ready' => ! empty( $site['user_login'] ) && ! empty( $site['secret'] ) && ! empty( $site['credential_uuid'] ),
		);
	}
	if ( count( $safe_sites ) >= 2 ) {
		$index = OpenStation_Fleet_Repository::site_ids(
			$user->ID,
			array( 'OpenStation_Fleet', 'normalize_site_record' )
		);
		$record_count = 0;
		foreach ( $index as $site_id ) {
			$record = OpenStation_Fleet_Repository::get(
				$user->ID,
				$site_id,
				array( 'OpenStation_Fleet', 'normalize_site_record' )
			);
			if ( is_array( $record ) ) {
				++$record_count;
			}
		}
		$fixture = array(
			'user_id' => (int) $user->ID,
			'sites' => array_slice( $safe_sites, 0, 2 ),
			'migration' => array(
				'aggregate_count' => $aggregate_count,
				'aggregate_removed' => ! metadata_exists( 'user', $user->ID, OpenStation_Fleet_Repository::AGGREGATE_META ),
				'index_count' => count( $index ),
				'record_count' => $record_count,
			),
		);
		break;
	}
}
echo 'FLEET_E2E_JSON:' . wp_json_encode( $fixture );
`;
	const fixture = parseMarkedJson( runWp( [ 'eval', php ] ) );
	if ( ! fixture ) {
		throw new Error( 'The test hub needs one administrator with at least two connected Fleet sites.' );
	}
	return fixture;
}

function authenticationCookies( userId, hubUrl ) {
	const php = `
$user_id = ${ Number( userId ) };
$expires = time() + 1200;
$cookies = array(
	array( 'name' => SECURE_AUTH_COOKIE, 'value' => wp_generate_auth_cookie( $user_id, $expires, 'secure_auth' ), 'expires' => $expires ),
	array( 'name' => LOGGED_IN_COOKIE, 'value' => wp_generate_auth_cookie( $user_id, $expires, 'logged_in' ), 'expires' => $expires ),
);
echo 'FLEET_E2E_JSON:' . wp_json_encode( $cookies );
`;
	const origin = new URL( hubUrl ).origin;
	return parseMarkedJson( runWp( [ 'eval', php ] ) ).map( ( cookie ) => ( {
		...cookie,
		url: origin,
		httpOnly: true,
		secure: true,
		sameSite: 'Lax',
	} ) );
}

function loadWordPressFixture() {
	if ( ! hubPath ) {
		throw new Error( 'FLEET_E2E_HUB_PATH is required.' );
	}
	const discoveredUrl = parseMarkedJson( runWp( [ 'eval', "echo 'FLEET_E2E_JSON:' . wp_json_encode( home_url() );" ] ) );
	const hubUrl = String( process.env.FLEET_E2E_HUB_URL || discoveredUrl ).trim().replace( /\/$/, '' );
	const fleet = discoverFleet();
	return {
		hubUrl,
		sites: fleet.sites,
		migration: fleet.migration,
		cookies: authenticationCookies( fleet.user_id, hubUrl ),
	};
}

module.exports = { loadWordPressFixture };
