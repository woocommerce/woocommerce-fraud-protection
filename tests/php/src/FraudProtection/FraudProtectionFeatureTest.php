<?php
/**
 * FraudProtectionFeatureTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\FraudProtection;

use Automattic\WooCommerce\FraudProtection\FraudProtectionFeature;
use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;

/**
 * Pins the feature strings gateways add to their `$supports` arrays.
 *
 * @covers \Automattic\WooCommerce\FraudProtection\FraudProtectionFeature
 */
class FraudProtectionFeatureTest extends FraudProtectionUnitTestCase {

	/**
	 * @testdox The feature strings are a released contract and keep their values.
	 */
	public function test_feature_strings_are_stable(): void {
		$this->assertSame( 'fraud_protection_payment_data', FraudProtectionFeature::PAYMENT_DATA );
		$this->assertSame( 'fraud_protection_checkout', FraudProtectionFeature::CHECKOUT );
		$this->assertSame( 'fraud_protection_add_payment_method', FraudProtectionFeature::ADD_PAYMENT_METHOD );
		$this->assertSame( 'fraud_protection_express_checkout', FraudProtectionFeature::EXPRESS_CHECKOUT );
		$this->assertSame(
			array( 'fraud_protection_payment_data', 'fraud_protection_checkout', 'fraud_protection_add_payment_method', 'fraud_protection_express_checkout' ),
			FraudProtectionFeature::ALL
		);
	}
}
