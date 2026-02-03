<?php
/**
 * Base test case for WooCommerce Fraud Protection.
 *
 * @package WooCommerce_Fraud_Protection
 */

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * WC_Fraud_Protection_Unit_Test_Case class.
 *
 * Provides a base test case with common setup for all plugin tests.
 */
class WC_Fraud_Protection_Unit_Test_Case extends TestCase {

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		parent::tearDown();
	}
}
