<?php
/**
 * StripeSetupIntentCompat class file.
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
 * Integrates Blackbox fraud protection into the Stripe add-payment-method flow.
 *
 * On My Account > Add payment method, Stripe creates and confirms the setup
 * intent through its `wc_stripe_create_and_confirm_setup_intent` AJAX action
 * before the form is posted, so the card is already validated by the time
 * AddPaymentMethodProtector runs. This compat layer verifies the session in
 * that AJAX request, after Stripe's own nonce and rate-limit checks and before
 * the setup intent is created, and blocks it on BLOCK decisions.
 *
 * The decision is kept for the form post of the same attempt, so that
 * AddPaymentMethodProtector applies it instead of verifying the session again.
 * An ALLOW is not applied when the posted setup intent holds another payment
 * method than the verified one, because the payment method of a setup intent
 * can be changed in the browser after the setup intent request.
 *
 * Fail-open: SessionVerifier handles all internal errors and returns ALLOW.
 */
class StripeSetupIntentCompat {

	use ClassicFormDataExtractionTrait;
	use StripeSetupIntentReaderTrait;

	/** Setup-intent verification source. */
	public const SOURCE = 'stripe_setup_intent_creation';

	/** Source of the add-payment-method form post that can reuse the decision. */
	private const ADD_PAYMENT_METHOD_SOURCE = 'add_payment_method';

	/** Stripe gateway ID prefix. */
	private const STRIPE_GATEWAY_PREFIX = 'stripe';

	/** WooCommerce session record key. */
	private const VERIFICATION_RECORD_KEY = '_fraud_protection_stripe_setup_intent_verification';

	/** The add-payment-method protector script, which adds the session ID to the form. */
	private const ADD_PAYMENT_METHOD_SCRIPT_HANDLE = 'wc-fraud-protection-add-payment-method';

	/** POST fields of the Stripe AJAX request that are not payment data. */
	private const NON_PAYMENT_FIELDS = array( 'action', '_ajax_nonce' );

	/** POST field of the Stripe AJAX request with the payment method ID. */
	private const PAYMENT_METHOD_FIELD = 'wc-stripe-payment-method';

	/** POST field of the add-payment-method form with the setup intent ID. */
	private const SETUP_INTENT_FIELD = 'wc-stripe-setup-intent';

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
	 * Register hooks for Stripe setup intent fraud protection.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'wc_stripe_is_valid_create_and_confirm_setup_intent_captcha', array( $this, 'verify_and_block_setup_intent' ) );
		add_filter( 'woocommerce_fraud_protection_skip_session_verify', array( $this, 'supply_decision_for_add_payment_method' ), 10, 4 );
		add_action( 'woocommerce_add_payment_method_form_bottom', array( $this, 'enqueue_add_payment_method_script' ), 20, 0 );
	}

	/**
	 * Verify the session and block the Stripe setup intent request if needed.
	 *
	 * Runs on `wc_stripe_is_valid_create_and_confirm_setup_intent_captcha`, which
	 * Stripe applies after its nonce and rate-limit checks and before it creates
	 * the setup intent. On BLOCK it responds with Stripe's error shape and
	 * terminates via wp_send_json_error(), so the record is written first.
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

		return $is_valid;
	}

	/**
	 * Apply the setup intent decision to the add-payment-method form post of the same attempt.
	 *
	 * The record is single-use: it is retired on every add-payment-method
	 * verification for a Stripe gateway, whether or not it matches.
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

		$payment_method = is_string( $request_data['payment_method'] ?? null ) ? $request_data['payment_method'] : '';
		if ( ! str_starts_with( $payment_method, self::STRIPE_GATEWAY_PREFIX ) ) {
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
					'Stripe setup intent holds another payment method than the verified one; add-payment-method request will verify',
					array( 'event_source' => $source )
				);

				return $supplied_decision;
			}

			return new SuppliedDecision( $record['decision'] );
		} catch ( \Throwable $e ) {
			$this->retire_verification_record();
			FraudProtectionController::log(
				'warning',
				'Reading the Stripe setup intent verification record failed; add-payment-method request will verify',
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
		if ( ! wp_script_is( self::ADD_PAYMENT_METHOD_SCRIPT_HANDLE, 'enqueued' ) || ! $this->has_available_stripe_gateway() ) {
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
	 * Check whether the posted setup intent holds a payment method other than the verified one.
	 *
	 * Stripe saves the payment method of the setup intent that the form posts,
	 * which the browser can change after the setup intent request. Only a
	 * setup intent that was read and holds another payment method counts:
	 * when it cannot be read, Stripe cannot save its payment method either,
	 * or the failure is not under the shopper's control.
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
				'Reading the Stripe setup intent failed; keeping the setup intent decision',
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
	 * Build the verification request data from the Stripe AJAX request.
	 *
	 * The request carries the Stripe payment method type instead of the
	 * WooCommerce gateway ID, so the gateway ID is derived from it the way
	 * Stripe names its gateways: `stripe` for cards, `stripe_{type}` otherwise.
	 *
	 * @return array Request data.
	 */
	private function build_setup_intent_request_data(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by Stripe before this filter runs.
		$request_data = $this->build_request_data( $_POST );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified by Stripe. The value is sanitized below.
		$payment_type = $_POST['wc-stripe-payment-type'] ?? null;
		$payment_type = is_string( $payment_type ) ? sanitize_key( wp_unslash( $payment_type ) ) : '';

		$request_data['payment_method'] = '' === $payment_type || 'card' === $payment_type
			? self::STRIPE_GATEWAY_PREFIX
			: self::STRIPE_GATEWAY_PREFIX . '_' . $payment_type;

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
				// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified by Stripe. The value is sanitized below.
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
				'Recording the Stripe setup intent verification failed; add-payment-method request will verify',
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
