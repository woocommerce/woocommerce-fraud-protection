/**
 * PayPal Express fetch interceptor for Woo Fraud Protection.
 *
 * Intercepts PayPal's ppc-create-order AJAX calls to inject the Blackbox
 * session ID into the request body. This allows the server-side PayPalCompat
 * handler to verify the session before PayPal order creation.
 *
 * Follows the same fetch interceptor pattern as PayPal's reCAPTCHA module.
 *
 * Resets Blackbox after the CreateOrder fetch returns so subsequent payment
 * attempts (retry, different method) get a fresh session for evaluation.
 */
( function () {
	if ( ! window.wcFraudProtection ) {
		return;
	}

	const fp = window.wcFraudProtection;
	const originalFetch = window.fetch;

	window.fetch = async function ( resource, init ) {
		init = init || {};
		// fetch() accepts string, URL, or Request objects.
		const url =
			resource instanceof Request ? resource.url : String( resource );

		if ( ! url || url.indexOf( 'ppc-create-order' ) === -1 ) {
			return originalFetch.call( this, resource, init );
		}

		// Acquire session ID (5s timeout, fail-open on timeout).
		const sessionId = await fp.acquireSessionId();

		try {
			const body = JSON.parse( init.body );
			body[ fp.config.sessionIdField ] = sessionId;
			init.body = JSON.stringify( body );
		} catch ( e ) {
			// Fail-open: send the request without session ID.
		}

		try {
			return await originalFetch.call( this, resource, init );
		} finally {
			// Reset Blackbox so subsequent payment attempts get a fresh session.
			fp.reset();
		}
	};
} )();
