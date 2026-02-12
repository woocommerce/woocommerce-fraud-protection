/**
 * Shortcode checkout fraud protection integration.
 *
 * Gates the classic checkout form submission to acquire a Blackbox session ID
 * via getSessionId(), injects it as a hidden field, then re-triggers submit.
 * Depends on blackbox-init.js (SDK configured + getSessionId polyfill) and jQuery.
 *
 * Flow:
 * 1. User clicks "Place Order" → checkout_place_order fires.
 * 2. No session ID field yet → block submission (return false), call getSessionId().
 * 3. Once the promise resolves, inject a hidden <input> with the session ID and
 *    re-trigger submit.
 * 4. checkout_place_order fires again — this time the field exists, so we allow
 *    through (return true). A deferred remove() cleans up the field after WC
 *    serializes the form, so it's fresh for any retry.
 * @param {Function} $ jQuery.
 */
/* global jQuery */
( function ( $ ) {
	'use strict';

	const SESSION_ID_FIELD = 'wc_fraud_protection_session_id';

	$( 'form.checkout' ).on( 'checkout_place_order', function () {
		// Re-entry after async acquisition: the field is present, let the form through.
		// Remove it via setTimeout(0) so it's gone before the next attempt, but after
		// WC has already serialized the form data synchronously.
		if ( $( '#' + SESSION_ID_FIELD ).length ) {
			setTimeout( function () {
				$( '#' + SESSION_ID_FIELD ).remove();
			}, 0 );
			return true;
		}

		// Fail-open: no Blackbox available.
		if ( ! window.Blackbox || ! window.Blackbox.getSessionId ) {
			return true;
		}

		const $form = $( this );

		// Block submission while we acquire a session ID asynchronously.
		// Race against a 5 s timeout so we never block the checkout indefinitely.
		// .catch() before .then() converts rejections into empty string (fail-open).
		Promise.race( [
			window.Blackbox.getSessionId(),
			new Promise( function ( resolve ) {
				setTimeout( function () {
					resolve( '' );
				}, 5000 );
			} ),
		] )
			.catch( function () {
				return '';
			} )
			.then( function ( sessionId ) {
				$(
					'<input type="hidden" name="' +
						SESSION_ID_FIELD +
						'" id="' +
						SESSION_ID_FIELD +
						'">'
				)
					.val( sessionId )
					.appendTo( $form );
				$form.trigger( 'submit' );
			} );

		return false;
	} );
} )( jQuery );
