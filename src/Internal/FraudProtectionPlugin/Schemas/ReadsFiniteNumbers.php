<?php
/**
 * ReadsFiniteNumbers trait file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\Schemas;

defined( 'ABSPATH' ) || exit;

/**
 * Reads a WooCommerce money value as a number, for schema records that report one.
 */
trait ReadsFiniteNumbers {

	/**
	 * Read a value as a finite float, or report none.
	 *
	 * WooCommerce guarantees neither a numeric shape nor finiteness for these, so a field that is
	 * a number on the wire is omitted rather than fabricated when the raw value has no finite
	 * numeric reading.
	 *
	 * The encoding boundary cannot stand in for this. A formatter renders a non-finite total as
	 * the *string* `'INF'`, which encodes perfectly well, and casting that string gives `0.0` — a
	 * confident zero standing in for a number nobody has. Both survive the boundary; only the
	 * field's owner knows the field was meant to be a number.
	 *
	 * @param mixed $value Raw value.
	 * @return float|null The value as a finite float, or null when it has none.
	 */
	private static function finite_number( mixed $value ): ?float {
		if ( ! is_numeric( $value ) ) {
			return null;
		}

		$number = (float) $value;

		return is_finite( $number ) ? $number : null;
	}
}
