<?php
/**
 * SessionEventFixtures class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\FraudProtection\Tests\Support;

/**
 * Builds session event rows for tests that write to the sessions table.
 */
class SessionEventFixtures {

	/**
	 * A complete event row with overridable fields, as accepted by SessionEventStore::record_event().
	 *
	 * @param array<string, mixed> $overrides Fields to override.
	 * @return array<string, mixed>
	 */
	public static function an_event( array $overrides = array() ): array {
		return array_merge(
			array(
				'session_id'       => 'session-abc',
				'source'           => 'blocks_checkout',
				'decision'         => 'block',
				'final_status'     => 'allowed',
				'trigger_type'     => 'blackbox',
				'risk_score'       => 0.91,
				'email'            => 'customer@example.com',
				'ip'               => '203.0.113.9',
				'ip_country'       => 'ES',
				'billing_country'  => 'US',
				'billing_state'    => 'CA',
				'billing_city'     => 'San Francisco',
				'billing_postcode' => '94110',
				'billing_name'     => 'Jane Doe',
				'order_id'         => 123,
				'payment_method'   => 'woocommerce_payments',
			),
			$overrides
		);
	}
}
