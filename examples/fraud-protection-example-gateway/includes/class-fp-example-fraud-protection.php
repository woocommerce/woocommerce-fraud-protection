<?php
/**
 * FP_Example_Fraud_Protection class file.
 *
 * @package WooCommerce\FraudProtection\Examples
 */

declare( strict_types=1 );

use Automattic\WooCommerce\FraudProtection\BlackboxScriptHandler;
use Automattic\WooCommerce\FraudProtection\BlockedSessionMessage;
use Automattic\WooCommerce\FraudProtection\GatewayPaymentData;
use Automattic\WooCommerce\FraudProtection\GatewayRequestVerifier;
use Automattic\WooCommerce\FraudProtection\MessageContext;
use Automattic\WooCommerce\FraudProtection\Schemas\PaymentInstrumentData;
use Automattic\WooCommerce\FraudProtection\Schemas\PaymentMethodData;
use Automattic\WooCommerce\FraudProtection\Schemas\PaymentMode;

defined( 'ABSPATH' ) || exit;

/**
 * The example gateway's native Fraud Protection integration.
 *
 * Three parts, matching the three features the gateway declares in its `$supports`:
 *
 * 1. `fraud_protection_payment_data`: {@see resolve_payment_data()} provides the details of the
 *    payment method that the gateway's attempts use.
 * 2. `fraud_protection_checkout`: the "Pay with provider" button sends the gateway's own request,
 *    an AJAX call that creates an order at the payment provider, like PayPal's create-order call.
 *    {@see handle_create_provider_order()} verifies it and stops it on a Block decision. The
 *    checkout that completes the purchase posts the provider order's ID back and reuses the
 *    request's decision, so the attempt is verified once (see assets/provider-checkout.js).
 * 3. `fraud_protection_express_checkout`: the "Express pay" button places the order through a
 *    Store API checkout request, which Fraud Protection verifies by itself. The button script
 *    only adds the session ID to the request's extension data (see assets/express.js).
 *
 * Everything fails open: without Fraud Protection nothing is registered, and a request
 * without a session ID is still verified.
 */
class FP_Example_Fraud_Protection {

	/**
	 * Verification source of the provider order request. Stable, ASCII, at most 32 characters.
	 */
	public const PROVIDER_ORDER_SOURCE = 'fp_example_provider_order';

	/**
	 * WooCommerce AJAX action of the provider order request.
	 */
	public const PROVIDER_ORDER_ACTION = 'fp_example_create_provider_order';

	/**
	 * Request field that carries the provider order's ID from the checkout form.
	 */
	public const PROVIDER_ORDER_FIELD = 'fp_example_provider_order_id';

	/**
	 * Script handle of the "Pay with provider" button.
	 */
	public const PROVIDER_SCRIPT = 'fp-example-provider-checkout';

	/**
	 * Script handle of the "Express pay" button.
	 */
	public const EXPRESS_SCRIPT = 'fp-example-express';

	/**
	 * Nonce action of the provider order request.
	 */
	private const PROVIDER_ORDER_NONCE = 'fp_example_create_provider_order';

	/**
	 * The address that the express button's stand-in payment sheet returns.
	 *
	 * A real wallet returns the address that the shopper picks in its payment sheet.
	 */
	private const EXPRESS_SHEET_ADDRESS = array(
		'first_name' => 'Sam',
		'last_name'  => 'Example',
		'address_1'  => '60 29th Street',
		'city'       => 'San Francisco',
		'state'      => 'CA',
		'postcode'   => '94110',
		'country'    => 'US',
		'email'      => 'sam@example.com',
	);

	/**
	 * Register the integration hooks.
	 *
	 * Fraud Protection is optional: without it the gateway works, unprotected.
	 */
	public function register(): void {
		// GatewayRequestVerifier needs Fraud Protection 0.4.0 or later.
		if ( ! class_exists( GatewayRequestVerifier::class ) ) {
			return;
		}

		wc_get_container()->get( GatewayPaymentData::class )->register_resolver( WC_Gateway_FP_Example::ID, array( $this, 'resolve_payment_data' ) );
		// The checkout that completes a provider order posts the order's ID.
		wc_get_container()->get( GatewayRequestVerifier::class )->register_reference_reader(
			WC_Gateway_FP_Example::ID,
			static fn( array $payment_data ) => $payment_data[ self::PROVIDER_ORDER_FIELD ] ?? ''
		);
		add_action( 'wp_enqueue_scripts', array( $this, 'register_scripts' ) );
		add_action( 'woocommerce_proceed_to_checkout', array( $this, 'render_provider_button' ), 30 );
		add_action( 'woocommerce_proceed_to_checkout', array( $this, 'render_express_button' ), 40 );
		add_action( 'woocommerce_checkout_before_order_review', array( $this, 'render_provider_order_field' ) );
		add_action( 'wc_ajax_' . self::PROVIDER_ORDER_ACTION, array( $this, 'handle_create_provider_order' ) );
	}

	/**
	 * Provide the details of the payment method that an attempt made with this gateway uses.
	 *
	 * Fraud Protection calls this only for this gateway's attempts.
	 *
	 * @param array             $payment_fields Flat key-value map of the submitted payment fields.
	 * @param PaymentMethodData $resolved       The payment data resolved so far.
	 * @return PaymentMethodData The payment data to use.
	 */
	public function resolve_payment_data( array $payment_fields, PaymentMethodData $resolved ): PaymentMethodData {
		// A saved card is pre-resolved from its WooCommerce token: keep that data and add only
		// what the token doesn't hold. A real gateway reads the mode and the merchant account
		// from its own settings or API.
		if ( $resolved->to_array()['is_saved_payment_method'] ) {
			return $resolved->with_transaction_mode( PaymentMode::Test )
				->with_merchant_identifier( 'acct_example', 'account' );
		}

		$instrument = PaymentInstrumentData::from_array(
			array(
				'brand'  => $payment_fields['fp_example_card_brand'] ?? null,
				'last4'  => $payment_fields['fp_example_card_last4'] ?? null,
				'wallet' => $payment_fields['fp_example_wallet'] ?? null,
			)
		);

		return new PaymentMethodData( WC_Gateway_FP_Example::ID, 'card', false, $instrument, PaymentMode::Test, 'acct_example', 'account' );
	}

	/**
	 * Register the button scripts on every page.
	 *
	 * Each is enqueued only where its button renders: on the classic cart by
	 * {@see render_provider_button()} and {@see render_express_button()}, and in the express
	 * payment area of the Cart and Checkout blocks by {@see FP_Example_Blocks_Support}.
	 */
	public function register_scripts(): void {
		wp_register_script( self::PROVIDER_SCRIPT, plugins_url( 'assets/provider-checkout.js', FP_EXAMPLE_GATEWAY_FILE ), array(), '0.1.0', true );
		wp_localize_script(
			self::PROVIDER_SCRIPT,
			'fpExampleProviderCheckout',
			array(
				'url'   => WC_AJAX::get_endpoint( self::PROVIDER_ORDER_ACTION ),
				'nonce' => wp_create_nonce( self::PROVIDER_ORDER_NONCE ),
			)
		);

		wp_register_script( self::EXPRESS_SCRIPT, plugins_url( 'assets/express.js', FP_EXAMPLE_GATEWAY_FILE ), array( 'wp-hooks' ), '0.1.0', true );
		wp_localize_script(
			self::EXPRESS_SCRIPT,
			'fpExampleExpress',
			array(
				'checkoutUrl' => rest_url( 'wc/store/v1/checkout' ),
				'nonce'       => wp_create_nonce( 'wc_store_api' ),
				'address'     => self::EXPRESS_SHEET_ADDRESS,
			)
		);

		// Make each button script depend on the shared Fraud Protection scripts. Registering loads
		// nothing: WordPress prints them only on pages where a button script is enqueued. When
		// they are unavailable (no Jetpack connection, an excluded page) the buttons still work,
		// and their requests are verified without a session ID.
		$script_handler = wc_get_container()->get( BlackboxScriptHandler::class );
		$script_handler->register_scripts_for( self::PROVIDER_SCRIPT );
		$script_handler->register_scripts_for( self::EXPRESS_SCRIPT );
	}

	/**
	 * Render the "Pay with provider" button on the classic cart page and load its script.
	 */
	public function render_provider_button(): void {
		if ( ! $this->is_gateway_available() ) {
			return;
		}

		wp_enqueue_script( self::PROVIDER_SCRIPT );

		echo '<button type="button" class="button fp-example-provider-button">' . esc_html__( 'Pay with provider (example)', 'fp-example-gateway' ) . '</button>';
	}

	/**
	 * Render the "Express pay" button on the classic cart page and load its script.
	 */
	public function render_express_button(): void {
		if ( ! $this->is_gateway_available() ) {
			return;
		}

		wp_enqueue_script( self::EXPRESS_SCRIPT );

		echo '<button type="button" class="button fp-example-express-button">' . esc_html__( 'Express pay (example)', 'fp-example-gateway' ) . '</button>';
	}

	/**
	 * Verify the provider order request and answer it.
	 *
	 * The browser adds the session ID under the field named by
	 * `window.wcFraudProtection.config.sessionIdField`, which `verify_request()` reads from the POST body.
	 */
	public function handle_create_provider_order(): void {
		check_ajax_referer( self::PROVIDER_ORDER_NONCE, 'security' );

		$request = wc_get_container()->get( GatewayRequestVerifier::class )->verify_request( self::PROVIDER_ORDER_SOURCE, WC_Gateway_FP_Example::ID );

		if ( $request->is_blocked() ) {
			wp_send_json_error( array( 'message' => ( new BlockedSessionMessage() )->get_plaintext( MessageContext::Purchase ) ), 403 );
		}

		// A real gateway creates the order at its provider here, such as a PayPal order, and the
		// checkout completes it. The provider order's ID ties that checkout to this verified
		// request: the browser only gets it from this response.
		$provider_order_id = wp_generate_uuid4();

		// The browser starts a new session after this request, so the checkout submits another
		// session ID: the decision is matched by the provider order's ID instead.
		$request->reuse_decision_for( $provider_order_id );

		wp_send_json_success( array( 'redirect' => add_query_arg( self::PROVIDER_ORDER_FIELD, $provider_order_id, wc_get_checkout_url() ) ) );
	}

	/**
	 * Carry the provider order's ID into the classic checkout form.
	 *
	 * On the Checkout block, assets/blocks.js adds it to the payment data instead.
	 * The "Pay with provider" button redirects to the checkout with the ID in the URL, and the
	 * form posts it back, as a real gateway posts the ID of the order it created at its provider.
	 * The hook renders inside the form but outside the part WooCommerce refreshes over AJAX.
	 */
	public function render_provider_order_field(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Matched against the WooCommerce session on submit.
		$provider_order_id = isset( $_GET[ self::PROVIDER_ORDER_FIELD ] ) ? sanitize_key( wp_unslash( $_GET[ self::PROVIDER_ORDER_FIELD ] ) ) : '';
		if ( '' === $provider_order_id ) {
			return;
		}

		printf( '<input type="hidden" name="%s" value="%s" />', esc_attr( self::PROVIDER_ORDER_FIELD ), esc_attr( $provider_order_id ) );
	}

	/**
	 * Check whether the shopper can pay with the example gateway.
	 *
	 * @return bool
	 */
	private function is_gateway_available(): bool {
		return isset( WC()->payment_gateways()->get_available_payment_gateways()[ WC_Gateway_FP_Example::ID ] );
	}
}
