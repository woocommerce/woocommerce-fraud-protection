/**
 * PayPal artifact fetch interceptor for Woo Fraud Protection.
 *
 * Injects the browser session into supported PayPal artifact requests. Errors
 * remain fail open and never prevent the original Fetch call.
 */
( function () {
	if ( ! window.wcFraudProtection ) {
		return;
	}

	const originalFetch = window.fetch;
	const protectedEndpoints = new Set( [
		'ppc-create-order',
		'ppc-create-setup-token',
		'ppc-vault-create-order',
	] );
	let artifactQueue = Promise.resolve();
	let resetBeforeNextArtifact = false;

	function getEndpoint( resource ) {
		const url =
			resource instanceof Request ? resource.url : String( resource );
		return (
			new URL( url, window.location.href ).searchParams.get(
				'wc-ajax'
			) || ''
		);
	}

	function reset( fp ) {
		try {
			if ( typeof fp.reset === 'function' ) {
				fp.reset();
			}
		} catch ( e ) {
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
		} catch ( e ) {
			return 'unknown';
		}
	}

	async function dispatchArtifact( thisArg, fp, resource, init ) {
		if ( resetBeforeNextArtifact ) {
			reset( fp );
			resetBeforeNextArtifact = false;
		}

		try {
			const sessionId = await fp.acquireSessionId();
			const body = JSON.parse( init.body );
			body[ fp.config.sessionIdField ] = sessionId;
			init.body = JSON.stringify( body );
		} catch ( e ) {
			// Fail open with the original request data.
		}

		let response;
		try {
			response = await originalFetch.call( thisArg, resource, init );
		} catch ( e ) {
			reset( fp );
			throw e;
		}

		try {
			const result = await classifyResponse( response );
			if ( 'failure' === result ) {
				reset( fp );
			} else {
				resetBeforeNextArtifact = true;
			}
		} catch ( e ) {
			resetBeforeNextArtifact = true;
		}

		return response;
	}

	window.fetch = function ( resource, init ) {
		try {
			const fp = window.wcFraudProtection;
			if (
				protectedEndpoints.has( getEndpoint( resource ) ) &&
				fp &&
				typeof fp.acquireSessionId === 'function'
			) {
				const dispatch = () =>
					dispatchArtifact( this, fp, resource, init || {} );
				const result = artifactQueue.then( dispatch, dispatch );
				artifactQueue = result.catch( () => undefined );
				return result;
			}
		} catch ( e ) {
			// Fail open.
		}

		return originalFetch.call( this, resource, init );
	};
} )();
