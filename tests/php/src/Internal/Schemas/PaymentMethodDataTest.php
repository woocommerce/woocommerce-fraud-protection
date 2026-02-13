<?php
/**
 * PaymentMethodDataTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Schemas;

use Automattic\WooCommerce\Internal\Schemas\CardPaymentMethodData;
use Automattic\WooCommerce\Internal\Schemas\PaymentMethodData;
use WC_Unit_Test_Case;

/**
 * Tests for the PaymentMethodData class.
 *
 * @covers \Automattic\WooCommerce\Internal\Schemas\PaymentMethodData
 */
class PaymentMethodDataTest extends WC_Unit_Test_Case {

	/**
	 * @testdox Constructor sets all properties; to_array() returns correct values.
	 */
	public function test_constructor_and_to_array(): void {
		$card = new CardPaymentMethodData( 'visa', 'credit', '4242' );
		$data = new PaymentMethodData( 'stripe', 'card', true, $card );

		$result = $data->to_array();

		$this->assertSame( 'card', $result['payment_type'] );
		$this->assertTrue( $result['is_saved_payment_method'] );
		$this->assertIsArray( $result['card'] );
		$this->assertSame( 'visa', $result['card']['brand'] );
	}

	/**
	 * @testdox Default is_saved_payment_method is false.
	 */
	public function test_default_is_saved_payment_method_is_false(): void {
		$data = new PaymentMethodData( 'stripe', 'card' );

		$this->assertFalse( $data->to_array()['is_saved_payment_method'] );
	}

	/**
	 * @testdox to_array() produces null card when no card data.
	 */
	public function test_to_array_null_card(): void {
		$data = new PaymentMethodData( 'stripe_ideal', 'ideal', false );

		$result = $data->to_array();

		$this->assertSame( 'ideal', $result['payment_type'] );
		$this->assertFalse( $result['is_saved_payment_method'] );
		$this->assertNull( $result['card'] );
	}

	/**
	 * @testdox to_array() includes full card data when card is present.
	 */
	public function test_to_array_with_full_card(): void {
		$card = new CardPaymentMethodData( 'mastercard', 'debit', '5678', 'fp_abc', 'GB', 11, 2026 );
		$data = new PaymentMethodData( 'stripe', 'card', true, $card );

		$this->assertSame(
			array(
				'gateway'                 => 'stripe',
				'payment_type'            => 'card',
				'is_saved_payment_method' => true,
				'card'                    => array(
					'brand'            => 'mastercard',
					'funding'          => 'debit',
					'last4'            => '5678',
					'fingerprint'      => 'fp_abc',
					'country'          => 'GB',
					'exp_month'        => 11,
					'exp_year'         => 2026,
					'billing_postcode' => null,
				),
			),
			$data->to_array()
		);
	}
}
