/**
 * Shortcode checkout fraud protection integration.
 *
 * Gates the classic checkout form submission via
 * wcFraudProtection.acquireSessionId(), injects the session ID as a
 * hidden field, then re-triggers submit. On re-entry (field present),
 * lets through and defers cleanup + reset for the next attempt.
 *
 * Depends on blackbox-init.js (wcFraudProtection) and jQuery.
 */
/* global jQuery */
( function ( $ ) {
	'use strict';

	const SESSION_ID_FIELD = 'wc_fraud_protection_session_id';

	$( 'form.checkout' ).on( 'checkout_place_order', function () {
		const fraudProtection = window.wcFraudProtection;

		if ( ! fraudProtection || ! fraudProtection.acquireSessionId ) {
			return true;
		}

		// Re-entry: field present, let through. Deferred cleanup removes
		// the field and resets so the next attempt gets a fresh session.
		if ( $( '#' + SESSION_ID_FIELD ).length ) {
			setTimeout( function () {
				$( '#' + SESSION_ID_FIELD ).remove();
				fraudProtection.reset();
			}, 0 );
			return true;
		}

		const $form = $( this );

		fraudProtection.acquireSessionId().then( function ( sessionId ) {
			$( '<input>', {
				type: 'hidden',
				name: SESSION_ID_FIELD,
				id: SESSION_ID_FIELD,
				value: sessionId,
			} ).appendTo( $form );
			$form.trigger( 'submit' );
		} );

		return false;
	} );
} )( jQuery );
