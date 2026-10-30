<?php
/**
 * FraudProtectionIntegrationTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\FraudProtection;

use Automattic\WooCommerce\FraudProtection\FraudProtectionIntegration;
use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;

/**
 * Pins the integration strings extensions add to the native integrations filter.
 *
 * @covers \Automattic\WooCommerce\FraudProtection\FraudProtectionIntegration
 */
class FraudProtectionIntegrationTest extends FraudProtectionUnitTestCase {

	/**
	 * @testdox The integration strings are a released contract and keep their values.
	 */
	public function test_integration_strings_are_stable(): void {
		$this->assertSame( 'change_payment_method', FraudProtectionIntegration::CHANGE_PAYMENT_METHOD );
		$this->assertSame( array( 'change_payment_method' ), FraudProtectionIntegration::ALL );
	}
}
