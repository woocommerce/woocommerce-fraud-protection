<?php

declare( strict_types=1 );

namespace Automattic\WooCommerceFraudProtection\Tests\Internal;

use Automattic\WooCommerceFraudProtection\Internal\FraudProtectionController;

/**
 * Tests for the FraudProtectionController class.
 */
class FraudProtectionControllerTest extends \WC_Unit_Test_Case {

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		// Set jetpack_activation_source option to prevent "Cannot use bool as array" error
		// in Jetpack Connection Manager's apply_activation_source_to_args method.
		update_option( 'jetpack_activation_source', array( '', '' ) );
	}

	/**
	 * Get a fresh controller instance with reset container.
	 *
	 * @return FraudProtectionController
	 */
	private function get_fresh_controller(): FraudProtectionController {
		$controller = new FraudProtectionController();
		$controller->init(
			$this->createMock( \Automattic\WooCommerceFraudProtection\Internal\BlockedSessionNotice::class ),
			$this->createMock( \Automattic\WooCommerceFraudProtection\Internal\BlackboxScriptHandler::class )
		);
		return $controller;
	}

	/**
	 * Test logging functionality.
	 */
	public function test_log_writes_to_woo_fraud_protection_source(): void {
		// Mock the logger.
		$logger = $this->getMockBuilder( \WC_Logger_Interface::class )
			->getMock();

		// Expect the log method to be called with correct parameters.
		$logger->expects( $this->once() )
			->method( 'log' )
			->with(
				$this->equalTo( 'info' ),
				$this->equalTo( 'Test message' ),
				$this->equalTo( array( 'source' => 'woo-fraud-protection' ) )
			);

		// Replace the logger with our mock.
		add_filter(
			'woocommerce_logging_class',
			function () use ( $logger ) {
				return $logger;
			}
		);

		// Call the log method.
		FraudProtectionController::log( 'info', 'Test message' );
	}

	/**
	 * Test logging with context data.
	 */
	public function test_log_merges_context_with_source(): void {
		// Mock the logger.
		$logger = $this->getMockBuilder( \WC_Logger_Interface::class )
			->getMock();

		$expected_context = array(
			'foo'    => 'bar',
			'source' => 'woo-fraud-protection',
		);

		// Expect the log method to be called with merged context.
		$logger->expects( $this->once() )
			->method( 'log' )
			->with(
				$this->equalTo( 'debug' ),
				$this->equalTo( 'Test with context' ),
				$this->equalTo( $expected_context )
			);

		// Replace the logger with our mock.
		add_filter(
			'woocommerce_logging_class',
			function () use ( $logger ) {
				return $logger;
			}
		);

		// Call the log method with context.
		FraudProtectionController::log( 'debug', 'Test with context', array( 'foo' => 'bar' ) );
	}

	/**
	 * Test that register method registers init action.
	 */
	public function test_register_registers_init_action(): void {
		// Get a fresh controller instance.
		$controller = $this->get_fresh_controller();

		// Call register.
		$controller->register();

		// Check if the init action is registered for our callback.
		$priority = has_action( 'init', array( $controller, 'on_init' ) );

		// The priority should be 10 (default).
		$this->assertSame( 10, $priority, 'Init action should be registered with default priority 10' );
	}

	/**
	 * Test that feature_is_enabled returns true when feature is enabled.
	 */
	public function test_feature_is_enabled_returns_true_when_enabled(): void {
		// Enable the feature.
		update_option( 'woocommerce_feature_fraud_protection_enabled', 'yes' );

		// Get a fresh controller instance to pick up the option change.
		$controller = $this->get_fresh_controller();

		// Check if the method returns true.
		$this->assertTrue( $controller->feature_is_enabled() );
	}

	/**
	 * Test that feature_is_enabled returns false before init has fired.
	 */
	public function test_feature_is_enabled_returns_false_before_init(): void {
		// Simulate init not having fired yet.
		global $wp_actions;
		$original_init_count = $wp_actions['init'] ?? 0;
		$wp_actions['init']  = 0;

		$controller = $this->get_fresh_controller();

		$this->assertFalse( $controller->feature_is_enabled() );

		// Restore.
		$wp_actions['init'] = $original_init_count;
	}

	/**
	 * Cleanup after test.
	 */
	public function tearDown(): void {
		parent::tearDown();

		// Clean up any filters or options.
		remove_all_filters( 'woocommerce_logging_class' );
		delete_option( 'woocommerce_feature_fraud_protection_enabled' );
		delete_option( 'jetpack_activation_source' );

		// Remove any init hooks registered by the controller.
		remove_all_actions( 'init' );

	}
}
