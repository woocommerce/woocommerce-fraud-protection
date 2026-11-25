<?php
/**
 * GatewayIdPatternTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin;

use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\GatewayIdPattern;

/**
 * Unit coverage for the gateway ID pattern matcher.
 *
 * GatewayPaymentDataTest and GatewayRequestVerifierTest cover how the registrations use it; this
 * pins which pattern wins for a gateway ID.
 */
class GatewayIdPatternTest extends FraudProtectionUnitTestCase {

	/**
	 * @testdox find_best_match() returns the exact gateway ID first, else the longest matching prefix.
	 *
	 * @dataProvider provide_matches
	 *
	 * @param array<int|string> $patterns   The registered patterns.
	 * @param string            $gateway_id The gateway ID to match.
	 * @param string|null       $expected   The expected pattern.
	 */
	public function test_find_best_match( array $patterns, string $gateway_id, ?string $expected ): void {
		$this->assertSame( $expected, GatewayIdPattern::find_best_match( $patterns, $gateway_id ) );
	}

	/**
	 * Data provider for {@see test_find_best_match()}.
	 *
	 * @return array<string, array{0: array<int|string>, 1: string, 2: string|null}>
	 */
	public function provide_matches(): array {
		return array(
			'exact ID'                               => array( array( 'stripe' ), 'stripe', 'stripe' ),
			'another exact ID'                       => array( array( 'stripe' ), 'stripe_sepa', null ),
			'prefix'                                 => array( array( 'stripe_*' ), 'stripe_sepa', 'stripe_*' ),
			'prefix without the separator'           => array( array( 'stripe*' ), 'stripe', 'stripe*' ),
			'prefix that does not match'             => array( array( 'stripe_*' ), 'stripe', null ),
			'exact ID before a prefix'               => array( array( 'stripe_*', 'stripe_sepa' ), 'stripe_sepa', 'stripe_sepa' ),
			'longer prefix before a shorter one'     => array( array( 'stripe*', 'stripe_sepa*', 'stripe_*' ), 'stripe_sepa_debit', 'stripe_sepa*' ),
			'asterisk alone'                         => array( array( '*' ), 'stripe', null ),
			'asterisk inside an ID'                  => array( array( 'str*pe' ), 'stripe', null ),
			'asterisk inside an ID, matched exactly' => array( array( 'str*pe' ), 'str*pe', 'str*pe' ),
			'numeric ID from an array key'           => array( array( 123 ), '123', '123' ),
			'no patterns'                            => array( array(), 'stripe', null ),
		);
	}
}
