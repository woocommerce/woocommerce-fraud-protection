/**
 * Blocks checkout fraud protection integration.
 *
 * Gates checkout submission via wcFraudProtection.acquireSessionId(),
 * sets the session ID as extension data for the checkout POST, then
 * lets the request proceed. Resets after checkout completes.
 *
 * Depends on blackbox-init.js (wcFraudProtection), wp-data (checkout
 * store), and wc-blocks-checkout-events (validation + success/fail hooks).
 */
( function () {
	'use strict';

	const NAMESPACE = 'woocommerce/fraud-protection';
	const STORE_KEY = 'wc/store/checkout';

	const checkoutEvents =
		window.wc &&
		window.wc.blocksCheckoutEvents &&
		window.wc.blocksCheckoutEvents.checkoutEvents;

	if ( ! checkoutEvents ) {
		return;
	}

	const fraudProtection = window.wcFraudProtection;

	checkoutEvents.onCheckoutValidation( function () {
		if ( ! fraudProtection || ! fraudProtection.acquireSessionId ) {
			return true;
		}

		return fraudProtection
			.acquireSessionId()
			.then( function ( sessionId ) {
				if ( sessionId ) {
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

	const resetFraudProtection = function () {
		if ( fraudProtection && fraudProtection.reset ) {
			fraudProtection.reset();
		}
	};

	checkoutEvents.onCheckoutSuccess( resetFraudProtection );
	checkoutEvents.onCheckoutFail( resetFraudProtection );
} )();
