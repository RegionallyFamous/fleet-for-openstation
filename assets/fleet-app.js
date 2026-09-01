( function () {
	'use strict';

	if ( window.__openStationFleetEffects ) {
		return;
	}
	window.__openStationFleetEffects = true;

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

	const openSite = ( siteId ) => {
		if ( ! siteId || ! window.wp || ! window.wp.os || typeof window.wp.os.openNewWindow !== 'function' ) {
			return false;
		}
		const normalizedId = String( siteId );
		const manager = window.wp.os.windowManager;
		if ( manager && typeof manager.getAll === 'function' ) {
			const existing = manager.getAll().find( ( candidate ) => matchesSite( candidate, normalizedId ) );
			if ( existing ) {
				focusSiteWindow( manager, existing );
				return true;
			}
		}
		const opened = window.wp.os.openNewWindow( 'fleet-site', {
			source: 'fleet',
			params: { site_id: normalizedId },
		} );
		if ( opened && manager && typeof manager.getAll === 'function' ) {
			let attempts = 0;
			const focusOpenedWindow = () => {
				const candidate = manager.getAll().find( ( item ) => matchesSite( item, normalizedId ) );
				if ( candidate ) {
					focusSiteWindow( manager, candidate );
					return;
				}
				attempts += 1;
				if ( attempts < 20 ) {
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
			const siteIds = Array.isArray( effect.siteIds ) ? effect.siteIds.slice( 0, 8 ) : [];
			siteIds.forEach( ( siteId, index ) => {
				window.setTimeout( () => openSite( siteId ), index * 160 );
			} );
			return;
		}
		if ( effect.type === 'fleet-authorize' && typeof effect.url === 'string' ) {
			try {
				const url = new URL( effect.url, window.location.href );
				if ( url.protocol === 'https:' ) {
					window.top.location.assign( url.toString() );
				}
			} catch ( error ) {
				// The server validates this URL; malformed client data is inert.
			}
		}
	} );
} )();
