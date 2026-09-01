( function () {
	'use strict';

	const openOpenStationWindow = ( link, event = null ) => {
		if ( window.parent === window ) {
			return false;
		}

		try {
			const manager = window.parent.wp && window.parent.wp.os && window.parent.wp.os.windowManager;
			if ( ! manager || typeof manager.open !== 'function' ) {
				return false;
			}

			if ( event ) {
				event.preventDefault();
				event.stopPropagation();
			}
			const windowId = link.dataset.fleetWindowId || 'admin-php-page-fleet-for-openstation';
			if ( link.hasAttribute( 'data-fleet-hub-window' ) && typeof manager.getById === 'function' ) {
				const hub = manager.getById( windowId );
				if ( hub ) {
					if ( typeof hub.navigateTo === 'function' ) {
						hub.navigateTo( link.href );
					}
					if ( typeof manager.focus === 'function' ) {
						manager.focus( hub );
					}
					return true;
				}
			}
			const opened = manager.open( {
				id: windowId,
				baseId: windowId,
				url: link.href,
				parentUrl: link.href,
				title: link.dataset.fleetWindowTitle || 'Fleet',
				icon: 'dashicons-admin-site-alt3',
				multi: false,
			} );
			if ( ! opened ) {
				window.location.assign( link.href );
			}
			return true;
		} catch ( error ) {
			if ( event ) {
				window.location.assign( link.href );
				return true;
			}
			return false;
		}
	};

	document.querySelectorAll( '[data-fleet-window-id], [data-fleet-hub-window]' ).forEach( ( link ) => {
		link.addEventListener( 'click', ( event ) => {
			if ( event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey ) {
				return;
			}
			openOpenStationWindow( link, event );
		} );
	} );

	const automaticLaunch = document.querySelector( '[data-fleet-auto-open]' );
	if ( automaticLaunch && openOpenStationWindow( automaticLaunch ) ) {
		window.history.replaceState( {}, '', automaticLaunch.dataset.fleetReturnUrl );
	}

	/*
	 * A managed site has its own OpenStation window id, while all Fleet URLs
	 * use the same WordPress admin.php file. Keep section changes in that
	 * site's iframe so OpenStation does not route them back to the hub window.
	 */
	document.querySelectorAll( '.fleet-workspace a[href]:not([data-fleet-hub-window])' ).forEach( ( link ) => {
		link.addEventListener( 'click', ( event ) => {
			if ( event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey ) {
				return;
			}
			let url;
			try {
				url = new URL( link.href, window.location.href );
			} catch ( error ) {
				return;
			}
			if ( url.origin !== window.location.origin || url.pathname !== window.location.pathname || url.searchParams.get( 'page' ) !== 'fleet-for-openstation' ) {
				return;
			}
			event.preventDefault();
			event.stopPropagation();
			window.location.assign( url.toString() );
		} );
	} );

	const apiRoute = document.querySelector( '#fleet-api-route' );
	const apiMethod = document.querySelector( '#fleet-api-method' );
	const routeFilter = document.querySelector( '[data-fleet-route-filter]' );
	const routeCards = Array.from( document.querySelectorAll( '[data-fleet-api-route]' ) );

	routeCards.forEach( ( card ) => {
		card.addEventListener( 'click', () => {
			if ( apiRoute ) {
				apiRoute.value = card.dataset.fleetApiRoute;
				apiRoute.focus();
			}
			if ( apiMethod && card.dataset.fleetApiMethod ) {
				apiMethod.value = card.dataset.fleetApiMethod;
			}
		} );
	} );

	if ( routeFilter ) {
		routeFilter.addEventListener( 'input', () => {
			const query = routeFilter.value.trim().toLowerCase();
			routeCards.forEach( ( card ) => {
				card.hidden = query !== '' && ! card.textContent.toLowerCase().includes( query );
			} );
		} );
	}

	const setup = document.querySelector( '.fleet-setup' );
	if ( ! setup ) {
		return;
	}

	const message = setup.querySelector( '[data-fleet-setup-message]' );
	const steps = Array.from( setup.querySelectorAll( '[data-fleet-setup-step]' ) );
	const error = setup.querySelector( '[data-fleet-setup-error]' );
	const errorMessage = setup.querySelector( '[data-fleet-setup-error-message]' );
	const messages = JSON.parse( setup.dataset.messages );
	let currentStep = 0;

	const showStep = ( index ) => {
		currentStep = Math.min( index, steps.length - 1 );
		steps.forEach( ( step, stepIndex ) => {
			step.classList.toggle( 'is-active', stepIndex === currentStep );
			step.classList.toggle( 'is-complete', stepIndex < currentStep );
		} );
		message.textContent = messages[ currentStep ];
	};

	const timer = window.setInterval( () => {
		if ( currentStep < steps.length - 1 ) {
			showStep( currentStep + 1 );
		}
	}, 4500 );

	const body = new URLSearchParams( {
		action: 'openstation_fleet_finish_setup',
		site_id: setup.dataset.siteId,
		_ajax_nonce: setup.dataset.nonce,
	} );

	fetch( setup.dataset.ajaxUrl, {
		method: 'POST',
		credentials: 'same-origin',
		headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
		body: body.toString(),
	} )
		.then( ( response ) => response.json() )
		.then( ( result ) => {
			if ( ! result.success || ! result.data || ! result.data.redirect ) {
				throw new Error( result.data && result.data.message ? result.data.message : setup.dataset.errorMessage );
			}
			window.clearInterval( timer );
			showStep( steps.length - 1 );
			steps.forEach( ( step ) => step.classList.add( 'is-complete' ) );
			message.textContent = setup.dataset.completeMessage;
			window.setTimeout( () => {
				let redirect;
				try {
					redirect = new URL( result.data.redirect, window.location.href );
				} catch ( error ) {
					window.top.location.assign( result.data.redirect );
					return;
				}

				const siteId = redirect.searchParams.get( 'site_id' );
				if ( siteId ) {
					const launch = document.createElement( 'a' );
					launch.href = redirect.toString();
					launch.dataset.fleetWindowId = setup.dataset.windowId;
					launch.dataset.fleetWindowTitle = setup.dataset.windowTitle;
					if ( openOpenStationWindow( launch ) ) {
						window.location.assign( setup.dataset.hubUrl );
						return;
					}
				}
				window.top.location.assign( redirect.toString() );
			}, 450 );
		} )
		.catch( ( caught ) => {
			window.clearInterval( timer );
			message.hidden = true;
			error.hidden = false;
			errorMessage.textContent = caught.message;
		} );
}() );
