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
 * 1. `fraud_protection_payment_data`: {@see resolve_payment_data()} describes the payment
 *    instrument of the gateway's attempts for their verification.
 * 2. `fraud_protection_checkout`: the gateway's own "Express pay" request, an AJAX call made
 *    from the cart page like PayPal's create-order call, is verified by
 *    {@see handle_express_pay()} and stopped on a Block decision. The request creates a
 *    payment reference, as PayPal creates an order, and the final checkout posts it back.
 *    That checkout reuses the request's decision, so the attempt is not scored twice.
 * 3. `fraud_protection_express_checkout`: the button script adds the session ID to its request
 *    synchronously (see assets/express.js).
 *
 * Everything fails open: without Fraud Protection nothing is registered, and a request
 * without a session ID is still verified and allowed when verification cannot decide.
 */
class FP_Example_Fraud_Protection {

	/**
	 * Verification source for the express request. Stable, ASCII, at most 32 characters.
	 */
	public const EXPRESS_SOURCE = 'fp_example_express';

	/**
	 * WooCommerce AJAX action of the express request.
	 */
	public const AJAX_ACTION = 'fp_example_express_pay';

	/**
	 * Request field that carries the express payment reference from the checkout form.
	 */
	public const EXPRESS_REFERENCE_FIELD = 'fp_example_express_reference';

	/**
	 * Nonce action of the express request.
	 */
	private const NONCE_ACTION = 'fp_example_express';

	/**
	 * Register the integration hooks.
	 *
	 * Fraud Protection is optional: without it the gateway works, unprotected.
	 */
	public function register(): void {
		// GatewayRequestVerifier needs Fraud Protection 0.3.1 or later.
		if ( ! class_exists( GatewayRequestVerifier::class ) ) {
			return;
		}

		wc_get_container()->get( GatewayPaymentData::class )->register_resolver( WC_Gateway_FP_Example::ID, array( $this, 'resolve_payment_data' ) );
		// The checkout that completes an express payment posts the reference the express request created.
		wc_get_container()->get( GatewayRequestVerifier::class )->register_reference_reader(
			WC_Gateway_FP_Example::ID,
			static fn( array $payment_data ) => $payment_data[ self::EXPRESS_REFERENCE_FIELD ] ?? ''
		);
		add_action( 'woocommerce_proceed_to_checkout', array( $this, 'render_express_button' ), 30 );
		add_action( 'woocommerce_checkout_before_order_review', array( $this, 'render_express_reference' ) );
		add_action( 'wc_ajax_' . self::AJAX_ACTION, array( $this, 'handle_express_pay' ) );
	}

	/**
	 * Describe the payment instrument of an attempt made with this gateway.
	 *
	 * Fraud Protection calls this only for this gateway's attempts.
	 *
	 * @param array             $payment_fields Flat key-value map of the submitted payment fields.
	 * @param PaymentMethodData $resolved       The payment data resolved so far.
	 * @return PaymentMethodData The payment data to use.
	 */
	public function resolve_payment_data( array $payment_fields, PaymentMethodData $resolved ): PaymentMethodData {
		$instrument = PaymentInstrumentData::from_array(
			array(
				'brand'  => $payment_fields['fp_example_card_brand'] ?? null,
				'last4'  => $payment_fields['fp_example_card_last4'] ?? null,
				'wallet' => $payment_fields['fp_example_wallet'] ?? null,
			)
		);

		// A saved card is pre-resolved from the WooCommerce token; keep that flag.
		$is_saved = (bool) ( $resolved->to_array()['is_saved_payment_method'] ?? false );

		// A real gateway reads the mode and the merchant account from its own settings or API.
		return new PaymentMethodData( WC_Gateway_FP_Example::ID, 'card', $is_saved, $instrument, PaymentMode::Test, 'acct_example', 'account' );
	}

	/**
	 * Render the express button on the cart page and load its script.
	 */
	public function render_express_button(): void {
		if ( ! isset( WC()->payment_gateways()->get_available_payment_gateways()[ WC_Gateway_FP_Example::ID ] ) ) {
			return;
		}

		wp_enqueue_script( 'fp-example-express', plugins_url( 'assets/express.js', FP_EXAMPLE_GATEWAY_FILE ), array( 'wp-hooks' ), '0.1.0', true );

		// Request the shared Fraud Protection scripts for this payment surface, which the button
		// script then depends on. When they are unavailable (no Jetpack connection, an excluded
		// context) the button still works and the server verifies the request without a session ID.
		wc_get_container()->get( BlackboxScriptHandler::class )->request_scripts_for( 'fp-example-express' );
		wp_localize_script(
			'fp-example-express',
			'fpExampleExpress',
			array(
				'url'   => WC_AJAX::get_endpoint( self::AJAX_ACTION ),
				'nonce' => wp_create_nonce( self::NONCE_ACTION ),
			)
		);

		echo '<button type="button" class="button fp-example-express-button">' . esc_html__( 'Express pay (example)', 'fp-example-gateway' ) . '</button>';
	}

	/**
	 * Verify the express request and answer it.
	 *
	 * The browser adds the session ID under the field named by
	 * `window.wcFraudProtection.config.sessionIdField`, which `verify_request()` reads from the POST body.
	 */
	public function handle_express_pay(): void {
		check_ajax_referer( self::NONCE_ACTION, 'security' );

		$request = wc_get_container()->get( GatewayRequestVerifier::class )->verify_request(
			self::EXPRESS_SOURCE,
			WC_Gateway_FP_Example::ID,
			array( 'fp_example_wallet' => sanitize_text_field( wp_unslash( $_POST['fp_example_wallet'] ?? '' ) ) )
		);

		if ( $request->is_blocked() ) {
			wp_send_json_error( array( 'message' => ( new BlockedSessionMessage() )->get_plaintext( MessageContext::Purchase ) ), 403 );
		}

		// A real gateway creates the payment at its provider here, such as a PayPal order, and the
		// final checkout completes it. The payment's ID ties that checkout to this verified request:
		// the browser only gets it from this response.
		$reference = wp_generate_uuid4();

		// The browser starts a new session after this request, so the checkout submits another
		// session ID: the decision is matched by the reference instead.
		$request->reuse_decision_for( $reference );

		wp_send_json_success( array( 'redirect' => add_query_arg( self::EXPRESS_REFERENCE_FIELD, $reference, wc_get_checkout_url() ) ) );
	}

	/**
	 * Carry the express payment reference into the checkout form.
	 *
	 * The express button redirects to the checkout with the reference in the URL, and the form
	 * posts it back, as a real gateway posts the ID of the payment it created at its provider.
	 * The hook renders inside the form but outside the part WooCommerce refreshes over AJAX.
	 */
	public function render_express_reference(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Matched against the WooCommerce session on submit.
		$reference = isset( $_GET[ self::EXPRESS_REFERENCE_FIELD ] ) ? sanitize_key( wp_unslash( $_GET[ self::EXPRESS_REFERENCE_FIELD ] ) ) : '';
		if ( '' === $reference ) {
			return;
		}

		printf( '<input type="hidden" name="%s" value="%s" />', esc_attr( self::EXPRESS_REFERENCE_FIELD ), esc_attr( $reference ) );
	}
}
