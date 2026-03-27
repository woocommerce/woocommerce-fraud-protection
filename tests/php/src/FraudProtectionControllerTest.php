<?php

declare( strict_types=1 );

namespace Automattic\WooCommerce\Tests\Internal;

use Automattic\WooCommerce\FraudProtection\FraudProtectionController;
use Automattic\WooCommerce\FraudProtection\SessionClearanceManager;
use Automattic\WooCommerce\RestApi\UnitTests\LoggerSpyTrait;

/**
 * Tests for the FraudProtectionController class.
 */
class FraudProtectionControllerTest extends \WC_Unit_Test_Case {

	use LoggerSpyTrait;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		// Set jetpack_activation_source option to prevent "Cannot use bool as array" error
		// in Jetpack Connection Manager's apply_activation_source_to_args method.
		update_option( 'jetpack_activation_source', array( '', '' ) );

		// Clear identity ID so log messages don't get an unexpected prefix.
		if ( WC()->session ) {
			WC()->session->set( SessionClearanceManager::CUSTOMER_IDENTITY_ID_KEY, null );
		}
	}

	/**
	 * Create a new controller instance.
	 *
	 * @return FraudProtectionController
	 */
	private function create_controller(): FraudProtectionController {
		return new FraudProtectionController();
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
	 * Test that event tracking hooks are registered (always enabled as standalone plugin).
	 */
	public function test_event_tracking_hooks_are_registered(): void {
		// Verify that cart event tracking hooks are registered.
		$this->assertNotFalse(
			has_action( 'woocommerce_add_to_cart' ),
			'woocommerce_add_to_cart hook should be registered'
		);
		$this->assertNotFalse(
			has_action( 'woocommerce_cart_item_removed' ),
			'woocommerce_cart_item_removed hook should be registered'
		);

		// Verify checkout event tracking hooks are registered.
		$this->assertNotFalse(
			has_action( 'woocommerce_checkout_order_processed' ),
			'woocommerce_checkout_order_processed hook should be registered'
		);

		// Verify blocking hooks are registered.
		$this->assertNotFalse(
			has_filter( 'woocommerce_add_to_cart_validation' ),
			'woocommerce_add_to_cart_validation filter should be registered'
		);
		$this->assertNotFalse(
			has_filter( 'woocommerce_available_payment_gateways' ),
			'woocommerce_available_payment_gateways filter should be registered'
		);
	}

	/**
	 * Test that register method registers init action.
	 */
	public function test_register_registers_init_action(): void {
		$controller = $this->create_controller();
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

		$controller = $this->create_controller();

		// Check if the method returns true.
		$this->assertTrue( FraudProtectionController::feature_is_enabled() );
	}

	/**
	 * Test that feature_is_enabled returns true (always enabled as standalone plugin).
	 *
	 * Note: As a standalone plugin, fraud protection is always enabled.
	 * The feature flag only applies when integrated into WooCommerce core.
	 */
	public function test_feature_is_enabled_always_returns_true_as_standalone_plugin(): void {
		// Even with the option set to 'no', the standalone plugin is always enabled.
		update_option( 'woocommerce_feature_fraud_protection_enabled', 'no' );

		$controller = $this->create_controller();

		// Standalone plugin is always enabled.
		$this->assertTrue( FraudProtectionController::feature_is_enabled() );
	}

	/**
	 * Test that log message is prefixed with identity ID when available in session.
	 */
	public function test_log_prepends_identity_id_when_available(): void {
		WC()->session->set( SessionClearanceManager::CUSTOMER_IDENTITY_ID_KEY, 'test-identity-123' );

		FraudProtectionController::log( 'info', 'Test message' );

		$this->assertLogged( 'info', 'Identity: test-identity-123 | Test message' );
	}

	/**
	 * Test that log message has no prefix when identity ID is not in session.
	 */
	public function test_log_has_no_prefix_when_identity_id_not_in_session(): void {
		// Ensure no identity ID is set.
		WC()->session->set( SessionClearanceManager::CUSTOMER_IDENTITY_ID_KEY, null );

		FraudProtectionController::log( 'info', 'Test message' );

		$this->assertLogged( 'info', 'Test message' );

		// Verify the message does NOT start with the identity prefix.
		$logs = array_values(
			array_filter(
				$this->captured_logs,
				fn( $log ) => 'info' === $log['level']
			)
		);
		$this->assertStringStartsNotWith( 'Identity:', $logs[0]['message'] );
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

		// Clean up session identity ID.
		if ( WC()->session ) {
			WC()->session->set( SessionClearanceManager::CUSTOMER_IDENTITY_ID_KEY, null );
		}
	}
}
