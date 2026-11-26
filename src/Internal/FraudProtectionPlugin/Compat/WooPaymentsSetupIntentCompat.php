<?php
/**
 * WooPaymentsSetupIntentCompat class file.
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
 * Integrates Blackbox fraud protection into the WooPayments add-payment-method flow.
 *
 * On My Account > Add payment method, WooPayments creates and confirms the
 * setup intent through its `create_setup_intent` AJAX action before the form
 * is posted, so the card is already validated by the time
 * AddPaymentMethodProtector runs. This compat layer verifies that AJAX request
 * through {@see GatewayRequestVerifier}, as WooPayments itself would, after the
 * WooPayments nonce, fraud-prevention and rate-limit checks and before the
 * setup intent is created, and blocks it on BLOCK decisions.
 *
 * The decision is kept for the form post of the same attempt, with the payment
 * method that the setup intent request confirms as the reference. The form
 * post reuses it only when the setup intent it posts holds that payment
 * method, because the setup intent ID in the form can be changed in the
 * browser after the setup intent request.
 *
 * Stands aside once the gateway declares `fraud_protection_add_payment_method`.
 *
 * Fail-open: GatewayRequestVerifier handles all internal errors and returns ALLOW.
 */
class WooPaymentsSetupIntentCompat {

	use WooPaymentsSetupIntentReaderTrait;

	/** Setup-intent verification source. SessionVerifier caps sources at 32 characters. */
	public const SOURCE = 'wcpay_setup_intent_creation';

	/** Source of the add-payment-method form post that can reuse the decision. */
	private const ADD_PAYMENT_METHOD_SOURCE = 'add_payment_method';

	/** WooPayments card gateway ID, the only one that WooPayments offers on the add-payment-method page. */
	private const GATEWAY_ID = 'woocommerce_payments';

	/** WordPress action that runs the WooPayments setup intent AJAX request. */
	private const SETUP_INTENT_AJAX_ACTION = 'wp_ajax_create_setup_intent';

	/** The add-payment-method protector script, which adds the session ID to the form. */
	private const ADD_PAYMENT_METHOD_SCRIPT_HANDLE = 'wc-fraud-protection-add-payment-method';

	/** POST fields of the WooPayments AJAX request that are not payment data. */
	private const NON_PAYMENT_FIELDS = array( 'action', '_ajax_nonce' );

	/** POST field of the WooPayments AJAX request with the payment method ID. */
	private const PAYMENT_METHOD_FIELD = 'wcpay-payment-method';

	/**
	 * POST fields that WooPayments reads the payment method ID from, in the order it checks them.
	 *
	 * @see \WCPay\Payment_Information::get_payment_method_from_request()
	 */
	private const PAYMENT_METHOD_SOURCE_FIELDS = array( 'wcpay-confirmation-token', self::PAYMENT_METHOD_FIELD, 'wcpay-payment-method-sepa' );

	/** POST field of the add-payment-method form with the setup intent ID. */
	private const SETUP_INTENT_FIELD = 'wcpay-setup-intent';

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
	 * Check whether the WooPayments gateway verifies its own add-payment-method requests.
	 *
	 * @return bool
	 */
	private function is_native(): bool {
		return $this->native_support->declared( FraudProtectionFeature::ADD_PAYMENT_METHOD, self::GATEWAY_ID );
	}

	/**
	 * Register hooks for WooPayments setup intent fraud protection.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'wcpay_create_and_confirm_setup_intention_request', array( $this, 'verify_and_block_setup_intent' ) );
		add_action( 'woocommerce_add_payment_method_form_bottom', array( $this, 'enqueue_add_payment_method_script' ), 20, 0 );

		$this->gateway_request_verifier->register_compatibility_reference_reader(
			self::GATEWAY_ID,
			array( FraudProtectionFeature::ADD_PAYMENT_METHOD ),
			array( $this, 'read_setup_intent_payment_method' )
		);
	}

	/**
	 * Verify the session and block the WooPayments setup intent request if needed.
	 *
	 * Runs on `wcpay_create_and_confirm_setup_intention_request`, which
	 * WooPayments applies right before it sends the setup intent to its server.
	 * WooPayments also applies it when it saves a card for a zero-total order
	 * at checkout, where the checkout protectors verify instead, so only the
	 * add-payment-method AJAX request is handled. The payment method that the
	 * request confirms is verified, which can differ from the posted
	 * `wcpay-payment-method` field. On BLOCK it responds with the WooPayments
	 * error shape and terminates via wp_send_json_error(), so the decision is
	 * kept first.
	 *
	 * @internal
	 *
	 * @param mixed $request The WooPayments setup intent request.
	 * @return mixed The unchanged request.
	 */
	public function verify_and_block_setup_intent( $request ) {
		if ( ! doing_action( self::SETUP_INTENT_AJAX_ACTION ) || $this->is_native() ) {
			return $request;
		}

		$payment_method_id = $this->get_confirmed_payment_method_id( $request );

		$verified_request = $this->gateway_request_verifier->verify_request( self::SOURCE, self::GATEWAY_ID, $this->get_setup_intent_payment_data( $payment_method_id ) );
		$verified_request->reuse_decision_for( $payment_method_id );

		if ( $verified_request->is_blocked() ) {
			wp_send_json_error(
				array(
					'error' => array(
						'message' => $this->blocked_session_message->get_plaintext( MessageContext::Generic ),
					),
				)
			);
		}

		return $request;
	}

	/**
	 * Read the payment method of the setup intent that an add-payment-method form post saves.
	 *
	 * WooPayments saves the payment method of the setup intent that the form posts, which the
	 * browser can change after the setup intent request. The setup intent is read from
	 * WooPayments, so the form post reuses the decision only when the payment method
	 * WooPayments will save is the one that was verified. When the setup intent cannot be
	 * read, the form post is verified normally.
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
				'Reading the WooPayments setup intent failed; add-payment-method request will verify',
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
	 * Enqueue the script that adds the session ID to the WooPayments setup intent request.
	 *
	 * Runs after AddPaymentMethodProtector has enqueued the add-payment-method
	 * script, which this script depends on to find the session ID in the form.
	 *
	 * @internal
	 *
	 * @return void
	 */
	public function enqueue_add_payment_method_script(): void {
		if ( ! wp_script_is( self::ADD_PAYMENT_METHOD_SCRIPT_HANDLE, 'enqueued' ) || ! $this->has_available_woopayments_gateway() || $this->is_native() ) {
			return;
		}

		wp_enqueue_script(
			'wc-fraud-protection-woopayments-add-payment-method',
			plugins_url( 'assets/js/woopayments-add-payment-method.js', WC_FRAUD_PROTECTION_PLUGIN_FILE ),
			array( self::ADD_PAYMENT_METHOD_SCRIPT_HANDLE ),
			WC_FRAUD_PROTECTION_VERSION,
			array( 'in_footer' => true )
		);
	}

	/**
	 * Check whether the WooPayments card gateway is available for the current request.
	 *
	 * @return bool True when the WooPayments card gateway is available.
	 */
	private function has_available_woopayments_gateway(): bool {
		if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
			return false;
		}

		return array_key_exists( self::GATEWAY_ID, WC()->payment_gateways()->get_available_payment_gateways() );
	}

	/**
	 * Get the payment method ID that the WooPayments setup intent request confirms.
	 *
	 * Read from the filtered request, which holds the value WooPayments sends.
	 * When it cannot be read there, the POST fields are checked in the order
	 * WooPayments checks them.
	 *
	 * @param mixed $request The WooPayments setup intent request.
	 * @return string The payment method ID, or an empty string.
	 */
	private function get_confirmed_payment_method_id( $request ): string {
		$payment_method_id = null;
		try {
			if ( is_object( $request ) && method_exists( $request, 'get_param' ) ) {
				$payment_method_id = $request->get_param( 'payment_method' );
			}
		} catch ( \Throwable ) {
			$payment_method_id = null;
		}

		if ( is_string( $payment_method_id ) && '' !== $payment_method_id ) {
			return $payment_method_id;
		}

		foreach ( self::PAYMENT_METHOD_SOURCE_FIELDS as $field ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified by WooPayments. The value is sanitized below.
			$value = $_POST[ $field ] ?? null;
			if ( is_string( $value ) && '' !== $value ) {
				return sanitize_text_field( wp_unslash( $value ) );
			}
		}

		return '';
	}

	/**
	 * Get the payment data of the WooPayments AJAX request.
	 *
	 * The payment method that the request confirms replaces the posted
	 * `wcpay-payment-method` field, so that its details are resolved.
	 *
	 * @param string $payment_method_id The payment method ID that the request confirms; empty when unknown.
	 * @return array Flat key-value map of the request's payment fields.
	 */
	private function get_setup_intent_payment_data( string $payment_method_id ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by WooPayments before this filter runs.
		$payment_data = ( new ClassicFormRequestData() )->build_request_data( $_POST )['payment_data'];
		$payment_data = array_diff_key( $payment_data, array_flip( self::NON_PAYMENT_FIELDS ) );

		if ( '' !== $payment_method_id ) {
			$payment_data[ self::PAYMENT_METHOD_FIELD ] = $payment_method_id;
		}

		return $payment_data;
	}
}
