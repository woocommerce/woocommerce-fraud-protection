<?php
/**
 * FP_Example_Fraud_Protection class file.
 *
 * @package WooCommerce\FraudProtection\Examples
 */

declare( strict_types=1 );

use Automattic\WooCommerce\FraudProtection\BlackboxScriptHandler;
use Automattic\WooCommerce\FraudProtection\BlockedSessionMessage;
use Automattic\WooCommerce\FraudProtection\MessageContext;
use Automattic\WooCommerce\FraudProtection\Schemas\FraudDecision;
use Automattic\WooCommerce\FraudProtection\Schemas\PaymentInstrumentData;
use Automattic\WooCommerce\FraudProtection\Schemas\PaymentMethodData;
use Automattic\WooCommerce\FraudProtection\Schemas\PaymentMode;
use Automattic\WooCommerce\FraudProtection\SessionVerifier;
use Automattic\WooCommerce\FraudProtection\SuppliedDecision;

defined( 'ABSPATH' ) || exit;

/**
 * The example gateway's native Fraud Protection integration.
 *
 * Three parts, matching the three features the gateway declares in its `$supports`:
 *
 * 1. `fraud_protection_payment_data`: {@see resolve_payment_data()} describes the payment
 *    instrument for the verification request.
 * 2. `fraud_protection_checkout`: the gateway's own "Express pay" request, an AJAX call made
 *    from the cart page like PayPal's create-order call, is verified by
 *    {@see handle_express_pay()} and stopped on a Block decision. The request creates a
 *    payment reference, as PayPal creates an order, and the final checkout posts it back.
 *    The decision is kept for that checkout, which {@see supply_express_decision()} answers
 *    so the attempt is not scored twice.
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
	 * WooCommerce session key that keeps the express decision for the final checkout.
	 */
	private const DECISION_SESSION_KEY = 'fp_example_fraud_verification';

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
		if ( ! class_exists( SessionVerifier::class ) ) {
			return;
		}

		add_filter( 'woocommerce_fraud_protection_resolved_payment_data', array( $this, 'resolve_payment_data' ), 10, 2 );
		add_filter( 'woocommerce_fraud_protection_skip_session_verify', array( $this, 'supply_express_decision' ), 10, 3 );
		add_action( 'woocommerce_proceed_to_checkout', array( $this, 'render_express_button' ), 30 );
		add_action( 'woocommerce_checkout_before_order_review', array( $this, 'render_express_reference' ) );
		add_action( 'wc_ajax_' . self::AJAX_ACTION, array( $this, 'handle_express_pay' ) );
	}

	/**
	 * Describe the payment instrument of an attempt made with this gateway.
	 *
	 * Fraud Protection calls this for every attempt; the callback returns the incoming
	 * value unchanged for other gateways.
	 *
	 * @param mixed $resolved                The payment data resolved so far.
	 * @param mixed $checkout_payment_fields Flat key-value map of the submitted payment fields.
	 * @return mixed The payment data to use.
	 */
	public function resolve_payment_data( $resolved, $checkout_payment_fields ) {
		// Values from a filter are untrusted: another callback may have changed them.
		if ( ! $resolved instanceof PaymentMethodData || WC_Gateway_FP_Example::ID !== $resolved->get_gateway() || ! is_array( $checkout_payment_fields ) ) {
			return $resolved;
		}

		$instrument = PaymentInstrumentData::from_array(
			array(
				'brand'  => $checkout_payment_fields['fp_example_card_brand'] ?? null,
				'last4'  => $checkout_payment_fields['fp_example_card_last4'] ?? null,
				'wallet' => $checkout_payment_fields['fp_example_wallet'] ?? null,
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

		// Request the shared Fraud Protection scripts for this payment surface. When they are
		// unavailable (no Jetpack connection, an excluded context) the button still works and
		// the server verifies the request without a session ID.
		$dependencies = array( 'wp-hooks' );
		if ( wc_get_container()->get( BlackboxScriptHandler::class )->request_scripts() ) {
			$dependencies[] = 'wc-fraud-protection-blackbox-init';
		}

		wp_enqueue_script( 'fp-example-express', plugins_url( 'assets/express.js', FP_EXAMPLE_GATEWAY_FILE ), $dependencies, '0.1.0', true );
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
	 * `window.wcFraudProtection.config.sessionIdField`, which is `SessionVerifier::SESSION_ID_FIELD`.
	 */
	public function handle_express_pay(): void {
		check_ajax_referer( self::NONCE_ACTION, 'security' );

		// Pass the session ID as submitted: SessionVerifier normalizes it and ignores a non-string.
		$session_id   = isset( $_POST[ SessionVerifier::SESSION_ID_FIELD ] ) ? wp_unslash( $_POST[ SessionVerifier::SESSION_ID_FIELD ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$request_data = array(
			'payment_method' => WC_Gateway_FP_Example::ID,
			'payment_data'   => array(
				'fp_example_wallet' => sanitize_text_field( wp_unslash( $_POST['fp_example_wallet'] ?? '' ) ),
			),
		);

		$verifier = wc_get_container()->get( SessionVerifier::class );
		$decision = $verifier->verify_session( $session_id, self::EXPRESS_SOURCE, 0, $request_data );

		if ( FraudDecision::Block === $decision ) {
			wp_send_json_error( array( 'message' => ( new BlockedSessionMessage() )->get_plaintext( MessageContext::Purchase ) ), 403 );
		}

		// A real gateway creates the payment at its provider here, such as a PayPal order, and the
		// final checkout completes it. The payment's ID ties that checkout to this verified request:
		// the browser only gets it from this response.
		$reference = wp_generate_uuid4();

		// Keep this attempt's decision for the final checkout. The browser starts a new session after
		// this request, so the checkout submits another session ID: the decision is matched by the
		// reference instead, and keeps the session ID the verification resolved for the order.
		$this->remember_decision( $verifier->last_verified_session_id(), $decision, $reference );

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

	/**
	 * Supply the express decision to the final checkout of the same attempt.
	 *
	 * Fraud Protection calls this before verifying any checkout. The record only applies to the
	 * checkout that posts the reference the express request created, for the same cart, and it
	 * is consumed on first use. A checkout without the reference is verified normally.
	 *
	 * @param mixed $supplied_decision The filter's default (false), or an earlier callback's result.
	 * @param mixed $source            Verification source, such as `shortcode_checkout` or `blocks_checkout`.
	 * @param mixed $request_data      Request data with `payment_method` and the posted `payment_data`.
	 * @return mixed The supplied decision, or the incoming value to verify normally.
	 */
	public function supply_express_decision( $supplied_decision, $source, $request_data ) {
		if ( false !== $supplied_decision || ! is_array( $request_data ) ) {
			return $supplied_decision;
		}

		if ( ! in_array( $source, array( 'shortcode_checkout', 'blocks_checkout' ), true ) || WC_Gateway_FP_Example::ID !== ( $request_data['payment_method'] ?? '' ) ) {
			return $supplied_decision;
		}

		$payment_data = is_array( $request_data['payment_data'] ?? null ) ? $request_data['payment_data'] : array();
		$reference    = is_string( $payment_data[ self::EXPRESS_REFERENCE_FIELD ] ?? null ) ? $payment_data[ self::EXPRESS_REFERENCE_FIELD ] : '';
		if ( '' === $reference || ! WC()->session || ! WC()->cart ) {
			return $supplied_decision;
		}

		$record = WC()->session->get( self::DECISION_SESSION_KEY );
		if ( ! is_array( $record ) || ( $record['reference'] ?? '' ) !== $reference ) {
			return $supplied_decision;
		}

		// One use only: the decision applies to this attempt alone.
		WC()->session->set( self::DECISION_SESSION_KEY, null );

		$decision = is_string( $record['decision'] ?? null ) ? FraudDecision::tryFrom( $record['decision'] ) : null;
		if (
			! $decision instanceof FraudDecision
			|| ! in_array( $decision, FraudDecision::ACTIONABLE, true )
			|| ! is_string( $record['session_id'] ?? null )
			|| ( $record['cart_hash'] ?? '' ) !== WC()->cart->get_cart_hash()
		) {
			return $supplied_decision;
		}

		return new SuppliedDecision( $decision, $record['session_id'] );
	}

	/**
	 * Keep an express decision for the final checkout of the current cart.
	 *
	 * @param string        $session_id The session ID the verification resolved; empty when there is none to key by.
	 * @param FraudDecision $decision   The decision the verification produced.
	 * @param string        $reference  The payment reference the request created.
	 */
	private function remember_decision( string $session_id, FraudDecision $decision, string $reference ): void {
		if ( ! WC()->session || ! WC()->cart ) {
			return;
		}

		$record = null;
		if ( '' !== $session_id && in_array( $decision, FraudDecision::ACTIONABLE, true ) ) {
			$record = array(
				'session_id' => $session_id,
				'decision'   => $decision->value,
				'reference'  => $reference,
				'cart_hash'  => WC()->cart->get_cart_hash(),
			);
		}

		WC()->session->set( self::DECISION_SESSION_KEY, $record );
	}
}
