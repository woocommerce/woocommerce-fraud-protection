<?php
/**
 * SanitizesScalarFields trait file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\FraudProtection\Schemas;

use Automattic\WooCommerce\FraudProtection\FraudProtectionController;

defined( 'ABSPATH' ) || exit;

/**
 * Reads scalar fields from an untrusted array and sanitizes them before they reach a
 * strict-typed constructor, so a wrongly-typed value can never throw a TypeError.
 *
 * Coercions and drops are logged (field name and type only, never the value) so a
 * misbehaving integration surfaces instead of failing silently. The log source is the
 * consuming class name.
 *
 * @internal
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
	 * Read an integer field. A whole number (int, integer-valued float, or numeric string) is
	 * cast to int; a fractional or non-numeric value is dropped to null and logged rather than
	 * silently truncated. The value itself is never logged.
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

		if ( is_numeric( $value ) && floor( (float) $value ) === (float) $value ) {
			return (int) $value;
		}

		FraudProtectionController::log(
			'error',
			sprintf( 'Dropped %s field "%s" with a non-integer value (%s).', self::sanitized_dto_label(), $field, gettype( $value ) )
		);
		return null;
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
	 * Read an enum field, returning it only when it is a string in the allowed set. A provided
	 * value outside the set is dropped to null and logged; an absent value is silent.
	 *
	 * @param array<string, mixed> $data    Raw fields.
	 * @param string               $field   Field name to read and validate.
	 * @param array<int, string>   $allowed Allowed normalized values.
	 * @return ?string
	 */
	private static function sanitize_enum( array $data, string $field, array $allowed ): ?string {
		$value = $data[ $field ] ?? null;

		if ( null === $value ) {
			return null;
		}

		if ( is_string( $value ) ) {
			$value = sanitize_text_field( $value );
			if ( in_array( $value, $allowed, true ) ) {
				return $value;
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
