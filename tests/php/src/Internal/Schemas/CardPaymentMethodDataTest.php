<?php
/**
 * CardPaymentMethodDataTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Schemas;

use Automattic\WooCommerce\FraudProtection\Schemas\CardPaymentMethodData;
use WC_Unit_Test_Case;

/**
 * Tests for the CardPaymentMethodData class.
 *
 * @covers \Automattic\WooCommerce\FraudProtection\Schemas\CardPaymentMethodData
 */
class CardPaymentMethodDataTest extends WC_Unit_Test_Case {

	/**
	 * @testdox Constructor sets all properties; to_array() returns correct values.
	 */
	public function test_constructor_and_to_array(): void {
		$card = new CardPaymentMethodData(
			'visa',
			'credit',
			'4242',
			'fp_abc123',
			'US',
			12,
			2025
		);

		$this->assertSame(
			array(
				'brand'            => 'visa',
				'funding'          => 'credit',
				'last4'            => '4242',
				'fingerprint'      => 'fp_abc123',
				'country'          => 'US',
				'exp_month'        => 12,
				'exp_year'         => 2025,
				'billing_postcode' => null,
			),
			$card->to_array()
		);
	}

	/**
	 * @testdox Nullable fields default to null in to_array().
	 */
	public function test_nullable_fields_default_to_null(): void {
		$card = new CardPaymentMethodData();

		$this->assertSame(
			array(
				'brand'            => null,
				'funding'          => null,
				'last4'            => null,
				'fingerprint'      => null,
				'country'          => null,
				'exp_month'        => null,
				'exp_year'         => null,
				'billing_postcode' => null,
			),
			$card->to_array()
		);
	}
}
