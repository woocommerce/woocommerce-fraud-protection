<?php
/**
 * BlocksCheckoutProtector class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\Protectors;

use Automattic\WooCommerce\FraudProtection\BlockedSessionMessage;
use Automattic\WooCommerce\FraudProtection\MessageContext;
use Automattic\WooCommerce\FraudProtection\Schemas\FraudDecision;
use Automattic\WooCommerce\FraudProtection\SessionVerifier;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\FraudProtectionController;
use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;
use Automattic\WooCommerce\StoreApi\Schemas\V1\CheckoutSchema;

defined( 'ABSPATH' ) || exit;

/**
 * Integrates Blackbox fraud protection into the blocks-based checkout.
 *
 * Handles the getSessionId -> verify -> verdict flow:
 * 1. Registers Store API extension data namespace for blackbox_session_id
 * 2. Extracts request data (including session_id) from the checkout POST request
 * 3. Calls verify() before payment processing, blocking on BLOCK decisions
 * 4. Enqueues the blocks-checkout.js script for getSessionId + setExtensionData
 *
 * All hooks fire within the same HTTP request, so request data is stored
 * as a class property (no WC session or DB storage needed).
 */
class BlocksCheckoutProtector {

	/**
	 * Extension namespace for Store API endpoint data.
	 */
	private const EXTENSION_NAMESPACE = 'woocommerce/fraud-protection';

	/**
	 * Source identifier for verify requests from this protector.
	 */
	private const SOURCE = 'blocks_checkout';

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
	 * Request data extracted from the current checkout request.
	 *
	 * Transient storage during a single HTTP request: set in extract_request_data(),
	 * consumed in verify_and_block(). Both hooks fire in the same request.
	 *
	 * Contains checkout fields (billing_address, payment_method, extensions, etc.)
	 * and the blackbox_session_id is extracted from extensions when needed.
	 *
	 * @var array
	 */
	private array $request_data = array();

	/**
	 * Initialize with dependencies.
	 *
	 * @internal
	 *
	 * @param SessionVerifier       $session_verifier        The session verifier instance.
	 * @param BlockedSessionMessage $blocked_session_message The blocked-session message generator.
	 */
	final public function init(
		SessionVerifier $session_verifier,
		BlockedSessionMessage $blocked_session_message
	): void {
		$this->session_verifier        = $session_verifier;
		$this->blocked_session_message = $blocked_session_message;
	}

	/**
	 * Register hooks for blocks checkout fraud protection.
	 *
	 * Called from FraudProtectionController::handle_init() when fraud protection is enabled.
	 *
	 * @return void
	 */
	public function register(): void {
		// Called from handle_init() which fires on `init`. By this point
		// woocommerce_blocks_loaded has already fired (during plugins_loaded),
		// so we register the extension data directly.
		$this->register_store_api_extension();

		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( $this, 'extract_request_data' ), 10, 2 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'verify_and_block' ) );
		add_action( 'woocommerce_blocks_checkout_enqueue_data', array( $this, 'enqueue_blocks_checkout_script' ) );
	}

	/**
	 * Extract request data from the checkout request.
	 *
	 * Called during `woocommerce_store_api_checkout_update_order_from_request`.
	 * Stores request data as a class property for use in verify_and_block().
	 *
	 * @internal
	 *
	 * @param \WC_Order        $order   The order being processed.
	 * @param \WP_REST_Request $request The checkout REST request.
	 * @return void
	 */
	public function extract_request_data( \WC_Order $order, \WP_REST_Request $request ): void {
		$this->request_data = array(
			'billing_address'   => $request->get_param( 'billing_address' ),
			'shipping_address'  => $request->get_param( 'shipping_address' ),
			'payment_method'    => sanitize_text_field( $request->get_param( 'payment_method' ) ?? '' ),
			'payment_data'      => $this->normalize_payment_data( $request->get_param( 'payment_data' ) ?? array() ),
			'create_account'    => (bool) $request->get_param( 'create_account' ),
			'additional_fields' => $request->get_param( 'additional_fields' ),
			'extensions'        => $request->get_param( 'extensions' ),
		);
	}

	/**
	 * Verify the session with Blackbox and block checkout if needed.
	 *
	 * Called during `woocommerce_store_api_checkout_order_processed`, which fires
	 * BEFORE payment processing (Checkout.php:549). This is the last chance to
	 * block the order before payment is attempted.
	 *
	 * Fail-open: If session_id is empty, verify is still called (Blackbox/server
	 * decides). SessionVerifier handles all internal errors and returns ALLOW.
	 *
	 * @internal
	 *
	 * @param \WC_Order $order The order being processed.
	 * @return void
	 *
	 * @throws RouteException When Blackbox returns a BLOCK decision.
	 */
	public function verify_and_block( \WC_Order $order ): void {
		$decision = $this->session_verifier->verify_session(
			$this->get_submitted_session_id(),
			self::SOURCE,
			$order->get_id(),
			$this->request_data
		);

		if ( FraudDecision::Block === $decision ) {
			throw new RouteException(
				'woocommerce_rest_checkout_error',
				esc_html( $this->blocked_session_message->get_plaintext( MessageContext::Purchase ) ),
				403
			);
		}
	}

	/**
	 * Enqueue the blocks-checkout.js script.
	 *
	 * Called during `woocommerce_blocks_checkout_enqueue_data`. The script gates
	 * checkout on Blackbox.getSessionId() and sets the extension data via wp.data.dispatch.
	 *
	 * @internal
	 *
	 * @return void
	 */
	public function enqueue_blocks_checkout_script(): void {
		wp_enqueue_script(
			'wc-fraud-protection-blocks-checkout',
			WC_FRAUD_PROTECTION_PLUGIN_URL . 'assets/js/blocks-checkout.js',
			array( 'wc-fraud-protection-blackbox-init', 'wp-data', 'wc-blocks-checkout-events' ),
			WC_FRAUD_PROTECTION_VERSION,
			array( 'in_footer' => true )
		);
	}

	/**
	 * Register the Store API extension data namespace.
	 *
	 * Registers `woocommerce/fraud-protection` as an extension namespace for the
	 * checkout endpoint, allowing the frontend to include blackbox_session_id
	 * in the checkout POST request body.
	 *
	 * @return void
	 */
	private function register_store_api_extension(): void {
		if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) ) {
			return;
		}

		// CheckoutSchema may be absent if the Store API package is partially loaded
		// (e.g., older WC version that exposes the registration function from a different schema build).
		if ( ! class_exists( CheckoutSchema::class ) ) {
			return;
		}

		woocommerce_store_api_register_endpoint_data(
			array(
				'endpoint'        => CheckoutSchema::IDENTIFIER,
				'namespace'       => self::EXTENSION_NAMESPACE,
				'schema_callback' => function () {
					return array(
						'blackbox_session_id' => array(
							'description' => 'Blackbox session ID for fraud protection verification.',
							'type'        => 'string',
							'context'     => array( 'view', 'edit' ),
							'readonly'    => true,
						),
					);
				},
			)
		);
	}

	/**
	 * Normalize Store API string or boolean payment data with sanitize_key() and wc_clean().
	 *
	 * The last value for each normalized key replaces earlier values.
	 *
	 * @param array $raw_payment_data Raw payment_data array from Store API.
	 * @return array<string, string> Flat key-value map.
	 */
	private function normalize_payment_data( array $raw_payment_data ): array {
		$normalized = array();

		foreach ( $raw_payment_data as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['key'], $item['value'] ) ) {
				continue;
			}

			$key   = $item['key'];
			$value = $item['value'];

			if ( ! is_string( $key ) || ( ! is_string( $value ) && ! is_bool( $value ) ) ) {
				continue;
			}

			// @phpstan-ignore argument.type, cast.string (Core supports booleans; PaymentContext casts the cleaned value.)
			$normalized[ sanitize_key( $key ) ] = (string) wc_clean( $value );
		}

		return $normalized;
	}

	/**
	 * Get the submitted session ID from the stored request data.
	 *
	 * @return mixed The submitted session ID, or an empty string if not found.
	 */
	private function get_submitted_session_id(): mixed {
		$extensions = $this->request_data['extensions'] ?? array();

		if ( ! is_array( $extensions ) || ! array_key_exists( self::EXTENSION_NAMESPACE, $extensions ) ) {
			return '';
		}

		$fraud_data = $extensions[ self::EXTENSION_NAMESPACE ];

		if ( ! is_array( $fraud_data ) || ! array_key_exists( 'blackbox_session_id', $fraud_data ) ) {
			return '';
		}

		return $fraud_data['blackbox_session_id'];
	}
}
