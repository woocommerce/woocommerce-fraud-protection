/**
 * Blocks checkout fraud protection integration.
 *
 * Gates checkout submission via wcFraudProtection.acquireSessionId(),
 * sets the session ID as extension data for the checkout POST (or, without
 * one, the reason why), then lets the request proceed. Resets after
 * checkout failure for retry.
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

	/**
	 * Acquire the session ID and, without one, the reason why.
	 *
	 * @return {Promise<{sessionId: string, error: string}>} Session ID and error.
	 */
	function acquireSessionIdResult() {
		if ( typeof fraudProtection.acquireSessionIdResult === 'function' ) {
			return fraudProtection.acquireSessionIdResult();
		}

		return fraudProtection.acquireSessionId().then( function ( sessionId ) {
			return { sessionId, error: '' };
		} );
	}

	checkoutEvents.onCheckoutValidation( function () {
		if (
			! fraudProtection ||
			( ! fraudProtection.acquireSessionIdResult &&
				! fraudProtection.acquireSessionId )
		) {
			return true;
		}

		return acquireSessionIdResult()
			.then( function ( result ) {
				const extensionData = { blackbox_session_id: result.sessionId };
				if ( ! result.sessionId && result.error ) {
					extensionData.blackbox_session_id_error = result.error;
				}

				const checkout = wp.data.dispatch( STORE_KEY );
				checkout.setExtensionData( NAMESPACE, extensionData, true );

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

	// Reset only on failure — user stays on the page and may retry with a fresh session.
	// No reset on success: the page navigates to order-received, so the collect triggered
	// by reset() would be wasted.
	checkoutEvents.onCheckoutFail( resetFraudProtection );
} )();
