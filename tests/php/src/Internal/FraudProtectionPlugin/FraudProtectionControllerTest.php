<?php

declare( strict_types=1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin;

use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Compat\PayPalDecisionReuse;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Compat\PayPalScriptCompat;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\FraudProtectionController;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Sessions\SessionIdentityManager;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Settings\FraudProtectionSettingsPage;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Settings\MerchantFacingFeaturesGate;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Settings\SettingsRestController;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Settings\SettingsTelemetry;

/**
 * Tests for the FraudProtectionController class.
 */
class FraudProtectionControllerTest extends FraudProtectionUnitTestCase {

	/**
	 * The System Under Test.
	 *
	 * @var FraudProtectionController
	 */
	private $sut;

	/**
	 * These tests exercise the controller's real logging implementation, so the
	 * static facade must resolve to a real controller rather than the in-memory spy.
	 *
	 * @return bool
	 */
	protected function uses_logging_spy(): bool {
		return false;
	}

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
			WC()->session->set( SessionIdentityManager::CUSTOMER_IDENTITY_ID_KEY, null );
		}

		$this->sut = wc_get_container()->get( FraudProtectionController::class );
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
	}

	/**
	 * Test that the payment gateway compatibility layers are wired through the
	 * controller and have their hooks registered during the bootstrap (rather
	 * than being registered directly by the plugin initializer).
	 */
	public function test_compat_layer_hooks_are_registered(): void {
		$container = wc_get_container();
		$this->sut->register();

		// Payment-data resolvers (Stripe, Square, PayPal, WooPayments) all hook this filter.
		$this->assertNotFalse(
			has_filter( 'woocommerce_fraud_protection_resolved_payment_data' ),
			'Payment data compat filter should be registered via the controller'
		);

		// PayPal express checkout compat action.
		$this->assertNotFalse(
			has_action( 'woocommerce_paypal_payments_create_order_request_started' ),
			'PayPal compat action should be registered via the controller'
		);
		$this->assertSame(
			10,
			has_filter(
				'woocommerce_fraud_protection_skip_session_verify',
				array( $container->get( PayPalDecisionReuse::class ), 'supply_decision_for_paypal_express' )
			),
			'PayPal decision reuse filter should be registered via the controller'
		);
		$this->assertSame(
			10,
			has_action(
				'woocommerce_paypal_payments_single_product_button_render',
				array( $container->get( PayPalScriptCompat::class ), 'enqueue_paypal_script' )
			),
			'PayPal script action should be registered via the controller'
		);

		// Subscriptions change-payment-method compat action.
		$this->assertNotFalse(
			has_action( 'woocommerce_subscription_change_payment_method_via_pay_shortcode' ),
			'Subscriptions change-payment compat action should be registered via the controller'
		);
		$this->assertNotFalse(
			has_action( 'woocommerce_subscriptions_change_payment_after_submit' ),
			'Subscriptions change-payment form render action should be registered via the controller'
		);
	}

	/**
	 * Test that register method registers init action.
	 */
	public function test_register_registers_init_action(): void {
		$this->sut->register();

		// Check if the init action is registered for our callback.
		$priority = has_action( 'init', array( $this->sut, 'handle_init' ) );

		// The priority should be 10 (default).
		$this->assertSame( 10, $priority, 'Init action should be registered with default priority 10' );
	}

	/**
	 * @testdox Settings telemetry registers while merchant-facing settings stay disabled by default.
	 */
	public function test_default_gate_registers_telemetry_without_merchant_surfaces(): void {
		$container = wc_get_container();
		$container->get( MerchantFacingFeaturesGate::class )->reset();

		$this->sut->handle_init();

		$this->assertNotFalse( has_filter( 'woocommerce_tracker_data', array( $container->get( SettingsTelemetry::class ), 'add_tracker_data' ) ) );
		$this->assertFalse( has_action( 'rest_api_init', array( $container->get( SettingsRestController::class ), 'register_routes' ) ) );
		$this->assertFalse( has_filter( 'woocommerce_get_settings_pages', array( $this->sut, 'add_settings_page' ) ) );
	}

	/**
	 * @testdox Enabling merchant-facing features registers the page and settings endpoint.
	 */
	public function test_enabled_gate_registers_page_and_endpoint(): void {
		$container = wc_get_container();
		$feature   = $container->get( MerchantFacingFeaturesGate::class );
		$feature->set_enabled( true );

		$this->sut->handle_init();

		$this->assertNotFalse( has_filter( 'woocommerce_get_settings_pages', array( $this->sut, 'add_settings_page' ) ) );
		$this->assertNotFalse( has_action( 'rest_api_init', array( $container->get( SettingsRestController::class ), 'register_routes' ) ) );
		$pages = apply_filters( 'woocommerce_get_settings_pages', array() );
		$this->assertContains( $container->get( FraudProtectionSettingsPage::class ), $pages );
	}

	/**
	 * Test that feature_is_enabled returns true when feature is enabled.
	 */
	public function test_feature_is_enabled_returns_true_when_enabled(): void {
		// Enable the feature.
		update_option( 'woocommerce_feature_fraud_protection_enabled', 'yes' );

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

		// Standalone plugin is always enabled.
		$this->assertTrue( FraudProtectionController::feature_is_enabled() );
	}

	/**
	 * @testdox The static log facade delegates to the active logger.
	 */
	public function test_static_log_facade_delegates_to_active_logger(): void {
		$spy = $this->spy_on_controller_logging();

		FraudProtectionController::log( 'warning', 'Routed message', array( 'foo' => 'bar' ), true );

		$this->assertCount( 1, $spy->entries, 'The active logger should receive one log call.' );
		$this->assertSame( 'warning', $spy->entries[0]['level'] );
		$this->assertSame( 'Routed message', $spy->entries[0]['message'] );
		$this->assertSame( array( 'foo' => 'bar' ), $spy->entries[0]['context'] );
		$this->assertTrue( $spy->entries[0]['forwarded'] );
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
		$messages = $this->capture_logged_messages();

		WC()->session->set( SessionIdentityManager::CUSTOMER_IDENTITY_ID_KEY, 'test-identity-123' );

		FraudProtectionController::log( 'info', 'Test message' );

		$this->assertContains( 'Identity: test-identity-123 | Test message', $messages );
	}

	/**
	 * @testdox Log identity prefixes use the accepted 255-byte value.
	 */
	public function test_log_truncates_valid_long_identity_prefix(): void {
		$messages = $this->capture_logged_messages();
		$prefix   = str_repeat( 'a', 255 );
		WC()->session->set( SessionIdentityManager::CUSTOMER_IDENTITY_ID_KEY, $prefix . 'tail' );

		FraudProtectionController::log( 'info', 'Test message' );

		$this->assertContains( 'Identity: ' . $prefix . ' | Test message', $messages );
	}

	/**
	 * @testdox Rejected legacy identities are omitted from local and forwarded log messages.
	 */
	public function test_log_omits_rejected_identity_prefix(): void {
		$messages = $this->capture_logged_messages();
		WC()->session->set( SessionIdentityManager::CUSTOMER_IDENTITY_ID_KEY, 'invalid identity' );

		$forwarded = $this->capture_error_log(
			static function () {
				FraudProtectionController::log( 'error', 'Test message', array(), true );
			}
		);

		$this->assertContains( 'Test message', $messages );
		$this->assertStringNotContainsString( 'invalid identity', $messages[0] );
		$this->assertStringNotContainsString( 'invalid identity', $forwarded );
	}

	/**
	 * Test that log message has no prefix when identity ID is not in session.
	 */
	public function test_log_has_no_prefix_when_identity_id_not_in_session(): void {
		$messages = $this->capture_logged_messages();

		// Ensure no identity ID is set.
		WC()->session->set( SessionIdentityManager::CUSTOMER_IDENTITY_ID_KEY, null );

		FraudProtectionController::log( 'info', 'Test message' );

		$this->assertContains( 'Test message', $messages );
		$this->assertStringStartsNotWith( 'Identity:', $messages[0] );
	}

	/**
	 * Install a real WC logger mock that records every logged message.
	 *
	 * Used to assert on the final message produced by the logger service
	 * (e.g. the identity prefix), which only exists once the real logging
	 * path runs, so these tests use the real logger rather than the in-memory logger.
	 *
	 * @return \ArrayObject<int, string> Populated with logged messages, in order, as they are recorded.
	 */
	private function capture_logged_messages(): \ArrayObject {
		$messages = new \ArrayObject();

		$logger = $this->getMockBuilder( \WC_Logger_Interface::class )->getMock();
		$logger->method( 'log' )->willReturnCallback(
			static function ( $level, $message ) use ( $messages ) {
				$messages[] = $message;
			}
		);

		add_filter(
			'woocommerce_logging_class',
			static function () use ( $logger ) {
				return $logger;
			}
		);

		return $messages;
	}

	/**
	 * Cleanup after test.
	 */
	public function tearDown(): void {
		if ( WC()->session ) {
			WC()->session->set( SessionIdentityManager::CUSTOMER_IDENTITY_ID_KEY, null );
		}

		parent::tearDown();
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

	/**
	 * @testdox log() keeps the context readable when a value cannot be encoded.
	 *
	 * WooCommerce renders the context into the log line with wp_json_encode() and interpolates
	 * the result, so one unencodable value makes that return false and the *entire* context
	 * disappears from the entry — the log that would have explained the problem arrives empty.
	 * Every log call in the plugin routes through here, so this is guarded once rather than at
	 * each of the payload-carrying sites.
	 */
	public function test_log_replaces_unencodable_context_values_with_a_marker(): void {
		$captured = null;
		$logger   = $this->getMockBuilder( \WC_Logger_Interface::class )->getMock();
		$logger->method( 'log' )->willReturnCallback(
			function ( $level, $message, $context ) use ( &$captured ) {
				$captured = $context;
			}
		);

		add_filter(
			'woocommerce_logging_class',
			static function () use ( $logger ) {
				return $logger;
			}
		);

		FraudProtectionController::log(
			'error',
			'Entry whose context holds an unencodable value',
			array(
				'session_id' => 'abc',
				'payload'    => array( 'order' => array( 'tax_total' => INF ) ),
			)
		);

		$this->assertIsArray( $captured );
		$this->assertSame( 'abc', $captured['session_id'], 'the rest of the context must survive' );
		$this->assertSame(
			'[unencodable: INF]',
			$captured['payload']['order']['tax_total'],
			'the offending value is named, not silently removed'
		);
		$this->assertNotFalse(
			wp_json_encode( $captured ),
			'the context WooCommerce renders must now encode'
		);
	}
}
