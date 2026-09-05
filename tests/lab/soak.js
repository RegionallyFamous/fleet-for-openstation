/** Bounded live-browser endurance test. Only the marked local lab is writable. */
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const assert = require( 'node:assert/strict' );
const { createHash, randomUUID } = require( 'node:crypto' );
const { chromium } = require( '@playwright/test' );
const { runSiteWp } = require( '../e2e/wordpress-fixture' );
const root = path.join( __dirname, 'runtime' );
const hub = path.join( root, 'sites/site-0' );
const smoke = process.argv.includes( '--smoke' );
const hours = Number( process.env.FLEET_SOAK_HOURS || 48 );
if ( process.env.FLEET_SOAK_WRITES !== '1' || ! [ 48, 72 ].includes( hours ) || ! fs.existsSync( path.join( root, '.fleet-lab' ) ) ) { throw new Error( 'Explicit marked-lab write opt-in and a 48/72-hour duration are required.' ); }
process.env.FLEET_LAB_RUNNER = path.join( root, 'runner.json' );
const runner = JSON.parse( fs.readFileSync( process.env.FLEET_LAB_RUNNER, 'utf8' ) );
assert.equal( runner.mapping[ hub ], 0 );
const output = path.join( root, smoke ? 'soak-smoke.json' : 'soak.json' );
if ( ! smoke && fs.existsSync( output ) ) { throw new Error( 'An endurance record already exists. Archive its result before explicitly starting another run.' ); }
const lock = fs.openSync( path.join( root, 'soak.lock' ), 'wx', 0o600 );
fs.writeSync( lock, String( process.pid ) );
const zip = path.resolve( __dirname, '../../dist/fleet-for-openstation.zip' );
const digest = () => createHash( 'sha256' ).update( fs.readFileSync( zip ) ).digest( 'hex' );
const report = { mode: smoke ? 'short harness check, NOT endurance certification' : '48–72-hour continuous browser and API lab', started: new Date().toISOString(), duration_hours: smoke ? null : hours, build_sha256: digest(), cycles: 0, reads: 0, writes: 0, peak_browser_heap_bytes: 0, peak_dom_nodes: 0, samples: [], status: 'running' };
const save = () => fs.writeFileSync( output, JSON.stringify( report, null, 2 ) + '\n', { mode: 0o600 } );
report.pid = process.pid;
save();
const php = ( code ) => runSiteWp( hub, `wp_set_current_user( 1 ); ${ code }` );
let browser;
let page;
const failedRequests = [];
let stage = 'setup';
let draft = null;
let stopping = false;
for ( const signal of [ 'SIGINT', 'SIGTERM' ] ) { process.on( signal, () => { stopping = true; } ); }

( async () => {
	const identity = php( `echo 'FLEET_E2E_JSON:' . wp_json_encode( array( 'login' => wp_get_current_user()->user_login, 'url' => home_url(), 'version' => OPENSTATION_FLEET_VERSION ) );` );
	assert.equal( identity.login, 'fleet_lab_admin' );
	assert.equal( identity.url, 'https://localhost:18443' );
	report.version = identity.version;
	const sites = php( `$out = array(); foreach( OpenStation_Fleet_Repository::all( 1, array( 'OpenStation_Fleet', 'normalize_site_record' ) ) as $id => $site ) { $out[] = array( 'id' => $id, 'url' => $site['site_url'] ); } echo 'FLEET_E2E_JSON:' . wp_json_encode( $out );` );
	assert.equal( sites.length, 100 );
	for ( const site of sites ) { assert.match( site.url, /^https:\/\/localhost:18[45][0-9]{2}$/ ); assert.ok( Number( new URL( site.url ).port ) >= 18444 && Number( new URL( site.url ).port ) <= 18543 ); }
	browser = await chromium.launch( require( './browser-options' )() );
	const context = await browser.newContext( { ignoreHTTPSErrors: true, viewport: { width: 1440, height: 960 } } );
	const cookies = php( `$expires = time() + 4 * DAY_IN_SECONDS; echo 'FLEET_E2E_JSON:' . wp_json_encode( array( array( 'name' => SECURE_AUTH_COOKIE, 'value' => wp_generate_auth_cookie( 1, $expires, 'secure_auth' ) ), array( 'name' => LOGGED_IN_COOKIE, 'value' => wp_generate_auth_cookie( 1, $expires, 'logged_in' ) ) ) );` );
	await context.addCookies( cookies.map( ( cookie ) => ( { ...cookie, url: identity.url, secure: true, httpOnly: true, sameSite: 'Lax' } ) ) );
	page = await context.newPage();
	page.on( 'response', ( response ) => {
		if ( response.status() >= 400 ) { failedRequests.push( { status: response.status(), path: new URL( response.url() ).pathname } ); }
	} );
	let errors = 0;
	page.on( 'pageerror', () => { errors++; } );
	page.setDefaultTimeout( 30000 );
	await page.goto( `${ identity.url }/wp-admin/admin.php?page=openstation` );
	await page.waitForFunction( () => window.wp?.os?.windowManager );
	await page.evaluate( () => new Promise( ( resolve ) => window.wp.os.ready( resolve ) ) );
	// Shell APIs become ready before saved windows finish restoring. Observe
	// the server-rendered session's native ids before reusing any instance id;
	// otherwise restoration can legitimately reapply an old site's params.
	stage = 'waiting for the saved desktop to restore';
	await page.waitForFunction( () => ( window.openStationConfig?.session?.windows || [] )
		.filter( ( win ) => win.native )
		.every( ( win ) => window.wp.os.windowManager.getById( win.id ) ) );
	await page.evaluate( () => Promise.all( window.wp.os.windowManager.getAll().map( ( win ) => win.whenContentReady() ) ) );
	const started = Date.now();
	let previous = started;
	const interval = smoke ? 5000 : 300000;
	while ( ! stopping && ( smoke ? report.cycles < 3 : Date.now() - started < hours * 3600000 ) ) {
		stage = 'continuity and build pin';
		assert.ok( Date.now() - previous < 20 * 60000, 'Host suspension interrupted continuous endurance coverage.' );
		assert.equal( digest(), report.build_sha256, 'Build changed during the soak.' );
		previous = Date.now();
		stage = '100 independent Core content reads';
		const read = php( `$start = microtime( true ); $count = 0; $errors = 0; foreach( OpenStation_Fleet_Repository::all( 1, array( 'OpenStation_Fleet', 'normalize_site_record' ) ) as $site ) { $r = OpenStation_Fleet_REST_Client::request( $site, 'GET', 'wp/v2/posts?context=edit&per_page=1&_fields=id,title' ); ++$count; if ( is_wp_error( $r ) || empty( $r[0]['id'] ) ) { ++$errors; } } echo 'FLEET_E2E_JSON:' . wp_json_encode( array( 'count' => $count, 'errors' => $errors, 'ms' => round( ( microtime( true ) - $start ) * 1000 ), 'peak_php_bytes' => memory_get_peak_usage( true ) ) );` );
		assert.equal( read.count, 100 ); assert.equal( read.errors, 0 ); report.reads += read.count;
		stage = 'three concurrent windows and open/close lifecycle';
		await page.evaluate( () => window.wp.os.windowManager.getAll().forEach( ( win ) => win.close() ) );
		await page.waitForFunction( () => ! document.querySelector( '.os-window' ) );
		for ( let n = 0; n < 3; n++ ) {
			const site = sites[ ( report.cycles * 3 + n ) % sites.length ];
			stage = `opening site window ${ site.id } in cycle ${ report.cycles + 1 }`;
			await page.evaluate( ( id ) => window.wp.os.openNewWindow( 'fleet-site', { params: { site_id: id } } ), site.id );
			await page.waitForFunction( ( id ) => window.wp.os.windowManager.getAll().find( ( win ) => win.config?.params?.site_id === id )?.element?.querySelector( '.fleet-native-site-header' ), site.id );
		}
		const metrics = await page.evaluate( () => ( { windows: window.wp.os.windowManager.getAll().length, nodes: document.querySelectorAll( '*' ).length, heap: performance.memory?.usedJSHeapSize || 0 } ) );
		assert.equal( metrics.windows, 3 ); assert.ok( metrics.nodes < 20000 ); assert.ok( metrics.heap < 256 * 1024 * 1024 ); assert.equal( errors, 0 );
		report.peak_browser_heap_bytes = Math.max( report.peak_browser_heap_bytes, metrics.heap );
		report.peak_dom_nodes = Math.max( report.peak_dom_nodes, metrics.nodes );
		stage = 'bounded cron pass';
		const cron = php( `$start = microtime( true ); OpenStation_Fleet::run_scheduled_checks(); echo 'FLEET_E2E_JSON:' . wp_json_encode( round( microtime( true ) - $start, 2 ) );` );
		assert.ok( cron < 45 );
		// One disposable draft per hour. Never alter seeded/client content.
		if ( smoke || report.cycles % 12 === 0 ) {
			stage = 'create/read/trash canary';
			const target = sites[ report.cycles % sites.length ];
			const index = Number( new URL( target.url ).port ) - 18443;
			const targetPath = Object.keys( runner.mapping ).find( ( item ) => runner.mapping[ item ] === index );
			const title = `Fleet endurance canary ${ randomUUID() }`;
			draft = { targetPath, id: 0, title };
			const created = php( `$r = OpenStation_Fleet::app_action( '${ target.id }', 'content', array( 'content_type' => 'posts', 'content_id' => 0, 'request_id' => wp_generate_uuid4(), 'title' => '${ title }', 'content' => '<p>Disposable endurance check.</p>', 'excerpt' => '', 'slug' => '', 'date_gmt' => '', 'status' => 'draft' ) ); echo 'FLEET_E2E_JSON:' . wp_json_encode( is_wp_error( $r ) ? array( 'error' => $r->get_error_code() ) : array( 'id' => $r['data']['id'] ) );` );
			if ( created.error ) { report.canary_error_code = created.error; }
			assert.ok( created.id );
			draft = { targetPath, id: created.id, title };
			const actual = runSiteWp( targetPath, `echo 'FLEET_E2E_JSON:' . wp_json_encode( get_post( ${ created.id } )->post_title );` );
			assert.equal( actual, title );
			const removed = php( `$item = OpenStation_Fleet::app_site_data( '${ target.id }', 'content', array( 'type' => 'posts', 'selected' => ${ created.id } ) )['item']; $r = OpenStation_Fleet::app_action( '${ target.id }', 'trash-content', array( 'content_type' => 'posts', 'content_id' => ${ created.id }, 'fingerprint' => OpenStation_Fleet_Content::fingerprint( $item ) ) ); echo 'FLEET_E2E_JSON:' . wp_json_encode( ! is_wp_error( $r ) );` );
			assert.equal( removed, true );
			runSiteWp( targetPath, `if ( get_post( ${ created.id } )->post_title !== '${ title }' ) { throw new Exception( 'Unowned cleanup target.' ); } wp_delete_post( ${ created.id }, true ); echo 'FLEET_E2E_JSON:true';` );
			draft = null; report.writes += 2;
		}
		report.cycles++; report.updated = new Date().toISOString(); report.elapsed_hours = ( Date.now() - started ) / 3600000;
		report.samples.push( { cycle: report.cycles, read_ms: read.ms, peak_php_bytes: read.peak_php_bytes, cron_seconds: cron, ...metrics } );
		report.samples = report.samples.slice( -600 ); save();
		console.log( JSON.stringify( { cycle: report.cycles, elapsed_hours: Number( report.elapsed_hours.toFixed( 3 ) ), reads: report.reads, status: report.status } ) );
		if ( ! smoke || report.cycles < 3 ) { await new Promise( ( resolve ) => setTimeout( resolve, interval ) ); }
	}
	assert.ok( ! stopping, 'Endurance interrupted by an explicit stop.' );
	if ( ! smoke ) { assert.ok( report.cycles >= hours * 9, 'Too few samples for continuous coverage.' ); }
	report.status = 'passed';
} )().catch( ( error ) => {
	report.status = 'failed'; report.failure = { stage, type: error.name }; process.exitCode = 1;
	console.error( `Endurance stopped at ${ stage }; sensitive error details withheld.` );
} ).finally( async () => {
	if ( report.status === 'failed' && page ) {
		report.failed_requests = failedRequests.slice( -10 );
		try { report.window_state = await page.evaluate( () => window.wp.os.windowManager.getAll().map( ( win ) => ( { id: win.id, site: win.config?.params?.site_id, headers: win.element?.querySelectorAll( '.fleet-native-site-header' ).length, busy: !!win.element?.querySelector( '[aria-busy="true"]' ) } ) ) ); } catch {}
	}
	if ( draft?.id ) {
		try { runSiteWp( draft.targetPath, `if ( get_post( ${ draft.id } )->post_title === '${ draft.title }' ) { wp_delete_post( ${ draft.id }, true ); } echo 'FLEET_E2E_JSON:true';` ); } catch { report.cleanup_required = { site: path.basename( draft.targetPath ), post: draft.id }; }
	} else if ( draft ) {
		report.cleanup_required = { site: path.basename( draft.targetPath ), title: draft.title, reason: 'Creation result was uncertain; inspect this exact canary, do not blindly retry.' };
	}
	if ( browser ) { await browser.close(); }
	report.ended = new Date().toISOString(); save(); fs.closeSync( lock ); fs.unlinkSync( path.join( root, 'soak.lock' ) );
} );
