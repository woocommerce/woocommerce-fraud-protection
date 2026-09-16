/* global beforeEach, jest */

const reportConsoleError = console.error.bind( console );
console.error = ( ...args ) => {
	// JSDOM cannot parse the nested CSS that @wordpress/ui injects at runtime.
	if (
		args[ 0 ]?.type === 'css parsing' ||
		args[ 0 ]?.message === 'Could not parse CSS stylesheet'
	) {
		return;
	}
	reportConsoleError( ...args );
};

// JSDOM does not provide Fetch API globals. Stub Request for instanceof checks.
global.Request = class Request {
	constructor( input ) {
		this.url = typeof input === 'string' ? input : String( input );
	}
};

// Base UI dispatches checkbox activation through PointerEvent, which jsdom does not provide.
if ( ! window.PointerEvent ) {
	Object.defineProperty( window, 'PointerEvent', {
		configurable: true,
		writable: true,
		value: window.MouseEvent,
	} );
}

if ( ! window.ResizeObserver ) {
	Object.defineProperty( window, 'ResizeObserver', {
		configurable: true,
		writable: true,
		value: class ResizeObserver {
			observe() {}
			unobserve() {}
			disconnect() {}
		},
	} );
}

// Stub HTMLFormElement.prototype.submit to prevent jsdom "Not implemented" errors.
// Individual tests can override form.submit with their own spy when they need to assert on it.
if ( typeof window.HTMLFormElement !== 'undefined' ) {
	beforeEach( () => {
		window.HTMLFormElement.prototype.submit = jest.fn();
	} );
}
