<?php
/**
 * RuleConditions class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\Rules;

defined( 'ABSPATH' ) || exit;

/**
 * Validation, normalization and hashing of rule condition documents.
 *
 * A condition document is the JSON stored in the `conditions` column of the
 * rules table: `{field, operator, value}`, optionally nested under `and`/`or`
 * in the future. For now accepts only a single `equals` condition
 * on `email` or `ip`.
 *
 * Values are normalized both at write time (feeding the condition hash) and
 * again at evaluation time, applied to the rule value and the evaluation
 * context alike.
 */
class RuleConditions {

	/**
	 * The billing email evaluation context field.
	 */
	public const FIELD_EMAIL = 'email';

	/**
	 * The visitor IP evaluation context field.
	 */
	public const FIELD_IP = 'ip';

	/**
	 * Condition fields the MVP validator accepts.
	 */
	private const VALID_FIELDS = array( self::FIELD_EMAIL, self::FIELD_IP );

	/**
	 * Condition operators the MVP validator accepts.
	 */
	private const VALID_OPERATORS = array( 'equals' );

	/**
	 * Maximum accepted email condition value length, mirroring the sessions
	 * table `email` column.
	 */
	private const MAX_EMAIL_LENGTH = 254;

	/**
	 * Validate a condition document and return its normalized form.
	 *
	 * @param mixed $conditions The condition document to validate.
	 * @return ?array The normalized document, or null when invalid.
	 */
	public static function validate_and_normalize( $conditions ): ?array {
		if ( ! is_array( $conditions ) ) {
			return null;
		}

		$field    = $conditions['field'] ?? null;
		$operator = $conditions['operator'] ?? null;
		$value    = $conditions['value'] ?? null;

		if ( ! in_array( $field, self::VALID_FIELDS, true ) ) {
			return null;
		}

		if ( ! in_array( $operator, self::VALID_OPERATORS, true ) ) {
			return null;
		}

		if ( ! is_string( $value ) ) {
			return null;
		}

		$normalized_value = self::normalize_value( $field, $value );
		if ( is_null( $normalized_value ) ) {
			return null;
		}

		return array(
			'field'    => $field,
			'operator' => $operator,
			'value'    => $normalized_value,
		);
	}

	/**
	 * Normalize a condition or context value for a field.
	 *
	 * @param string $field The condition field the value belongs to.
	 * @param string $value The raw value.
	 * @return ?string The normalized value, or null when the value is invalid for the field.
	 */
	public static function normalize_value( string $field, string $value ): ?string {
		$value = trim( $value );
		if ( '' === $value ) {
			return null;
		}

		switch ( $field ) {
			case self::FIELD_EMAIL:
				// Lowercased so differently-cased variants of the same address compare equal.
				$value = strtolower( $value );
				if ( mb_strlen( $value ) > self::MAX_EMAIL_LENGTH || 1 !== preg_match( '/^\S+@\S+$/', $value ) ) {
					return null;
				}
				return $value;

			case self::FIELD_IP:
				// Canonical text form (compressed lowercase for IPv6) so textual variants of the same address compare equal.
				if ( false === filter_var( $value, FILTER_VALIDATE_IP ) ) {
					return null;
				}
				$packed = inet_pton( $value );
				return false === $packed ? null : (string) inet_ntop( $packed );

			default:
				// Trimmed only, keeping the method usable for future context fields.
				return $value;
		}
	}

	/**
	 * Get the deduplication hash of a normalized condition document.
	 *
	 * @param array $conditions The normalized condition document.
	 * @return string The 64-character hexadecimal hash.
	 */
	public static function hash( array $conditions ): string {
		return hash( 'sha256', (string) wp_json_encode( self::sort_keys_recursively( $conditions ) ) );
	}

	/**
	 * Recursively sort the keys of an array.
	 *
	 * @param array $data The array to sort.
	 * @return array The array with keys sorted at every level.
	 */
	private static function sort_keys_recursively( array $data ): array {
		ksort( $data );

		foreach ( $data as $key => $value ) {
			if ( is_array( $value ) ) {
				$data[ $key ] = self::sort_keys_recursively( $value );
			}
		}

		return $data;
	}
}
