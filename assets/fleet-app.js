window.wp.os.ready( function () {
	'use strict';

	if ( window.__openStationFleetEffects ) {
		return;
	}
	window.__openStationFleetEffects = true;

	// Ask the public loader before server-rendered controls arrive. The shared
	// kit is deduplicated by OpenStation; Fleet never bundles a second copy.
	window.wp.os.loadComponents( [ 'os-stack', 'os-tabpanel', 'os-form', 'os-table', 'os-save-status' ] )
		.catch( ( error ) => window.console.error( '[Fleet] Native controls could not load.', error ) );

	const pendingSites = new Map();
	let authorizationStarted = false;
	let recentWorkspace = { key: '', openedAt: 0 };

	const actionEvents = {
		'os-button': 'click',
		button: 'click',
		'os-card': 'os-card-click',
		'os-form': 'os-form-submit',
	};

	const actionForEvent = ( event ) => {
		const path = typeof event.composedPath === 'function' ? event.composedPath() : [];
		const action = path.find( ( candidate ) => candidate instanceof Element && candidate.hasAttribute( 'os-action' ) );
		if ( ! action ) {
			return null;
		}
		const eventName = action.getAttribute( 'os-on' ) || actionEvents[ action.tagName.toLowerCase() ] || 'click';
		return eventName === event.type ? action : null;
	};

	const preventQueuedAction = ( event ) => {
		const action = actionForEvent( event );
		const root = action && action.closest( '[data-os-app]' );
		if ( ! root || root.getAttribute( 'aria-busy' ) !== 'true' ) {
			return;
		}
		if ( event.cancelable ) {
			event.preventDefault();
		}
		event.stopImmediatePropagation();
	};

	const reflectPendingAction = ( event ) => {
		const action = actionForEvent( event );
		const root = action && action.closest( '[data-os-app]' );
		if ( ! root ) {
			return;
		}
		window.queueMicrotask( () => {
			if ( root.getAttribute( 'aria-busy' ) !== 'true' || ! action.isConnected ) {
				return;
			}
			const ariaDisabled = action.getAttribute( 'aria-disabled' );
			action.setAttribute( 'aria-disabled', 'true' );
			const observer = new MutationObserver( () => {
				if ( root.getAttribute( 'aria-busy' ) === 'true' ) {
					return;
				}
				observer.disconnect();
				if ( action.isConnected ) {
					if ( ariaDisabled === null ) {
						action.removeAttribute( 'aria-disabled' );
					} else {
						action.setAttribute( 'aria-disabled', ariaDisabled );
					}
				}
			} );
			observer.observe( root, { attributes: true, attributeFilter: [ 'aria-busy' ] } );
		} );
	};

	new Set( Object.values( actionEvents ) ).forEach( ( eventName ) => {
		document.addEventListener( eventName, preventQueuedAction, true );
		document.addEventListener( eventName, reflectPendingAction );
	} );

	const matchesSite = ( candidate, siteId ) => {
		const config = candidate && candidate.config ? candidate.config : {};
		return config.baseId === 'fleet-site'
			&& config.params
			&& String( config.params.site_id || '' ) === siteId;
	};

	const focusSiteWindow = ( manager, candidate ) => {
		if ( typeof candidate.restore === 'function' ) {
			candidate.restore();
		}
		if ( typeof manager.focus === 'function' ) {
			manager.focus( candidate );
		}
	};

	const releasePendingSite = ( siteId, token ) => {
		if ( pendingSites.get( siteId ) !== token ) {
			return;
		}
		window.clearTimeout( token.timeout );
		pendingSites.delete( siteId );
	};

	const openSite = ( siteId ) => {
		if ( ! siteId || ! window.wp || ! window.wp.os || typeof window.wp.os.openNewWindow !== 'function' ) {
			return false;
		}
		const normalizedId = String( siteId );
		const manager = window.wp.os.windowManager;
		if ( manager && typeof manager.getAll === 'function' ) {
			const existing = manager.getAll().find( ( candidate ) => matchesSite( candidate, normalizedId ) );
			if ( existing ) {
				const pending = pendingSites.get( normalizedId );
				if ( pending ) {
					releasePendingSite( normalizedId, pending );
				}
				focusSiteWindow( manager, existing );
				return true;
			}
		}
		if ( pendingSites.has( normalizedId ) ) {
			return true;
		}
		const token = {};
		token.timeout = window.setTimeout( () => releasePendingSite( normalizedId, token ), 4000 );
		pendingSites.set( normalizedId, token );
		let opened = false;
		try {
			opened = window.wp.os.openNewWindow( 'fleet-site', {
				source: 'fleet',
				params: { site_id: normalizedId },
			} );
		} catch ( error ) {
			releasePendingSite( normalizedId, token );
			return false;
		}
		if ( ! opened ) {
			releasePendingSite( normalizedId, token );
			return false;
		}
		if ( opened && manager && typeof manager.getAll === 'function' ) {
			let attempts = 0;
			const focusOpenedWindow = () => {
				const candidate = manager.getAll().find( ( item ) => matchesSite( item, normalizedId ) );
				if ( candidate ) {
					releasePendingSite( normalizedId, token );
					focusSiteWindow( manager, candidate );
					return;
				}
				attempts += 1;
				if ( attempts < 40 ) {
					window.setTimeout( focusOpenedWindow, 50 );
				}
			};
			window.setTimeout( focusOpenedWindow, 0 );
		}
		return opened;
	};

	// Unsaved content stays in this page's memory, never in localStorage or cookies.
	const dirtyWindows = new Set();
	const windowElement = ( element ) => element?.closest?.( '.os-window' );
	const appWindowId = ( element ) => window.wp.os.windowManager.getAll().find( ( win ) => win.element === windowElement( element ) )?.id;
	const recovery = new Map();
	const uploads = new Set();
	document.addEventListener( 'click', async ( event ) => {
		const button = event.target.closest?.( '.fleet-native-upload-send' );
		if ( ! button ) { return; }
		const panel = button.closest( '.fleet-native-upload' );
		const file = panel.querySelector( 'input[type="file"]' ).files[ 0 ];
		const status = panel.querySelector( '.fleet-native-upload-status' );
		if ( ! file || file.size < 1 || file.size > 2097152 ) { status.textContent = 'Choose one file between 1 byte and 2 MB.'; return; }
		const owner = windowElement( panel );
		if ( uploads.has( owner.id ) ) { return; }
		uploads.add( owner.id );
		button.setAttribute( 'busy', '' );
		try {
			const accepted = await window.wp.os.confirm( { title: 'Upload to this site?', message: `${ file.name } will be uploaded to the site named in this window.`, confirmLabel: 'Upload' } );
			if ( ! accepted ) { return; }
			status.textContent = 'Uploading… Keep this window open.';
			const bytes = await new Promise( ( resolve, reject ) => {
				const reader = new FileReader();
				reader.onload = () => resolve( String( reader.result ).split( ',' )[ 1 ] );
				reader.onerror = reject;
				reader.readAsDataURL( file );
			} );
			const ok = await window.wp.os.apps.dispatch( appWindowId( panel ), 'upload-media', { filename: file.name, bytes, request_id: button.dataset.requestId }, 'media' );
			if ( ! ok && status.isConnected ) { status.textContent = 'Upload result unknown. Check the media library before selecting this file again.'; }
		} catch ( error ) {
			if ( status.isConnected ) { status.textContent = 'The file could not be read or the upload was interrupted. Check the library before trying again.'; }
		} finally {
			uploads.delete( owner.id );
			button.removeAttribute( 'busy' );
		}
	} );
	const editorFor = ( element ) => element.closest( '.os-window' )?.querySelector( '.fleet-native-editor' );
	const publishingDispatch = ( element, args ) => {
		const form = editorFor( element );
		if ( ! form ) { return; }
		window.wp.os.apps.dispatch( appWindowId( form ), 'publishing-options', { ...args, draft: form.getValues() }, 'content' );
	};
	document.addEventListener( 'os-form-submit', ( event ) => {
		if ( event.target.matches?.( '.fleet-native-bulk-comments' ) ) {
			event.preventDefault();
			event.stopImmediatePropagation();
			const ids = Array.from( event.target.querySelectorAll( 'input[type="checkbox"]:checked' ), ( input ) => input.value );
			window.wp.os.apps.dispatch( appWindowId( event.target ), 'review-comments', { ...event.detail.values, ids }, 'comments' );
			return;
		}
		if ( ! event.target.matches?.( '.fleet-native-picker-search' ) ) { return; }
		event.preventDefault();
		event.stopImmediatePropagation();
		publishingDispatch( event.target, event.detail.values );
	}, true );
	document.addEventListener( 'click', ( event ) => {
		const target = event.target.closest?.( '.fleet-native-picker-open,.fleet-native-pick-item,.fleet-native-pick-clear,.fleet-native-picker-page' );
		if ( ! target ) { return; }
		event.preventDefault();
		const form = editorFor( target );
		if ( ! form || form.closest( '[data-os-app]' )?.getAttribute( 'aria-busy' ) === 'true' ) { return; }
		if ( target.matches( '.fleet-native-picker-open' ) ) {
			publishingDispatch( target, { kind: target.dataset.kind } );
			return;
		}
		const picker = target.closest( '.fleet-native-publishing-picker' );
		const kind = picker.dataset.kind;
		if ( target.matches( '.fleet-native-picker-page' ) ) {
			publishingDispatch( target, { ...picker.querySelector( 'os-form' ).getValues(), page: target.dataset.page } );
			return;
		}
		const input = form.querySelector( `[name="${ kind }"]` );
		if ( ! input ) { return; }
		const multiple = kind === 'categories' || kind === 'tags';
		if ( target.matches( '.fleet-native-pick-clear' ) ) {
			input.value = kind === 'featured_media' ? '0' : '';
		} else if ( multiple ) {
			const ids = new Set( input.value.split( ',' ).filter( Boolean ) );
			ids.has( target.dataset.id ) ? ids.delete( target.dataset.id ) : ids.add( target.dataset.id );
			input.value = Array.from( ids ).sort( ( a, b ) => Number( a ) - Number( b ) ).join( ',' );
		} else {
			input.value = target.dataset.id;
		}
		form.querySelector( `[data-publishing-value="${ kind }"]` ).textContent = multiple ? input.value.split( ',' ).filter( Boolean ).map( ( id ) => `#${ id }` ).join( ', ' ) || 'None selected' : target.matches( '.fleet-native-pick-clear' ) ? 'Not selected' : target.textContent.trim();
		dirtyWindows.add( windowElement( form ).id );
		form.dispatchEvent( new CustomEvent( 'os-form-input', { bubbles: true, detail: { form } } ) );
	} );
	// Core Heartbeat saves encrypted checkpoints without morphing live editor DOM.
	// Source is never written to browser storage, cookies, or OpenStation settings.
	document.addEventListener( 'change', ( event ) => {
		if ( ! event.target.matches?.( '.fleet-native-recovery-enable' ) ) { return; }
		const form = editorFor( event.target );
		const id = windowElement( form ).id;
		if ( event.target.checked ) {
			recovery.set( id, { form, sequence: Date.now(), last: '', pending: '' } );
			form.querySelector( '.fleet-native-recovery-status' ).textContent = 'Waiting for a WordPress heartbeat…';
			window.wp.heartbeat.connectNow();
		} else {
			recovery.delete( id );
			form.querySelector( '.fleet-native-recovery-status' ).textContent = 'Off · Existing checkpoints expire after seven days or can be deleted from the content list.';
		}
	} );
	window.jQuery( document ).on( 'heartbeat-send.fleet', ( event, data ) => {
		const payload = {};
		for ( const [ id, item ] of recovery ) {
			if ( ! item.form.isConnected ) { recovery.delete( id ); continue; }
			if ( Object.keys( payload ).length >= 5 ) { break; }
			const values = item.form.getValues();
			const json = JSON.stringify( values );
			if ( json === item.last ) { continue; }
			item.pending = json;
			item.sequence += 1;
			payload[ id ] = { ...values, original_status: window.wp.os.apps.session( appWindowId( item.form ), 'content' )?.state.editor.original_status || 'draft', enabled: true, sequence: item.sequence, site_id: item.form.dataset.siteId, connection: item.form.dataset.connection };
		}
		if ( Object.keys( payload ).length ) { data.fleet_recovery = payload; }
	} ).on( 'heartbeat-tick.fleet', ( event, data ) => {
		for ( const [ id, result ] of Object.entries( data.fleet_recovery || {} ) ) {
			const item = recovery.get( id );
			if ( ! item?.form.isConnected ) { continue; }
			item.form.querySelector( '.fleet-native-recovery-status' ).textContent = result.error || `Recovery copy saved at ${ new Date( result.saved * 1000 ).toLocaleTimeString() }. Not saved to WordPress.`;
			if ( ! result.error ) { item.last = item.pending; }
		}
	} ).on( 'heartbeat-error.fleet', () => {
		for ( const item of recovery.values() ) {
			if ( item.form.isConnected ) { item.form.querySelector( '.fleet-native-recovery-status' ).textContent = 'Recovery unavailable. Keep this window open and save or copy your source.'; }
		}
	} );
	const confirmDiscard = () => window.wp.os.confirm( {
		title: 'Discard unsaved changes?',
		message: 'Your content has not been saved to WordPress. Stay here to save it or copy your changes first.',
		confirmLabel: 'Discard changes',
		danger: true,
	} );
	document.addEventListener( 'os-form-input', ( event ) => {
		const form = event.detail?.form;
		if ( ! form?.matches?.( '.fleet-native-editor' ) ) {
			return;
		}
		const owner = windowElement( form );
		if ( owner ) {
			dirtyWindows.add( owner.id );
			const status = form.querySelector( '.fleet-native-save-state' );
			if ( status ) {
				status.setAttribute( 'idle-label', 'Unsaved changes' );
			}
		}
	} );
	document.addEventListener( 'click', ( event ) => {
		const target = event.target?.closest?.( '.os-window__tab, [os-action]' );
		const owner = windowElement( target );
		if ( ! owner || ! dirtyWindows.has( owner.id ) || target.closest( '.fleet-native-editor, .fleet-native-editor-action' ) ) {
			return;
		}
		event.preventDefault();
		event.stopImmediatePropagation();
		confirmDiscard().then( ( accepted ) => {
			if ( accepted && target.isConnected ) {
				dirtyWindows.delete( owner.id );
				target.click();
			}
		} );
	}, true );
	window.addEventListener( 'beforeunload', ( event ) => {
		if ( dirtyWindows.size ) {
			event.preventDefault();
			event.returnValue = '';
		}
	} );
	window.wp?.hooks?.addFilter( 'os.native-window.before-close', 'fleet/unsaved-content', ( allowed, context ) => {
		const managed = window.wp.os.windowManager.getById( context.windowId );
		const owner = managed?.element;
		if ( ! allowed || ! owner || ! dirtyWindows.has( owner.id ) ) {
			return allowed;
		}
		confirmDiscard().then( ( accepted ) => {
			if ( accepted ) {
				dirtyWindows.delete( owner.id );
				managed.close();
			}
		} );
		return false;
	} );

	const callback = new URL( window.location.href );
	const connectedId = callback.searchParams.get( 'fleet_connected' );
	if ( connectedId && /^[a-f0-9]{16}$/.test( connectedId ) ) {
		callback.searchParams.delete( 'fleet_connected' );
		window.history.replaceState( null, '', callback.toString() );
		let attempts = 0;
		const resumeSetup = () => {
			if ( ! openSite( connectedId ) && ++attempts < 40 ) {
				window.setTimeout( resumeSetup, 250 );
			}
		};
		window.setTimeout( resumeSetup, 250 );
	}

	document.addEventListener( 'os-app-effect', ( event ) => {
		const detail = event.detail || {};
		const effect = detail.effect || {};
		if ( effect.type === 'fleet-editor-clean' || effect.type === 'fleet-editor-dirty' ) {
			const owner = windowElement( event.target );
			if ( owner ) {
				if ( effect.type === 'fleet-editor-dirty' ) {
					dirtyWindows.add( owner.id );
					owner.querySelectorAll( '.fleet-native-save-state' ).forEach( ( status ) => status.setAttribute( 'idle-label', 'Unsaved changes' ) );
				} else {
					dirtyWindows.delete( owner.id );
				}
			}
			return;
		}
		if ( effect.type === 'fleet-open-site' ) {
			openSite( effect.siteId );
			return;
		}
		if ( effect.type === 'fleet-open-workspace' ) {
			const siteIds = Array.isArray( effect.siteIds )
				? Array.from( new Set( effect.siteIds.map( String ).filter( Boolean ) ) ).slice( 0, 8 )
				: [];
			const workspaceKey = siteIds.join( '|' );
			const now = Date.now();
			if ( workspaceKey && recentWorkspace.key === workspaceKey && now - recentWorkspace.openedAt < 2000 ) {
				return;
			}
			recentWorkspace = { key: workspaceKey, openedAt: now };
			siteIds.forEach( ( siteId, index ) => {
				window.setTimeout( () => openSite( siteId ), index * 160 );
			} );
			return;
		}
		if ( effect.type === 'fleet-authorize' && typeof effect.url === 'string' ) {
			if ( authorizationStarted ) {
				return;
			}
			try {
				const url = new URL( effect.url, window.location.href );
				if ( url.protocol === 'https:' ) {
					authorizationStarted = true;
					window.top.location.assign( url.toString() );
				}
			} catch ( error ) {
				authorizationStarted = false;
				// The server validates this URL; malformed client data is inert.
			}
		}
	} );
} );
