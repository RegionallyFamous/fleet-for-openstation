/** Capture real, authenticated demo UI; never authorize a connection or save content. */
const { chromium } = require( '@playwright/test' );
const { loadWordPressFixture } = require( './wordpress-fixture' );
const { createHash } = require( 'node:crypto' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
if ( process.env.FLEET_SCREENSHOTS !== '1' ) { throw new Error( 'Set FLEET_SCREENSHOTS=1 to replace demo captures.' ); }
const fixture = loadWordPressFixture();
const output = path.resolve( __dirname, '../../assets/screenshots' );
( async () => {
	const browser = await chromium.launch();
	try {
		const context = await browser.newContext( { ignoreHTTPSErrors: true, viewport: { width: 1510, height: 1000 }, deviceScaleFactor: 1 } );
		await context.addCookies( fixture.cookies );
		const page = await context.newPage();
		page.setDefaultTimeout( 30_000 );
		await page.goto( `${ fixture.hubUrl }/wp-admin/admin.php?page=openstation` );
		await page.waitForFunction( () => window.wp?.os?.windowManager );
		await page.evaluate( () => {
			window.__fleetCaptureMultiple = false;
			let siteIndex = 0;
			window.wp.hooks.addFilter( 'os.window.geometry', 'fleet/screenshots', ( geometry, context ) => {
				if ( ! [ 'fleet-site', 'fleet-for-openstation' ].includes( context.baseId ) ) { return geometry; }
				if ( window.__fleetCaptureMultiple ) { return { ...geometry, x: 14 + 744 * siteIndex++, y: 70, width: 730, height: 795, state: 'normal' }; }
				return { ...geometry, x: 255, y: 45, width: 1000, height: context.baseId === 'fleet-for-openstation' ? 640 : 835, state: 'normal' };
			} );
		} );
		async function close() {
			await page.evaluate( () => window.wp.os.windowManager.getAll().forEach( ( win ) => win.close() ) );
			await page.waitForFunction( () => window.wp.os.windowManager.getAll().length === 0 );
			// The manager unregisters before the close animation removes its DOM.
			await page.waitForFunction( () => ! document.querySelector( '.os-window' ) );
		}
		async function settle() {
			await page.waitForFunction( () => ! document.querySelector( '[data-os-app][aria-busy="true"]' ) );
			await page.evaluate( () => document.fonts.ready );
			await page.mouse.move( 1490, 25 );
		}
		async function capture( name ) {
			await settle();
			const box = name === 'multiple-sites' ? null : await page.locator( '.os-window' ).boundingBox();
			const clip = box || undefined;
			await page.screenshot( { path: path.join( output, `${ name }.jpg` ), type: 'jpeg', quality: 92, animations: 'disabled', clip } );
			console.log( `Captured ${ name }` );
		}
		await close();
		await page.evaluate( () => window.wp.os.openNewWindow( 'fleet-for-openstation', { source: 'fleet-demo' } ) );
		await page.locator( '.fleet-native-site-card' ).first().waitFor();
		await capture( 'fleet-hub' );
		for ( const [ tab, name ] of [ [ 'inbox', 'fleet-inbox' ], [ 'workspaces', 'client-workspaces' ], [ 'search', 'fleet-search' ] ] ) {
			await page.locator( `.os-window__tab[data-panel="${ tab }"]` ).click();
			if ( tab === 'search' ) {
				const form = page.locator( '.fleet-native-search-form' );
				await form.locator( 'input' ).fill( 'Harbor' );
				await form.getByRole( 'button', { name: 'Search Fleet', exact: true } ).click();
			}
			await capture( name );
		}
		await close();
		await page.evaluate( () => window.wp.os.openNewWindow( 'fleet-for-openstation', { source: 'fleet-demo' } ) );
		await page.locator( '.fleet-native-connect-details' ).waitFor();
		await settle();
		await page.locator( '.fleet-native-connect-details summary' ).click();
		await page.locator( '.fleet-native-connect' ).scrollIntoViewIfNeeded();
		await capture( 'connect-site' );
		await close();
		await page.evaluate( ( id ) => window.wp.os.openNewWindow( 'fleet-site', { source: 'fleet-demo', params: { site_id: id } } ), fixture.sites[ 1 ].id );
		await page.locator( '.fleet-native-site-header' ).first().waitFor();
		await page.locator( '.os-window__tab[data-panel="content"]' ).click();
		await page.locator( 'os-tabpanel[for="content"] .fleet-native-content-list' ).waitFor();
		await capture( 'managed-site' );
		await page.locator( '.fleet-native-content-list' ).getByRole( 'row' ).nth( 1 ).click();
		await page.locator( '.fleet-native-editor' ).waitFor();
		await capture( 'content-editor' );
		// Review is read-only: this exercises the real workflow without confirming a write.
		const editor = page.locator( '.fleet-native-editor' );
		await editor.getByLabel( 'Content source', { exact: true } ).fill( '<!-- wp:paragraph -->\n<p>Our autumn programme brings new workshops, exhibitions, and community events to Harbor Arts.</p>\n<!-- /wp:paragraph -->' );
		await editor.locator( 'os-select[name="status"]' ).evaluate( ( field ) => { field.value = 'publish'; } );
		await editor.getByRole( 'button', { name: 'Save to WordPress', exact: true } ).click();
		await page.locator( '.fleet-native-publish-review' ).waitFor();
		await capture( 'publishing-review' );
		await page.getByRole( 'button', { name: 'Keep editing', exact: true } ).click();
		await page.getByRole( 'button', { name: 'Back to list', exact: true } ).click();
		await page.getByRole( 'button', { name: 'Discard changes', exact: true } ).click();
		await page.locator( '.os-window__tab[data-panel="api"]' ).click();
		await capture( 'api-explorer' );
		await close();
		await page.evaluate( () => { window.__fleetCaptureMultiple = true; } );
		for ( const site of fixture.sites ) {
			await page.evaluate( ( id ) => window.wp.os.openNewWindow( 'fleet-site', { source: 'fleet-demo', params: { site_id: id } } ), site.id );
			await page.waitForFunction( ( id ) => window.wp.os.windowManager.getAll().find( ( win ) => win.config?.params?.site_id === id )?.element?.querySelector( '.fleet-native-site-header' ), site.id );
			const elementId = await page.evaluate( ( id ) => window.wp.os.windowManager.getAll().find( ( win ) => win.config?.params?.site_id === id ).element.id, site.id );
			await page.locator( `#${ elementId } .os-window__tab[data-panel="${ site === fixture.sites[ 0 ] ? 'content' : 'comments' }"]` ).click();
		}
		await capture( 'multiple-sites' );
		const hashes = {};
		for ( const asset of [ 'fleet-app.css', 'fleet-app.js' ] ) {
			const response = await context.request.get( `${ fixture.hubUrl }/wp-content/plugins/fleet-for-openstation/assets/${ asset }` );
			const hash = ( value ) => createHash( 'sha256' ).update( value ).digest( 'hex' );
			const remote = hash( await response.body() );
			const local = hash( fs.readFileSync( path.resolve( output, '..', asset ) ) );
			if ( remote !== local ) { throw new Error( `Stale asset: ${ asset }` ); }
			hashes[ asset ] = remote;
		}
		console.log( JSON.stringify( { verified_assets: hashes } ) );
	} finally { await browser.close(); }
} )().catch( ( error ) => { console.error( error ); process.exitCode = 1; } );
