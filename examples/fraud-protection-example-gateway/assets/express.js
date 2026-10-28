/**
 * Express pay button of the Fraud Protection example gateway.
 *
 * Shows the two sides of an express integration:
 *
 * - The gateway's button code builds its request data through a synchronous
 *   wp.hooks filter, the way WooPayments and Stripe build the place-order
 *   data of their express buttons.
 * - The Fraud Protection integration prepares a session ID once, when the
 *   button is set up, and adds it to the request data synchronously with
 *   window.wcFraudProtection.takePreparedSessionId(). Each ID is handed out
 *   for one attempt and a fresh session starts for the next one.
 *
 * Depends on wp-hooks, and on wc-fraud-protection-blackbox-init when Fraud
 * Protection loaded its scripts for this page.
 */
( function () {
	'use strict';

	const REQUEST_DATA_FILTER = 'fpExample.expressRequestData';
	const settings = window.fpExampleExpress || {};
	const hooks = window.wp && window.wp.hooks;

	/**
	 * Build the request data for an express payment (gateway code).
	 *
	 * @param {string} wallet The wallet the shopper picked.
	 * @return {Object} Request fields.
	 */
	function buildRequestData( wallet ) {
		const data = {
			security: settings.nonce || '',
			fp_example_wallet: wallet,
		};

		return hooks ? hooks.applyFilters( REQUEST_DATA_FILTER, data ) : data;
	}

	/**
	 * Send the express payment request (gateway code).
	 *
	 * @param {HTMLButtonElement} button The clicked button.
	 */
	function pay( button ) {
		button.disabled = true;

		window
			.fetch( settings.url, {
				method: 'POST',
				credentials: 'same-origin',
				body: new URLSearchParams( buildRequestData( 'apple_pay' ) ),
			} )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( result ) {
				if ( result && result.success ) {
					window.location.assign( result.data.redirect );
					return;
				}

				const message =
					( result && result.data && result.data.message ) ||
					'The payment could not be started.';
				window.alert( message ); // eslint-disable-line no-alert
				button.disabled = false;
			} )
			.catch( function () {
				button.disabled = false;
			} );
	}

	// Fraud Protection integration: add the session ID when the request data is built.
	const fraudProtection = window.wcFraudProtection;
	if (
		hooks &&
		fraudProtection &&
		typeof fraudProtection.prepareSessionId === 'function' &&
		typeof fraudProtection.takePreparedSessionId === 'function'
	) {
		fraudProtection.prepareSessionId();

		hooks.addFilter(
			REQUEST_DATA_FILTER,
			'fp-example/fraud-protection',
			function ( data ) {
				const sessionId = fraudProtection.takePreparedSessionId();
				if ( sessionId ) {
					data[ fraudProtection.config.sessionIdField ] = sessionId;
				}

				return data;
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
