( function () {
	'use strict';

	if ( window.__openStationFleetEffects ) {
		return;
	}
	window.__openStationFleetEffects = true;

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

	document.addEventListener( 'os-app-effect', ( event ) => {
		const detail = event.detail || {};
		const effect = detail.effect || {};
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
