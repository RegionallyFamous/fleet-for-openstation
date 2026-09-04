( function () {
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
} )();
