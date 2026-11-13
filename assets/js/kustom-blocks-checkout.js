/**
 * Kustom Checkout fraud protection integration for the Checkout block.
 *
 * Kustom's servers place the order after the shopper pays inside its iframe,
 * so the Checkout block's submit events never run. Kustom stores the block's
 * extension data in the WooCommerce session before the shopper pays, and
 * sends it with that request. While Kustom is the selected payment method,
 * this script keeps the prepared session ID in the extension data.
 *
 * The session ID is not handed out, so the session keeps collecting until
 * the order is placed. Another payment method overwrites the data when its
 * own submit runs. Errors leave the data unchanged.
 *
 * Depends on blackbox-init.js (wcFraudProtection) and wp-data.
 */
( function () {
	'use strict';

	const NAMESPACE = 'woocommerce/fraud-protection';
	const PAYMENT_STORE = 'wc/store/payment';
	const CHECKOUT_STORE = 'wc/store/checkout';
	const KUSTOM_GATEWAY_ID = 'kco';

	const fraudProtection = window.wcFraudProtection;
	const data = window.wp && window.wp.data;

	if ( ! fraudProtection || ! data || ! data.subscribe ) {
		return;
	}

	let extensionData = {
		blackbox_session_id: '',
		blackbox_session_id_error: 'sdk_unavailable',
	};

	/**
	 * Add the extension data to the checkout while Kustom is selected.
	 */
	function publish() {
		try {
			if (
				data.select( PAYMENT_STORE ).getActivePaymentMethod() !==
				KUSTOM_GATEWAY_ID
			) {
				return;
			}

			const current = data.select( CHECKOUT_STORE ).getExtensionData()[
				NAMESPACE
			];
			if (
				JSON.stringify( current ) !== JSON.stringify( extensionData )
			) {
				data.dispatch( CHECKOUT_STORE ).setExtensionData(
					NAMESPACE,
					extensionData,
					true
				);
			}
		} catch {
			// Fail open.
		}
	}

	// The shopper can select Kustom after the session ID is ready.
	data.subscribe( publish, PAYMENT_STORE );

	if (
		typeof fraudProtection.prepareSessionId !== 'function' ||
		typeof fraudProtection.onPreparedSessionIdChange !== 'function'
	) {
		publish();
		return;
	}

	fraudProtection.prepareSessionId();
	fraudProtection.onPreparedSessionIdChange( function ( sessionId ) {
		extensionData = sessionId
			? { blackbox_session_id: sessionId }
			: {
					blackbox_session_id: '',
					blackbox_session_id_error: 'not_ready',
			  };
		publish();
	} );
} )();
