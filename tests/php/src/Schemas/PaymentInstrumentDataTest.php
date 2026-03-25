<?php
/**
 * PaymentInstrumentDataTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\FraudProtection\Schemas;

use Automattic\WooCommerce\FraudProtection\Schemas\PaymentInstrumentData;
use WC_Unit_Test_Case;

/**
 * Tests for the PaymentInstrumentData class.
 *
 * @covers \Automattic\WooCommerce\FraudProtection\Schemas\PaymentInstrumentData
 */
class PaymentInstrumentDataTest extends WC_Unit_Test_Case {

	/**
	 * @testdox from_array() sets all properties; to_array() returns correct values.
	 */
	public function test_from_array_and_to_array(): void {
		$instrument = PaymentInstrumentData::from_array(
			array(
				'brand'       => 'visa',
				'funding'     => 'credit',
				'last4'       => '4242',
				'fingerprint' => 'fp_abc123',
				'country'     => 'US',
				'exp_month'   => 12,
				'exp_year'    => 2025,
			)
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
				'wallet'           => null,
				'bank_code'        => null,
				'bin'                => null,
				'cvc_check'          => null,
				'avs_address_check'  => null,
				'avs_postcode_check' => null,
			),
			$instrument->to_array()
		);
	}

	/**
	 * @testdox Nullable fields default to null in to_array().
	 */
	public function test_nullable_fields_default_to_null(): void {
		$instrument = PaymentInstrumentData::from_array();

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
				'wallet'           => null,
				'bank_code'        => null,
				'bin'                => null,
				'cvc_check'          => null,
				'avs_address_check'  => null,
				'avs_postcode_check' => null,
			),
			$instrument->to_array()
		);
	}

	/**
	 * @testdox is_empty() returns true when all fields are null.
	 */
	public function test_is_empty_returns_true_when_all_null(): void {
		$this->assertTrue( PaymentInstrumentData::from_array()->is_empty() );
	}

	/**
	 * @testdox is_empty() returns false when any field is set.
	 */
	public function test_is_empty_returns_false_when_any_field_set(): void {
		$this->assertFalse( PaymentInstrumentData::from_array( array( 'brand' => 'visa' ) )->is_empty() );
	}

	/**
	 * @testdox sanitize_check() accepts valid check constants.
	 */
	public function test_sanitize_check_accepts_valid_values(): void {
		$instrument = PaymentInstrumentData::from_array(
			array(
				'cvc_check'          => PaymentInstrumentData::CHECK_PASS,
				'avs_address_check'  => PaymentInstrumentData::CHECK_FAIL,
				'avs_postcode_check' => PaymentInstrumentData::CHECK_UNAVAILABLE,
			)
		);

		$array = $instrument->to_array();
		$this->assertSame( PaymentInstrumentData::CHECK_PASS, $array['cvc_check'] );
		$this->assertSame( PaymentInstrumentData::CHECK_FAIL, $array['avs_address_check'] );
		$this->assertSame( PaymentInstrumentData::CHECK_UNAVAILABLE, $array['avs_postcode_check'] );
	}

	/**
	 * @testdox sanitize_check() drops unrecognized values to null (fail-open).
	 */
	public function test_sanitize_check_drops_invalid_values(): void {
		$instrument = PaymentInstrumentData::from_array(
			array(
				'cvc_check'         => 'invalid_value',
				'avs_address_check' => 'also_invalid',
			)
		);

		$array = $instrument->to_array();
		$this->assertNull( $array['cvc_check'] );
		$this->assertNull( $array['avs_address_check'] );
		$this->assertNull( $array['avs_postcode_check'] );
	}
}
