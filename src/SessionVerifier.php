<?php
/**
 * SessionVerifier class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\FraudProtection;

defined( 'ABSPATH' ) || exit;

/**
 * Encapsulates the resolve-payment → collect → verify → apply-decision flow.
 *
 * Each checkout/payment handler (blocks checkout, shortcode checkout,
 * add-payment-method, etc.) delegates to this class for the common
 * verification sequence, and only handles the context-specific response
 * to the returned decision (e.g. throw RouteException, add wc_notice).
 *
 * Fail-open: verify_session() never throws. All internal errors (payment
 * data resolution, API call, decision handler) result in an ALLOW decision.
 *
 * @internal
 */
class SessionVerifier {

	/**
	 * WC session key for caching verified session decisions.
	 *
	 * Blackbox rejects re-verification of the same session ID. Payment
	 * integrations should reset Blackbox between flows so each verify
	 * gets a fresh session ID. This cache is a safety net for edge cases
	 * where that reset doesn't happen (e.g. PayPal express: CreateOrder +
	 * checkout submit) — it prevents Blackbox rejection errors and ensures
	 * the original decision is preserved.
	 *
	 * Stores an associative array with session_id and decision keys —
	 * only the most recent session ID is cached.
	 */
	private const SESSION_CACHE_KEY = '_fraud_protection_verified_session';

	/**
	 * Session data collector instance.
	 *
	 * @var SessionDataCollector
	 */
	private SessionDataCollector $data_collector;

	/**
	 * API client instance.
	 *
	 * @var ApiClient
	 */
	private ApiClient $api_client;

	/**
	 * Decision handler instance.
	 *
	 * @var DecisionHandler
	 */
	private DecisionHandler $decision_handler;

	/**
	 * Payment data resolver instance.
	 *
	 * @var PaymentDataResolver
	 */
	private PaymentDataResolver $payment_data_resolver;

	/**
	 * Initialize with dependencies.
	 *
	 * @internal
	 *
	 * @param SessionDataCollector $data_collector        The session data collector instance.
	 * @param ApiClient            $api_client            The API client instance.
	 * @param DecisionHandler      $decision_handler      The decision handler instance.
	 * @param PaymentDataResolver  $payment_data_resolver The payment data resolver instance.
	 */
	final public function init(
		SessionDataCollector $data_collector,
		ApiClient $api_client,
		DecisionHandler $decision_handler,
		PaymentDataResolver $payment_data_resolver
	): void {
		$this->data_collector        = $data_collector;
		$this->api_client            = $api_client;
		$this->decision_handler      = $decision_handler;
		$this->payment_data_resolver = $payment_data_resolver;
	}

	/**
	 * Verify a session and return the final decision.
	 *
	 * Resolves payment data, collects session/order data, calls the Blackbox
	 * verify API, and applies the decision.
	 *
	 * Fail-open: Never throws. Returns ALLOW on any internal error.
	 *
	 * @param string $session_id   The Blackbox session ID from collect().
	 * @param string $source       Identifies the caller (e.g. 'blocks_checkout').
	 * @param int    $order_id     The WooCommerce order ID (0 for pre-order flows).
	 * @param array  $request_data Request data containing payment_method and payment_data.
	 * @return string The final decision: 'allow' or 'block'.
	 */
	public function verify_session( string $session_id, string $source, int $order_id = 0, array $request_data = array() ): string {
		// Return cached decision if this session_id was already verified.
		$cached = $this->get_cached_decision( $session_id );
		if ( null !== $cached ) {
			FraudProtectionController::log(
				'info',
				sprintf(
					'Returning cached decision "%s" for session %s (source: %s)',
					$cached,
					$session_id,
					$source
				)
			);
			return $cached;
		}

		// Resolve payment data (fail-open).
		$payment_data = null;
		try {
			$payment_data = $this->payment_data_resolver->resolve(
				$request_data['payment_method'] ?? '',
				$request_data['payment_data'] ?? array()
			);
		} catch ( \Throwable $e ) {
			FraudProtectionController::log(
				'warning',
				'Payment data resolution failed: ' . $e->getMessage(),
				array( 'exception' => $e )
			);
		}

		// Collect data, call API, apply decision (fail-open).
		try {
			$payload = $this->data_collector->get_collected_data( $order_id );

			$payload['source']       = $source;
			$payload['request_data'] = $request_data;
			$payload['payment']      = $payment_data ? $payment_data->to_array() : null;

			$decision = $this->api_client->verify( $session_id, $payload );
			$decision = $this->decision_handler->apply_decision( $decision, $payload );
		} catch ( \Throwable $e ) {
			FraudProtectionController::log(
				'error',
				'Session verification failed, allowing: ' . $e->getMessage(),
				array(
					'exception'  => $e,
					'source'     => $source,
					'session_id' => $session_id,
				)
			);
			$decision = ApiClient::DECISION_ALLOW;
		}

		// Cache both real and fail-open decisions. This is a safety net —
		// integrations should reset Blackbox between flows so each verify
		// gets a fresh session ID.
		$this->cache_decision( $session_id, $decision );

		return $decision;
	}

	/**
	 * Get a cached decision for a session ID from the WC session.
	 *
	 * @param string $session_id Blackbox session ID.
	 * @return string|null Cached decision, or null on miss / empty ID / no WC session.
	 */
	private function get_cached_decision( string $session_id ): ?string {
		if ( '' === $session_id ) {
			return null;
		}

		if ( ! function_exists( 'WC' ) || ! WC()->session instanceof \WC_Session ) {
			return null;
		}

		$cache = WC()->session->get( self::SESSION_CACHE_KEY, array() );

		if ( ! is_array( $cache )
			|| ! isset( $cache['session_id'], $cache['decision'] )
			|| $cache['session_id'] !== $session_id
		) {
			return null;
		}

		$value = (string) $cache['decision'];

		if ( ! in_array( $value, ApiClient::VALID_DECISIONS, true ) ) {
			FraudProtectionController::log(
				'warning',
				sprintf(
					'Discarding invalid cached decision "%s" for session %s',
					$value,
					$session_id
				)
			);
			return null;
		}

		return $value;
	}

	/**
	 * Cache a decision for a session ID in the WC session.
	 *
	 * @param string $session_id Blackbox session ID.
	 * @param string $decision   The decision to cache.
	 */
	private function cache_decision( string $session_id, string $decision ): void {
		if ( '' === $session_id ) {
			return;
		}

		if ( ! function_exists( 'WC' ) || ! WC()->session instanceof \WC_Session ) {
			return;
		}

		WC()->session->set(
			self::SESSION_CACHE_KEY,
			array(
				'session_id' => $session_id,
				'decision'   => $decision,
			)
		);
	}
}
