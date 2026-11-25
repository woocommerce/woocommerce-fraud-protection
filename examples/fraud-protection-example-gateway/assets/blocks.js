/**
 * Cart and Checkout block support of the Fraud Protection example gateway.
 *
 * Gateway code, with nothing specific to Fraud Protection:
 *
 * - Registers the gateway as a payment method of the Checkout block, with the
 *   same card fields as the classic checkout. The block places the order with
 *   a Store API checkout request, which Fraud Protection verifies by itself;
 *   the fields reach the gateway's payment data resolver as the request's
 *   payment data.
 * - When the shopper comes from the "Pay with provider" button, posts the
 *   provider order's ID with the payment data, as the classic checkout form
 *   does, so the checkout reuses that request's decision.
 * - Adds the "Pay with provider" and "Express pay" buttons to the express
 *   payment area of the Cart and Checkout blocks, when their scripts loaded
 *   before this one. Those scripts handle the clicks.
 *
 * Depends on wc-blocks-registry, wc-settings and wp-element.
 */
( function () {
	'use strict';

	const GATEWAY_ID = 'fp_example';

	const registry = window.wc && window.wc.wcBlocksRegistry;
	const wcSettings = window.wc && window.wc.wcSettings;
	const element = window.wp && window.wp.element;
	if ( ! registry || ! wcSettings || ! element ) {
		return;
	}

	const { createElement: el, useEffect, useState } = element;
	const data = wcSettings.getPaymentMethodData( GATEWAY_ID, {} ) || {};
	const labels = data.labels || {};
	const title = data.title || GATEWAY_ID;
	const supports = { features: data.supports || [ 'products' ] };

	/**
	 * Read the provider order's ID from the URL that the "Pay with provider" button opened.
	 *
	 * @return {string} The provider order's ID, or an empty string.
	 */
	function getProviderOrderId() {
		if ( ! data.providerOrderField ) {
			return '';
		}

		return (
			new URL( window.location.href ).searchParams.get(
				data.providerOrderField
			) || ''
		);
	}

	/**
	 * The card fields of the payment method.
	 *
	 * A real gateway tokenizes the card in the browser and posts the token.
	 * This example posts the fields that the payment data resolver reads.
	 *
	 * @param {Object} props Props that the Checkout block passes to payment methods.
	 * @return {Object} The rendered fields.
	 */
	function CardFields( props ) {
		const [ brand, setBrand ] = useState( 'visa' );
		const [ last4, setLast4 ] = useState( '4242' );
		const onPaymentSetup = props.eventRegistration.onPaymentSetup;
		const responseTypes = props.emitResponse.responseTypes;

		useEffect(
			function () {
				return onPaymentSetup( function () {
					const paymentMethodData = {
						fp_example_card_brand: brand,
						fp_example_card_last4: last4,
					};
					const providerOrderId = getProviderOrderId();
					if ( providerOrderId ) {
						paymentMethodData[ data.providerOrderField ] =
							providerOrderId;
					}

					return {
						type: responseTypes.SUCCESS,
						meta: { paymentMethodData },
					};
				} );
			},
			[ onPaymentSetup, responseTypes, brand, last4 ]
		);

		return el(
			'div',
			null,
			el(
				'p',
				null,
				el(
					'label',
					{ htmlFor: 'fp-example-card-brand' },
					labels.cardBrand
				),
				el(
					'select',
					{
						id: 'fp-example-card-brand',
						value: brand,
						onChange( event ) {
							setBrand( event.target.value );
						},
					},
					el( 'option', { value: 'visa' }, 'Visa' ),
					el( 'option', { value: 'mastercard' }, 'Mastercard' )
				)
			),
			el(
				'p',
				null,
				el(
					'label',
					{ htmlFor: 'fp-example-card-last4' },
					labels.cardLast4
				),
				el( 'input', {
					id: 'fp-example-card-last4',
					type: 'text',
					inputMode: 'numeric',
					maxLength: 4,
					value: last4,
					onChange( event ) {
						setLast4( event.target.value );
					},
				} )
			)
		);
	}

	registry.registerPaymentMethod( {
		name: GATEWAY_ID,
		label: title,
		ariaLabel: title,
		content: el( CardFields ),
		edit: el( 'p', null, title ),
		canMakePayment() {
			return true;
		},
		supports,
	} );

	/**
	 * Register a cart button as an express payment method of the blocks.
	 *
	 * @param {string} name      Express payment method name.
	 * @param {string} className Class that the button's script handles clicks for.
	 * @param {string} label     Button text.
	 */
	function registerButton( name, className, label ) {
		// The blocks pass their payment props to this component; the button needs none of them.
		// The express payment area sets no font size for its buttons, so the button sets its own
		// and fills its slot, like the other express buttons.
		function Button() {
			return el(
				'button',
				{
					type: 'button',
					className: 'button ' + className,
					style: { fontSize: '1rem', width: '100%', height: '100%' },
				},
				label
			);
		}

		registry.registerExpressPaymentMethod( {
			name,
			title: label,
			paymentMethodId: GATEWAY_ID,
			content: el( Button ),
			edit: el( Button ),
			canMakePayment() {
				return true;
			},
			supports,
		} );
	}

	// The button scripts load before this one only when the gateway's Fraud Protection
	// integration registered them; their settings are defined then.
	if ( window.fpExampleProviderCheckout ) {
		registerButton(
			'fp_example_provider',
			'fp-example-provider-button',
			labels.provider
		);
	}
	if ( window.fpExampleExpress ) {
		registerButton(
			'fp_example_express',
			'fp-example-express-button',
			labels.express
		);
	}
} )();
