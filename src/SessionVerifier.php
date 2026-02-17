<?php
/**
 * SessionVerifier class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\FraudProtection;

use Automattic\WooCommerce\FraudProtection\Schemas\PaymentMethodData;

defined( 'ABSPATH' ) || exit;

/**
 * Encapsulates the collect → verify → apply-decision flow.
 *
 * Each checkout/payment handler (blocks checkout, shortcode checkout,
 * add-payment-method, etc.) delegates to this class for the common
 * verification sequence, and only handles the context-specific response
 * to the returned decision (e.g. throw RouteException, add wc_notice).
 *
 * @internal
 */
class SessionVerifier {

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
	 * Initialize with dependencies.
	 *
	 * @internal
	 *
	 * @param SessionDataCollector $data_collector  The session data collector instance.
	 * @param ApiClient            $api_client      The API client instance.
	 * @param DecisionHandler      $decision_handler The decision handler instance.
	 */
	final public function init(
		SessionDataCollector $data_collector,
		ApiClient $api_client,
		DecisionHandler $decision_handler
	): void {
		$this->data_collector   = $data_collector;
		$this->api_client       = $api_client;
		$this->decision_handler = $decision_handler;
	}

	/**
	 * Verify a session and return the final decision.
	 *
	 * Collects session/order data, calls the Blackbox verify API, and applies
	 * the decision (including filter overrides and session status updates).
	 *
	 * @param string             $session_id   The Blackbox session ID from collect().
	 * @param int                $order_id     The WooCommerce order ID.
	 * @param string             $source       Identifies the caller (e.g. 'blocks_checkout').
	 * @param array              $request_data Optional request data from the request being verified.
	 * @param ?PaymentMethodData $payment_data Optional resolved payment data.
	 * @return string The final decision after filters: 'allow' or 'block'.
	 */
	public function verify_session( string $session_id, int $order_id, string $source, array $request_data = array(), ?PaymentMethodData $payment_data = null ): string {
		$payload = $this->data_collector->get_collected_data( $order_id );

		$payload['source']       = $source;
		$payload['request_data'] = $request_data;
		$payload['payment']      = $payment_data ? $payment_data->to_array() : null;

		$decision = $this->api_client->verify( $session_id, $payload );

		return $this->decision_handler->apply_decision( $decision, $payload );
	}
}
