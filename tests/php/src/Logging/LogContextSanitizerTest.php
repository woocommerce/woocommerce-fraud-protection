<?php

declare( strict_types=1 );

namespace Automattic\WooCommerce\FraudProtection\Tests\src\Logging;

use Automattic\WooCommerce\FraudProtection\Logging\LogContextSanitizer;

/**
 * Tests for {@see LogContextSanitizer}.
 */
class LogContextSanitizerTest extends \WC_Unit_Test_Case {

	/**
	 * Empty context should return an empty string.
	 */
	public function test_empty_context_returns_empty_string(): void {
		$this->assertSame( '', LogContextSanitizer::sanitize( array() ) );
	}

	/**
	 * Every allowlisted scalar key should round-trip through the sanitizer.
	 */
	public function test_allowlisted_scalar_keys_pass_through(): void {
		$context = array(
			'session_id'          => 'abc-123',
			'identity_id'         => 'customer_abc-30char-hash',
			'order_id'            => 4242,
			'source'              => 'blocks_checkout',
			'filter'              => 'woocommerce_fraud_protection_decision',
			'hook'                => 'session_verify',
			'callback_name'       => 'My_Plugin\\Compat::callback',
			'request_uri'         => '/verify',
			'decision'            => 'allow',
			'decision_received'   => 'maybe',
			'argument_type'       => 'array',
			'http_status'         => 503,
			'error_code'          => 'http_request_failed',
			'exception_class'     => 'RuntimeException',
			'exception_message'   => 'Something broke',
			'exception_file'      => '/srv/htdocs/wp-content/plugins/x/y.php',
			'exception_line'      => 142,
			'plugin_version'      => '0.1.3',
			'wp_version'          => '6.7.1',
			'php_version'         => '7.4.33',
			'wc_version'          => '10.6.0',
			'latency_ms'          => 1234,
			'gateway'             => 'stripe',
			'payment_type'        => 'card',
			'payment_method_slug' => 'woocommerce_payments',
		);

		$encoded = LogContextSanitizer::sanitize( $context );

		$decoded = json_decode( $encoded, true );
		$this->assertIsArray( $decoded );
		$this->assertSame( $context, $decoded );
	}

	/**
	 * Keys not on the allowlist must be dropped wholesale.
	 *
	 * @dataProvider non_allowlisted_keys_provider
	 *
	 * @param string $key   Key expected to be dropped.
	 * @param mixed  $value Value that would otherwise leak sensitive data.
	 */
	public function test_non_allowlisted_keys_are_dropped( string $key, $value ): void {
		$encoded = LogContextSanitizer::sanitize( array( $key => $value ) );
		$this->assertSame( '', $encoded, "Key '{$key}' should have been dropped" );
	}

	/**
	 * Provider for keys whose values are unbounded or contain personally
	 * identifying data and must never appear in forwarded context.
	 *
	 * @return array<string, array{string, mixed}>
	 */
	public function non_allowlisted_keys_provider(): array {
		return array(
			// PII fields.
			'email'            => array( 'email', 'shopper@example.com' ),
			'phone'            => array( 'phone', '+1-555-0000' ),
			'first_name'       => array( 'first_name', 'Ada' ),
			'last_name'        => array( 'last_name', 'Lovelace' ),
			'address_1'        => array( 'address_1', '1 Analytical Engine Ln' ),
			'postal_code'      => array( 'postal_code', '12345' ),
			'visitor_ip'       => array( 'visitor_ip', '203.0.113.42' ),
			'full_headers'     => array( 'full_headers', "User-Agent: test\r\nAccept: */*" ),

			// Unbounded structured blobs.
			'payload'          => array( 'payload', array( 'cart' => array( 'item' => 'x' ) ) ),
			'response'         => array( 'response', array( 'decision' => 'allow' ) ),
			'request_data'     => array( 'request_data', array( 'token' => 'tok_visa' ) ),
			'verify_context'   => array( 'verify_context', array( 'order_id' => 42 ) ),

			// Payment identifiers.
			'card_fingerprint' => array( 'card_fingerprint', 'fp_xyz' ),
			'last4'             => array( 'last4', '4242' ),

			// Raw exception / trace - safe pieces (class/message/file/line) are
			// extracted at the call site and passed under allowlisted keys.
			'exception'        => array( 'exception', new \RuntimeException( 'boom' ) ),
			'error'            => array( 'error', 'boom' ),
		);
	}

	/**
	 * Non-scalar values on allowlisted keys must still be dropped (defence in
	 * depth: if an allowlisted key ever receives a structured value, we don't
	 * recursively walk it).
	 */
	public function test_non_scalar_values_on_allowlisted_keys_are_dropped(): void {
		$context = array(
			'session_id'        => array( 'nested' => 'value' ),
			'exception_message' => (object) array( 'foo' => 'bar' ),
		);

		$this->assertSame( '', LogContextSanitizer::sanitize( $context ) );
	}

	/**
	 * Long string values should be truncated to the documented maximum.
	 */
	public function test_long_string_values_are_truncated(): void {
		$long  = str_repeat( 'a', 500 );
		$short = str_repeat( 'a', 200 );

		$encoded = LogContextSanitizer::sanitize( array( 'exception_message' => $long ) );
		$decoded = json_decode( $encoded, true );

		$this->assertIsArray( $decoded );
		$this->assertSame( $short, $decoded['exception_message'] );
	}

	/**
	 * Mixed input should keep only allowlisted scalars, dropping everything
	 * else.
	 */
	public function test_mixed_context_keeps_only_allowlisted_scalars(): void {
		$context = array(
			'session_id'        => 'abc-123',
			'gateway'           => 'stripe',
			'exception_message' => 'Boom',
			'email'             => 'shopper@example.com',
			'visitor_ip'        => '203.0.113.42',
			'payload'           => array( 'cart' => array( 'item' => 'x' ) ),
		);

		$encoded = LogContextSanitizer::sanitize( $context );
		$decoded = json_decode( $encoded, true );

		$this->assertIsArray( $decoded );
		// Sanitizer iterates ALLOWED_KEYS in declaration order, so output is
		// stably ordered by allowlist position (session_id, exception_message,
		// gateway) regardless of input order.
		$this->assertSame(
			array(
				'session_id'        => 'abc-123',
				'exception_message' => 'Boom',
				'gateway'           => 'stripe',
			),
			$decoded
		);
	}
}
