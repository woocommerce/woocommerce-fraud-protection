/**
 * Express checkout fraud protection integration.
 *
 * WooPayments and Stripe express payment buttons (Apple Pay, Google Pay, and
 * similar) place the order through the Store API without the checkout form,
 * so the checkout scripts never add the session ID. Both gateways build the
 * request's extension data through a synchronous filter, so a session ID is
 * prepared ahead of time and added there. Errors leave the data unchanged.
 *
 * Only the gateways listed in wcFraudProtectionExpressCheckout.gateways are
 * handled: a gateway that sends the session ID itself is left out, so its
 * session ID is not replaced. Without the list, both gateways are handled.
 *
 * Depends on blackbox-init.js (wcFraudProtection) and wp-hooks.
 */
( function () {
	'use strict';

	const NAMESPACE = 'woocommerce/fraud-protection';
	const EXTENSION_DATA_FILTERS = {
		woocommerce_payments:
			'wcpay.express-checkout.cart-place-order-extension-data',
		stripe: 'wcstripe.express-checkout.cart-place-order-extension-data',
	};

	const settings = window.wcFraudProtectionExpressCheckout;
	const gateways =
		settings && Array.isArray( settings.gateways )
			? settings.gateways
			: Object.keys( EXTENSION_DATA_FILTERS );

	const fraudProtection = window.wcFraudProtection;
	const hooks = window.wp && window.wp.hooks;

	if (
		! fraudProtection ||
		typeof fraudProtection.prepareSessionId !== 'function' ||
		typeof fraudProtection.takePreparedSessionId !== 'function' ||
		! hooks ||
		typeof hooks.addFilter !== 'function'
	) {
		return;
	}

	/**
	 * Add a fresh session ID to the express checkout extension data.
	 *
	 * Without a fresh ID, any session ID already in the data is removed: it can
	 * come from the checkout form's earlier attempt and must not be sent again.
	 *
	 * @param {*} extensionData Extension data built by the gateway.
	 * @return {*} The extension data with the session ID.
	 */
	function addSessionId( extensionData ) {
		try {
			if (
				! extensionData ||
				typeof extensionData !== 'object' ||
				Array.isArray( extensionData )
			) {
				return extensionData;
			}

			const data = Object.assign( {}, extensionData );
			delete data[ NAMESPACE ];

			const sessionId = fraudProtection.takePreparedSessionId();
			if ( sessionId ) {
				data[ NAMESPACE ] = { blackbox_session_id: sessionId };
			}

			return data;
		} catch {
			return extensionData;
		}
	}

	gateways.forEach( function ( gateway ) {
		if (
			Object.prototype.hasOwnProperty.call(
				EXTENSION_DATA_FILTERS,
				gateway
			)
		) {
			hooks.addFilter(
				EXTENSION_DATA_FILTERS[ gateway ],
				NAMESPACE,
				addSessionId
			);
		}
	} );

	fraudProtection.prepareSessionId();
} )();
