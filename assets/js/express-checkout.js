/**
 * Express checkout fraud protection integration.
 *
 * WooPayments and Stripe express payment buttons (Apple Pay, Google Pay, and
 * similar) place the order through the Store API without the checkout form,
 * so the checkout scripts never add the session ID. Both gateways build the
 * request's extension data through a synchronous filter, so a session ID is
 * prepared ahead of time and added there. Errors leave the data unchanged.
 *
 * Square's wallet buttons also skip the checkout form, but they post the order
 * to Square's own AJAX endpoint, which runs the classic checkout process.
 * Square has no filter for that request, so the session ID is added to its body
 * with a jQuery AJAX prefilter. Errors leave the request unchanged.
 *
 * Depends on blackbox-init.js (wcFraudProtection), wp-hooks, and jQuery.
 */
/* global jQuery */
( function () {
	'use strict';

	const NAMESPACE = 'woocommerce/fraud-protection';
	const EXTENSION_DATA_FILTERS = [
		'wcpay.express-checkout.cart-place-order-extension-data',
		'wcstripe.express-checkout.cart-place-order-extension-data',
	];
	const SQUARE_WALLET_CHECKOUT_ENDPOINT =
		'square_digital_wallet_process_checkout';

	const fraudProtection = window.wcFraudProtection;
	const hooks = window.wp && window.wp.hooks;
	const $ = typeof jQuery === 'undefined' ? null : jQuery;
	const canFilterExtensionData =
		!! hooks && typeof hooks.addFilter === 'function';
	const canFilterAjaxRequests = !! $ && typeof $.ajaxPrefilter === 'function';

	if (
		! fraudProtection ||
		typeof fraudProtection.prepareSessionId !== 'function' ||
		typeof fraudProtection.takePreparedSessionId !== 'function' ||
		( ! canFilterExtensionData && ! canFilterAjaxRequests )
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

	/**
	 * Check whether an AJAX request is Square's wallet checkout request.
	 *
	 * @param {Object} settings jQuery AJAX settings.
	 * @return {boolean} Whether it is the wallet checkout request.
	 */
	function isSquareWalletCheckout( settings ) {
		return (
			new URL( settings.url, window.location.href ).searchParams.get(
				'wc-ajax'
			) === SQUARE_WALLET_CHECKOUT_ENDPOINT
		);
	}

	/**
	 * Add a fresh session ID to Square's wallet checkout request.
	 *
	 * The request is built from the wallet's data, not from the checkout form,
	 * so it never holds the session ID field of an earlier attempt. Without a
	 * fresh ID, the request reports `not_ready` instead.
	 *
	 * @param {Object} settings jQuery AJAX settings, before the request is sent.
	 */
	function addSessionIdToSquareWalletCheckout( settings ) {
		try {
			if (
				typeof settings.data !== 'string' ||
				! isSquareWalletCheckout( settings )
			) {
				return;
			}

			const config = fraudProtection.config;
			const sessionId = fraudProtection.takePreparedSessionId();
			const fields = { [ config.sessionIdField ]: sessionId };
			if ( ! sessionId && config.sessionIdErrorField ) {
				fields[ config.sessionIdErrorField ] = 'not_ready';
			}

			settings.data += ( settings.data ? '&' : '' ) + $.param( fields );
		} catch {
			// Fail open: the request continues unchanged.
		}
	}

	if ( canFilterExtensionData ) {
		EXTENSION_DATA_FILTERS.forEach( function ( filterName ) {
			hooks.addFilter( filterName, NAMESPACE, addSessionId );
		} );
	}

	if ( canFilterAjaxRequests ) {
		$.ajaxPrefilter( addSessionIdToSquareWalletCheckout );
	}

	fraudProtection.prepareSessionId();
} )();
