<?php
/**
 * PaymentInstrumentDataTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\FraudProtection\Schemas;

use Automattic\WooCommerce\FraudProtection\Schemas\CheckResult;
use Automattic\WooCommerce\FraudProtection\Schemas\PaymentInstrumentData;
use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;

/**
 * Tests for the PaymentInstrumentData class.
 *
 * @covers \Automattic\WooCommerce\FraudProtection\Schemas\PaymentInstrumentData
 */
class PaymentInstrumentDataTest extends FraudProtectionUnitTestCase {

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
	 * @testdox sanitize_check() accepts valid check values.
	 */
	public function test_sanitize_check_accepts_valid_values(): void {
		$instrument = PaymentInstrumentData::from_array(
			array(
				'cvc_check'          => CheckResult::Pass->value,
				'avs_address_check'  => CheckResult::Fail->value,
				'avs_postcode_check' => CheckResult::Unavailable->value,
			)
		);

		$array = $instrument->to_array();
		$this->assertSame( CheckResult::Pass->value, $array['cvc_check'] );
		$this->assertSame( CheckResult::Fail->value, $array['avs_address_check'] );
		$this->assertSame( CheckResult::Unavailable->value, $array['avs_postcode_check'] );
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

	/**
	 * @testdox from_array() coerces a numeric string field to string and logs a warning.
	 */
	public function test_numeric_field_is_coerced_and_logged(): void {
		$instrument = PaymentInstrumentData::from_array(
			array(
				'bin'   => 424242,
				'last4' => 4242,
			)
		);

		$array = $instrument->to_array();
		$this->assertSame( '424242', $array['bin'], 'a numeric bin is coerced to string, preserving the value' );
		$this->assertSame( '4242', $array['last4'] );
		$this->assertLogged( 'warning', 'Coerced PaymentInstrumentData field "bin" from integer to string.' );
	}

	/**
	 * @testdox from_array() drops an unsupported-type string field to null and logs an error.
	 */
	public function test_malformed_string_field_is_dropped_and_logged(): void {
		$instrument = PaymentInstrumentData::from_array(
			array(
				'fingerprint' => array( 'nested' => 'value' ),
				'brand'       => true,
			)
		);

		$array = $instrument->to_array();
		$this->assertNull( $array['fingerprint'], 'an array fingerprint is dropped, not thrown on' );
		$this->assertNull( $array['brand'], 'a boolean brand is dropped' );
		$this->assertLogged( 'error', 'Dropped PaymentInstrumentData field "fingerprint" with unsupported type array.' );
		$this->assertLogged( 'error', 'Dropped PaymentInstrumentData field "brand" with unsupported type boolean.' );
	}

	/**
	 * @testdox from_array() drops a non-integer expiry field to null and logs an error.
	 */
	public function test_non_integer_expiry_field_is_dropped_and_logged(): void {
		$instrument = PaymentInstrumentData::from_array(
			array(
				'exp_month' => 'not-a-month',
				'exp_year'  => array( 2025 ),
			)
		);

		$array = $instrument->to_array();
		$this->assertNull( $array['exp_month'] );
		$this->assertNull( $array['exp_year'] );
		$this->assertLogged( 'error', 'Dropped PaymentInstrumentData field "exp_month" with a non-integer value (string).' );
		$this->assertLogged( 'error', 'Dropped PaymentInstrumentData field "exp_year" with a non-integer value (array).' );
	}

	/**
	 * @testdox from_array() drops a fractional expiry rather than silently truncating it.
	 */
	public function test_fractional_expiry_field_is_dropped_and_logged(): void {
		$instrument = PaymentInstrumentData::from_array(
			array(
				'exp_month' => 12.5,
				'exp_year'  => 2025,
			)
		);

		$array = $instrument->to_array();
		$this->assertNull( $array['exp_month'], 'a fractional exp_month is dropped, not truncated to 12' );
		$this->assertSame( 2025, $array['exp_year'], 'a whole exp_year still passes' );
		$this->assertLogged( 'error', 'Dropped PaymentInstrumentData field "exp_month" with a non-integer value (double).' );
	}

	/**
	 * @testdox from_array() never throws on a fully malformed instrument; valid siblings survive.
	 */
	public function test_from_array_does_not_throw_on_malformed_input(): void {
		$instrument = PaymentInstrumentData::from_array(
			array(
				'brand'       => array( 'x' ),
				'last4'       => false,
				'fingerprint' => array( 1, 2 ),
				'exp_month'   => array(),
				'cvc_check'   => array( 'bad' ),
				'bin'         => '424242',
			)
		);

		// Reaching to_array() at all proves no TypeError escaped the strict constructor.
		$array = $instrument->to_array();
		$this->assertNull( $array['brand'] );
		$this->assertNull( $array['fingerprint'] );
		$this->assertNull( $array['cvc_check'] );
		$this->assertSame( '424242', $array['bin'], 'the one valid field survives the malformed siblings' );
	}
}
