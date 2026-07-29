<?php
/**
 * RuleConditionsTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin\Rules;

use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Rules\RuleConditions;
use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;

/**
 * Tests for the RuleConditions class.
 */
class RuleConditionsTest extends FraudProtectionUnitTestCase {

	/**
	 * @testdox Should normalize a valid email condition, lowercasing and trimming the value.
	 */
	public function test_validates_and_normalizes_email_condition(): void {
		$result = RuleConditions::validate_and_normalize(
			array(
				'field'    => 'email',
				'operator' => 'equals',
				'value'    => '  Fraudster@Example.COM ',
			)
		);

		$this->assertSame(
			array(
				'field'    => 'email',
				'operator' => 'equals',
				'value'    => 'fraudster@example.com',
			),
			$result
		);
	}

	/**
	 * @testdox Should normalize a valid IP condition to its canonical text form.
	 * @dataProvider ip_normalization_data
	 *
	 * @param string $raw      The raw IP value.
	 * @param string $expected The expected canonical form.
	 */
	public function test_validates_and_normalizes_ip_condition( string $raw, string $expected ): void {
		$result = RuleConditions::validate_and_normalize(
			array(
				'field'    => 'ip',
				'operator' => 'equals',
				'value'    => $raw,
			)
		);

		$this->assertSame( $expected, $result['value'] ?? null );
	}

	/**
	 * Data provider for IP normalization tests.
	 *
	 * @return array
	 */
	public function ip_normalization_data(): array {
		return array(
			'plain IPv4'      => array( '203.0.113.7', '203.0.113.7' ),
			'padded IPv4'     => array( ' 203.0.113.7 ', '203.0.113.7' ),
			'expanded IPv6'   => array( '2001:0DB8:0000:0000:0000:0000:0000:0001', '2001:db8::1' ),
			'uppercase IPv6'  => array( '2001:DB8::1', '2001:db8::1' ),
			'compressed IPv6' => array( '::1', '::1' ),
		);
	}

	/**
	 * @testdox Should reject invalid condition documents.
	 * @dataProvider invalid_conditions_data
	 *
	 * @param mixed $conditions The invalid condition document.
	 */
	public function test_rejects_invalid_conditions( $conditions ): void {
		$this->assertNull( RuleConditions::validate_and_normalize( $conditions ) );
	}

	/**
	 * Data provider for invalid condition documents.
	 *
	 * @return array
	 */
	public function invalid_conditions_data(): array {
		$valid = array(
			'field'    => 'email',
			'operator' => 'equals',
			'value'    => 'someone@example.com',
		);

		return array(
			'not an array'         => array( 'email equals someone@example.com' ),
			'empty array'          => array( array() ),
			'missing value'        => array( array_diff_key( $valid, array( 'value' => true ) ) ),
			'unknown field'        => array( array_merge( $valid, array( 'field' => 'billing_country' ) ) ),
			'unknown operator'     => array( array_merge( $valid, array( 'operator' => 'wildcard' ) ) ),
			'non-string value'     => array( array_merge( $valid, array( 'value' => 42 ) ) ),
			'empty value'          => array( array_merge( $valid, array( 'value' => '  ' ) ) ),
			'email without at'     => array( array_merge( $valid, array( 'value' => 'not-an-email' ) ) ),
			'email with spaces'    => array( array_merge( $valid, array( 'value' => 'some one@example.com' ) ) ),
			'overlong email'       => array( array_merge( $valid, array( 'value' => str_repeat( 'a', 250 ) . '@example.com' ) ) ),
			'invalid IP'           => array(
				array(
					'field'    => 'ip',
					'operator' => 'equals',
					'value'    => '999.0.113.7',
				),
			),
			'hostname as IP'       => array(
				array(
					'field'    => 'ip',
					'operator' => 'equals',
					'value'    => 'example.com',
				),
			),
			'nested compound rule' => array(
				array(
					'operator' => 'and',
					'checks'   => array( $valid ),
					'extra'    => true,
				),
			),
		);
	}

	/**
	 * @testdox Should accept extra keys, stripping them from the normalized document.
	 */
	public function test_strips_extra_keys(): void {
		$result = RuleConditions::validate_and_normalize(
			array(
				'field'    => 'email',
				'operator' => 'equals',
				'value'    => 'someone@example.com',
				'position' => 1,
			)
		);

		$this->assertSame(
			array(
				'field'    => 'email',
				'operator' => 'equals',
				'value'    => 'someone@example.com',
			),
			$result,
			'Extra keys must not survive into the normalized document'
		);
	}

	/**
	 * @testdox Should produce the same hash for the same conditions regardless of key order.
	 */
	public function test_hash_is_stable_across_key_order(): void {
		$hash_a = RuleConditions::hash(
			array(
				'field'    => 'email',
				'operator' => 'equals',
				'value'    => 'someone@example.com',
			)
		);
		$hash_b = RuleConditions::hash(
			array(
				'value'    => 'someone@example.com',
				'field'    => 'email',
				'operator' => 'equals',
			)
		);

		$this->assertSame( $hash_a, $hash_b, 'Key order must not change the hash' );
		$this->assertSame( 64, strlen( $hash_a ), 'The hash must be a 64-character SHA-256 hex digest' );
	}

	/**
	 * @testdox Should produce different hashes for different condition values.
	 */
	public function test_hash_differs_for_different_conditions(): void {
		$base = array(
			'field'    => 'email',
			'operator' => 'equals',
			'value'    => 'someone@example.com',
		);

		$this->assertNotSame(
			RuleConditions::hash( $base ),
			RuleConditions::hash( array_merge( $base, array( 'value' => 'other@example.com' ) ) )
		);
	}

	/**
	 * @testdox normalize_value() should return null for values invalid for the field.
	 */
	public function test_normalize_value_rejects_invalid_values(): void {
		$this->assertNull( RuleConditions::normalize_value( 'email', '' ) );
		$this->assertNull( RuleConditions::normalize_value( 'email', 'no-at-sign' ) );
		$this->assertNull( RuleConditions::normalize_value( 'ip', 'not-an-ip' ) );
	}
}
