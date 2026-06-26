<?php
/**
 * PaymentMethodDataTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin\Schemas;

use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Schemas\PaymentInstrumentData;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Schemas\PaymentMethodData;
use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;

/**
 * Tests for the PaymentMethodData class.
 *
 * @covers \Automattic\WooCommerce\Internal\FraudProtectionPlugin\Schemas\PaymentMethodData
 */
class PaymentMethodDataTest extends FraudProtectionUnitTestCase {

	/**
	 * @testdox Constructor sets all properties; to_array() returns correct values.
	 */
	public function test_constructor_and_to_array(): void {
		$instrument = PaymentInstrumentData::from_array( array( 'brand' => 'visa', 'funding' => 'credit', 'last4' => '4242' ) );
		$data       = new PaymentMethodData( 'stripe', 'card', true, $instrument );

		$result = $data->to_array();

		$this->assertSame( 'card', $result['payment_type'] );
		$this->assertTrue( $result['is_saved_payment_method'] );
		$this->assertIsArray( $result['instrument'] );
		$this->assertSame( 'visa', $result['instrument']['brand'] );
	}

	/**
	 * @testdox Default is_saved_payment_method is false.
	 */
	public function test_default_is_saved_payment_method_is_false(): void {
		$data = new PaymentMethodData( 'stripe', 'card' );

		$this->assertFalse( $data->to_array()['is_saved_payment_method'] );
	}

	/**
	 * @testdox to_array() produces empty instrument when no instrument data.
	 */
	public function test_to_array_empty_instrument(): void {
		$data = new PaymentMethodData( 'stripe_ideal', 'ideal', false );

		$result = $data->to_array();

		$this->assertSame( 'ideal', $result['payment_type'] );
		$this->assertFalse( $result['is_saved_payment_method'] );
		$this->assertSame( PaymentInstrumentData::empty()->to_array(), $result['instrument'] );
	}

	/**
	 * @testdox to_array() includes full instrument data when instrument is present.
	 */
	public function test_to_array_with_full_instrument(): void {
		$instrument = PaymentInstrumentData::from_array( array( 'brand' => 'mastercard', 'funding' => 'debit', 'last4' => '5678', 'fingerprint' => 'fp_abc', 'country' => 'GB', 'exp_month' => 11, 'exp_year' => 2026 ) );
		$data       = new PaymentMethodData( 'stripe', 'card', true, $instrument );

		$this->assertSame(
			array(
				'gateway'                 => 'stripe',
				'payment_type'            => 'card',
				'is_saved_payment_method' => true,
				'instrument'              => array(
					'brand'            => 'mastercard',
					'funding'          => 'debit',
					'last4'            => '5678',
					'fingerprint'      => 'fp_abc',
					'country'          => 'GB',
					'exp_month'        => 11,
					'exp_year'         => 2026,
					'billing_postcode' => null,
					'wallet'           => null,
					'bank_code'        => null,
					'bin'                => null,
					'cvc_check'          => null,
					'avs_address_check'  => null,
					'avs_postcode_check' => null,
				),
				'transaction_mode'        => PaymentMethodData::MODE_UNKNOWN,
			),
			$data->to_array()
		);
	}

	/**
	 * @testdox Invalid transaction_mode falls back to unknown.
	 */
	public function test_invalid_transaction_mode_falls_back_to_unknown(): void {
		$data = new PaymentMethodData( 'stripe', 'card', false, null, 'sandbox' );

		$this->assertSame( PaymentMethodData::MODE_UNKNOWN, $data->to_array()['transaction_mode'] );
	}

	/**
	 * @testdox with_transaction_mode() validates the mode value.
	 */
	public function test_with_transaction_mode_validates(): void {
		$data   = new PaymentMethodData( 'stripe', 'card' );
		$result = $data->with_transaction_mode( 'invalid' );

		$this->assertSame( PaymentMethodData::MODE_UNKNOWN, $result->to_array()['transaction_mode'] );
	}

	/**
	 * @testdox Valid transaction_mode values are preserved.
	 */
	public function test_valid_transaction_modes_preserved(): void {
		$test    = new PaymentMethodData( 'stripe', 'card', false, null, PaymentMethodData::MODE_TEST );
		$live    = new PaymentMethodData( 'stripe', 'card', false, null, PaymentMethodData::MODE_LIVE );
		$unknown = new PaymentMethodData( 'stripe', 'card', false, null, PaymentMethodData::MODE_UNKNOWN );

		$this->assertSame( PaymentMethodData::MODE_TEST, $test->to_array()['transaction_mode'] );
		$this->assertSame( PaymentMethodData::MODE_LIVE, $live->to_array()['transaction_mode'] );
		$this->assertSame( PaymentMethodData::MODE_UNKNOWN, $unknown->to_array()['transaction_mode'] );
	}

	/**
	 * @testdox with_transaction_mode() preserves all other fields.
	 */
	public function test_with_transaction_mode_preserves_fields(): void {
		$instrument = PaymentInstrumentData::from_array( array( 'brand' => 'visa', 'funding' => 'credit', 'last4' => '4242', 'fingerprint' => 'fp_abc', 'country' => 'US', 'exp_month' => 12, 'exp_year' => 2028, 'billing_postcode' => '10001' ) );
		$original   = new PaymentMethodData( 'stripe', 'card', true, $instrument );

		$result = $original->with_transaction_mode( PaymentMethodData::MODE_TEST );

		$expected = array_merge(
			$original->to_array(),
			array( 'transaction_mode' => PaymentMethodData::MODE_TEST )
		);

		$this->assertSame( $expected, $result->to_array() );
	}
}
