<?php
/**
 * FraudProtectionControllerForTests file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\FraudProtection\Tests\Support;

use Automattic\WooCommerce\Internal\FraudProtectionPlugin\FraudProtectionController;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Logging\FraudProtectionLogger;

/**
 * Test access to the logger used by the static controller facade.
 */
class FraudProtectionControllerForTests extends FraudProtectionController {

	/**
	 * Point the static FraudProtectionController::log() facade at a logger.
	 *
	 * @param FraudProtectionLogger $logger The logger to make the facade target.
	 * @return void
	 */
	public static function set_facade_logger( FraudProtectionLogger $logger ): void {
		self::$logger = $logger;
	}
}
