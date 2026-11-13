/**
 * Kustom Checkout fraud protection integration for the Checkout block.
 *
 * Kustom's servers place the order after the shopper pays inside its iframe,
 * so the Checkout block's submit events never run. Kustom stores the block's
 * extension data in the WooCommerce session before the shopper pays, and
 * sends it with that request. While Kustom is the selected payment method,
 * this script keeps the prepared session ID in the extension data.
 *
 * The session ID is not handed out, so the session keeps collecting until
 * the order is placed. Another payment method overwrites the data when its
 * own submit runs. Errors leave the data unchanged.
 *
 * Kustom's servers do not tell the page when they use the session ID. The
 * cart data carries the last session ID that a Kustom attempt sent, and this
 * script starts a new session when it is the prepared one. The script gets the
 * cart again when the shopper comes back to the page from Kustom's iframe. It
 * only reads the response: a GET leaves Kustom's order and the cart store alone.
 *
 * Depends on blackbox-init.js (wcFraudProtection), wp-data and wp-api-fetch.
 */
( function () {
	'use strict';

	const NAMESPACE = 'woocommerce/fraud-protection';
	const PAYMENT_STORE = 'wc/store/payment';
	const CHECKOUT_STORE = 'wc/store/checkout';
	const CART_STORE = 'wc/store/cart';
	const KUSTOM_GATEWAY_ID = 'kco';
	const SENT_SESSION_ID_FIELD = 'kustom_sent_session_id';
	const CART_PATH = '/wc/store/v1/cart';

	const fraudProtection = window.wcFraudProtection;
	const data = window.wp && window.wp.data;

	if ( ! fraudProtection || ! data || ! data.subscribe ) {
		return;
	}

	let extensionData = {
		blackbox_session_id: '',
		blackbox_session_id_error: 'sdk_unavailable',
	};
	let preparedSessionId = '';
	// The used session ID that this page already started a new session for.
	let replacedSessionId = '';
	// Whether the shopper moved into an iframe while Kustom was selected, since
	// the page last had the focus.
	let isInKustomIframe = false;
	let isFetchingCart = false;

	/**
	 * Whether Kustom is the selected payment method.
	 *
	 * @return {boolean} Whether Kustom is selected.
	 */
	function isKustomSelected() {
		return (
			data.select( PAYMENT_STORE ).getActivePaymentMethod() ===
			KUSTOM_GATEWAY_ID
		);
	}

	/**
	 * Add the extension data to the checkout while Kustom is selected.
	 */
	function publish() {
		try {
			if ( ! isKustomSelected() ) {
				return;
			}

			const current = data.select( CHECKOUT_STORE ).getExtensionData()[
				NAMESPACE
			];
			if (
				JSON.stringify( current ) !== JSON.stringify( extensionData )
			) {
				data.dispatch( CHECKOUT_STORE ).setExtensionData(
					NAMESPACE,
					extensionData,
					true
				);
			}
		} catch {
			// Fail open.
		}
	}

	/**
	 * Start a new session when a Kustom attempt already sent the prepared session ID.
	 *
	 * @param {Object} cart Store API cart data.
	 */
	function replaceSentSessionId( cart ) {
		try {
			if (
				! preparedSessionId ||
				preparedSessionId === replacedSessionId ||
				typeof fraudProtection.reset !== 'function'
			) {
				return;
			}

			const cartData =
				cart && cart.extensions && cart.extensions[ NAMESPACE ];
			if (
				! cartData ||
				cartData[ SENT_SESSION_ID_FIELD ] !== preparedSessionId
			) {
				return;
			}

			replacedSessionId = preparedSessionId;
			fraudProtection.reset();
		} catch {
			// Fail open.
		}
	}

	/**
	 * Check the cart in the cart store for the session ID that Kustom sent.
	 */
	function checkCartStore() {
		try {
			replaceSentSessionId( data.select( CART_STORE ).getCartData() );
		} catch {
			// Fail open.
		}
	}

	/**
	 * Get the cart from the server, and check it for the session ID that Kustom sent.
	 */
	function fetchCart() {
		const apiFetch = window.wp.apiFetch;
		if (
			isFetchingCart ||
			! preparedSessionId ||
			typeof apiFetch !== 'function'
		) {
			return;
		}

		isFetchingCart = true;
		Promise.resolve()
			.then( function () {
				return apiFetch( {
					path: CART_PATH,
					method: 'GET',
					cache: 'no-store',
				} );
			} )
			.then( replaceSentSessionId )
			.catch( function () {
				// Fail open.
			} )
			.then( function () {
				isFetchingCart = false;
			} );
	}

	// The shopper can select Kustom after the session ID is ready.
	data.subscribe( publish, PAYMENT_STORE );

	if (
		typeof fraudProtection.prepareSessionId !== 'function' ||
		typeof fraudProtection.onPreparedSessionIdChange !== 'function'
	) {
		publish();
		return;
	}

	data.subscribe( checkCartStore, CART_STORE );

	// The page loses focus when the shopper clicks into Kustom's iframe, and
	// gets it back when they come back to the page, for example to pay again
	// or to select another payment method.
	window.addEventListener( 'blur', function () {
		// The focus reaches the iframe after the blur event. The document still
		// has the focus when an iframe in it has it, unlike when the shopper
		// leaves the tab or the window. Leaving them from the iframe, for
		// example to find a card number, keeps the shopper in the iframe.
		setTimeout( function () {
			try {
				if ( isKustomSelected() && document.hasFocus() ) {
					isInKustomIframe = true;
				}
			} catch {
				// Fail open.
			}
		}, 0 );
	} );
	window.addEventListener( 'focus', function () {
		if ( ! isInKustomIframe ) {
			return;
		}

		isInKustomIframe = false;
		fetchCart();
	} );

	fraudProtection.prepareSessionId();
	fraudProtection.onPreparedSessionIdChange( function ( sessionId ) {
		preparedSessionId = sessionId;
		extensionData = sessionId
			? { blackbox_session_id: sessionId }
			: {
					blackbox_session_id: '',
					blackbox_session_id_error: 'not_ready',
			  };
		publish();
		checkCartStore();
	} );
} )();
