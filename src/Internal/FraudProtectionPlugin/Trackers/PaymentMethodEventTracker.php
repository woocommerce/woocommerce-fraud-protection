<?php
/**
 * PaymentMethodEventTracker class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\Trackers;

use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Logging\FraudProtectionLogger;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Sessions\SessionDataCollector;

defined( 'ABSPATH' ) || exit;

/**
 * Tracks payment method events for fraud protection analysis.
 *
 * This class provides methods to track events for adding payment methods in My Account page
 * for fraud protection. Event-specific data is passed to the SessionDataCollector which
 * handles session data storage internally.
 */
class PaymentMethodEventTracker {
	/**
	 * Failure log message.
	 */
	private const FAILURE_MESSAGE = 'Payment method event tracker callback failed';

	/**
	 * Event source for failure logs.
	 */
	private const EVENT_SOURCE = 'payment_method_event_tracker';

	/**
	 * Session data collector instance.
	 *
	 * @var SessionDataCollector
	 */
	private SessionDataCollector $session_data_collector;

	/**
	 * Fraud Protection logger instance.
	 *
	 * @var FraudProtectionLogger
	 */
	private FraudProtectionLogger $logger;

	/**
	 * Initialize with dependencies.
	 *
	 * @internal
	 *
	 * @param SessionDataCollector  $session_data_collector The session data collector instance.
	 * @param FraudProtectionLogger $logger                 The Fraud Protection logger instance.
	 */
	final public function init( SessionDataCollector $session_data_collector, FraudProtectionLogger $logger ): void {
		$this->session_data_collector = $session_data_collector;
		$this->logger                 = $logger;
	}

	/**
	 * Register payment method event tracking hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'woocommerce_new_payment_token', array( $this, 'track_payment_method_added' ), 10, 2 );
		add_action( 'before_woocommerce_add_payment_method', array( $this, 'track_add_payment_method_page_loaded' ), 10, 0 );
	}

	/**
	 * Track add payment method page loaded event.
	 *
	 * Collects session data when the add payment method page is initially loaded.
	 * This captures the initial session state before any user interactions.
	 *
	 * @internal
	 * @return void
	 */
	public function track_add_payment_method_page_loaded(): void {
		try {
			$this->session_data_collector->collect( 'add_payment_method_page_loaded', array() );
		} catch ( \Throwable $e ) {
			$this->log_tracker_failure( 'before_woocommerce_add_payment_method', $e );
		}
	}

	/**
	 * Track payment method added event.
	 *
	 * Collects session data when a payment method is added.
	 *
	 * @internal
	 *
	 * @param int               $token_id The newly created token ID.
	 * @param \WC_Payment_Token $token    The payment token object.
	 */
	public function track_payment_method_added( $token_id, $token ): void {
		try {
			$event_data = $this->build_payment_method_event_data( 'added', $token );

			$this->session_data_collector->collect( 'payment_method_added', $event_data );
		} catch ( \Throwable $e ) {
			$this->log_tracker_failure( 'woocommerce_new_payment_token', $e );
		}
	}

	/**
	 * Build payment method event-specific data.
	 *
	 * Extracts relevant information from the payment token object including
	 * token type, gateway ID, user ID, and card details for card tokens.
	 * This data will be merged with session data during collection.
	 *
	 * @param string            $action Action type (added, updated, set_default, deleted, add_failed).
	 * @param \WC_Payment_Token $token  The payment token object.
	 * @return array Payment method event data.
	 */
	private function build_payment_method_event_data( string $action, \WC_Payment_Token $token ): array {
		$event_data = array(
			'action'     => $action,
			'token_id'   => $token->get_id(),
			'token_type' => $token->get_type(),
			'gateway_id' => $token->get_gateway_id(),
			'user_id'    => $token->get_user_id(),
			'is_default' => $token->is_default(),
		);

		// Add card-specific details if this is a credit card token.
		if ( $token instanceof \WC_Payment_Token_CC ) {
			$event_data['card_type']    = $token->get_card_type();
			$event_data['card_last4']   = $token->get_last4();
			$event_data['expiry_month'] = $token->get_expiry_month();
			$event_data['expiry_year']  = $token->get_expiry_year();
		}

		return $event_data;
	}

	/**
	 * Log a tracker callback failure.
	 *
	 * @param string     $hook The WordPress hook the failing callback is registered on.
	 * @param \Throwable $e    The caught throwable.
	 * @return void
	 */
	private function log_tracker_failure( string $hook, \Throwable $e ): void {
		$this->logger->log(
			'error',
			self::FAILURE_MESSAGE,
			array(
				'event_source'      => self::EVENT_SOURCE,
				'hook'              => $hook,
				'exception_class'   => $e::class,
				'exception_message' => $e->getMessage(),
				'exception_file'    => $e->getFile(),
				'exception_line'    => $e->getLine(),
			),
			true
		);
	}
}
