<?php
/**
 * QuantityValue class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin;

defined( 'ABSPATH' ) || exit;

/**
 * Reads a raw WooCommerce quantity as a number for local calculations.
 *
 * The quantity itself is reported as WooCommerce supplied it. This only produces a separate
 * numeric reading of it, for the arithmetic that needs one.
 */
final class QuantityValue {

	/**
	 * This class only exposes static helpers.
	 */
	private function __construct() {}

	/**
	 * Read a value as a finite float, or null when it has no such reading.
	 *
	 * Each type is settled before the parser is reached, because `filter_var()` works on the
	 * string form of its argument and PHP 8.5 warns when NAN is cast to another type. Numbers
	 * need no parsing anyway: an int always has a reading, and a float is its own reading when
	 * finite. Only a string has to be parsed.
	 *
	 * Restricting the parser to strings is also what keeps the reading honest for the types that
	 * are not numbers. `filter_var()` would read `true` as 1.0 and a numeric Stringable as its
	 * float value, so a quantity would end up divided as a number it was never recorded as.
	 *
	 * @param mixed $value Raw value.
	 * @return float|null
	 */
	public static function as_finite_float( mixed $value ): ?float {
		if ( is_int( $value ) ) {
			return (float) $value;
		}

		if ( is_float( $value ) ) {
			return is_finite( $value ) ? $value : null;
		}

		if ( ! is_string( $value ) ) {
			return null;
		}

		return filter_var( $value, FILTER_VALIDATE_FLOAT, FILTER_NULL_ON_FAILURE );
	}
}
