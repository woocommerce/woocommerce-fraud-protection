<?php
/**
 * ExpressCheckoutScriptCompat class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\Compat;

use Automattic\WooCommerce\FraudProtection\BlackboxScriptHandler;
use Automattic\WooCommerce\FraudProtection\FraudProtectionFeature;

defined( 'ABSPATH' ) || exit;

/**
 * Loads Fraud Protection browser scripts where WooPayments or Stripe render express payment buttons.
 *
 * The express buttons place the order through the Store API checkout route, where
 * BlocksCheckoutProtector reads the session ID that express-checkout.js adds. A
 * gateway that declares `fraud_protection_express_checkout` sends the session ID
 * itself, so its button scripts are left alone.
 */
class ExpressCheckoutScriptCompat {

	/**
	 * Script handles that the gateways enqueue only on pages where they show express buttons, keyed to their gateway.
	 *
	 * This includes pages with the Cart and Checkout blocks: the gateways show express
	 * buttons in the blocks only when they also enqueue these scripts. Their block
	 * integration scripts are not used, because they are registered even when express
	 * buttons are turned off.
	 */
	private const EXPRESS_BUTTON_HANDLES = array(
		'WCPAY_EXPRESS_CHECKOUT_ECE' => 'woocommerce_payments',
		'wc_stripe_express_checkout' => 'stripe',
	);

	/**
	 * Shared script handler.
	 *
	 * @var BlackboxScriptHandler
	 */
	private BlackboxScriptHandler $blackbox_script_handler;

	/**
	 * Native gateway support lookup.
	 *
	 * @var GatewayNativeSupport
	 */
	private GatewayNativeSupport $native_support;

	/**
	 * Initialize with dependencies.
	 *
	 * @internal
	 *
	 * @param BlackboxScriptHandler $blackbox_script_handler The shared script handler.
	 * @param GatewayNativeSupport  $native_support          Native gateway support lookup.
	 */
	final public function init( BlackboxScriptHandler $blackbox_script_handler, GatewayNativeSupport $native_support ): void {
		$this->blackbox_script_handler = $blackbox_script_handler;
		$this->native_support          = $native_support;
	}

	/**
	 * Register express payment surface hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_express_checkout_script_if_buttons_enqueued' ), PHP_INT_MAX, 0 );
	}

	/**
	 * Enqueue the express checkout script when a gateway enqueued its express button script.
	 *
	 * @internal
	 *
	 * @return void
	 */
	public function enqueue_express_checkout_script_if_buttons_enqueued(): void {
		$gateway_ids = array();
		$has_buttons = false;
		foreach ( self::EXPRESS_BUTTON_HANDLES as $handle => $gateway_id ) {
			if ( $this->is_native( $gateway_id ) ) {
				continue;
			}

			$gateway_ids[] = $gateway_id;
			if ( wp_script_is( $handle, 'registered' ) && wp_script_is( $handle, 'enqueued' ) ) {
				$has_buttons = true;
			}
		}

		if ( $has_buttons ) {
			$this->enqueue_express_checkout_script( array_values( array_unique( $gateway_ids ) ) );
		}
	}

	/**
	 * Check whether a gateway sends the session ID with its express checkout orders itself.
	 *
	 * @param string $gateway_id The gateway's main ID.
	 * @return bool
	 */
	private function is_native( string $gateway_id ): bool {
		return $this->native_support->declared( FraudProtectionFeature::EXPRESS_CHECKOUT, $gateway_id );
	}

	/**
	 * Request the shared scripts and enqueue the express checkout script.
	 *
	 * The script adds the session ID only for the given gateways, so it leaves alone
	 * a gateway on the same page that sends the session ID itself.
	 *
	 * @param string[] $gateway_ids Gateways whose express checkout data the script handles.
	 * @return void
	 */
	private function enqueue_express_checkout_script( array $gateway_ids ): void {
		if ( ! $this->blackbox_script_handler->request_scripts() ) {
			return;
		}

		wp_enqueue_script(
			'wc-fraud-protection-express-checkout',
			plugins_url( 'assets/js/express-checkout.js', WC_FRAUD_PROTECTION_PLUGIN_FILE ),
			array( 'wc-fraud-protection-blackbox-init', 'wp-hooks' ),
			WC_FRAUD_PROTECTION_VERSION,
			array( 'in_footer' => true )
		);
		wp_localize_script( 'wc-fraud-protection-express-checkout', 'wcFraudProtectionExpressCheckout', array( 'gateways' => $gateway_ids ) );
	}
}
