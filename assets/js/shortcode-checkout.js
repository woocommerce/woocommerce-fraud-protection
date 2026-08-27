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

	const findSessionIdFields = function ( $form, sessionIdField ) {
		return $form.find( 'input' ).filter( function () {
			return this.id === sessionIdField || this.name === sessionIdField;
		} );
	};

	$( 'form.checkout' ).on( 'checkout_place_order', function () {
		const fraudProtection = window.wcFraudProtection;

		if ( ! fraudProtection || ! fraudProtection.acquireSessionId ) {
			return true;
		}

		const sessionIdField = fraudProtection.config.sessionIdField;
		const $form = $( this );

		// Re-entry: field present, let through. Deferred cleanup removes
		// the field and resets so the next attempt gets a fresh session.
		if ( findSessionIdFields( $form, sessionIdField ).length ) {
			setTimeout( function () {
				findSessionIdFields( $form, sessionIdField ).remove();
				fraudProtection.reset();
			}, 0 );
			return true;
		}

		fraudProtection.acquireSessionId().then( function ( sessionId ) {
			const $fields = findSessionIdFields( $form, sessionIdField );

			if ( $fields.length ) {
				$fields.first().val( sessionId );
				$fields.slice( 1 ).remove();
			} else {
				$( '<input>', {
					type: 'hidden',
					name: sessionIdField,
					id: sessionIdField,
					value: sessionId,
				} ).appendTo( $form );
			}

			$form.trigger( 'submit' );
		} );

		return false;
	} );
} )( jQuery );
