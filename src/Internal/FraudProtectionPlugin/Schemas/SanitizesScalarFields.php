<?php
/**
 * SanitizesScalarFields trait file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\Schemas;

use Automattic\WooCommerce\Internal\FraudProtectionPlugin\FraudProtectionController;

defined( 'ABSPATH' ) || exit;

/**
 * Reads scalar fields from an untrusted array and sanitizes them before they reach a
 * strict-typed constructor, so a wrongly-typed value can never throw a TypeError.
 *
 * Coercions and drops are logged (field name and type only, never the value) so a
 * misbehaving integration surfaces instead of failing silently. The log source is the
 * consuming class name.
 */
trait SanitizesScalarFields {

	/**
	 * Read a string field. A string passes through sanitized, with an empty result treated as
	 * absent (null); a scalar number is coerced and logged; any other type is dropped to null
	 * and logged. The value itself is never logged.
	 *
	 * @param array<string, mixed> $data  Raw fields.
	 * @param string               $field Field name to read and sanitize.
	 * @return ?string
	 */
	private static function sanitize_string_field( array $data, string $field ): ?string {
		$value = $data[ $field ] ?? null;

		if ( null === $value ) {
			return null;
		}

		if ( is_string( $value ) ) {
			$clean = sanitize_text_field( $value );
			return '' === $clean ? null : $clean;
		}

		if ( is_int( $value ) || is_float( $value ) ) {
			FraudProtectionController::log(
				'warning',
				sprintf( 'Coerced %s field "%s" from %s to string.', self::sanitized_dto_label(), $field, gettype( $value ) )
			);
			return (string) $value;
		}

		FraudProtectionController::log(
			'error',
			sprintf( 'Dropped %s field "%s" with unsupported type %s.', self::sanitized_dto_label(), $field, gettype( $value ) )
		);
		return null;
	}

	/**
	 * Read an integer field. A whole number the int type can hold is read as an int; anything
	 * else is dropped to null and logged rather than silently truncated. The value itself is
	 * never logged.
	 *
	 * "Can hold" is part of the rule, at both ends: a value outside the type is reported as
	 * absent, never cast and never saturated to the nearest edge, because either would state a
	 * number nobody supplied. This trait is public surface — integrations hand these values in.
	 *
	 * @param array<string, mixed> $data  Raw fields.
	 * @param string               $field Field name to read and sanitize.
	 * @return ?int
	 */
	private static function sanitize_int_field( array $data, string $field ): ?int {
		$value = $data[ $field ] ?? null;

		if ( null === $value ) {
			return null;
		}

		$integer = self::read_int( $value );

		if ( null !== $integer ) {
			return $integer;
		}

		FraudProtectionController::log(
			'error',
			sprintf( 'Dropped %s field "%s" with a non-integer value (%s).', self::sanitized_dto_label(), $field, gettype( $value ) )
		);
		return null;
	}

	/**
	 * Read a value as an integer the field can carry, or report none.
	 *
	 * Three shapes, in order. An int is taken as given. An integer written out as a string is
	 * relayed by its digits, and refused when those digits name one the type cannot carry.
	 * Everything else is read by numeric value — a whole-valued float, or a string like `'5.0'`
	 * that names a number without spelling out an integer.
	 *
	 * Reading through a float is what the first two avoid: it rounds anything past 2^53, puts
	 * PHP_INT_MAX outside its own type, and saturates where refusing is the honest answer.
	 *
	 * @param mixed $value Raw value.
	 * @return ?int The integer, or null when the value names none the field can carry.
	 */
	private static function read_int( mixed $value ): ?int {
		if ( is_int( $value ) ) {
			return $value;
		}

		// Whitespace and leading zeros are notation, normalised away before the range is tested
		// rather than being what fails it. One match serves both the admit and the refuse path,
		// so they cannot disagree about which strings count as written out. `\s` is exactly the
		// whitespace a numeric string may carry; trim() is not, and would let a refusal through.
		if ( is_string( $value ) && 1 === preg_match( '/^\s*(?<sign>[+-]?)0*(?<digits>\d+)\s*$/', $value, $written ) ) {
			$integer = filter_var( $written['sign'] . $written['digits'], FILTER_VALIDATE_INT );

			return false === $integer ? null : $integer;
		}

		if ( ! is_numeric( $value ) ) {
			return null;
		}

		// Read by numeric value rather than by PHP type, as CartEventTracker reads a cart count.
		// The bounds below test this reading and the field is set from it, so they cannot differ.
		$number = (float) $value;

		// Asymmetric because (float) PHP_INT_MIN is exact while PHP_INT_MAX rounds up to 2^63: an
		// inclusive upper bound would admit 2^63 and cast it back with the wrong sign. No separate
		// finiteness check — the bounds exclude both infinities, and NAN fails the whole test.
		$is_representable_whole_number = floor( $number ) === $number
			&& $number >= (float) PHP_INT_MIN
			&& $number < (float) PHP_INT_MAX;

		return $is_representable_whole_number ? (int) $number : null;
	}

	/**
	 * Read a non-negative whole-number field (e.g. a minor-units amount).
	 *
	 * @param array<string, mixed> $data  Raw fields.
	 * @param string               $field Field name to read.
	 * @return ?int
	 */
	private static function sanitize_non_negative_int( array $data, string $field ): ?int {
		$value = self::sanitize_int_field( $data, $field );
		if ( null === $value || $value >= 0 ) {
			return $value;
		}

		self::log_dropped_field( $field, 'negative value' );
		return null;
	}

	/**
	 * Read a positive whole-number field (e.g. a Woo order ID).
	 *
	 * @param array<string, mixed> $data  Raw fields.
	 * @param string               $field Field name to read.
	 * @return ?int
	 */
	private static function sanitize_positive_int( array $data, string $field ): ?int {
		$value = self::sanitize_int_field( $data, $field );
		if ( null === $value || $value > 0 ) {
			return $value;
		}

		self::log_dropped_field( $field, 'non-positive value' );
		return null;
	}

	/**
	 * Read an enum field, returning the matching case when the raw value is either one of the allowed
	 * cases handed over directly, or a string equal to an allowed case's backing value. A provided
	 * value outside the set is dropped to null and logged; an absent value is silent. The allowed set
	 * is passed as cases (e.g. `SomeEnum::cases()`, or a subset).
	 *
	 * @template T of \BackedEnum
	 * @param array<string, mixed> $data    Raw fields.
	 * @param string               $field   Field name to read and validate.
	 * @param array<int, T>        $allowed Allowed enum cases.
	 * @return ?T The matching case, or null when absent or unrecognized.
	 */
	private static function sanitize_enum( array $data, string $field, array $allowed ): ?\BackedEnum {
		$value = $data[ $field ] ?? null;

		if ( is_null( $value ) ) {
			return null;
		}

		if ( is_string( $value ) ) {
			$value = sanitize_text_field( $value );
		}

		// Match either an allowed case handed over directly, or its backing string.
		foreach ( $allowed as $case ) {
			if ( $case === $value || $case->value === $value ) {
				return $case;
			}
		}

		FraudProtectionController::log(
			'warning',
			sprintf( 'Dropped %s field "%s" with an unrecognized value.', self::sanitized_dto_label(), $field )
		);
		return null;
	}

	/**
	 * Log that a provided field value was dropped (field name and reason only, never the value).
	 * Callers should only log a value that was actually provided; an absent field stays silent.
	 *
	 * @param string $field  Field name.
	 * @param string $reason Short reason the value was dropped.
	 * @return void
	 */
	private static function log_dropped_field( string $field, string $reason ): void {
		FraudProtectionController::log(
			'warning',
			sprintf( 'Dropped %s field "%s" (%s).', self::sanitized_dto_label(), $field, $reason )
		);
	}

	/**
	 * Short class name of the consuming DTO, used to label coerce/drop log messages.
	 *
	 * @return string
	 */
	private static function sanitized_dto_label(): string {
		$parts = explode( '\\', static::class );
		return (string) end( $parts );
	}
}
