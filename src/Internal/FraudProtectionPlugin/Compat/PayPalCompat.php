<?php
/**
 * PayPalCompat class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\Compat;

use Automattic\WooCommerce\FraudProtection\BlockedSessionMessage;
use Automattic\WooCommerce\FraudProtection\MessageContext;
use Automattic\WooCommerce\FraudProtection\Schemas\FraudDecision;
use Automattic\WooCommerce\FraudProtection\SessionVerifier;
use Automattic\WooCommerce\FraudProtection\SessionIdNormalizer;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\FraudProtectionController;
use WooCommerce\PayPalCommerce\Button\Endpoint\RequestData;
use WooCommerce\PayPalCommerce\PPCP;

defined( 'ABSPATH' ) || exit;

/**
 * Integrates Blackbox fraud protection into PayPal Payments express checkout flows.
 *
 * Verifies protected PayPal requests and blocks their transport when required.
 */
class PayPalCompat {

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
	 * PayPal decision reuse service.
	 *
	 * @var PayPalDecisionReuse
	 */
	private PayPalDecisionReuse $decision_reuse;

	/**
	 * The session ID this request's order verification recorded, if any.
	 *
	 * @var string
	 */
	private string $session_recorded_this_request = '';

	/**
	 * The origin of this request's order verification.
	 *
	 * @var string
	 */
	private string $origin_recorded_this_request = '';

	/**
	 * Initialize with dependencies.
	 *
	 * @internal
	 *
	 * @param SessionVerifier       $session_verifier        The session verifier instance.
	 * @param BlockedSessionMessage $blocked_session_message The blocked-session message generator.
	 * @param SessionIdNormalizer   $session_id_normalizer    The session ID normalizer.
	 * @param PayPalDecisionReuse   $decision_reuse           The PayPal decision reuse service.
	 */
	final public function init(
		SessionVerifier $session_verifier,
		BlockedSessionMessage $blocked_session_message,
		SessionIdNormalizer $session_id_normalizer,
		PayPalDecisionReuse $decision_reuse
	): void {
		$this->session_verifier        = $session_verifier;
		$this->blocked_session_message = $blocked_session_message;
		$this->session_id_normalizer   = $session_id_normalizer;
		$this->decision_reuse          = $decision_reuse;
	}

	/**
	 * Register hooks for PayPal express fraud protection.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'woocommerce_paypal_payments_create_order_request_started', array( $this, 'verify_and_block_create_order' ) );
		add_action( 'woocommerce_paypal_payments_paypal_order_created', array( $this, 'associate_created_order_with_verification' ) );
		add_filter( 'ppcp_request_args', array( $this, 'verify_protected_paypal_request' ), 10, 2 );
	}

	/**
	 * Verify the session and block the PayPal CreateOrder request if needed.
	 *
	 * Runs on `woocommerce_paypal_payments_create_order_request_started`. On
	 * BLOCK it responds and terminates via wp_send_json_error(), so the record is
	 * written first: the blocked attempt is the one whose verdict must outlive
	 * its request.
	 *
	 * @internal
	 *
	 * @param mixed $data The CreateOrder request data from PayPal Payments.
	 * @return void
	 */
	public function verify_and_block_create_order( $data ): void {
		$request_data         = is_array( $data ) ? $data : array();
		$submitted_session_id = $request_data[ SessionVerifier::SESSION_ID_FIELD ] ?? '';
		$can_store_result     = '' !== $this->session_id_normalizer->normalize_stored( $submitted_session_id );

		$this->verify_and_block_paypal_request( $request_data, PayPalDecisionReuse::ORDER_CREATION_SOURCE, $can_store_result );
	}

	/**
	 * Verify matching setup-token and vault-order transport requests.
	 *
	 * @internal
	 *
	 * @param mixed $args HTTP request arguments.
	 * @param mixed $url  PayPal request URL.
	 * @return mixed The unchanged HTTP request arguments.
	 */
	public function verify_protected_paypal_request( $args, $url ) {
		if ( ! is_array( $args ) || ! is_string( $url ) ) {
			return $args;
		}
		$origin = $this->protected_request_origin( $args, $url );
		if ( '' === $origin ) {
			return $args;
		}

		$data             = array();
		$can_store_result = false;
		try {
			$data                 = $this->read_protected_request_data( $origin );
			$submitted_session_id = $data[ SessionVerifier::SESSION_ID_FIELD ] ?? '';
			$can_store_result     = '' !== $this->session_id_normalizer->normalize_stored( $submitted_session_id );
		} catch ( \Throwable $e ) {
			FraudProtectionController::log(
				'warning',
				'Reading protected PayPal request data failed; verifying without a browser session',
				array(
					'hook'              => 'ppcp_request_args',
					'exception_class'   => $e::class,
					'exception_message' => $e->getMessage(),
				),
				true
			);
		}

		$this->verify_and_block_paypal_request( $data, $origin, $can_store_result );

		return $args;
	}

	/**
	 * Read one protected request through PayPal Payments.
	 *
	 * @param string $origin Verification source.
	 * @return array Validated request data.
	 * @throws \RuntimeException When PayPal request data is unavailable.
	 * @throws \UnexpectedValueException When the request-data service is incompatible.
	 */
	private function read_protected_request_data( string $origin ): array {
		if ( ! class_exists( PPCP::class ) || ! class_exists( RequestData::class ) ) {
			throw new \RuntimeException( 'PayPal request data is unavailable.' );
		}

		$endpoints = array(
			PayPalDecisionReuse::SETUP_TOKEN_CREATION_SOURCE => 'ppc-create-setup-token',
			PayPalDecisionReuse::VAULT_ORDER_CREATION_SOURCE => 'ppc-vault-create-order',
		);
		if ( ! isset( $endpoints[ $origin ] ) ) {
			throw new \UnexpectedValueException( 'Unsupported PayPal request origin.' );
		}

		$request_data = PPCP::container()->get( 'button.request-data' );
		if ( ! $request_data instanceof RequestData ) {
			throw new \UnexpectedValueException( 'PayPal request data is incompatible.' );
		}

		return $request_data->read_request( $endpoints[ $origin ] );
	}

	/**
	 * Associate the PayPal order just created with this request's verification.
	 *
	 * @internal
	 *
	 * @param mixed $order PayPal order entity.
	 * @return void
	 */
	public function associate_created_order_with_verification( $order ): void {
		$session_id = $this->session_recorded_this_request;
		$origin     = $this->origin_recorded_this_request;

		$this->session_recorded_this_request = '';
		$this->origin_recorded_this_request  = '';

		$this->decision_reuse->associate_created_order( $order, $session_id, $origin );
	}

	/**
	 * Verify one protected PayPal request and enforce its decision.
	 *
	 * @param array  $data           Validated request data.
	 * @param string $origin         Verification source.
	 * @param bool   $can_store_result Whether the submitted session ID can identify this response.
	 */
	private function verify_and_block_paypal_request( array $data, string $origin, bool $can_store_result ): void {
		$submitted_session_id = array_key_exists( SessionVerifier::SESSION_ID_FIELD, $data ) ? $data[ SessionVerifier::SESSION_ID_FIELD ] : '';
		$decision             = $this->session_verifier->verify_session( $submitted_session_id, $origin, 0, $data );
		$resolved_session_id  = $this->session_verifier->last_verified_session_id();
		$record_stored        = false;

		try {
			$record_stored = $this->decision_reuse->record_verification( $origin, $resolved_session_id, $decision, $can_store_result );
		} catch ( \Throwable $e ) {
			$this->decision_reuse->retire_verification_record();
			$context = array(
				'event_source'      => $origin,
				'exception_class'   => $e::class,
				'exception_message' => $e->getMessage(),
			);
			if ( '' !== $resolved_session_id ) {
				$context['session_id'] = $resolved_session_id;
			}
			FraudProtectionController::log(
				'warning',
				'Recording the PayPal request verification failed; final request will verify',
				$context,
				true
			);
		}

		if ( FraudDecision::Block === $decision ) {
			wp_send_json_error(
				array( 'message' => $this->blocked_session_message->get_plaintext( MessageContext::Purchase ) ),
				403
			);
		}

		if ( $record_stored && in_array( $origin, array( PayPalDecisionReuse::ORDER_CREATION_SOURCE, PayPalDecisionReuse::VAULT_ORDER_CREATION_SOURCE ), true ) ) {
			$this->session_recorded_this_request = $resolved_session_id;
			$this->origin_recorded_this_request  = $origin;
		}
	}

	/**
	 * Resolve a supported protected request from its active action and target.
	 *
	 * @param array  $args HTTP request arguments.
	 * @param string $url  PayPal request URL.
	 * @return string Verification source, or an empty string.
	 */
	private function protected_request_origin( array $args, string $url ): string {
		if ( 'POST' !== ( $args['method'] ?? null ) ) {
			return '';
		}

		$path = wp_parse_url( $url, PHP_URL_PATH );
		$path = is_string( $path ) ? '/' . ltrim( untrailingslashit( $path ), '/' ) : '';

		if ( doing_action( 'wc_ajax_ppc-create-setup-token' ) && '/v3/vault/setup-tokens' === $path ) {
			return PayPalDecisionReuse::SETUP_TOKEN_CREATION_SOURCE;
		}

		if ( doing_action( 'wc_ajax_ppc-vault-create-order' ) && '/v2/checkout/orders' === $path ) {
			return PayPalDecisionReuse::VAULT_ORDER_CREATION_SOURCE;
		}

		return '';
	}
}
