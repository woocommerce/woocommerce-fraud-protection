/**
 * "Express pay" button of the Fraud Protection example gateway.
 *
 * Shows the two sides of an express checkout integration:
 *
 * - The gateway's button code places the order with a Store API checkout
 *   request, without the checkout form. It builds the request's extension
 *   data through a synchronous wp.hooks filter, the way WooPayments and
 *   Stripe build the extension data of their express buttons. Fraud
 *   Protection verifies that request by itself.
 * - The Fraud Protection integration prepares a session ID once, when the
 *   button is set up, and adds it to the extension data with
 *   window.wcFraudProtection.addSessionIdToExtensions(). The filter cannot
 *   wait for a promise, so prepareSessionId() fetches the ID in the
 *   background and addSessionIdToExtensions() adds it without waiting. It
 *   takes a new ID for every request, and a fresh session starts for the
 *   next one.
 *
 * Depends on wp-hooks, and on wc-fraud-protection-blackbox-init when Fraud
 * Protection loaded its scripts for this page.
 */
( function () {
	'use strict';

	const EXTENSIONS_FILTER = 'fpExample.expressExtensions';
	const settings = window.fpExampleExpress || {};
	const hooks = window.wp && window.wp.hooks;

	/**
	 * Build the Store API checkout request of an express payment (gateway code).
	 *
	 * A real wallet returns the address that the shopper picks in its payment
	 * sheet; this stand-in uses the one the server provides.
	 *
	 * @return {Object} The request body.
	 */
	function buildCheckoutRequest() {
		const billingAddress = Object.assign( {}, settings.address );
		const shippingAddress = Object.assign( {}, billingAddress );
		delete shippingAddress.email;

		return {
			billing_address: billingAddress,
			shipping_address: shippingAddress,
			payment_method: 'fp_example',
			payment_data: [ { key: 'fp_example_wallet', value: 'apple_pay' } ],
			extensions: hooks
				? hooks.applyFilters( EXTENSIONS_FILTER, {} )
				: {},
		};
	}

	/**
	 * Place the order with a Store API checkout request (gateway code).
	 *
	 * @param {HTMLButtonElement} button The clicked button.
	 */
	function pay( button ) {
		button.disabled = true;

		window
			.fetch( settings.checkoutUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
					Nonce: settings.nonce || '',
				},
				body: JSON.stringify( buildCheckoutRequest() ),
			} )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( result ) {
				const redirectUrl =
					result &&
					result.payment_result &&
					result.payment_result.redirect_url;
				if ( redirectUrl ) {
					window.location.assign( redirectUrl );
					return;
				}

				const message =
					( result && result.message ) ||
					'The payment could not be completed.';
				window.alert( message ); // eslint-disable-line no-alert
				button.disabled = false;
			} )
			.catch( function () {
				button.disabled = false;
			} );
	}

	// Fraud Protection integration: add the session ID when the extension data is built.
	const fraudProtection = window.wcFraudProtection;
	if (
		hooks &&
		fraudProtection &&
		typeof fraudProtection.addSessionIdToExtensions === 'function'
	) {
		fraudProtection.prepareSessionId();

		hooks.addFilter(
			EXTENSIONS_FILTER,
			'fp-example/fraud-protection',
			function ( extensions ) {
				return fraudProtection.addSessionIdToExtensions( extensions );
			}
		);
	}

	document
		.querySelectorAll( '.fp-example-express-button' )
		.forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				pay( button );
			} );
		} );
} )();
