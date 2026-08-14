<?php
/**
 * SessionIdNormalizer class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\FraudProtection;

defined( 'ABSPATH' ) || exit;

/**
 * Normalizes an untrusted or legacy local session ID value.
 *
 * The invalid markers are sent to Blackbox as nonempty invalid IDs. They
 * preserve the difference between a missing ID and a malformed input value.
 * Blackbox response IDs are not normalized.
 */
class SessionIdNormalizer {

	/**
	 * Maximum normalized session ID length.
	 */
	private const MAX_LENGTH = 255;

	/**
	 * Marker for a boolean value.
	 */
	private const INVALID_BOOLEAN = 'wcfp-invalid-boolean';

	/**
	 * Marker for a null value.
	 */
	private const INVALID_NULL = 'wcfp-invalid-null';

	/**
	 * Marker for an array.
	 */
	private const INVALID_ARRAY = 'wcfp-invalid-array';

	/**
	 * Marker for an object.
	 */
	private const INVALID_OBJECT = 'wcfp-invalid-object';

	/**
	 * Marker for a resource or closed resource.
	 */
	private const INVALID_RESOURCE = 'wcfp-invalid-resource';

	/**
	 * Marker for a string that contains unsupported characters.
	 */
	private const INVALID_CHARACTERS = 'wcfp-invalid-characters';

	/**
	 * Marker for a non-finite number.
	 */
	private const INVALID_NUMBER = 'wcfp-invalid-number';

	/**
	 * Normalize one untrusted or legacy local value to a bounded Base64URL session ID.
	 *
	 * @param mixed $value Session ID value.
	 * @return string The bounded value or an invalid marker.
	 *
	 * @since 0.1.8
	 */
	public function normalize( mixed $value ): string {
		if ( is_bool( $value ) ) {
			return self::INVALID_BOOLEAN;
		}

		if ( is_float( $value ) && ! is_finite( $value ) ) {
			return self::INVALID_NUMBER;
		}

		if ( is_scalar( $value ) ) {
			$normalized = (string) $value;

			if ( 1 === preg_match( '/[^A-Za-z0-9_-]/', $normalized ) ) {
				return self::INVALID_CHARACTERS;
			}

			return substr( $normalized, 0, self::MAX_LENGTH );
		}

		return match ( gettype( $value ) ) {
			'NULL'              => self::INVALID_NULL,
			'array'             => self::INVALID_ARRAY,
			'object'            => self::INVALID_OBJECT,
			'resource',
			'resource (closed)' => self::INVALID_RESOURCE,
			default             => '',
		};
	}

	/**
	 * Normalize a stored session ID and discard invalid mappings.
	 *
	 * @param mixed $value Stored session ID value.
	 * @return string The bounded value, or an empty string for invalid input.
	 *
	 * @since 0.1.8
	 */
	public function normalize_stored( mixed $value ): string {
		$normalized = $this->normalize( $value );

		return $this->is_invalid_marker( $normalized ) ? '' : $normalized;
	}

	/**
	 * Check whether a value is one of this class's invalid markers.
	 *
	 * @param string $value Session ID value to check.
	 * @return bool True for an invalid marker.
	 *
	 * @since 0.1.8
	 */
	public function is_invalid_marker( string $value ): bool {
		return in_array(
			$value,
			array(
				self::INVALID_BOOLEAN,
				self::INVALID_NULL,
				self::INVALID_ARRAY,
				self::INVALID_OBJECT,
				self::INVALID_RESOURCE,
				self::INVALID_CHARACTERS,
				self::INVALID_NUMBER,
			),
			true
		);
	}
}
