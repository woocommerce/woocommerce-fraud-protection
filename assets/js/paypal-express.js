/**
 * PayPal request fetch interceptor for Woo Fraud Protection.
 *
 * Injects the browser session into supported PayPal requests. Errors
 * remain fail open and never prevent the original Fetch call.
 *
 * After a successful request, the session stays for the PayPal payment that
 * continues it. When the shopper goes on with another gateway instead, for
 * example after closing PayPal, a new session is started for that payment.
 *
 * The checkout that completes a setup-token or vault-order request reuses
 * that request's decision only with the same session ID, so after a
 * successful one, the ID is handed over to the next acquisition, if PayPal is
 * still the selected gateway then. A request
 * sent while the Checkout block processes an order, for example by PayPal's
 * card fields, already has the checkout's ID, so it sends that one.
 */
( function () {
	if ( ! window.wcFraudProtection ) {
		return;
	}

	const originalFetch = window.fetch;
	const protectedPayPalEndpoints = [
		'ppc-create-order',
		'ppc-create-setup-token',
		'ppc-vault-create-order',
	];
	const handOverEndpoints = [
		'ppc-create-setup-token',
		'ppc-vault-create-order',
	];
	const PAYPAL_GATEWAY_PREFIX = 'ppcp-';
	const PAYMENT_STORE_KEY = 'wc/store/payment';
	const CHECKOUT_STORE_KEY = 'wc/store/checkout';
	// Where blocks-checkout.js keeps the checkout's session ID.
	const EXTENSION_NAMESPACE = 'woocommerce/fraud-protection';
	let resetBeforeNextPayPalRequest = false;
	let isWatchingGateway = false;

	function getEndpoint( resource ) {
		const url =
			resource instanceof Request ? resource.url : String( resource );
		return (
			new URL( url, window.location.href ).searchParams.get(
				'wc-ajax'
			) || ''
		);
	}

	function resetSession( fp ) {
		try {
			if ( typeof fp.reset === 'function' ) {
				fp.reset();
			}
		} catch {
			// Fail open.
		}
	}

	/**
	 * Read the session ID of the order that the Checkout block is processing.
	 *
	 * @return {string} Session ID, or an empty string when there is none.
	 */
	function getProcessingCheckoutSessionId() {
		try {
			const data = window.wp && window.wp.data;
			const checkout =
				data && data.select ? data.select( CHECKOUT_STORE_KEY ) : null;
			if (
				! checkout ||
				typeof checkout.isProcessing !== 'function' ||
				typeof checkout.getExtensionData !== 'function' ||
				! checkout.isProcessing()
			) {
				return '';
			}

			const extensionData =
				checkout.getExtensionData()[ EXTENSION_NAMESPACE ];
			const sessionId =
				extensionData && extensionData.blackbox_session_id;
			return typeof sessionId === 'string' ? sessionId : '';
		} catch {
			return '';
		}
	}

	/**
	 * Read the gateway selected on the classic checkout or pay-for-order page.
	 *
	 * @return {*} Gateway ID, or undefined when there is no selection.
	 */
	function getClassicGateway() {
		const $ = window.jQuery;
		return typeof $ === 'function'
			? $( 'input[name="payment_method"]:checked' ).val()
			: undefined;
	}

	/**
	 * Read the Checkout block's active payment method.
	 *
	 * @return {*} Gateway ID, or an empty string when there is no store.
	 */
	function getBlocksGateway() {
		const data = window.wp && window.wp.data;
		const store =
			data && data.select ? data.select( PAYMENT_STORE_KEY ) : null;
		return store && store.getActivePaymentMethod
			? store.getActivePaymentMethod()
			: '';
	}

	/**
	 * Whether the shopper still pays with PayPal.
	 *
	 * When the selected gateway can't be read, the PayPal payment is assumed
	 * to continue.
	 *
	 * @return {boolean} False only when another gateway is selected.
	 */
	function isPayPalGatewaySelected() {
		let gateway;
		try {
			gateway = getClassicGateway();
			if ( typeof gateway !== 'string' || ! gateway ) {
				gateway = getBlocksGateway();
			}
		} catch {
			gateway = '';
		}

		return (
			typeof gateway !== 'string' ||
			! gateway ||
			gateway.startsWith( PAYPAL_GATEWAY_PREFIX )
		);
	}

	/**
	 * Hand the session ID of a successful request to the checkout that completes it.
	 *
	 * The checkout only gets it while PayPal is still the selected gateway.
	 * This is checked when the checkout acquires its session ID, because the
	 * classic checkout does that before the place-order reset below runs.
	 *
	 * @param {Object} fp        The wcFraudProtection API.
	 * @param {*}      resource  The request resource.
	 * @param {string} sessionId The session ID that the request sent.
	 */
	function handOverSessionId( fp, resource, sessionId ) {
		try {
			if (
				sessionId &&
				typeof fp.handOverSessionId === 'function' &&
				handOverEndpoints.includes( getEndpoint( resource ) )
			) {
				fp.handOverSessionId( sessionId, isPayPalGatewaySelected );
			}
		} catch {
			// Fail open: the checkout verifies with a new session ID.
		}
	}

	/**
	 * Start a new session when another gateway takes over from PayPal.
	 *
	 * @param {*} gateway The gateway the shopper is using now.
	 */
	function resetForGateway( gateway ) {
		if (
			! resetBeforeNextPayPalRequest ||
			typeof gateway !== 'string' ||
			! gateway ||
			gateway.startsWith( PAYPAL_GATEWAY_PREFIX )
		) {
			return;
		}

		resetBeforeNextPayPalRequest = false;
		resetSession( window.wcFraudProtection );
	}

	/**
	 * Watch the classic checkout and pay-for-order gateway selection.
	 */
	function watchClassicGateway() {
		const $ = window.jQuery;
		if ( typeof $ !== 'function' ) {
			return;
		}

		const resetForSelectedGateway = function () {
			resetForGateway( getClassicGateway() );
		};

		$( document.body ).on(
			'change',
			'input[name="payment_method"]',
			resetForSelectedGateway
		);
		$( 'form.checkout' ).on(
			'checkout_place_order',
			resetForSelectedGateway
		);
	}

	/**
	 * Watch the Checkout block's active payment method.
	 */
	function watchBlocksGateway() {
		const data = window.wp && window.wp.data;
		if ( ! data || ! data.subscribe || ! data.select ) {
			return;
		}

		let activeGateway = getBlocksGateway();
		data.subscribe( function () {
			try {
				const gateway = getBlocksGateway();
				if ( gateway !== activeGateway ) {
					activeGateway = gateway;
					resetForGateway( gateway );
				}
			} catch {
				// Fail open.
			}
		}, PAYMENT_STORE_KEY );
	}

	/**
	 * Start watching for another gateway, once a request kept its session.
	 *
	 * Waits for that request because the checkout may not be set up when this
	 * script loads, and pages without a PayPal payment have nothing to watch.
	 */
	function watchGateway() {
		if ( isWatchingGateway ) {
			return;
		}

		isWatchingGateway = true;
		try {
			watchClassicGateway();
			watchBlocksGateway();
		} catch {
			// Fail open.
		}
	}

	async function classifyResponse( response ) {
		if ( ! response || false === response.ok ) {
			return 'failure';
		}

		try {
			const data = await response.clone().json();
			return data && false === data.success ? 'failure' : 'success';
		} catch {
			return 'unknown';
		}
	}

	async function dispatchProtectedPayPalRequest(
		thisArg,
		fp,
		resource,
		init
	) {
		// The checkout has its own session ID for this attempt.
		const checkoutSessionId = getProcessingCheckoutSessionId();
		if ( resetBeforeNextPayPalRequest ) {
			if ( ! checkoutSessionId ) {
				resetSession( fp );
			}
			resetBeforeNextPayPalRequest = false;
		}

		let sentSessionId = '';
		try {
			let result;
			if ( checkoutSessionId ) {
				result = { sessionId: checkoutSessionId, error: '' };
			} else if ( typeof fp.acquireSessionIdResult === 'function' ) {
				result = await fp.acquireSessionIdResult();
			} else {
				result = { sessionId: await fp.acquireSessionId(), error: '' };
			}
			const body = JSON.parse( init.body );
			body[ fp.config.sessionIdField ] = result.sessionId;
			if (
				! result.sessionId &&
				result.error &&
				fp.config.sessionIdErrorField
			) {
				body[ fp.config.sessionIdErrorField ] = result.error;
			}
			init.body = JSON.stringify( body );
			sentSessionId =
				typeof result.sessionId === 'string' ? result.sessionId : '';
		} catch {
			// Fail open with the original request data.
		}

		let response;
		try {
			response = await originalFetch.call( thisArg, resource, init );
		} catch ( e ) {
			resetSession( fp );
			throw e;
		}

		const result = await classifyResponse( response );
		if ( 'failure' === result ) {
			resetSession( fp );
		} else {
			resetBeforeNextPayPalRequest = true;
			// Only a confirmed success has a decision for the checkout to reuse.
			// A checkout that is already processing sends the ID it has, so a
			// hand-over would only wait for a later attempt.
			if ( 'success' === result && ! checkoutSessionId ) {
				handOverSessionId( fp, resource, sentSessionId );
			}
			watchGateway();
		}

		return response;
	}

	window.fetch = function ( resource, init ) {
		try {
			const fp = window.wcFraudProtection;
			if (
				protectedPayPalEndpoints.includes( getEndpoint( resource ) ) &&
				fp &&
				( typeof fp.acquireSessionIdResult === 'function' ||
					typeof fp.acquireSessionId === 'function' )
			) {
				return dispatchProtectedPayPalRequest(
					this,
					fp,
					resource,
					init || {}
				);
			}
		} catch {
			// Fail open.
		}

		return originalFetch.call( this, resource, init );
	};
} )();
