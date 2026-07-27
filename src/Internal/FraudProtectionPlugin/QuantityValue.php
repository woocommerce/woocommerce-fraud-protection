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
	 * Parse a numeric value as a finite float.
	 *
	 * @param mixed $value Raw value.
	 * @return float|null
	 */
	public static function as_finite_float( mixed $value ): ?float {
		return is_numeric( $value )
			? filter_var( $value, FILTER_VALIDATE_FLOAT, FILTER_NULL_ON_FAILURE )
			: null;
	}
}
