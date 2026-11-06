<?php
/**
 * StripeSetupIntentCompat class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\Compat;

use Automattic\WooCommerce\FraudProtection\BlockedSessionMessage;
use Automattic\WooCommerce\FraudProtection\ClassicFormRequestData;
use Automattic\WooCommerce\FraudProtection\FraudProtectionFeature;
use Automattic\WooCommerce\FraudProtection\GatewayRequestVerifier;
use Automattic\WooCommerce\FraudProtection\MessageContext;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\FraudProtectionController;

defined( 'ABSPATH' ) || exit;

/**
 * Integrates Blackbox fraud protection into the Stripe add-payment-method flow.
 *
 * On My Account > Add payment method, Stripe creates and confirms the setup
 * intent through its `wc_stripe_create_and_confirm_setup_intent` AJAX action
 * before the form is posted, so the card is already validated by the time
 * AddPaymentMethodProtector runs. This compat layer verifies that AJAX request
 * through {@see GatewayRequestVerifier}, as the Stripe plugin itself would, after
 * Stripe's own nonce and rate-limit checks and before the setup intent is
 * created, and blocks it on BLOCK decisions.
 *
 * The decision is kept for the form post of the same attempt, with the payment
 * method that Stripe attaches to the setup intent in that request as the
 * reference. The form post reuses it only when the setup intent it posts holds
 * that payment method, because the payment method of a setup intent can be
 * changed in the browser after the setup intent request.
 *
 * Stands aside once the gateway or the main Stripe gateway declares
 * `fraud_protection_add_payment_method`.
 *
 * Fail-open: GatewayRequestVerifier handles all internal errors and returns ALLOW.
 */
class StripeSetupIntentCompat {

	use StripeSetupIntentReaderTrait;

	/** Setup-intent verification source. */
	public const SOURCE = 'stripe_setup_intent_creation';

	/** Source of the add-payment-method form post that can reuse the decision. */
	private const ADD_PAYMENT_METHOD_SOURCE = 'add_payment_method';

	/** Stripe gateway ID prefix, which is also the main gateway ID. */
	private const STRIPE_GATEWAY_PREFIX = 'stripe';

	/** The add-payment-method protector script, which adds the session ID to the form. */
	private const ADD_PAYMENT_METHOD_SCRIPT_HANDLE = 'wc-fraud-protection-add-payment-method';

	/** POST fields of the Stripe AJAX request that are not payment data. */
	private const NON_PAYMENT_FIELDS = array( 'action', '_ajax_nonce' );

	/** POST field of the Stripe AJAX request with the payment method ID. */
	private const PAYMENT_METHOD_FIELD = 'wc-stripe-payment-method';

	/** POST field of the add-payment-method form with the setup intent ID. */
	private const SETUP_INTENT_FIELD = 'wc-stripe-setup-intent';

	/**
	 * Gateway request verifier.
	 *
	 * @var GatewayRequestVerifier
	 */
	private GatewayRequestVerifier $gateway_request_verifier;

	/**
	 * Blocked-session message generator.
	 *
	 * @var BlockedSessionMessage
	 */
	private BlockedSessionMessage $blocked_session_message;

	/**
	 * Native gateway support lookup.
	 *
	 * @var GatewayNativeSupport
	 */
	private GatewayNativeSupport $native_support;

	/**
	 * Initialize with dependencies.
	 *
	 * @internal
	 *
	 * @param GatewayRequestVerifier $gateway_request_verifier The gateway request verifier.
	 * @param BlockedSessionMessage  $blocked_session_message  The blocked-session message generator.
	 * @param GatewayNativeSupport   $native_support           Native gateway support lookup.
	 */
	final public function init(
		GatewayRequestVerifier $gateway_request_verifier,
		BlockedSessionMessage $blocked_session_message,
		GatewayNativeSupport $native_support
	): void {
		$this->gateway_request_verifier = $gateway_request_verifier;
		$this->blocked_session_message  = $blocked_session_message;
		$this->native_support           = $native_support;
	}

	/**
	 * Check whether a Stripe gateway verifies its own add-payment-method requests.
	 *
	 * @param string $gateway_id The gateway of the current attempt; the main Stripe gateway is asked after it.
	 * @return bool
	 */
	private function is_native( string $gateway_id ): bool {
		return $this->native_support->declared( FraudProtectionFeature::ADD_PAYMENT_METHOD, $gateway_id, self::STRIPE_GATEWAY_PREFIX );
	}

	/**
	 * Register hooks for Stripe setup intent fraud protection.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'wc_stripe_is_valid_create_and_confirm_setup_intent_captcha', array( $this, 'verify_and_block_setup_intent' ) );
		add_action( 'woocommerce_add_payment_method_form_bottom', array( $this, 'enqueue_add_payment_method_script' ), 20, 0 );
		$this->gateway_request_verifier->register_compatibility_reference_reader(
			self::STRIPE_GATEWAY_PREFIX . '*',
			array( FraudProtectionFeature::ADD_PAYMENT_METHOD ),
			array( $this, 'read_setup_intent_payment_method' ),
			self::STRIPE_GATEWAY_PREFIX
		);
	}

	/**
	 * Verify the session and block the Stripe setup intent request if needed.
	 *
	 * Runs on `wc_stripe_is_valid_create_and_confirm_setup_intent_captcha`, which
	 * Stripe applies after its nonce and rate-limit checks and before it creates
	 * the setup intent. On BLOCK it responds with Stripe's error shape and
	 * terminates via wp_send_json_error(), so the decision is kept first.
	 *
	 * @internal
	 *
	 * @param mixed $is_valid Current validity from prior filters. A falsey value stops processing.
	 * @return mixed The unchanged validity.
	 */
	public function verify_and_block_setup_intent( $is_valid ) {
		if ( ! $is_valid ) {
			return $is_valid;
		}

		$gateway_id = $this->get_setup_intent_gateway_id();
		if ( $this->is_native( $gateway_id ) ) {
			return $is_valid;
		}

		$request = $this->gateway_request_verifier->verify_request( self::SOURCE, $gateway_id, $this->get_setup_intent_payment_data() );

		// Stripe attaches this payment method to the setup intent it creates later in this request.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified by Stripe. The value is sanitized below.
		$payment_method_id = $_POST[ self::PAYMENT_METHOD_FIELD ] ?? null;
		$request->reuse_decision_for( is_string( $payment_method_id ) ? sanitize_text_field( wp_unslash( $payment_method_id ) ) : '' );

		if ( $request->is_blocked() ) {
			wp_send_json_error(
				array(
					'error' => array(
						'message' => $this->blocked_session_message->get_plaintext( MessageContext::Generic ),
					),
				)
			);
		}

		return $is_valid;
	}

	/**
	 * Read the payment method of the setup intent that an add-payment-method form post saves.
	 *
	 * Stripe saves the payment method of the setup intent that the form posts, which the
	 * browser can change after the setup intent request. The setup intent is read from
	 * Stripe, so the form post reuses the decision only when the payment method Stripe
	 * will save is the one that was verified. When the setup intent cannot be read, the
	 * form post is verified normally.
	 *
	 * @internal
	 *
	 * @param array  $payment_data Payment data of the form post.
	 * @param array  $request_data Request data of the form post.
	 * @param string $source       Verification source of the form post.
	 * @return string The payment method ID, or an empty string.
	 */
	public function read_setup_intent_payment_method( array $payment_data, array $request_data, string $source ): string {
		unset( $request_data );

		$setup_intent_id = $payment_data[ self::SETUP_INTENT_FIELD ] ?? null;
		if ( self::ADD_PAYMENT_METHOD_SOURCE !== $source || ! is_string( $setup_intent_id ) ) {
			return '';
		}

		try {
			return $this->get_setup_intent_payment_method_id( $setup_intent_id ) ?? '';
		} catch ( \Throwable $e ) {
			FraudProtectionController::log(
				'warning',
				'Reading the Stripe setup intent failed; add-payment-method request will verify',
				array(
					'event_source'      => $source,
					'exception_class'   => $e::class,
					'exception_message' => $e->getMessage(),
				)
			);

			return '';
		}
	}

	/**
	 * Enqueue the script that adds the session ID to Stripe's setup intent request.
	 *
	 * Runs after AddPaymentMethodProtector has enqueued the add-payment-method
	 * script, which this script depends on to find the session ID in the form.
	 * Stripe enqueues its own script later, on wp_footer, so the available
	 * gateways are checked instead.
	 *
	 * @internal
	 *
	 * @return void
	 */
	public function enqueue_add_payment_method_script(): void {
		if ( ! wp_script_is( self::ADD_PAYMENT_METHOD_SCRIPT_HANDLE, 'enqueued' ) || ! $this->has_available_stripe_gateway() || $this->is_native( self::STRIPE_GATEWAY_PREFIX ) ) {
			return;
		}

		wp_enqueue_script(
			'wc-fraud-protection-stripe-add-payment-method',
			plugins_url( 'assets/js/stripe-add-payment-method.js', WC_FRAUD_PROTECTION_PLUGIN_FILE ),
			array( self::ADD_PAYMENT_METHOD_SCRIPT_HANDLE, 'jquery' ),
			WC_FRAUD_PROTECTION_VERSION,
			array( 'in_footer' => true )
		);
	}

	/**
	 * Check whether a Stripe gateway is available for the current request.
	 *
	 * @return bool True when an available gateway ID starts with the Stripe prefix.
	 */
	private function has_available_stripe_gateway(): bool {
		if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
			return false;
		}

		foreach ( array_keys( WC()->payment_gateways()->get_available_payment_gateways() ) as $gateway_id ) {
			if ( str_starts_with( (string) $gateway_id, self::STRIPE_GATEWAY_PREFIX ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get the gateway ID of the Stripe AJAX request.
	 *
	 * The request carries the Stripe payment method type instead of the
	 * WooCommerce gateway ID, so the gateway ID is derived from it the way
	 * Stripe names its gateways: `stripe` for cards, `stripe_{type}` otherwise.
	 *
	 * @return string The gateway ID.
	 */
	private function get_setup_intent_gateway_id(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified by Stripe. The value is sanitized below.
		$payment_type = $_POST['wc-stripe-payment-type'] ?? null;
		$payment_type = is_string( $payment_type ) ? sanitize_key( wp_unslash( $payment_type ) ) : '';

		return '' === $payment_type || 'card' === $payment_type
			? self::STRIPE_GATEWAY_PREFIX
			: self::STRIPE_GATEWAY_PREFIX . '_' . $payment_type;
	}

	/**
	 * Get the payment data of the Stripe AJAX request.
	 *
	 * @return array Flat key-value map of the request's payment fields.
	 */
	private function get_setup_intent_payment_data(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by Stripe before this filter runs.
		$payment_data = ( new ClassicFormRequestData() )->build_request_data( $_POST )['payment_data'];

		return array_diff_key( $payment_data, array_flip( self::NON_PAYMENT_FIELDS ) );
	}
}
