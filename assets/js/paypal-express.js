/**
 * PayPal request fetch interceptor for Woo Fraud Protection.
 *
 * Injects the browser session into supported PayPal requests. Errors
 * remain fail open and never prevent the original Fetch call.
 *
 * After a successful request, the session stays for the PayPal payment that
 * continues it. When the shopper goes on with another gateway instead, for
 * example after closing PayPal, a new session is started for that payment.
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
	const PAYPAL_GATEWAY_PREFIX = 'ppcp-';
	const PAYMENT_STORE_KEY = 'wc/store/payment';
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
			resetForGateway(
				$( 'input[name="payment_method"]:checked' ).val()
			);
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

		const getActiveGateway = function () {
			const store = data.select( PAYMENT_STORE_KEY );
			return store && store.getActivePaymentMethod
				? store.getActivePaymentMethod()
				: '';
		};

		let activeGateway = getActiveGateway();
		data.subscribe( function () {
			try {
				const gateway = getActiveGateway();
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
		if ( resetBeforeNextPayPalRequest ) {
			resetSession( fp );
			resetBeforeNextPayPalRequest = false;
		}

		try {
			const result =
				typeof fp.acquireSessionIdResult === 'function'
					? await fp.acquireSessionIdResult()
					: { sessionId: await fp.acquireSessionId(), error: '' };
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
