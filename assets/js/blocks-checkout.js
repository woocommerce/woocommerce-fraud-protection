/**
 * Blocks checkout fraud protection integration.
 *
 * Gates checkout submission via wcFraudProtection.acquireSessionId(),
 * sets the session ID as extension data for the checkout POST (or, without
 * one, the reason why), then lets the request proceed. Resets after
 * checkout failure for retry, and after an attempt that stops before the
 * order is sent.
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

	// Whether the latest attempt took a session ID that no order has used yet.
	let isSessionIdUnsent = false;

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
				isSessionIdUnsent = !! result.sessionId;

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
		isSessionIdUnsent = false;
		if ( fraudProtection && fraudProtection.reset ) {
			fraudProtection.reset();
		}
	};

	// Reset only on failure — user stays on the page and may retry with a fresh session.
	// No reset on success: the page navigates to order-received, so the collect triggered
	// by reset() would be wasted.
	checkoutEvents.onCheckoutFail( resetFraudProtection );

	// An attempt can also stop before the order is sent, for example when the
	// gateway rejects the card details in the browser. The checkout then goes
	// back to idle without onCheckoutFail. Reset so that the next attempt,
	// including an express payment, gets a session ID.
	if ( wp.data.subscribe && wp.data.select ) {
		wp.data.subscribe( function () {
			if ( ! isSessionIdUnsent ) {
				return;
			}

			try {
				const checkout = wp.data.select( STORE_KEY );
				if ( checkout.isAfterProcessing() || checkout.isComplete() ) {
					isSessionIdUnsent = false;
				} else if ( checkout.isIdle() ) {
					resetFraudProtection();
				}
			} catch {
				// Fail open.
				isSessionIdUnsent = false;
			}
		}, STORE_KEY );
	}
} )();
