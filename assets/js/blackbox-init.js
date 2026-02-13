/**
 * Woo Fraud Protection - Blackbox Initialization
 *
 * Configures the Blackbox JS SDK with the site's API key and blog ID.
 * Loaded on checkout, pay-for-order, and add-payment-method pages.
 */
( function () {
	'use strict';

	const config = window.wcBlackboxConfig;
	if ( ! config ) {
		return;
	}

	if ( ! window.Blackbox || ! window.Blackbox.configure ) {
		return;
	}

	window.Blackbox.configure( {
		apiKey: config.apiKey,
	} );

	// Polyfill getSessionId/getNewSessionId for old Blackbox SDK.
	// Once the SDK ships these natively, this block is a no-op.
	if ( ! window.Blackbox.getSessionId ) {
		const collectAndReturn = function () {
			return window.Blackbox.collect()
				.then( function ( response ) {
					return response && response.data && response.data.session_id
						? response.data.session_id
						: '';
				} )
				.catch( function () {
					return '';
				} );
		};

		// Wrap with an extensible object that delegates to the original
		// via prototype chain. Handles frozen/sealed SDK objects.
		window.Blackbox = Object.create( window.Blackbox );

		window.Blackbox.getSessionId = function () {
			return collectAndReturn();
		};

		window.Blackbox.getNewSessionId = function () {
			const resetPromise = window.Blackbox.reset
				? window.Blackbox.reset().catch( function () {} )
				: Promise.resolve();
			return resetPromise.then( collectAndReturn );
		};
	}
} )();
