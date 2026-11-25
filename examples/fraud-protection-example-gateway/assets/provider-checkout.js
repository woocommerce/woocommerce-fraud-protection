/**
 * "Pay with provider" button of the Fraud Protection example gateway.
 *
 * The button sends the gateway's own request, which creates an order at the
 * payment provider, as PayPal's create-order call does, and then opens the
 * checkout that completes it. The server verifies that request, so the
 * Fraud Protection integration adds a session ID to it.
 *
 * The integration takes the session ID with
 * window.wcFraudProtection.takeSessionId() right before the request is sent,
 * not when the button is set up, so the session includes the shopper's
 * activity up to the click. Each ID is handed out for one attempt, and a
 * fresh session starts for the next one.
 *
 * Depends on wc-fraud-protection-blackbox-init when Fraud Protection loaded
 * its scripts for this page.
 */
( function () {
	'use strict';

	const settings = window.fpExampleProviderCheckout || {};

	/**
	 * Add a session ID to the request (Fraud Protection integration).
	 *
	 * Without Fraud Protection on the page, the request is sent unchanged.
	 *
	 * @param {Object} body Request fields.
	 * @return {Promise<Object>} The request fields with the session ID.
	 */
	async function addSessionId( body ) {
		const fraudProtection = window.wcFraudProtection;
		if (
			fraudProtection &&
			typeof fraudProtection.takeSessionId === 'function'
		) {
			body[ fraudProtection.config.sessionIdField ] =
				await fraudProtection.takeSessionId();
		}

		return body;
	}

	/**
	 * Create the order at the provider, then open the checkout (gateway code).
	 *
	 * @param {HTMLButtonElement} button The clicked button.
	 */
	async function pay( button ) {
		button.disabled = true;

		try {
			const body = await addSessionId( {
				security: settings.nonce || '',
			} );
			const response = await window.fetch( settings.url, {
				method: 'POST',
				credentials: 'same-origin',
				body: new URLSearchParams( body ),
			} );
			const result = await response.json();

			if ( result && result.success ) {
				window.location.assign( result.data.redirect );
				return;
			}

			const message =
				( result && result.data && result.data.message ) ||
				'The payment could not be started.';
			window.alert( message ); // eslint-disable-line no-alert
		} catch {
			// The shopper can try again.
		}

		button.disabled = false;
	}

	// Listen on the document: the Cart and Checkout blocks render the button after this script runs.
	document.addEventListener( 'click', function ( event ) {
		const button =
			event.target instanceof window.Element &&
			event.target.closest( '.fp-example-provider-button' );
		if ( button && ! button.disabled ) {
			pay( button );
		}
	} );
} )();
