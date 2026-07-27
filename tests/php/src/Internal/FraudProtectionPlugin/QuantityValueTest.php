<?php
/**
 * QuantityValueTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin;

use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\QuantityValue;

/**
 * Unit coverage for the quantity helper.
 *
 * The callers are covered by CartItemTest and CartEventTrackerTest; this pins the helper's own
 * contract directly. What it decides is narrow on purpose: whether a raw value has a usable
 * finite numeric reading for the unit-amount arithmetic. The reported quantity is unaffected
 * either way.
 */
class QuantityValueTest extends FraudProtectionUnitTestCase {

	/**
	 * @testdox as_finite_float() parses only values usable as a finite number.
	 *
	 * @dataProvider provide_finite_floats
	 *
	 * @param mixed      $value    Raw value.
	 * @param float|null $expected Expected result.
	 */
	public function test_as_finite_float( mixed $value, ?float $expected ): void {
		$this->assertSame( $expected, QuantityValue::as_finite_float( $value ) );
	}

	/**
	 * Data provider for {@see test_as_finite_float()}.
	 *
	 * @return array<string, array{0: mixed, 1: float|null}>
	 */
	public function provide_finite_floats(): array {
		return array(
			'int'                      => array( 2, 2.0 ),
			'float'                    => array( 2.5, 2.5 ),
			'numeric string'           => array( '2', 2.0 ),
			'fractional string'        => array( '2.5', 2.5 ),
			'negative'                 => array( '-3', -3.0 ),
			'zero'                     => array( 0, 0.0 ),

			// Finite but denormal: usable locally, even though dividing by it can overflow.
			'denormal'                 => array( '1e-320', 1.0E-320 ),

			// is_numeric() passes but the value overflows to INF, so there is no finite float.
			'exponent overflow string' => array( '1e400', null ),

			// Not numeric at all. The is_numeric() gate is what keeps booleans and numeric
			// Stringables out of local arithmetic — filter_var() alone would read true as 1.0.
			'non-numeric string'       => array( 'not-a-number', null ),
			'bool true'                => array( true, null ),
			'null'                     => array( null, null ),
			'array'                    => array( array( 2 ), null ),

			// Numeric by is_numeric(), but not finite.
			'positive infinity'        => array( INF, null ),
			'not a number'             => array( NAN, null ),
		);
	}
}
