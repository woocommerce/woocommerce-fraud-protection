<?php

declare( strict_types=1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin;

use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\FraudProtectionController;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\SessionClearanceManager;
use Automattic\WooCommerce\RestApi\UnitTests\LoggerSpyTrait;

/**
 * Tests for the FraudProtectionController class.
 */
class FraudProtectionControllerTest extends FraudProtectionUnitTestCase {

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
	 * @testdox WC Core fraud protection feature is force-disabled to prevent conflicts.
	 */
	public function test_wc_core_fraud_protection_feature_is_disabled(): void {
		$this->assertFalse(
			apply_filters( 'woocommerce_feature_fraud_protection_enabled', true ),
			'WC Core fraud protection feature should be force-disabled'
		);
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

	/**
	 * Capture lines forwarded to error_log() (via the LegacyProxy) during $callback.
	 *
	 * The base test case registers an error_log spy on the mockable proxy, so the
	 * real error_log() is never called; the forwarded lines are captured in memory.
	 *
	 * @param callable $callback Code that may forward to error_log().
	 *
	 * @return string Captured output (forwarded lines joined by newlines).
	 */
	private function capture_error_log( callable $callback ): string {
		$this->forwarded_platform_logs = array();

		$callback();

		return implode( "\n", $this->get_forwarded_platform_logs() );
	}

	/**
	 * Without `$forward_to_platform_log = true`, log() must not write to the
	 * PHP error log, regardless of severity.
	 */
	public function test_log_does_not_forward_when_flag_unset(): void {
		$captured = $this->capture_error_log(
			static function () {
				FraudProtectionController::log( 'error', 'Local-only error' );
			}
		);

		$this->assertSame( '', trim( $captured ) );
	}

	/**
	 * Entries with the flag set must be forwarded as a `PHP Warning:` line
	 * carrying the plugin tag, the message, and the JSON-encoded sanitized
	 * context, with the parser-recognised `in <file> on line <N>` marker
	 * at the very end.
	 */
	public function test_log_forwards_to_platform_with_tag(): void {
		$captured = $this->capture_error_log(
			static function () {
				FraudProtectionController::log(
					'error',
					'Blackbox API request failed',
					array(
						'event_source' => 'api_verify',
						'error_code'   => 'http_request_failed',
					),
					true
				);
			}
		);

		$this->assertMatchesRegularExpression(
			'#PHP Warning: \[woo-fraud-protection error\] Blackbox API request failed \{[^}]+\} in \S+/woocommerce-fraud-protection\.php on line -20$#m',
			trim( $captured )
		);
		$this->assertStringContainsString( '"event_source":"api_verify"', $captured );
		$this->assertStringContainsString( '"error_code":"http_request_failed"', $captured );
	}

	/**
	 * With no allowlisted context, the JSON segment is omitted (no trailing
	 * empty braces) and the `in ... on line ...` marker still sits at the
	 * end so the host parser can extract `file` / `line`.
	 */
	public function test_log_forwards_without_json_when_context_empty(): void {
		$captured = $this->capture_error_log(
			static function () {
				FraudProtectionController::log( 'error', 'No context here', array(), true );
			}
		);

		$this->assertMatchesRegularExpression(
			'#PHP Warning: \[woo-fraud-protection error\] No context here in \S+/woocommerce-fraud-protection\.php on line -20$#m',
			trim( $captured )
		);
		$this->assertStringNotContainsString( '{}', $captured );
	}

	/**
	 * Each forwarded severity must encode its app-level into the trailing
	 * `on line <N>` field per the documented mapping.
	 *
	 * @dataProvider forwarded_level_to_line_code_provider
	 *
	 * @param string $level     Log level to forward.
	 * @param int    $line_code Expected encoded line number.
	 */
	public function test_log_encodes_app_level_in_line_code( string $level, int $line_code ): void {
		$captured = $this->capture_error_log(
			static function () use ( $level ) {
				FraudProtectionController::log( $level, 'severity test', array(), true );
			}
		);

		$this->assertMatchesRegularExpression(
			'#PHP Warning: \[woo-fraud-protection ' . preg_quote( $level, '#' ) . '\] severity test in \S+/woocommerce-fraud-protection\.php on line ' . $line_code . '$#m',
			trim( $captured )
		);
	}

	/**
	 * Provider for {@see test_log_encodes_app_level_in_line_code}.
	 *
	 * @return array<string, array{string, int}>
	 */
	public function forwarded_level_to_line_code_provider(): array {
		return array(
			'warning'   => array( 'warning', -10 ),
			'error'     => array( 'error', -20 ),
			'critical'  => array( 'critical', -30 ),
			'alert'     => array( 'alert', -40 ),
			'emergency' => array( 'emergency', -50 ),
		);
	}

	/**
	 * Levels that fall outside the documented mapping must still emit a
	 * forwarded line (using the `warning` line code) rather than being
	 * silently dropped at the formatting step.
	 */
	public function test_log_unmapped_level_falls_back_to_warning_code(): void {
		$captured = $this->capture_error_log(
			static function () {
				FraudProtectionController::log( 'info', 'unmapped level', array(), true );
			}
		);

		$this->assertMatchesRegularExpression(
			'#PHP Warning: \[woo-fraud-protection info\] unmapped level in \S+/woocommerce-fraud-protection\.php on line -10$#m',
			trim( $captured )
		);
	}

	/**
	 * Non-allowlisted context keys must not appear in the forwarded line.
	 */
	public function test_log_strips_non_allowlisted_context_when_forwarding(): void {
		$captured = $this->capture_error_log(
			static function () {
				FraudProtectionController::log(
					'error',
					'Test',
					array(
						'event_source' => 'api_verify',
						'email'        => 'shopper@example.com',
						'visitor_ip'   => '203.0.113.42',
						'payload'      => array( 'card' => 'tok_visa' ),
					),
					true
				);
			}
		);

		$this->assertStringContainsString( '"event_source":"api_verify"', $captured );
		$this->assertStringNotContainsString( 'shopper@example.com', $captured );
		$this->assertStringNotContainsString( '203.0.113.42', $captured );
		$this->assertStringNotContainsString( 'tok_visa', $captured );
	}

	/**
	 * The local WC log receives the entry even when platform forwarding is
	 * not requested.
	 */
	public function test_log_writes_local_wc_log_when_not_forwarding(): void {
		$logger = $this->getMockBuilder( \WC_Logger_Interface::class )->getMock();
		$logger->expects( $this->once() )
			->method( 'log' )
			->with(
				$this->equalTo( 'error' ),
				$this->equalTo( 'Local log entry' ),
				$this->callback(
					static function ( $context ) {
						return is_array( $context )
							&& 'woo-fraud-protection' === ( $context['source'] ?? null );
					}
				)
			);

		add_filter(
			'woocommerce_logging_class',
			static function () use ( $logger ) {
				return $logger;
			}
		);

		FraudProtectionController::log( 'error', 'Local log entry' );
	}
}
