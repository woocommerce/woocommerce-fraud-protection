<?php
/**
 * LoggerSpyTrait stub for testing without WooCommerce test framework.
 *
 * @package WooCommerce_Fraud_Protection\Tests
 */

namespace Automattic\WooCommerce\RestApi\UnitTests;

/**
 * Stub LoggerSpyTrait for environments without WooCommerce test framework.
 *
 * This is a minimal implementation that provides empty methods.
 * In a full WC dev environment, the real trait from WooCommerce is used.
 */
trait LoggerSpyTrait {
	/**
	 * Set up the logger spy.
	 */
	protected function set_up_logger_spy(): void {
		// Stub implementation - no-op.
	}

	/**
	 * Tear down the logger spy.
	 */
	protected function tear_down_logger_spy(): void {
		// Stub implementation - no-op.
	}

	/**
	 * Get logged messages.
	 *
	 * @return array Empty array in stub.
	 */
	protected function get_logged_messages(): array {
		return array();
	}

	/**
	 * Assert a message was logged.
	 *
	 * @param string $level Expected log level.
	 * @param string $message Expected message.
	 */
	protected function assert_logged( string $level, string $message ): void {
		// Stub implementation - always passes.
		$this->assertTrue( true );
	}

	/**
	 * Assert no messages were logged.
	 */
	protected function assert_not_logged(): void {
		// Stub implementation - always passes.
		$this->assertTrue( true );
	}
}
