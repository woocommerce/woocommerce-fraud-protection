<?php
/**
 * ReadsFiniteNumbers trait file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\Schemas;

defined( 'ABSPATH' ) || exit;

/**
 * Reads untrusted values as numbers: a money value as a finite float, an integer field as an
 * int the type carries exactly.
 */
trait ReadsFiniteNumbers {

	/**
	 * Read a value as a finite float, or null when it has no finite numeric reading.
	 *
	 * The encode boundary cannot stand in for this: a formatter renders a non-finite total as
	 * the string 'INF', which encodes fine and casts to 0.0.
	 *
	 * @param mixed $value Raw value.
	 * @return ?float
	 */
	private static function finite_number( mixed $value ): ?float {
		if ( ! is_numeric( $value ) ) {
			return null;
		}

		$number = (float) $value;

		return is_finite( $number ) ? $number : null;
	}

	/**
	 * Read a value as an integer the int type can carry, or report none.
	 *
	 * Three shapes, in order: an int is taken as given; an integer written out as a string is
	 * relayed by its digits, and refused when they name one the type cannot carry; everything
	 * else is read by numeric value. The first two never read through a float, which rounds
	 * past 2^53 and saturates where refusing is the honest answer.
	 *
	 * @param mixed $value Raw value.
	 * @return ?int The integer, or null when the value names none the field can carry.
	 */
	private static function read_int( mixed $value ): ?int {
		if ( is_int( $value ) ) {
			return $value;
		}

		// One match decides both the admit and the refuse path, with whitespace and leading
		// zeros normalised away first. `\s` is exactly the whitespace a numeric string may
		// carry; trim() is not, and would let a refusal through.
		if ( is_string( $value ) && 1 === preg_match( '/^\s*(?<sign>[+-]?)0*(?<digits>\d+)\s*$/', $value, $written ) ) {
			$integer = filter_var( $written['sign'] . $written['digits'], FILTER_VALIDATE_INT );

			return false === $integer ? null : $integer;
		}

		$number = self::finite_number( $value );

		return null !== $number && self::is_exact_int( $number ) ? (int) $number : null;
	}

	/**
	 * Whether the int type holds this whole number exactly.
	 *
	 * The bounds are asymmetric: (float) PHP_INT_MIN is exact, while PHP_INT_MAX rounds up to
	 * 2^63, so an inclusive upper bound would admit 2^63 and cast it back with the wrong sign.
	 *
	 * @param float $number A finite number.
	 * @return bool
	 */
	private static function is_exact_int( float $number ): bool {
		return floor( $number ) === $number
			&& $number >= (float) PHP_INT_MIN
			&& $number < (float) PHP_INT_MAX;
	}
}
