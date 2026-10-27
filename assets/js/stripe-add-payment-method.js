/**
 * Stripe add-payment-method fraud protection integration.
 *
 * Stripe saves the payment method through an AJAX request that runs before
 * the add-payment-method form is posted, so the session ID (or the reason
 * why there is none) that add-payment-method.js adds to the form is copied
 * to that request. Errors leave the request unchanged.
 *
 * Depends on add-payment-method.js (and through it wcFraudProtection) and jQuery.
 */
/* global jQuery */
( function ( $ ) {
	'use strict';

	const SETUP_INTENT_ACTION = 'wc_stripe_create_and_confirm_setup_intent';

	const fraudProtection = window.wcFraudProtection;

	if (
		! fraudProtection ||
		! fraudProtection.config ||
		typeof $.ajaxPrefilter !== 'function'
	) {
		return;
	}

	/**
	 * Read a field value from the add-payment-method form.
	 *
	 * @param {string} name Field name.
	 * @return {string} The field value, or an empty string.
	 */
	function getFormFieldValue( name ) {
		const value = $( '#add_payment_method' )
			.find( 'input[name="' + name + '"]' )
			.first()
			.val();

		return typeof value === 'string' ? value : '';
	}

	$.ajaxPrefilter( function ( options ) {
		try {
			if ( typeof options.data !== 'string' ) {
				return;
			}

			const params = new URLSearchParams( options.data );
			if ( params.get( 'action' ) !== SETUP_INTENT_ACTION ) {
				return;
			}

			const added = new URLSearchParams();
			[
				fraudProtection.config.sessionIdField,
				fraudProtection.config.sessionIdErrorField,
			].forEach( function ( name ) {
				if ( ! name || params.has( name ) ) {
					return;
				}

				const value = getFormFieldValue( name );
				if ( value ) {
					added.set( name, value );
				}
			} );

			const addedData = added.toString();
			if ( addedData ) {
				options.data += '&' + addedData;
			}
		} catch {
			// Fail open.
		}
	} );
} )( jQuery );
