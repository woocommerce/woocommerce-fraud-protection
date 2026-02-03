<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\FraudProtection\Tests;

use WC_Fraud_Protection_Unit_Test_Case;

/**
 * Example test to verify the test setup is working.
 */
class ExampleTest extends WC_Fraud_Protection_Unit_Test_Case {

	/**
	 * @testdox Should pass this example test.
	 */
	public function test_example(): void {
		$this->assertTrue( true );
	}

	/**
	 * @testdox Should have WooCommerce loaded.
	 */
	public function test_woocommerce_is_loaded(): void {
		$this->assertTrue( class_exists( 'WooCommerce' ), 'WooCommerce should be loaded' );
	}
}
