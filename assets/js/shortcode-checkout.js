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

	$( 'form.checkout' ).on( 'checkout_place_order', function () {
		const fraudProtection = window.wcFraudProtection;

		if ( ! fraudProtection || ! fraudProtection.acquireSessionId ) {
			return true;
		}

		const sessionIdField = fraudProtection.config.sessionIdField;

		// Re-entry: field present, let through. Deferred cleanup removes
		// the field and resets so the next attempt gets a fresh session.
		if ( $( '#' + sessionIdField ).length ) {
			setTimeout( function () {
				$( '#' + sessionIdField ).remove();
				fraudProtection.reset();
			}, 0 );
			return true;
		}

		const $form = $( this );

		fraudProtection.acquireSessionId().then( function ( sessionId ) {
			$( '<input>', {
				type: 'hidden',
				name: sessionIdField,
				id: sessionIdField,
				value: sessionId,
			} ).appendTo( $form );
			$form.trigger( 'submit' );
		} );

		return false;
	} );
} )( jQuery );
