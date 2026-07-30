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

	/**
	 * @testdox A field the caller never supplied is left unset in silence.
	 *
	 * The trait documents twice that only a provided value is logged. Without it, every optional
	 * field an integration leaves out is reported as a problem on every call.
	 */
	public function test_an_absent_field_is_left_unset_in_silence(): void {
		$spy = $this->spy_on_controller_logging();

		$instrument = PaymentInstrumentData::from_array( array( 'exp_year' => 2030 ) );

		$this->assertSame( 2030, $instrument->to_array()['exp_year'], 'the supplied field is still read' );
		$this->assertSame( array(), $spy->entries, 'an unsupplied field is not a problem to report' );
	}

	/**
	 * @testdox An expiry is set only from a whole number the int field can hold.
	 *
	 * @dataProvider provide_expiry_readings
	 *
	 * @param mixed $value    Raw exp_year value.
	 * @param ?int  $expected The expiry expected on the wire, or null when the value has none.
	 */
	public function test_expiry_is_set_only_from_a_representable_whole_number( mixed $value, ?int $expected ): void {
		$instrument = PaymentInstrumentData::from_array( array( 'exp_year' => $value ) );

		$this->assertSame( $expected, $instrument->to_array()['exp_year'] );
	}

	/**
	 * Data provider for {@see test_expiry_is_set_only_from_a_representable_whole_number()}.
	 *
	 * `exp_year` is the one field that reports the shared int sanitizer's answer unfiltered, so
	 * the boundary rows belong here. There are three shapes to pin, and the rows are grouped by
	 * which one answers.
	 *
	 * @return array<string, array{0: mixed, 1: ?int}>
	 */
	public function provide_expiry_readings(): array {
		return array(
			// An int is taken as given, ahead of everything else. Read through a float instead,
			// PHP_INT_MAX rounds up out of its own type and anything past 2^53 loses digits.
			'whole year'                             => array( 2025, 2025 ),
			'integer maximum'                        => array( PHP_INT_MAX, PHP_INT_MAX ),
			'integer beyond float precision'         => array( 9007199254740993, 9007199254740993 ),

			// An integer written out is relayed by its digits, so it survives past 2^53 too.
			'numeric string'                         => array( '2025', 2025 ),
			'integer string beyond float precision'  => array( '9223372036854774785', 9223372036854774785 ),
			'integer maximum as a string'            => array( '9223372036854775807', PHP_INT_MAX ),
			'integer minimum as a string'            => array( '-9223372036854775808', PHP_INT_MIN ),
			// Notation, not value: whitespace, a sign and leading zeros are normalised away
			// before the range is tested, so none of them decides the answer.
			'zero-padded integer string'             => array( '05', 5 ),
			'integer string in whitespace'           => array( "  5\n", 5 ),
			'signed integer string'                  => array( '+5', 5 ),
			'zero-padded negative integer string'    => array( '-007', -7 ),
			'every digit a zero'                     => array( '000', 0 ),
			// A written-out integer the type cannot carry has no reading that would fit, so it
			// is refused rather than saturated to the nearest edge.
			'one past the integer maximum, written'  => array( '9223372036854775808', null ),
			'one below the integer minimum, written' => array( '-9223372036854775809', null ),
			// Where the two notions of "written out" would diverge: read as a number instead,
			// these land exactly on PHP_INT_MIN and are kept. The form feed is the one that
			// catches a trim()-based rewrite, since trim() does not strip it but a numeric
			// string may carry it.
			'below the minimum, in whitespace'      => array( ' -9223372036854775809 ', null ),
			'below the minimum, after a form feed'  => array( "\f-9223372036854775809", null ),

			// Everything else is read by numeric value.
			'whole number written as a decimal'      => array( '5.0', 5 ),
			'whole number in exponent notation'      => array( '1e3', 1000 ),
			'exactly the integer minimum'            => array( -9223372036854775808.0, PHP_INT_MIN ),
			'largest lossless integer'               => array( 9223372036854774784.0, 9223372036854774784 ),
			'numeric string with no finite reading'  => array( '1e309', null ),
			'non-finite float'                       => array( INF, null ),
			'above the integer maximum'              => array( 1.0e19, null ),
			// The upper bound is exclusive, so this one is out even though it is the float
			// PHP_INT_MAX rounds to; admitting it would cast back with the wrong sign.
			'one past the integer maximum'           => array( 9223372036854775808.0, null ),
			'below the integer minimum'              => array( -1.0e19, null ),
		);
	}
}
