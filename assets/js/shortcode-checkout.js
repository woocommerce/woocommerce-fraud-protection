/**
 * Shortcode checkout fraud protection integration.
 *
 * Gates the classic checkout form submission via
 * wcFraudProtection.acquireSessionId(), injects the session ID as a
 * hidden field (or, without one, the reason why), then re-triggers submit.
 * On re-entry (field present), lets through.
 *
 * The field stays until the checkout request completes, because a gateway
 * can stop a submit and submit the form again itself, for example after
 * creating a payment method in the browser. Once the request completes,
 * its session ID was used: the fields are removed and the session is reset
 * so the next attempt gets a fresh one. A checkout_error event does the
 * same, for failures reported without that request.
 *
 * Depends on blackbox-init.js (wcFraudProtection) and jQuery.
 */
/* global jQuery */
( function ( $ ) {
	'use strict';

	// Forms that hold fields added by this script.
	const formsWithFields = new Set();

	/**
	 * Acquire the session ID and, without one, the reason why.
	 *
	 * @param {Object} fraudProtection The wcFraudProtection API.
	 * @return {Promise<{sessionId: string, error: string}>} Session ID and error.
	 */
	function acquireSessionIdResult( fraudProtection ) {
		if ( typeof fraudProtection.acquireSessionIdResult === 'function' ) {
			return fraudProtection.acquireSessionIdResult();
		}

		return fraudProtection.acquireSessionId().then( function ( sessionId ) {
			return { sessionId, error: '' };
		} );
	}

	/**
	 * Set a hidden field on the form, adding it when missing.
	 *
	 * @param {Object} $form Form.
	 * @param {string} name  Field name, also used as its ID.
	 * @param {string} value Field value.
	 */
	function setField( $form, name, value ) {
		const $existingField = $form
			.find( 'input[name="' + name + '"]' )
			.first();

		if ( $existingField.length ) {
			$existingField.val( value );
		} else {
			$( '<input>', {
				type: 'hidden',
				name,
				id: name,
				value,
			} ).appendTo( $form );
		}
	}

	/**
	 * Remove the fields of a finished attempt and start a fresh session.
	 */
	function resetAfterAttempt() {
		const fraudProtection = window.wcFraudProtection;
		if ( ! fraudProtection || ! fraudProtection.config ) {
			return;
		}

		const config = fraudProtection.config;
		let sessionIdWasSent = false;

		formsWithFields.forEach( function ( form ) {
			const $sessionIdField = $( form ).find(
				'input[name="' + config.sessionIdField + '"]'
			);
			if ( $sessionIdField.val() ) {
				sessionIdWasSent = true;
			}
			$sessionIdField.remove();
			if ( config.sessionIdErrorField ) {
				$( form )
					.find( 'input[name="' + config.sessionIdErrorField + '"]' )
					.remove();
			}
		} );
		formsWithFields.clear();

		// Without a session ID, the current session was not used.
		if ( sessionIdWasSent && typeof fraudProtection.reset === 'function' ) {
			fraudProtection.reset();
		}
	}

	/**
	 * Check whether an AJAX request is WooCommerce's checkout request.
	 *
	 * @param {Object} settings jQuery AJAX settings.
	 * @return {boolean} Whether it is the checkout request.
	 */
	function isCheckoutRequest( settings ) {
		try {
			return (
				new URL(
					settings && settings.url,
					window.location.href
				).searchParams.get( 'wc-ajax' ) === 'checkout'
			);
		} catch {
			return false;
		}
	}

	$( 'form.checkout' ).on( 'checkout_place_order', function () {
		const fraudProtection = window.wcFraudProtection;

		if (
			! fraudProtection ||
			( ! fraudProtection.acquireSessionIdResult &&
				! fraudProtection.acquireSessionId )
		) {
			return true;
		}

		const config = fraudProtection.config;
		const $form = $( this );

		// Re-entry: the field is present, so this attempt has its session ID.
		if (
			$form.find( 'input[name="' + config.sessionIdField + '"]' ).length
		) {
			return true;
		}

		acquireSessionIdResult( fraudProtection ).then( function ( result ) {
			setField( $form, config.sessionIdField, result.sessionId );
			if (
				! result.sessionId &&
				result.error &&
				config.sessionIdErrorField
			) {
				setField( $form, config.sessionIdErrorField, result.error );
			}
			formsWithFields.add( $form[ 0 ] );

			$form.trigger( 'submit' );
		} );

		return false;
	} );

	$( document ).on( 'ajaxComplete', function ( event, xhr, settings ) {
		if ( isCheckoutRequest( settings ) ) {
			resetAfterAttempt();
		}
	} );

	$( document.body ).on( 'checkout_error', resetAfterAttempt );
} )( jQuery );
