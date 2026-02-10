/**
 * Blocks checkout fraud protection integration.
 *
 * Handles Blackbox collect() and sets extension data for the checkout POST.
 * Depends on blackbox-init.js (SDK configured) and wp-data (checkout store).
 */
( function () {
	'use strict';

	const NAMESPACE = 'woocommerce/fraud-protection';
	const STORE_KEY = 'wc/store/checkout';

	// Start pending so the validation gate waits for collect() or times out.
	let collectPromise = new Promise( function () {} );

	/**
	 * Collect a Blackbox session ID and set it as checkout extension data.
	 *
	 * Fail-open: if Blackbox SDK is not loaded or collect() fails, checkout
	 * proceeds without a session_id (server will verify with empty string).
	 */
	const collectAndStoreSessionId = function () {
		if ( ! window.Blackbox || ! window.Blackbox.collect ) {
			return;
		}

		collectPromise = window.Blackbox.collect()
			.then( function ( response ) {
				const sessionId = response && response.data && response.data.session_id ? response.data.session_id : '';
				if ( ! sessionId ) {
					return;
				}

				if ( ! window.wp || ! window.wp.data || ! window.wp.data.dispatch ) {
					return;
				}
				const checkout = window.wp.data.dispatch( STORE_KEY );
				if ( checkout && checkout.setExtensionData ) {
					checkout.setExtensionData(
						NAMESPACE,
						{ blackbox_session_id: sessionId },
						true
					);
				}
			} )
			.catch( function () {
				// Fail-open: continue without session_id.
			} );
	};

	/**
	 * Reset Blackbox telemetry and re-collect a fresh session ID.
	 *
	 * Called after checkout processing completes (success or failure) so that
	 * stale behavioral data doesn't accumulate across retries.
	 */
	const resetAndCollect = function () {
		if ( ! window.Blackbox || ! window.Blackbox.reset ) {
			return;
		}
		window.Blackbox.reset()
			.then( function () {
				collectAndStoreSessionId();
			} )
			.catch( function () {
				// reset() failed, still try to collect (fail-open).
				collectAndStoreSessionId();
			} );
	};

	// The wc/store/checkout store is registered lazily when the checkout block
	// mounts, so we use wp.data.subscribe to wait for it before collecting.
	if ( ! window.wp || ! window.wp.data || ! window.wp.data.subscribe ) {
		return;
	}

	let wasProcessing = false;
	let initialCollectDone = false;

	window.wp.data.subscribe( function () {
		const store = window.wp.data.select( STORE_KEY );
		if ( ! store ) {
			return;
		}

		// Initial collect once the checkout store is available.
		if ( ! initialCollectDone ) {
			initialCollectDone = true;
			collectAndStoreSessionId();
		}

		// Re-collect after each checkout submission for fresh session_id.
		// Covers both success and failure. On success the page redirects
		// so the fresh session_id is only meaningful for retry scenarios.
		const isProcessing = store.isProcessing();
		if ( wasProcessing && ! isProcessing ) {
			resetAndCollect();
		}
		wasProcessing = isProcessing;
	} );

	// Gate checkout on session_id readiness.
	// The checkout events API is exposed at wc.blocksCheckoutEvents.checkoutEvents.
	const checkoutEvents = window.wc && window.wc.blocksCheckoutEvents && window.wc.blocksCheckoutEvents.checkoutEvents;
	if ( checkoutEvents && checkoutEvents.onCheckoutValidation ) {
		checkoutEvents.onCheckoutValidation( function () {
			// Wait for in-flight collect(), with a timeout so we don't block forever (fail-open).
			return Promise.race( [
				collectPromise.then( function () { return true; } ),
				new Promise( function ( resolve ) {
					setTimeout( function () { resolve( true ); }, 5000 );
				} ),
			] );
		} );
	}
} )();
