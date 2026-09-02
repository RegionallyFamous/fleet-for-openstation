const { test, expect } = require( '@playwright/test' );
const AxeBuilder = require( '@axe-core/playwright' ).default;
const { loadWordPressFixture } = require( './wordpress-fixture' );

const fixture = loadWordPressFixture();

async function openShell( page ) {
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

	return page.evaluate( ( ids ) => ids.map( ( siteId ) => {
		const candidate = window.wp.os.windowManager.getAll().find( ( window ) => (
			window.config?.baseId === 'fleet-site'
			&& String( window.config?.params?.site_id || '' ) === siteId
		) );
		return { id: candidate.id, elementId: candidate.element.id, siteId };
	} ), siteIds );
}

function assertNoConsoleErrors( errors ) {
	expect( errors, errors.join( '\n' ) ).toEqual( [] );
}

test.describe( 'Fleet App Framework integration', () => {
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
