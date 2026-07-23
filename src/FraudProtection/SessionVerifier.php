<?php
/**
 * SessionVerifier class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\FraudProtection;

use Automattic\WooCommerce\FraudProtection\Schemas\FraudDecision;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\ApiClient;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\DecisionHandler;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\FraudProtectionController;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\PaymentDataResolver;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Schemas\SessionTrigger;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Sessions\SessionDataCollector;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Sessions\SessionEventRecorder;

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
 */
class SessionVerifier {

	/**
	 * Order meta key for storing the Blackbox session ID.
	 *
	 * Persisted so the report integration can correlate order outcomes
	 * (e.g. chargebacks, refunds) back to the original fraud-check session.
	 */
	public const ORDER_BLACKBOX_SESSION_ID_KEY = '_wc_fraud_protection_session_id';

	/**
	 * Name of the request field carrying the Blackbox session ID.
	 *
	 * The Blackbox JS writes the acquired session ID into a form field / request
	 * parameter of this name; server-side flows read it back from the request
	 * payload before calling {@see verify_session()}. Exposed so gateway compat
	 * layers can read the session ID from their own request payloads.
	 */
	public const SESSION_ID_FIELD = 'wc_fraud_protection_session_id';

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
	 * Register hooks for deferred session ID persistence.
	 *
	 * In shortcode checkout the order does not exist at verification time
	 * (order_id = 0). This hook copies the session ID from the WC session
	 * to order meta once the order is created.
	 */
	public function register(): void {
		add_action( 'woocommerce_checkout_order_created', array( $this, 'persist_session_id_to_order' ) );
	}

	/**
	 * Copy the Blackbox session ID from the WC session to order meta.
	 *
	 * Hooked to `woocommerce_checkout_order_created` for flows where the
	 * order did not exist at verification time.
	 *
	 * @internal
	 *
	 * @param \WC_Order $order The newly created order.
	 */
	public function persist_session_id_to_order( \WC_Order $order ): void {
		$session_id = $this->get_session_id_from_session();
		if ( '' === $session_id ) {
			return;
		}

		$order->update_meta_data( self::ORDER_BLACKBOX_SESSION_ID_KEY, $session_id );
		$order->save_meta_data();

		FraudProtectionController::log(
			'info',
			sprintf(
				'Persisted session ID to order meta (deferred): order=%d',
				$order->get_id()
			)
		);
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
	 * @return FraudDecision The final decision: Allow or Block.
	 */
	public function verify_session( string $session_id, string $source, int $order_id = 0, array $request_data = array() ): FraudDecision {
		try {
			/**
			 * Filters whether to skip session verification.
			 *
			 * Extensions that handle their own verification (e.g. PayPal express
			 * checkout via PayPalCompat) can return true to skip the redundant
			 * verify call from standard protectors.
			 *
			 * Fail-open: truthy values skip verification (session is allowed).
			 *
			 * @since 0.1.0
			 *
			 * @param bool   $skip         Whether to skip verification. Default false.
			 * @param string $source       Source identifier (e.g. 'blocks_checkout').
			 * @param array  $request_data Request data with payment_method, payment_data, etc.
			 * @param string $session_id   The Blackbox session ID being verified.
			 */
			$skip = (bool) apply_filters( 'woocommerce_fraud_protection_skip_session_verify', false, $source, $request_data, $session_id );

			if ( $skip ) {
				FraudProtectionController::log(
					'info',
					sprintf( 'Session verification skipped by `woocommerce_fraud_protection_skip_session_verify` filter for source: %s', $source )
				);
				return FraudDecision::Allow;
			}
		} catch ( \Throwable $e ) {
			FraudProtectionController::log(
				'warning',
				'`woocommerce_fraud_protection_skip_session_verify` filter threw',
				array(
					'event_source'      => $source,
					'session_id'        => $session_id,
					'filter'            => 'woocommerce_fraud_protection_skip_session_verify',
					'exception'         => $e,
					'exception_class'   => $e::class,
					'exception_message' => $e->getMessage(),
					'exception_file'    => $e->getFile(),
					'exception_line'    => $e->getLine(),
				),
				true
			);
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
				'Payment data resolution failed',
				array(
					'event_source'      => $source,
					'session_id'        => $session_id,
					'order_id'          => $order_id,
					'payment_type'      => $request_data['payment_method'] ?? '',
					'hook'              => 'payment_data_resolution',
					'exception'         => $e,
					'exception_class'   => $e::class,
					'exception_message' => $e->getMessage(),
					'exception_file'    => $e->getFile(),
					'exception_line'    => $e->getLine(),
					'verify_context'    => array(
						'source'       => $source,
						'session_id'   => $session_id,
						'order_id'     => $order_id,
						'request_data' => $request_data,
					),
				),
				true
			);
		}

		// Collect data, call API, apply decision (fail-open).
		try {
			$payload = $this->data_collector->get_collected_data( $order_id );

			$payload['source']  = $source;
			$payload['payment'] = $payment_data?->to_array();

			$result = $this->api_client->verify( $session_id, $payload );

			// No collect session: persist the ID Blackbox generated so /report can attach the outcome.
			$effective_session_id = '' === $session_id ? $result->session_id : $session_id;

			// Bundle per-verify data for the session event recorder (added after the
			// API call, so it is never sent to Blackbox).
			$payload[ SessionEventRecorder::VERIFY_RESULT_KEY ] = array(
				'session_id'     => $effective_session_id,
				'risk_score'     => $result->risk_score,
				'payment_method' => (string) ( $request_data['payment_method'] ?? '' ),
			);

			// A fail-open verify produced no real verdict: record it under the
			// verify_error trigger so the synthetic allow stays distinguishable
			// from a genuine Blackbox allow in the sessions log.
			$trigger = $result->fail_open ? SessionTrigger::VerifyError : SessionTrigger::Blackbox;

			$decision = $this->decision_handler->apply_decision( $result->decision, $payload, $trigger );

			$this->persist_session_id( $effective_session_id, $order_id );
		} catch ( \Throwable $e ) {
			FraudProtectionController::log(
				'error',
				'Session verification failed, allowing',
				array(
					'event_source'      => $source,
					'session_id'        => $session_id,
					'order_id'          => $order_id,
					'hook'              => 'session_verify',
					'exception'         => $e,
					'exception_class'   => $e::class,
					'exception_message' => $e->getMessage(),
					'exception_file'    => $e->getFile(),
					'exception_line'    => $e->getLine(),
					'verify_context'    => array(
						'source'       => $source,
						'session_id'   => $session_id,
						'order_id'     => $order_id,
						'request_data' => $request_data,
					),
				),
				true
			);
			return FraudDecision::Allow;
		}

		return $decision;
	}

	/**
	 * Persist the Blackbox session ID to order meta and WC session.
	 *
	 * Saves to order meta when an order already exists (blocks checkout,
	 * pay-for-order). Always saves to WC session so the deferred hook
	 * can pick it up for shortcode checkout.
	 *
	 * @param string $session_id The Blackbox session ID.
	 * @param int    $order_id   The WooCommerce order ID (0 for pre-order flows).
	 */
	private function persist_session_id( string $session_id, int $order_id ): void {
		$this->store_session_id_in_session( $session_id );

		if ( $order_id > 0 ) {
			$order = wc_get_order( $order_id );
			if ( $order instanceof \WC_Order ) {
				$order->update_meta_data( self::ORDER_BLACKBOX_SESSION_ID_KEY, $session_id );
				$order->save_meta_data();
			}
		}
	}

	/**
	 * Store the Blackbox session ID in the WC session.
	 *
	 * @param string $session_id The Blackbox session ID.
	 */
	private function store_session_id_in_session( string $session_id ): void {
		if ( ! function_exists( 'WC' ) || ! WC()->session instanceof \WC_Session ) {
			return;
		}

		WC()->session->set( self::ORDER_BLACKBOX_SESSION_ID_KEY, $session_id );
	}

	/**
	 * Retrieve the Blackbox session ID from the WC session.
	 *
	 * @return string The session ID, or empty string if unavailable.
	 */
	private function get_session_id_from_session(): string {
		if ( ! function_exists( 'WC' ) || ! WC()->session instanceof \WC_Session ) {
			return '';
		}

		$session_id = WC()->session->get( self::ORDER_BLACKBOX_SESSION_ID_KEY, '' );

		return is_string( $session_id ) ? $session_id : '';
	}
}
