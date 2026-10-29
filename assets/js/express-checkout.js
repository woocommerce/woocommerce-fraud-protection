/**
 * Express checkout fraud protection integration.
 *
 * WooPayments and Stripe express payment buttons (Apple Pay, Google Pay, and
 * similar) place the order through the Store API without the checkout form,
 * so the checkout scripts never add the session ID. Both gateways build the
 * request's extension data through a synchronous filter, so a session ID is
 * prepared ahead of time and added there. Errors leave the data unchanged.
 *
 * Depends on blackbox-init.js (wcFraudProtection) and wp-hooks.
 */
( function () {
	'use strict';

	const NAMESPACE = 'woocommerce/fraud-protection';
	const EXTENSION_DATA_FILTERS = [
		'wcpay.express-checkout.cart-place-order-extension-data',
		'wcstripe.express-checkout.cart-place-order-extension-data',
	];

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
	 * Without a fresh ID, the data reports `not_ready` instead, replacing any
	 * session ID already in it: that one can come from the checkout form's
	 * earlier attempt and must not be sent again.
	 *
	 * @param {*} extensionData Extension data built by the gateway.
	 * @return {*} The extension data with the session ID, or the reason why it has none.
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

			const sessionId = fraudProtection.takePreparedSessionId();

			return Object.assign( {}, extensionData, {
				[ NAMESPACE ]: sessionId
					? { blackbox_session_id: sessionId }
					: { blackbox_session_id_error: 'not_ready' },
			} );
		} catch {
			return extensionData;
		}
	}

	EXTENSION_DATA_FILTERS.forEach( function ( filterName ) {
		hooks.addFilter( filterName, NAMESPACE, addSessionId );
	} );

	fraudProtection.prepareSessionId();
} )();
