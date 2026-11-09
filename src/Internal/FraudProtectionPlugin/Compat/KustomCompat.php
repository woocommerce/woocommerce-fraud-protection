<?php
/**
 * KustomCompat class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\Compat;

use Automattic\WooCommerce\FraudProtection\BlackboxScriptHandler;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Protectors\BlocksCheckoutProtector;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Protectors\ShortcodeCheckoutProtector;

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
			array( 'wc-fraud-protection-blackbox-init', 'wp-data' ),
			WC_FRAUD_PROTECTION_VERSION,
			array( 'in_footer' => true )
		);
	}

	/**
	 * Keep a retry from sending the session ID of an earlier attempt.
	 *
	 * When Kustom refuses an order, the shopper can pay again in the iframe and the
	 * browser is not told. Kustom then sends the stored extension data again, so
	 * the second request reports that it has no session ID for this attempt.
	 *
	 * @internal
	 *
	 * @param mixed $order   The order being processed.
	 * @param mixed $request The checkout REST request.
	 * @return void
	 */
	public function send_session_id_only_once( mixed $order, mixed $request ): void {
		if ( ! $request instanceof \WP_REST_Request || self::GATEWAY_ID !== $request->get_param( 'payment_method' ) ) {
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
			WC()->session->set( self::SENT_SESSION_ID_KEY, $session_id );
			return;
		}

		$extensions[ $namespace ] = array(
			'blackbox_session_id'       => '',
			'blackbox_session_id_error' => 'not_ready',
		);
		$request->set_param( 'extensions', $extensions );
	}
}
