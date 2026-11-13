<?php
/**
 * KustomCompat class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\Compat;

use Automattic\WooCommerce\FraudProtection\BlackboxScriptHandler;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Protectors\BlocksCheckoutProtector;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Protectors\ShortcodeCheckoutProtector;
use Automattic\WooCommerce\StoreApi\Schemas\V1\CartSchema;

defined( 'ABSPATH' ) || exit;

/**
 * Passes the browser session to verification on Kustom's embedded checkout.
 *
 * Classic checkout: Kustom's template replaces form-checkout.php and fires
 * neither of the actions that ShortcodeCheckoutProtector uses to enqueue its
 * script. Kustom still places the order through the classic checkout request,
 * which that script completes with the session ID.
 *
 * Checkout block: Kustom's servers place the order through the Store API after
 * the shopper pays in its iframe, so the block's submit events never run. Since
 * version 2.24.1 Kustom stores the block's extension data in the WooCommerce
 * session and sends it with that request. kustom-blocks-checkout.js keeps the
 * session ID in that data, and BlocksCheckoutProtector reads it as usual.
 *
 * Kustom's servers do not tell the browser when they use the session ID. The
 * Store API cart data carries the last session ID that a Kustom attempt sent to
 * verification, and the script starts a new session when it matches its own.
 * The script gets the cart again when the shopper comes back to the page from
 * an iframe. Requests that still carry a used ID, such as a retry made without
 * leaving Kustom's iframe, are sent without it, with any payment method.
 */
class KustomCompat {

	/**
	 * Kustom's payment gateway ID.
	 */
	private const GATEWAY_ID = 'kco';

	/**
	 * WooCommerce session key of the last session ID sent for a Kustom order.
	 */
	private const SENT_SESSION_ID_KEY = '_wc_fraud_protection_kustom_sent_session_id';

	/**
	 * Store API cart data field that carries that session ID to the browser.
	 */
	private const CART_DATA_FIELD = 'kustom_sent_session_id';

	/**
	 * Session ID of the current Kustom request, recorded once the order reaches verification.
	 *
	 * @var string|null
	 */
	private ?string $pending_session_id = null;

	/**
	 * Classic checkout protector, which owns the classic checkout script.
	 *
	 * @var ShortcodeCheckoutProtector
	 */
	private ShortcodeCheckoutProtector $shortcode_checkout_protector;

	/**
	 * Shared script handler.
	 *
	 * @var BlackboxScriptHandler
	 */
	private BlackboxScriptHandler $blackbox_script_handler;

	/**
	 * Initialize with dependencies.
	 *
	 * @internal
	 *
	 * @param ShortcodeCheckoutProtector $shortcode_checkout_protector The classic checkout protector.
	 * @param BlackboxScriptHandler      $blackbox_script_handler      The shared script handler.
	 */
	final public function init(
		ShortcodeCheckoutProtector $shortcode_checkout_protector,
		BlackboxScriptHandler $blackbox_script_handler
	): void {
		$this->shortcode_checkout_protector = $shortcode_checkout_protector;
		$this->blackbox_script_handler      = $blackbox_script_handler;
	}

	/**
	 * Register the Kustom hooks.
	 *
	 * Kustom adds the classic checkout form fields on kco_wc_before_snippet, so a
	 * theme that overrides the template has to keep it.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'kco_wc_before_snippet', array( $this->shortcode_checkout_protector, 'enqueue_shortcode_checkout_script' ), 10, 0 );
		add_action( 'woocommerce_blocks_enqueue_checkout_block_scripts_before', array( $this, 'enqueue_blocks_checkout_script' ), 10, 0 );
		// Before BlocksCheckoutProtector reads the request.
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( $this, 'send_session_id_only_once' ), 5, 2 );
		// Before BlocksCheckoutProtector verifies the order.
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'record_sent_session_id' ), 5, 0 );
		add_action( 'init', array( $this, 'register_store_api_cart_extension' ), 10, 0 );
	}

	/**
	 * Add the session ID that a Kustom attempt sent to the Store API cart data.
	 *
	 * Stores without Kustom keep their cart data unchanged.
	 *
	 * @internal
	 *
	 * @return void
	 */
	public function register_store_api_cart_extension(): void {
		if (
			! defined( 'KCO_WC_VERSION' ) ||
			! function_exists( 'woocommerce_store_api_register_endpoint_data' ) ||
			! class_exists( CartSchema::class )
		) {
			return;
		}

		woocommerce_store_api_register_endpoint_data(
			array(
				'endpoint'        => CartSchema::IDENTIFIER,
				'namespace'       => BlocksCheckoutProtector::EXTENSION_NAMESPACE,
				'data_callback'   => array( $this, 'get_store_api_cart_data' ),
				'schema_callback' => static function (): array {
					return array(
						self::CART_DATA_FIELD => array(
							'description' => 'Session ID that a Kustom attempt sent, which the browser must not send again.',
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
	 * Get the Fraud Protection data of the Store API cart.
	 *
	 * @internal
	 *
	 * @return array<string, string>
	 */
	public function get_store_api_cart_data(): array {
		$session_id = WC()->session instanceof \WC_Session ? WC()->session->get( self::SENT_SESSION_ID_KEY ) : null;

		return array( self::CART_DATA_FIELD => is_string( $session_id ) ? $session_id : '' );
	}

	/**
	 * Enqueue the script that keeps the session ID in the Checkout block's extension data.
	 *
	 * @internal
	 *
	 * @return void
	 */
	public function enqueue_blocks_checkout_script(): void {
		// These endpoints render their classic views instead of the Checkout block.
		if ( is_wc_endpoint_url( 'order-pay' ) || is_wc_endpoint_url( 'order-received' ) ) {
			return;
		}

		if ( ! isset( WC()->payment_gateways()->get_available_payment_gateways()[ self::GATEWAY_ID ] ) ) {
			return;
		}

		if ( ! $this->blackbox_script_handler->request_scripts() ) {
			return;
		}

		wp_enqueue_script(
			'wc-fraud-protection-kustom-blocks-checkout',
			plugins_url( 'assets/js/kustom-blocks-checkout.js', WC_FRAUD_PROTECTION_PLUGIN_FILE ),
			array( 'wc-fraud-protection-blackbox-init', 'wp-data', 'wp-api-fetch' ),
			WC_FRAUD_PROTECTION_VERSION,
			array( 'in_footer' => true )
		);
	}

	/**
	 * Keep a retry from sending the session ID of an earlier attempt.
	 *
	 * When Kustom refuses an order, the browser is not told and keeps the session.
	 * The shopper can pay again in the iframe, and Kustom sends the stored
	 * extension data again. The shopper can also switch to another payment method,
	 * whose script then sends the same session ID. So a request with any payment
	 * method that carries the session ID of an earlier verified Kustom attempt
	 * reports that it has no session ID for this attempt.
	 *
	 * Only Kustom requests are recorded, by record_sent_session_id(). A request
	 * that WooCommerce rejects before verification leaves the ID for the retry.
	 *
	 * @internal
	 *
	 * @param mixed $order   The order being processed.
	 * @param mixed $request The checkout REST request.
	 * @return void
	 */
	public function send_session_id_only_once( mixed $order, mixed $request ): void {
		$this->pending_session_id = null;

		if ( ! $request instanceof \WP_REST_Request ) {
			return;
		}

		if ( ! WC()->session instanceof \WC_Session ) {
			return;
		}

		$extensions = $request->get_param( 'extensions' );
		$namespace  = BlocksCheckoutProtector::EXTENSION_NAMESPACE;
		$session_id = is_array( $extensions ) && is_array( $extensions[ $namespace ] ?? null )
			? ( $extensions[ $namespace ]['blackbox_session_id'] ?? null )
			: null;

		if ( ! is_string( $session_id ) || '' === $session_id ) {
			return;
		}

		if ( WC()->session->get( self::SENT_SESSION_ID_KEY ) !== $session_id ) {
			if ( self::GATEWAY_ID === $request->get_param( 'payment_method' ) ) {
				$this->pending_session_id = $session_id;
			}
			return;
		}

		$extensions[ $namespace ] = array(
			'blackbox_session_id'       => '',
			'blackbox_session_id_error' => 'not_ready',
		);
		$request->set_param( 'extensions', $extensions );
	}

	/**
	 * Record the session ID of the current Kustom request as sent.
	 *
	 * Runs when WooCommerce has validated the order, right before
	 * BlocksCheckoutProtector verifies it. Recording it after verification would
	 * miss blocked attempts, because a Block stops the request.
	 *
	 * @internal
	 *
	 * @return void
	 */
	public function record_sent_session_id(): void {
		$session_id               = $this->pending_session_id;
		$this->pending_session_id = null;

		if ( null === $session_id || ! WC()->session instanceof \WC_Session ) {
			return;
		}

		WC()->session->set( self::SENT_SESSION_ID_KEY, $session_id );
	}
}
