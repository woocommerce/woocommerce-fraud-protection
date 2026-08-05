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
 *
 * Fail-open (CRITICAL): window.wcFraudProtection always carries `config`
 * (printed by wp_localize_script), but acquireSessionId()/reset() are attached
 * by blackbox-init.js only when the Blackbox SDK loaded — possibly after this
 * script runs, or never (content blockers, network failures). The session API
 * is therefore checked at call time, and on any failure the request must go
 * through unmodified. window.fetch is global: an interceptor error must never
 * break it.
 */
( function () {
	if ( ! window.wcFraudProtection ) {
		return;
	}

	const originalFetch = window.fetch;

	/**
	 * Inject the Blackbox session ID into a ppc-create-order request, dispatch
	 * it, then reset Blackbox. Any error before dispatch sends the request
	 * without a session ID; any error after dispatch never affects the response.
	 *
	 * @param {*}      thisArg  `this` of the intercepted fetch call.
	 * @param {Object} fp       The window.wcFraudProtection API object.
	 * @param {*}      resource fetch() resource argument.
	 * @param {Object} init     fetch() init argument.
	 * @return {Promise} The fetch response promise.
	 */
	async function interceptCreateOrder( thisArg, fp, resource, init ) {
		try {
			// Acquire session ID (fail-open on timeout, see SESSION_ID_TIMEOUT_MS).
			const sessionId = await fp.acquireSessionId();
			const body = JSON.parse( init.body );
			body[ fp.config.sessionIdField ] = sessionId;
			init.body = JSON.stringify( body );
		} catch ( e ) {
			// Fail-open: send the request without session ID.
		}

		try {
			return await originalFetch.call( thisArg, resource, init );
		} finally {
			try {
				// Reset Blackbox so subsequent payment attempts get a fresh session.
				fp.reset();
			} catch ( e ) {
				// Fail-open: a reset failure must not affect the response.
			}
		}
	}

	window.fetch = function ( resource, init ) {
		try {
			// fetch() accepts string, URL, or Request objects.
			const url =
				resource instanceof Request ? resource.url : String( resource );
			const fp = window.wcFraudProtection;

			if (
				url &&
				url.indexOf( 'ppc-create-order' ) !== -1 &&
				fp &&
				typeof fp.acquireSessionId === 'function'
			) {
				return interceptCreateOrder( this, fp, resource, init || {} );
			}
		} catch ( e ) {
			// Fail-open: fall through to the original fetch.
		}

		return originalFetch.call( this, resource, init );
	};
} )();
