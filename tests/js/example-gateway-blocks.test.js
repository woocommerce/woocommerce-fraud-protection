/** @jest-environment jsdom */ // eslint-disable-line jsdoc/check-tag-names

/**
 * Tests for the example gateway's Cart and Checkout block script: the payment
 * method posts its fields, and the provider order's ID when the shopper comes
 * from the "Pay with provider" button, and the cart buttons join the express
 * payment area when their scripts are loaded.
 */

const element = require( '@wordpress/element' );
const { render, screen, fireEvent } = require( '@testing-library/react' );

const PAYMENT_METHOD_DATA = {
	title: 'Example card payment',
	supports: [ 'products' ],
	labels: {
		cardBrand: 'Card brand',
		cardLast4: 'Last four digits',
		provider: 'Pay with provider (example)',
		express: 'Express pay (example)',
	},
	providerOrderField: 'fp_example_provider_order_id',
};

let registry;

beforeEach( () => {
	registry = {
		registerPaymentMethod: jest.fn(),
		registerExpressPaymentMethod: jest.fn(),
	};
} );

afterEach( () => {
	delete window.wc;
	delete window.wp;
	delete window.fpExampleProviderCheckout;
	delete window.fpExampleExpress;
	window.history.replaceState( {}, '', '/' );
} );

function load( { buttonScripts = true } = {} ) {
	window.wc = {
		wcBlocksRegistry: registry,
		wcSettings: {
			getPaymentMethodData: jest.fn( () => PAYMENT_METHOD_DATA ),
		},
	};
	window.wp = { element };
	if ( buttonScripts ) {
		window.fpExampleProviderCheckout = {};
		window.fpExampleExpress = {};
	}

	jest.isolateModules( () => {
		require( '../../examples/fraud-protection-example-gateway/assets/blocks' );
	} );
}

function renderPaymentMethod() {
	const config = registry.registerPaymentMethod.mock.calls[ 0 ][ 0 ];
	let paymentSetup;
	const props = {
		eventRegistration: {
			onPaymentSetup: jest.fn( ( callback ) => {
				paymentSetup = callback;
				return () => {};
			} ),
		},
		emitResponse: { responseTypes: { SUCCESS: 'success' } },
	};
	render( element.cloneElement( config.content, props ) );

	return () => paymentSetup();
}

describe( 'example gateway block payment method', () => {
	it( 'registers the gateway as a Checkout block payment method', () => {
		load();

		expect( registry.registerPaymentMethod ).toHaveBeenCalledTimes( 1 );
		const config = registry.registerPaymentMethod.mock.calls[ 0 ][ 0 ];
		expect( config.name ).toBe( 'fp_example' );
		expect( config.label ).toBe( 'Example card payment' );
		expect( config.canMakePayment() ).toBe( true );
	} );

	it( 'posts the card fields as payment data', () => {
		load();
		const paymentSetup = renderPaymentMethod();

		fireEvent.change( screen.getByLabelText( 'Card brand' ), {
			target: { value: 'mastercard' },
		} );
		fireEvent.change( screen.getByLabelText( 'Last four digits' ), {
			target: { value: '1234' },
		} );

		expect( paymentSetup() ).toEqual( {
			type: 'success',
			meta: {
				paymentMethodData: {
					fp_example_card_brand: 'mastercard',
					fp_example_card_last4: '1234',
				},
			},
		} );
	} );

	it( 'posts the provider order ID that the "Pay with provider" button opened the checkout with', () => {
		window.history.replaceState(
			{},
			'',
			'/checkout/?fp_example_provider_order_id=provider-order-1'
		);
		load();
		const paymentSetup = renderPaymentMethod();

		expect( paymentSetup().meta.paymentMethodData ).toEqual( {
			fp_example_card_brand: 'visa',
			fp_example_card_last4: '4242',
			fp_example_provider_order_id: 'provider-order-1',
		} );
	} );

	it( 'adds the cart buttons to the express payment area when their scripts are loaded', () => {
		load();

		const configs = registry.registerExpressPaymentMethod.mock.calls.map(
			( call ) => call[ 0 ]
		);
		expect(
			configs.map( ( config ) => [ config.name, config.paymentMethodId ] )
		).toEqual( [
			[ 'fp_example_provider', 'fp_example' ],
			[ 'fp_example_express', 'fp_example' ],
		] );

		// The blocks pass their payment props to the content; the button keeps them out of the DOM.
		render(
			element.cloneElement( configs[ 1 ].content, {
				billing: { cartTotal: {} },
			} )
		);
		const button = screen.getByRole( 'button', {
			name: 'Express pay (example)',
		} );
		expect( button.classList ).toContain( 'fp-example-express-button' );
		expect( button.hasAttribute( 'billing' ) ).toBe( false );
	} );

	it( 'adds no cart buttons without their scripts', () => {
		load( { buttonScripts: false } );

		expect( registry.registerPaymentMethod ).toHaveBeenCalledTimes( 1 );
		expect( registry.registerExpressPaymentMethod ).not.toHaveBeenCalled();
	} );
} );
