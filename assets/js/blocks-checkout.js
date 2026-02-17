/**
 * Blocks checkout fraud protection integration.
 *
 * Gates checkout submission to acquire a Blackbox session ID via getSessionId(),
 * sets it as extension data for the checkout POST, then lets the request proceed.
 * Resets Blackbox after checkout completes (success or failure) so that stale
 * behavioral data doesn't accumulate across retries.
 *
 * Depends on blackbox-init.js (SDK configured), wp-data (checkout store), and
 * wc-blocks-checkout-events (validation gate + success/fail hooks).
 */
( function () {
	'use strict';

	const NAMESPACE = 'woocommerce/fraud-protection';
	const STORE_KEY = 'wc/store/checkout';

	const SESSION_ID_TIMEOUT = 5000;

	const checkoutTimeoutGuard = function () {
		return new Promise( function ( resolve ) {
			setTimeout( function () {
				resolve( '' );
			}, SESSION_ID_TIMEOUT );
		} );
	};

	const checkoutEvents =
		window.wc &&
		window.wc.blocksCheckoutEvents &&
		window.wc.blocksCheckoutEvents.checkoutEvents;

	if ( ! checkoutEvents ) {
		return;
	}

	/**
	 * Gate checkout on session_id acquisition.
	 *
	 * When checkout validation fires, acquire a fresh session ID via
	 * getSessionId(), store it as extension data, and let checkout proceed.
	 *
	 * Fail-open: if Blackbox SDK is not loaded or getSessionId() fails,
	 * checkout proceeds without a session_id (server will verify with
	 * empty string).
	 */
	checkoutEvents.onCheckoutValidation( function () {
		// Fail-open: no Blackbox available.
		if ( ! window.Blackbox || ! window.Blackbox.getSessionId ) {
			return true;
		}

		// Race against a 5 s timeout so we never block the checkout
		// indefinitely (fail-open). .catch() converts rejections into
		// empty string so checkout still proceeds.
		return Promise.race( [
			window.Blackbox.getSessionId(),
			checkoutTimeoutGuard(),
		] )
			.catch( function () {
				return '';
			} )
			.then( function ( sessionId ) {
				if ( sessionId ) {
					// wp-data is a declared script dependency, so wp.data
					// is guaranteed to be available.
					const checkout = wp.data.dispatch( STORE_KEY );
					checkout.setExtensionData(
						NAMESPACE,
						{ blackbox_session_id: sessionId },
						true
					);
				}

				return true;
			} )
			.catch( function () {
				// Fail-open: extension data errors should not block checkout.
				return true;
			} );
	} );

	/**
	 * Reset Blackbox after checkout completes (success or failure)
	 * so stale behavioral data doesn't accumulate across retries.
	 */
	const resetBlackbox = function () {
		if ( window.Blackbox && window.Blackbox.reset ) {
			window.Blackbox.reset().catch( function () {
				// Fail-open: reset failure is non-critical.
			} );
		}
	};

	checkoutEvents.onCheckoutSuccess( resetBlackbox );
	checkoutEvents.onCheckoutFail( resetBlackbox );
} )();
