<?php
/**
 * FraudProtectionUnitTestCase file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\FraudProtection\Tests;

use WC_Unit_Test_Case;

/**
 * Base test case for WooCommerce Fraud Protection unit tests.
 * 
 * It inherits from WooCommerce core's `WC_Unit_Test_Case`, so all the
 * testing infrastructure provided by that class is available.
 */
abstract class FraudProtectionUnitTestCase extends WC_Unit_Test_Case {

	/**
	 * Lines captured from `error_log()` calls forwarded through the proxy during the test.
	 *
	 * @var string[]
	 */
	protected $forwarded_platform_logs = array();

	/**
	 * Runs before each test.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->forwarded_platform_logs = array();

		$this->register_legacy_proxy_function_mocks(
			array(
				'error_log' => function ( $message ) {
					$this->forwarded_platform_logs[] = (string) $message;
					return true;
				},
			)
		);
	}

	/**
	 * Runs after each test.
	 */
	public function tearDown(): void {
		$this->reset_legacy_proxy_mocks();
		$this->forwarded_platform_logs = array();

		parent::tearDown();
	}

	/**
	 * Get the lines forwarded to the platform log during the test.
	 *
	 * Each entry is the verbatim string passed to `error_log()`, i.e. a
	 * `PHP Warning: [woo-fraud-protection <level>] ...` line.
	 *
	 * @return string[]
	 */
	protected function get_forwarded_platform_logs(): array {
		return $this->forwarded_platform_logs;
	}
}
