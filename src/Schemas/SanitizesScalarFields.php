<?php
/**
 * SanitizesScalarFields trait file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\FraudProtection\Schemas;

use Automattic\WooCommerce\FraudProtection\FraudProtectionController;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and sanitizes scalar fields from an untrusted input array before they reach a
 * strict-typed constructor, so a wrongly-typed value can never throw a TypeError.
 *
 * - `sanitize_string_field()` / `sanitize_int_field()` coerce a recoverable value or drop it
 *   to null, logging either case (with the field name and type only, never the value) so a
 *   misbehaving integration surfaces instead of failing silently.
 * - `sanitize_enum()` validates a value against a fixed allowed set, failing open to null.
 *
 * Log messages identify the source by its class name, so no per-consumer configuration is
 * needed.
 *
 * @internal
 */
trait SanitizesScalarFields {

	/**
	 * Read a string field, coercing a scalar number or dropping any other wrong type.
	 *
	 * Strings and null pass through. A scalar number is coerced to string and logged as a
	 * warning (the value survives); any other type is dropped to null and logged as an
	 * error (the value is lost). Both are forwarded so a rogue integration is visible. The
	 * value itself is never logged — only the field name and its type — so no PII is emitted.
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
			return sanitize_text_field( $value );
		}

		if ( is_int( $value ) || is_float( $value ) ) {
			FraudProtectionController::log(
				'warning',
				sprintf( 'Coerced %s field "%s" from %s to string.', self::sanitized_dto_label(), $field, gettype( $value ) ),
				array(),
				true
			);
			return (string) $value;
		}

		FraudProtectionController::log(
			'error',
			sprintf( 'Dropped %s field "%s" with unsupported type %s.', self::sanitized_dto_label(), $field, gettype( $value ) ),
			array(),
			true
		);
		return null;
	}

	/**
	 * Read an integer field, dropping a non-integer value.
	 *
	 * Whole numbers (int, integer-valued float, or numeric string) are cast to int; null
	 * passes through. A fractional or non-numeric value is dropped to null and logged as an
	 * error rather than silently truncated, and forwarded for visibility. The value itself
	 * is never logged — only the field name and its type.
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
			sprintf( 'Dropped %s field "%s" with a non-integer value (%s).', self::sanitized_dto_label(), $field, gettype( $value ) ),
			array(),
			true
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
	 * Read an enum field, returning it only when it is a string in the allowed set.
	 *
	 * An absent (null) value is silent. A provided value outside the allowed set is dropped
	 * to null and logged as a warning, forwarded for visibility — so an unmapped gateway
	 * value surfaces instead of disappearing. The value itself is never logged, only the
	 * field name.
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
			sprintf( 'Dropped %s field "%s" with an unrecognized value.', self::sanitized_dto_label(), $field ),
			array(),
			true
		);
		return null;
	}

	/**
	 * Log that a provided field value was dropped, forwarded for visibility.
	 *
	 * The value itself is never logged — only the field name and a short reason — so no
	 * payment data or PII is emitted. Callers should only log a value that was actually
	 * provided; an absent (null) field is normal and stays silent.
	 *
	 * @param string $field  Field name.
	 * @param string $reason Short reason the value was dropped.
	 * @return void
	 */
	private static function log_dropped_field( string $field, string $reason ): void {
		FraudProtectionController::log(
			'warning',
			sprintf( 'Dropped %s field "%s" (%s).', self::sanitized_dto_label(), $field, $reason ),
			array(),
			true
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
