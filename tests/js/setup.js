// JSDOM does not provide Fetch API globals. Stub Request for instanceof checks.
global.Request = class Request {
	constructor( input ) {
		this.url = typeof input === 'string' ? input : String( input );
	}
};

// Stub HTMLFormElement.prototype.submit to prevent jsdom "Not implemented" errors.
// Individual tests can override form.submit with their own spy when they need to assert on it.
if ( typeof HTMLFormElement !== 'undefined' ) {
	beforeEach( () => {
		HTMLFormElement.prototype.submit = jest.fn();
	} );
}
