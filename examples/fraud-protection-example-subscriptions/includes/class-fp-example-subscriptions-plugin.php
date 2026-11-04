<?php
/**
 * FP_Example_Subscriptions_Plugin class file.
 *
 * @package WooCommerce\FraudProtection\Examples
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Loads the change-payment flow once WooCommerce is available.
 */
final class FP_Example_Subscriptions_Plugin {

	/**
	 * Load the flow and register its hooks.
	 */
	public static function load(): void {
		if ( ! function_exists( 'WC' ) ) {
			return;
		}

		require_once __DIR__ . '/class-fp-example-change-payment.php';

		( new FP_Example_Change_Payment() )->register();
	}
}
