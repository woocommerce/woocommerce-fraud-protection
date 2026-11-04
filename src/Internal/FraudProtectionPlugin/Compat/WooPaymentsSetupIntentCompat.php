<?php
/**
 * WooPaymentsSetupIntentCompat class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\Compat;

use Automattic\WooCommerce\FraudProtection\BlockedSessionMessage;
use Automattic\WooCommerce\FraudProtection\MessageContext;
use Automattic\WooCommerce\FraudProtection\Schemas\FraudDecision;
use Automattic\WooCommerce\FraudProtection\SessionIdNormalizer;
use Automattic\WooCommerce\FraudProtection\SessionVerifier;
use Automattic\WooCommerce\FraudProtection\SuppliedDecision;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\ClassicFormDataExtractionTrait;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\FraudProtectionController;

defined( 'ABSPATH' ) || exit;

/**
 * Integrates Blackbox fraud protection into the WooPayments add-payment-method flow.
 *
 * On My Account > Add payment method, WooPayments creates and confirms the
 * setup intent through its `create_setup_intent` AJAX action before the form
 * is posted, so the card is already validated by the time
 * AddPaymentMethodProtector runs. This compat layer verifies the session in
 * that AJAX request, after the WooPayments nonce, fraud-prevention and
 * rate-limit checks and before the setup intent is created, and blocks it on
 * BLOCK decisions.
 *
 * The decision is kept for the form post of the same attempt, so that
 * AddPaymentMethodProtector applies it instead of verifying the session again.
 * An ALLOW is not applied when the posted setup intent holds another payment
 * method than the verified one, because the setup intent ID in the form can
 * be changed in the browser after the setup intent request.
 *
 * Fail-open: SessionVerifier handles all internal errors and returns ALLOW.
 */
class WooPaymentsSetupIntentCompat {

	use ClassicFormDataExtractionTrait;
	use WooPaymentsSetupIntentReaderTrait;

	/** Setup-intent verification source. SessionVerifier caps sources at 32 characters. */
	public const SOURCE = 'wcpay_setup_intent_creation';

	/** Source of the add-payment-method form post that can reuse the decision. */
	private const ADD_PAYMENT_METHOD_SOURCE = 'add_payment_method';

	/** WooPayments card gateway ID, the only one that WooPayments offers on the add-payment-method page. */
	private const GATEWAY_ID = 'woocommerce_payments';

	/** WordPress action that runs the WooPayments setup intent AJAX request. */
	private const SETUP_INTENT_AJAX_ACTION = 'wp_ajax_create_setup_intent';

	/** WooCommerce session record key. */
	private const VERIFICATION_RECORD_KEY = '_fraud_protection_woopayments_setup_intent_verification';

	/** The add-payment-method protector script, which adds the session ID to the form. */
	private const ADD_PAYMENT_METHOD_SCRIPT_HANDLE = 'wc-fraud-protection-add-payment-method';

	/** POST fields of the WooPayments AJAX request that are not payment data. */
	private const NON_PAYMENT_FIELDS = array( 'action', '_ajax_nonce' );

	/** POST field of the WooPayments AJAX request with the payment method ID. */
	private const PAYMENT_METHOD_FIELD = 'wcpay-payment-method';

	/** POST field of the add-payment-method form with the setup intent ID. */
	private const SETUP_INTENT_FIELD = 'wcpay-setup-intent';

	/**
	 * Session verifier instance.
	 *
	 * @var SessionVerifier
	 */
	private SessionVerifier $session_verifier;

	/**
	 * Blocked-session message generator.
	 *
	 * @var BlockedSessionMessage
	 */
	private BlockedSessionMessage $blocked_session_message;

	/**
	 * Session ID normalizer.
	 *
	 * @var SessionIdNormalizer
	 */
	private SessionIdNormalizer $session_id_normalizer;

	/**
	 * Initialize with dependencies.
	 *
	 * @internal
	 *
	 * @param SessionVerifier       $session_verifier        The session verifier instance.
	 * @param BlockedSessionMessage $blocked_session_message The blocked-session message generator.
	 * @param SessionIdNormalizer   $session_id_normalizer   The session ID normalizer.
	 */
	final public function init(
		SessionVerifier $session_verifier,
		BlockedSessionMessage $blocked_session_message,
		SessionIdNormalizer $session_id_normalizer
	): void {
		$this->session_verifier        = $session_verifier;
		$this->blocked_session_message = $blocked_session_message;
		$this->session_id_normalizer   = $session_id_normalizer;
	}

	/**
	 * Register hooks for WooPayments setup intent fraud protection.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'wcpay_create_and_confirm_setup_intention_request', array( $this, 'verify_and_block_setup_intent' ) );
		add_filter( 'woocommerce_fraud_protection_skip_session_verify', array( $this, 'supply_decision_for_add_payment_method' ), 10, 4 );
		add_action( 'woocommerce_add_payment_method_form_bottom', array( $this, 'enqueue_add_payment_method_script' ), 20, 0 );
	}

	/**
	 * Verify the session and block the WooPayments setup intent request if needed.
	 *
	 * Runs on `wcpay_create_and_confirm_setup_intention_request`, which
	 * WooPayments applies right before it sends the setup intent to its server.
	 * WooPayments also applies it when it saves a card for a zero-total order
	 * at checkout, where the checkout protectors verify instead, so only the
	 * add-payment-method AJAX request is handled. On BLOCK it responds with the
	 * WooPayments error shape and terminates via wp_send_json_error(), so the
	 * record is written first.
	 *
	 * @internal
	 *
	 * @param mixed $request The WooPayments setup intent request.
	 * @return mixed The unchanged request.
	 */
	public function verify_and_block_setup_intent( $request ) {
		if ( ! doing_action( self::SETUP_INTENT_AJAX_ACTION ) ) {
			return $request;
		}

		$submitted_session_id = $this->get_submitted_session_id();

		$decision = $this->session_verifier->verify_session(
			$submitted_session_id,
			self::SOURCE,
			0,
			$this->build_setup_intent_request_data()
		);

		$this->record_verification( $submitted_session_id, $decision );

		if ( FraudDecision::Block === $decision ) {
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
	 * Apply the setup intent decision to the add-payment-method form post of the same attempt.
	 *
	 * The record is single-use: it is retired on every add-payment-method
	 * verification for the WooPayments gateway, whether or not it matches.
	 * An ALLOW is not applied when the posted setup intent holds another
	 * payment method than the verified one; the request then verifies normally.
	 *
	 * @internal
	 *
	 * @param SuppliedDecision|false $supplied_decision The filter's default (false), or what an earlier consumer returned.
	 * @param string                 $source            Source identifier.
	 * @param array                  $request_data      Request data with payment_method, payment_data, etc.
	 * @param string                 $session_id        The Blackbox session ID being verified.
	 * @return SuppliedDecision|false The supplied result, or the value passed in to defer.
	 */
	public function supply_decision_for_add_payment_method( SuppliedDecision|false $supplied_decision, string $source, array $request_data, string $session_id ): SuppliedDecision|false {
		if ( self::ADD_PAYMENT_METHOD_SOURCE !== $source ) {
			return $supplied_decision;
		}

		if ( self::GATEWAY_ID !== ( $request_data['payment_method'] ?? null ) ) {
			return $supplied_decision;
		}

		try {
			$record = $this->get_verification_record();
			$this->retire_verification_record();

			if ( null === $record || '' === $session_id || $record['session_id'] !== $session_id || get_current_user_id() !== $record['user_id'] ) {
				return $supplied_decision;
			}

			if (
				FraudDecision::Allow === $record['decision']
				&& $this->setup_intent_has_other_payment_method( $request_data, $record['payment_method_id'] )
			) {
				FraudProtectionController::log(
					'info',
					'WooPayments setup intent holds another payment method than the verified one; add-payment-method request will verify',
					array( 'event_source' => $source )
				);

				return $supplied_decision;
			}

			return new SuppliedDecision( $record['decision'] );
		} catch ( \Throwable $e ) {
			$this->retire_verification_record();
			FraudProtectionController::log(
				'warning',
				'Reading the WooPayments setup intent verification record failed; add-payment-method request will verify',
				array(
					'event_source'      => $source,
					'exception_class'   => $e::class,
					'exception_message' => $e->getMessage(),
				),
				true
			);

			return $supplied_decision;
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
		if ( ! wp_script_is( self::ADD_PAYMENT_METHOD_SCRIPT_HANDLE, 'enqueued' ) || ! $this->has_available_woopayments_gateway() ) {
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
	 * Check whether the posted setup intent holds a payment method other than the verified one.
	 *
	 * WooPayments saves the payment method of the setup intent that the form
	 * posts, which the browser can change after the setup intent request.
	 * Only a setup intent that was read and holds another payment method
	 * counts: when it cannot be read, WooPayments cannot save its payment
	 * method either, or the failure is not under the shopper's control.
	 *
	 * @param array  $request_data      Request data of the add-payment-method form post.
	 * @param string $payment_method_id The payment method ID that was verified.
	 * @return bool True when the setup intent holds another payment method.
	 */
	private function setup_intent_has_other_payment_method( array $request_data, string $payment_method_id ): bool {
		$setup_intent_id = $request_data['payment_data'][ self::SETUP_INTENT_FIELD ] ?? null;
		if ( ! is_string( $setup_intent_id ) ) {
			return false;
		}

		try {
			$saved_payment_method = $this->get_setup_intent_payment_method_id( $setup_intent_id );
		} catch ( \Throwable $e ) {
			FraudProtectionController::log(
				'warning',
				'Reading the WooPayments setup intent failed; keeping the setup intent decision',
				array(
					'event_source'      => self::ADD_PAYMENT_METHOD_SOURCE,
					'exception_class'   => $e::class,
					'exception_message' => $e->getMessage(),
				)
			);

			return false;
		}

		return null !== $saved_payment_method && $payment_method_id !== $saved_payment_method;
	}

	/**
	 * Build the verification request data from the WooPayments AJAX request.
	 *
	 * The request does not carry the gateway ID. WooPayments only sends it from
	 * the card gateway, so that gateway ID is used.
	 *
	 * @return array Request data.
	 */
	private function build_setup_intent_request_data(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by WooPayments before this filter runs.
		$request_data = $this->build_request_data( $_POST );

		$request_data['payment_method'] = self::GATEWAY_ID;

		foreach ( self::NON_PAYMENT_FIELDS as $field ) {
			unset( $request_data['payment_data'][ $field ] );
		}

		return $request_data;
	}

	/**
	 * Store the decision for the form post of the same attempt.
	 *
	 * Any earlier record is replaced. Nothing is stored when the request had no
	 * session ID, because the form post would not have one to match.
	 * The payment method ID of the request is kept so that an ALLOW is not
	 * applied to a setup intent that holds another payment method.
	 *
	 * @param mixed         $submitted_session_id The session ID submitted with the AJAX request.
	 * @param FraudDecision $decision             The applied decision.
	 * @return void
	 */
	private function record_verification( $submitted_session_id, FraudDecision $decision ): void {
		try {
			if ( ! function_exists( 'WC' ) || ! WC()->session ) {
				return;
			}

			$session_id = $this->session_verifier->last_verified_session_id();
			$record     = null;
			if (
				'' !== $this->session_id_normalizer->normalize_stored( $submitted_session_id )
				&& '' !== $session_id
				&& in_array( $decision, FraudDecision::ACTIONABLE, true )
			) {
				// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified by WooPayments. The value is sanitized below.
				$payment_method_id = $_POST[ self::PAYMENT_METHOD_FIELD ] ?? null;

				$record = array(
					'session_id'        => $session_id,
					'decision'          => $decision,
					'user_id'           => get_current_user_id(),
					'payment_method_id' => is_string( $payment_method_id ) ? sanitize_text_field( wp_unslash( $payment_method_id ) ) : '',
				);
			}

			WC()->session->set( self::VERIFICATION_RECORD_KEY, $record );
		} catch ( \Throwable $e ) {
			$this->retire_verification_record();
			FraudProtectionController::log(
				'warning',
				'Recording the WooPayments setup intent verification failed; add-payment-method request will verify',
				array(
					'event_source'      => self::SOURCE,
					'exception_class'   => $e::class,
					'exception_message' => $e->getMessage(),
				),
				true
			);
		}
	}

	/**
	 * Read the current verification record from the WC session.
	 *
	 * @return ?array{session_id: string, decision: FraudDecision, user_id: int, payment_method_id: string} The record, or null.
	 */
	private function get_verification_record(): ?array {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return null;
		}

		$stored = WC()->session->get( self::VERIFICATION_RECORD_KEY );
		if ( ! is_array( $stored ) ) {
			return null;
		}

		$session_id = $this->session_id_normalizer->normalize_stored( $stored['session_id'] ?? null );
		$decision   = $stored['decision'] ?? null;
		$user_id    = $stored['user_id'] ?? null;

		$payment_method_id = $stored['payment_method_id'] ?? null;
		if ( ! is_string( $payment_method_id ) ) {
			$payment_method_id = '';
		}

		if (
			'' === $session_id
			|| ! $decision instanceof FraudDecision
			|| ! in_array( $decision, FraudDecision::ACTIONABLE, true )
			|| ! is_int( $user_id )
			|| $user_id <= 0
		) {
			return null;
		}

		return array(
			'session_id'        => $session_id,
			'decision'          => $decision,
			'user_id'           => $user_id,
			'payment_method_id' => $payment_method_id,
		);
	}

	/**
	 * Retire the current verification record without affecting the request.
	 *
	 * @return void
	 */
	private function retire_verification_record(): void {
		try {
			if ( function_exists( 'WC' ) && WC()->session ) {
				WC()->session->set( self::VERIFICATION_RECORD_KEY, null );
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}
}
