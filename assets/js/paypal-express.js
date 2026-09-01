/**
 * PayPal request fetch interceptor for Woo Fraud Protection.
 *
 * Injects the browser session into supported PayPal requests. Errors
 * remain fail open and never prevent the original Fetch call.
 */
( function () {
	if ( ! window.wcFraudProtection ) {
		return;
	}

	const originalFetch = window.fetch;
	const protectedPayPalEndpoints = [
		'ppc-create-order',
		'ppc-create-setup-token',
		'ppc-vault-create-order',
	];
	let resetBeforeNextPayPalRequest = false;

	function getEndpoint( resource ) {
		const url =
			resource instanceof Request ? resource.url : String( resource );
		return (
			new URL( url, window.location.href ).searchParams.get(
				'wc-ajax'
			) || ''
		);
	}

	function resetSessionSafely( fp ) {
		try {
			if ( typeof fp.reset === 'function' ) {
				fp.reset();
			}
		} catch ( e ) {
			// Fail open.
		}
	}

	async function classifyResponse( response ) {
		if ( ! response || false === response.ok ) {
			return 'failure';
		}

		try {
			const data = await response.clone().json();
			return data && false === data.success ? 'failure' : 'success';
		} catch ( e ) {
			return 'unknown';
		}
	}

	async function dispatchProtectedPayPalRequest(
		thisArg,
		fp,
		resource,
		init
	) {
		if ( resetBeforeNextPayPalRequest ) {
			resetSessionSafely( fp );
			resetBeforeNextPayPalRequest = false;
		}

		try {
			const sessionId = await fp.acquireSessionId();
			const body = JSON.parse( init.body );
			body[ fp.config.sessionIdField ] = sessionId;
			init.body = JSON.stringify( body );
		} catch ( e ) {
			// Fail open with the original request data.
		}

		let response;
		try {
			response = await originalFetch.call( thisArg, resource, init );
		} catch ( e ) {
			resetSessionSafely( fp );
			throw e;
		}

		const result = await classifyResponse( response );
		if ( 'failure' === result ) {
			resetSessionSafely( fp );
		} else {
			resetBeforeNextPayPalRequest = true;
		}

		return response;
	}

	window.fetch = function ( resource, init ) {
		try {
			const fp = window.wcFraudProtection;
			if (
				protectedPayPalEndpoints.includes( getEndpoint( resource ) ) &&
				fp &&
				typeof fp.acquireSessionId === 'function'
			) {
				return dispatchProtectedPayPalRequest(
					this,
					fp,
					resource,
					init || {}
				);
			}
		} catch ( e ) {
			// Fail open.
		}

		return originalFetch.call( this, resource, init );
	};
} )();
