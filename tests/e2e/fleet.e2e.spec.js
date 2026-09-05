const { test, expect } = require( '@playwright/test' );
const AxeBuilder = require( '@axe-core/playwright' ).default;
const { loadWordPressFixture, runSiteWp } = require( './wordpress-fixture' );

const fixture = loadWordPressFixture();

async function openShell( page ) {
	await page.addInitScript( () => performance.setResourceTimingBufferSize( 2000 ) );
	await page.context().addCookies( fixture.cookies );
	await page.goto( `${ fixture.hubUrl }/wp-admin/admin.php?page=openstation`, {
		waitUntil: 'domcontentloaded',
	} );
	expect( page.url() ).not.toContain( 'wp-login.php' );
	await page.waitForFunction( () => (
		window.wp?.os?.windowManager?.getAll
		&& typeof window.wp.os.openNewWindow === 'function'
	) );
}

async function openHub( page ) {
	await page.evaluate( () => {
		const manager = window.wp.os.windowManager;
		const existing = manager.getAll().find( ( candidate ) => candidate.config?.baseId === 'fleet-for-openstation' );
		if ( existing ) {
			existing.restore();
			manager.focus( existing );
			return;
		}
		window.wp.os.openNewWindow( 'fleet-for-openstation', { source: 'fleet-e2e' } );
	} );
	await page.locator( '.fleet-native-sites' ).first().waitFor();
	await page.evaluate( async () => {
		await window.wp.os.windowManager.getAll().find( ( candidate ) => candidate.config?.baseId === 'fleet-for-openstation' )?.whenContentReady();
		await window.wp.os.loadComponents( [ 'os-form', 'os-table' ] );
	} );
	return page.evaluate( () => window.wp.os.windowManager.getAll()
		.find( ( candidate ) => candidate.config?.baseId === 'fleet-for-openstation' )?.element?.id || '' );
}

async function openManagedSites( page ) {
	const siteIds = fixture.sites.map( ( site ) => site.id );
	for ( const siteId of siteIds ) {
		await page.evaluate( ( id ) => {
			const manager = window.wp.os.windowManager;
			const existing = manager.getAll().find( ( candidate ) => (
				candidate.config?.baseId === 'fleet-site'
				&& String( candidate.config?.params?.site_id || '' ) === id
			) );
			if ( existing ) {
				existing.restore();
				manager.focus( existing );
				return;
			}
			window.wp.os.openNewWindow( 'fleet-site', {
				source: 'fleet-e2e',
				params: { site_id: id },
			} );
		}, siteId );
		await page.waitForFunction( ( id ) => window.wp.os.windowManager.getAll().some( ( candidate ) => (
			candidate.config?.baseId === 'fleet-site'
			&& String( candidate.config?.params?.site_id || '' ) === id
		) ), siteId );
	}

	await page.waitForFunction( ( ids ) => ids.every( ( id ) => {
		const candidate = window.wp.os.windowManager.getAll().find( ( window ) => (
			window.config?.baseId === 'fleet-site'
			&& String( window.config?.params?.site_id || '' ) === id
		) );
		return candidate?.element?.querySelector( '.fleet-native-site-header' );
	} ), siteIds );
	await page.evaluate( async ( ids ) => {
		await Promise.all( window.wp.os.windowManager.getAll().filter( ( candidate ) => ids.includes( candidate.config?.params?.site_id ) ).map( ( candidate ) => candidate.whenContentReady() ) );
		await window.wp.os.loadComponents( [ 'os-form', 'os-select', 'os-table', 'os-save-status' ] );
	}, siteIds );

	return page.evaluate( ( ids ) => ids.map( ( siteId ) => {
		const candidate = window.wp.os.windowManager.getAll().find( ( window ) => (
			window.config?.baseId === 'fleet-site'
			&& String( window.config?.params?.site_id || '' ) === siteId
		) );
		return { id: candidate.id, elementId: candidate.element.id, siteId };
	} ), siteIds );
}

function assertNoConsoleErrors( errors ) {
	// OpenStation 9bac917 emits this progressive-enhancement viewport setting.
	// WebKit ignores it safely. Keep this exact dependency warning visible in
	// test annotations; never suppress generic console or JavaScript failures.
	const known = 'Viewport argument key "interactive-widget" not recognized and ignored.';
	const warnings = errors.filter( ( message ) => message === known );
	if ( warnings.length ) { test.info().annotations.push( { type: 'upstream-warning', description: known } ); }
	const unexpected = errors.filter( ( message ) => message !== known );
	expect( unexpected, unexpected.join( '\n' ) ).toEqual( [] );
}

test.describe( 'Fleet App Framework integration', () => {
	test( 'keeps support reports local and excludes private connection fields', () => {
		const report = runSiteWp( process.env.FLEET_E2E_HUB_PATH, `
wp_set_current_user( ${ fixture.userId } );
$requests = 0; add_filter( 'pre_http_request', static function( $pre ) use ( &$requests ) { ++$requests; return $pre; } );
$report = OpenStation_Fleet::diagnostics(); $json = wp_json_encode( $report ); $private = false;
foreach( OpenStation_Fleet_Repository::all( ${ fixture.userId }, array( 'OpenStation_Fleet', 'normalize_site_record' ) ) as $site ) {
 foreach( array( 'site_url', 'rest_url', 'secret', 'credential_uuid', 'user_login', 'name' ) as $key ) {
  if ( ! empty( $site[$key] ) && false !== strpos( $json, (string) $site[$key] ) ) { $private = true; }
 }
}
echo 'FLEET_E2E_JSON:' . wp_json_encode( array( 'keys' => array_keys( $report ), 'private' => $private, 'requests' => $requests, 'ready' => $report['app_framework_ready'] ) );` );
		expect( report.private ).toBe( false );
		expect( report.requests ).toBe( 0 );
		expect( report.ready ).toBe( true );
		expect( report.keys.sort() ).toEqual( [ 'fleet_version', 'wordpress_version', 'php_version', 'app_framework_ready', 'https', 'encryption_available', 'multisite', 'cron_disabled', 'next_check_utc', 'connected_sites', 'sites_needing_setup', 'sites_with_errors', 'sites_backing_off' ].sort() );
	} );
	test( 'aligns native collection controls and action buttons', async ( { page } ) => {
		await openShell( page );
		await page.evaluate( () => window.wp.hooks.addFilter( 'os.window.geometry', 'fleet/alignment-test', ( geometry, context ) => context.baseId === 'fleet-site' ? { ...geometry, width: 1000, height: 720, state: 'normal' } : geometry ) );
		const windows = await openManagedSites( page );
		const frame = page.locator( `#${ windows[ 1 ].elementId }` );
		await frame.locator( '.os-window__tab[data-panel="content"]' ).click();
		const form = frame.locator( 'os-tabpanel[for="content"] .fleet-native-filters' );
		await form.waitFor();
		await expect( frame.locator( 'os-tabpanel[for="content"] [data-os-app]' ) ).not.toHaveAttribute( 'aria-busy', 'true' );
		const boxes = await Promise.all( [ form.getByRole( 'combobox' ).first(), form.locator( 'input[name="search"]' ), form.getByRole( 'button', { name: 'Apply filters', exact: true } ) ].map( ( locator ) => locator.boundingBox() ) );
		const bottoms = boxes.map( ( box ) => box.y + box.height );
		expect( Math.max( ...bottoms ) - Math.min( ...bottoms ) ).toBeLessThanOrEqual( 2 );
	} );
	for ( const type of [ 'posts', 'pages' ] ) {
		test( `creates, edits, detects a conflict, and trashes ${ type } through Core`, async ( { page } ) => {
			test.setTimeout( 120_000 );
			test.skip( process.env.FLEET_E2E_WRITES !== '1', 'Explicit disposable-fixture write opt-in required.' );
			const sitePath = process.env.FLEET_E2E_MANAGED_PATH;
			const remoteUrl = runSiteWp( sitePath, "echo 'FLEET_E2E_JSON:' . wp_json_encode( home_url() );" );
			const remote = fixture.sites.find( ( site ) => site.site_url.replace( /\/$/, '' ) === remoteUrl.replace( /\/$/, '' ) );
			expect( remote, 'The fixture must be one of the connected sites.' ).toBeTruthy();
			const title = `Fleet E2E ${ type } ${ Date.now() }`;
			const source = '<!-- wp:paragraph --><p>A real block &amp; a source round trip.</p><!-- /wp:paragraph -->';
			let postId = 0;
			try {
				await openShell( page );
				const windows = await openManagedSites( page );
				const selected = windows.find( ( win ) => win.siteId === remote.id );
				await page.evaluate( ( id ) => window.wp.os.windowManager.focus( window.wp.os.windowManager.getById( id ) ), selected.id );
				const frame = page.locator( `#${ selected.elementId }` );
				await frame.locator( '.os-window__tab[data-panel="content"]' ).click();
				const win = frame.locator( 'os-tabpanel[for="content"]' );
				// Remote actions can outlast an ordinary assertion under load. Wait for
				// the framework's completed response, then assert its actual outcome.
				const waitForAction = () => expect( win.locator( '[data-os-app]' ) ).not.toHaveAttribute( 'aria-busy', 'true', { timeout: 30_000 } );
				await win.locator( '.fleet-native-filters' ).waitFor();
				if ( type === 'pages' ) {
					await win.locator( 'os-select[name="type"]' ).evaluate( ( field ) => { field.value = 'pages'; } );
					await win.locator( '.fleet-native-filters' ).getByRole( 'button', { name: 'Apply filters', exact: true } ).click();
				}
				await win.getByRole( 'button', { name: type === 'pages' ? 'New page' : 'New post', exact: true } ).click();
				const editor = win.locator( '.fleet-native-editor' );
				const requestId = await editor.locator( 'input[name="request_id"]' ).inputValue();
				await editor.locator( 'os-text-field[name="title"] input' ).fill( title );
				await editor.locator( 'os-textarea[name="content"] textarea' ).fill( source );
				await expect( editor.locator( '.fleet-native-save-state' ).getByText( 'Unsaved changes', { exact: true } ) ).toBeVisible();
				await editor.getByRole( 'button', { name: 'Save to WordPress', exact: true } ).click();
				await waitForAction();
				await expect( win.getByText( 'Saved on WordPress.', { exact: true } ).first() ).toBeVisible();
				const row = win.locator( '.fleet-native-content-list' ).getByRole( 'row' ).filter( { hasText: title } );
				await row.click();
				postId = Number( await editor.locator( 'input[name="content_id"]' ).inputValue() );
				expect( postId ).toBeGreaterThan( 0 );
				const stored = runSiteWp( sitePath, `echo 'FLEET_E2E_JSON:' . wp_json_encode( get_post( ${ postId } ) );` );
				expect( stored.post_content ).toBe( source );
				expect( stored.post_status ).toBe( 'draft' );
				const replay = runSiteWp( process.env.FLEET_E2E_HUB_PATH, `wp_set_current_user( ${ fixture.userId } ); $calls=0; add_filter( 'pre_http_request', static function($pre) use(&$calls){++$calls;return $pre;}); $r=OpenStation_Fleet::app_action('${ remote.id }','content',array('content_type'=>'${ type }','content_id'=>0,'request_id'=>'${ requestId }','title'=>'Duplicate must not be sent','content'=>'','excerpt'=>'','slug'=>'','status'=>'draft','date_gmt'=>''));echo 'FLEET_E2E_JSON:'.wp_json_encode(array('code'=>is_wp_error($r)?$r->get_error_code():'unexpected-success','requests'=>$calls));` );
				expect( replay ).toEqual( { code: 'fleet_create_uncertain', requests: 0 } );
				await editor.locator( 'os-text-field[name="title"] input' ).fill( `${ title } local edit` );
				runSiteWp( sitePath, `wp_update_post( array( 'ID' => ${ postId }, 'post_title' => 'Changed by another editor' ) ); echo 'FLEET_E2E_JSON:true';` );
				await editor.getByRole( 'button', { name: 'Save to WordPress', exact: true } ).click();
				await waitForAction();
				await expect( win.getByText( /This content changed on WordPress/ ) ).toBeVisible();
				await expect( editor.locator( 'os-text-field[name="title"] input' ) ).toHaveValue( `${ title } local edit` );
				expect( runSiteWp( sitePath, `echo 'FLEET_E2E_JSON:' . wp_json_encode( get_the_title( ${ postId } ) );` ) ).toBe( 'Changed by another editor' );
				await win.getByRole( 'button', { name: 'Back to list', exact: true } ).click();
				await page.getByRole( 'button', { name: 'Discard changes', exact: true } ).click();
				await win.locator( '.fleet-native-content-list' ).getByRole( 'row' ).filter( { hasText: 'Changed by another editor' } ).click();
				await editor.locator( 'os-text-field[name="title"] input' ).fill( `${ title } saved` );
				await editor.getByRole( 'button', { name: 'Save to WordPress', exact: true } ).click();
				await waitForAction();
				await expect( win.locator( '.fleet-native-content-list' ).getByRole( 'row' ).filter( { hasText: `${ title } saved` } ) ).toBeVisible();
				await win.locator( '.fleet-native-content-list' ).getByRole( 'row' ).filter( { hasText: `${ title } saved` } ).click();
				await win.getByRole( 'button', { name: 'Move to Trash', exact: true } ).click();
				await page.getByRole( 'button', { name: 'Confirm', exact: true } ).click();
				await waitForAction();
				await expect( win.getByText( 'Moved to Trash on WordPress.', { exact: true } ) ).toBeVisible();
				expect( runSiteWp( sitePath, `echo 'FLEET_E2E_JSON:' . wp_json_encode( get_post_status( ${ postId } ) );` ) ).toBe( 'trash' );
				await page.reload();
				await openShell( page );
				await openManagedSites( page );
				expect( runSiteWp( sitePath, `echo 'FLEET_E2E_JSON:' . wp_json_encode( get_the_title( ${ postId } ) );` ) ).toBe( `${ title } saved` );
			} finally {
				if ( ! postId ) {
					postId = runSiteWp( sitePath, `global $wpdb; echo 'FLEET_E2E_JSON:' . wp_json_encode( (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM $wpdb->posts WHERE post_title = %s", '${ title }' ) ) );` );
				}
				if ( postId ) {
					runSiteWp( sitePath, `wp_delete_post( ${ postId }, true ); echo 'FLEET_E2E_JSON:true';` );
				}
			}
		} );
	}
	test( 'uses native App Framework assets without classic admin assets', async ( { page } ) => {
		const errors = [];
		page.on( 'console', ( message ) => {
			if ( message.type() === 'error' ) {
				errors.push( message.text() );
			}
		} );
		page.on( 'pageerror', ( error ) => errors.push( error.message ) );

		await openShell( page );
		await openHub( page );

		const integration = await page.evaluate( () => {
			const resources = performance.getEntriesByType( 'resource' ).map( ( entry ) => entry.name );
			const hub = window.wp.os.windowManager.getAll().find( ( candidate ) => candidate.config?.baseId === 'fleet-for-openstation' );
			return {
				hubIsNative: Boolean( hub?.config?.native ),
				nativeMarkupInShell: Boolean( document.querySelector( '.fleet-native-sites' ) ),
				fleetFrames: document.querySelectorAll( 'iframe[src*="fleet-for-openstation"]' ).length,
				resources,
			};
		} );

		expect( integration.hubIsNative ).toBe( true );
		expect( integration.nativeMarkupInShell ).toBe( true );
		expect( integration.fleetFrames ).toBe( 0 );
		expect( integration.resources.some( ( url ) => /\/fleet-for-openstation\/assets\/fleet-app\.js(?:\?|$)/.test( url ) ) ).toBe( true );
		expect( integration.resources.some( ( url ) => /\/fleet-for-openstation\/assets\/fleet-app\.css(?:\?|$)/.test( url ) ) ).toBe( true );
		expect( integration.resources.some( ( url ) => /\/fleet-for-openstation\/assets\/admin\.(?:js|css)(?:\?|$)/.test( url ) ) ).toBe( false );
		assertNoConsoleErrors( errors );
	} );

	test( 'keeps two managed-site windows and their tabs independent', async ( { page } ) => {
		const errors = [];
		page.on( 'console', ( message ) => {
			if ( message.type() === 'error' ) {
				errors.push( message.text() );
			}
		} );
		page.on( 'pageerror', ( error ) => errors.push( error.message ) );

		await openShell( page );
		const windows = await openManagedSites( page );

		expect( new Set( windows.map( ( window ) => window.siteId ) ).size ).toBe( 2 );
		expect( new Set( windows.map( ( window ) => window.id ) ).size ).toBe( windows.length );
		expect( windows.every( ( window ) => window.elementId ) ).toBe( true );

		await page.evaluate( ( siteIds ) => {
			const managed = siteIds.map( ( siteId ) => window.wp.os.windowManager.getAll().find( ( candidate ) => (
				candidate.config?.baseId === 'fleet-site'
				&& String( candidate.config?.params?.site_id || '' ) === siteId
			) ) );
			const [ first, second ] = managed;
			first.element.querySelector( '.os-window__tab[data-panel="content"]' ).click();
			second.element.querySelector( '.os-window__tab[data-panel="comments"]' ).click();
		}, fixture.sites.map( ( site ) => site.id ) );
		await page.waitForFunction( ( elementIds ) => (
			document.querySelector( `#${ elementIds[ 0 ] } .os-window__tab[data-panel="content"]` )?.getAttribute( 'aria-selected' ) === 'true'
			&& document.querySelector( `#${ elementIds[ 1 ] } .os-window__tab[data-panel="comments"]` )?.getAttribute( 'aria-selected' ) === 'true'
		), windows.map( ( window ) => window.elementId ) );
		await expect( page.locator( `#${ windows[ 0 ].elementId } os-tabpanel[for="content"] .fleet-native-site-header` ) ).toBeVisible();
		await expect( page.locator( `#${ windows[ 1 ].elementId } os-tabpanel[for="comments"] .fleet-native-site-header` ) ).toBeVisible();
		const state = await page.evaluate( ( selectedWindows ) => selectedWindows.map( ( selected ) => ( {
			siteId: selected.siteId,
			tab: document.querySelector( `#${ selected.elementId } .os-window__tab[aria-selected="true"]` )?.dataset.panel,
		} ) ), windows );

		expect( state[ 0 ] ).toEqual( { siteId: fixture.sites[ 0 ].id, tab: 'content' } );
		expect( state[ 1 ] ).toEqual( { siteId: fixture.sites[ 1 ].id, tab: 'comments' } );
		await expect( page.locator( `#${ windows[ 0 ].elementId }` ).getByText( fixture.sites[ 0 ].name, { exact: true } ).first() ).toBeVisible();
		await expect( page.locator( `#${ windows[ 1 ].elementId }` ).getByText( fixture.sites[ 1 ].name, { exact: true } ).first() ).toBeVisible();
		assertNoConsoleErrors( errors );
	} );

	test( 'has the Core REST and Application Password prerequisites for every connection', async ( { request } ) => {
		for ( const site of fixture.sites ) {
			expect( new URL( site.site_url ).protocol ).toBe( 'https:' );
			expect( new URL( site.rest_url ).protocol ).toBe( 'https:' );
			expect( new URL( site.rest_url ).origin ).toBe( new URL( site.site_url ).origin );
			expect( site.credential_ready ).toBe( true );

			const response = await request.get( site.rest_url );
			expect( response.ok(), `${ site.name } REST index should be reachable` ).toBe( true );
			const index = await response.json();
			expect( index.namespaces ).toContain( 'wp/v2' );
			expect( index.authentication?.[ 'application-passwords' ]?.endpoints?.authorization ).toMatch( /^https:\/\// );
		}
	} );

	test( 'stores every migrated connection independently before removing the previous aggregate', () => {
		expect( fixture.migration.index_count ).toBeGreaterThanOrEqual( fixture.sites.length );
		expect( fixture.migration.record_count ).toBe( fixture.migration.index_count );
		expect( fixture.migration.aggregate_removed ).toBe( true );
		if ( fixture.migration.aggregate_count > 0 ) {
			expect( fixture.migration.record_count ).toBeGreaterThanOrEqual( fixture.migration.aggregate_count );
		}
	} );

	test( 'has no WCAG A or AA axe violations in the hub and managed windows', async ( { page } ) => {
		await openShell( page );
		const hubElementId = await openHub( page );
		const windows = await openManagedSites( page );

		const results = await new AxeBuilder( { page } )
			.include( [ `#${ hubElementId }`, ...windows.map( ( window ) => `#${ window.elementId }` ) ] )
			.withTags( [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa' ] )
			.analyze();
		expect(
			results.violations,
			results.violations.map( ( violation ) => `${ violation.id }: ${ violation.help }` ).join( '\n' )
		).toEqual( [] );
	} );
} );
