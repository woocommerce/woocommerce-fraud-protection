<?php
/**
 * Test stub for WooCommerce Square.
 *
 * Defines wc_square() and its supporting classes in the global namespace so
 * that SquarePaymentDataCompat can resolve transaction mode via the gateway API.
 */

declare( strict_types=1 );

if ( function_exists( 'wc_square' ) ) {
	return;
}

/**
 * Stub for Square's Settings handler.
 */
class WC_Square_Settings_Stub {

	/**
	 * Whether Square is in sandbox mode.
	 *
	 * @var bool
	 */
	private static bool $sandbox = false;

	/**
	 * Set the sandbox state for testing.
	 *
	 * @param bool $sandbox True = sandbox, false = production.
	 * @return void
	 */
	public static function set_sandbox( bool $sandbox ): void {
		self::$sandbox = $sandbox;
	}

	/**
	 * Whether Square is in sandbox mode.
	 *
	 * @return bool
	 */
	public function is_sandbox(): bool {
		return self::$sandbox;
	}
}

/**
 * Stub for Square's Plugin class.
 */
class WC_Square_Plugin_Stub {

	/**
	 * Get the settings handler.
	 *
	 * @return WC_Square_Settings_Stub
	 */
	public function get_settings_handler(): WC_Square_Settings_Stub {
		return new WC_Square_Settings_Stub();
	}
}

/**
 * Return the Square plugin instance stub.
 *
 * @return WC_Square_Plugin_Stub
 */
function wc_square(): WC_Square_Plugin_Stub {
	return new WC_Square_Plugin_Stub();
}
