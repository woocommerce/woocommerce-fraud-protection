<?php
/**
 * LogContextSanitizer class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtection\Logging;

defined( 'ABSPATH' ) || exit;

/**
 * Strict allowlist sanitizer for log context arrays bound for the PHP error
 * log.
 *
 * Plugin log call sites pass varied context arrays today, including upstream
 * API payloads, raw responses, request data, and exception objects. Anything
 * forwarded to the PHP error log is potentially aggregated and indexed by
 * the hosting platform, so the data we forward must be a known, reviewed
 * subset rather than whatever the caller happened to pass.
 *
 * Behavior:
 * - Only keys on {@see ALLOWED_KEYS} are kept; everything else is dropped.
 * - Keys are matched at the top level only. Nested structures are NOT
 *   walked recursively - if a non-allowlisted top-level key happens to
 *   contain an allowlisted key, that nested value is still dropped.
 *   This is deliberate: it preserves default-deny ("if I chose to drop
 *   this structure, none of its contents ship") and keeps the rule
 *   trivially auditable. Call sites that want both rich local context
 *   and structured platform context pass duplicates: nested values for
 *   the local log, flat allowlisted keys for the sanitizer.
 * - Only scalar values are kept (string/int/float/bool); arrays and
 *   objects are dropped.
 * - String values are truncated to {@see MAX_VALUE_LENGTH} characters.
 *
 * The message string passed alongside this context is NOT sanitized here -
 * callers are responsible for not interpolating user-controlled values into
 * log messages.
 *
 * Adding a key to {@see ALLOWED_KEYS} should be reviewed with privacy in
 * mind: only constant or low-cardinality categorical values, version
 * identifiers, internal correlation IDs, or structured exception fields
 * belong on the allowlist. Free-form user-supplied data does not.
 *
 * @internal
 */
final class LogContextSanitizer {

	/**
	 * Context keys whose scalar values may be forwarded to the PHP error log.
	 *
	 * Any key not listed here is dropped, regardless of its value.
	 *
	 * Grouped (by intent) but stored as a flat list:
	 * - Correlation IDs: session_id, identity_id, order_id.
	 * - Where in the plugin: event_source, filter, hook, api_endpoint.
	 * - What we got: decision_received, argument_type, http_status.
	 * - Why it failed: error_code, exception_class, exception_message,
	 *   exception_file, exception_line.
	 * - Integration: payment_type.
	 *
	 * Note: `source` is intentionally NOT on the allowlist - WooCommerce's
	 * logger uses it as the log channel name and {@see FraudProtectionController::log()}
	 * overrides it to 'woo-fraud-protection'. Use `event_source` instead.
	 *
	 * @var string[]
	 */
	private const ALLOWED_KEYS = array(
		'session_id',
		'identity_id',
		'order_id',
		'event_source',
		'filter',
		'hook',
		'api_endpoint',
		'decision_received',
		'argument_type',
		'http_status',
		'error_code',
		'exception_class',
		'exception_message',
		'exception_file',
		'exception_line',
		'payment_type',
	);

	/**
	 * Maximum character length for any string value forwarded. Measured in
	 * characters (not bytes) so multibyte strings are not split mid-codepoint.
	 */
	private const MAX_VALUE_LENGTH = 200;

	/**
	 * Private constructor to prevent instantiation; this is a static utility.
	 */
	private function __construct() {
	}

	/**
	 * Sanitize a log context array into a JSON string containing only
	 * allowlisted scalar values.
	 *
	 * Returns an empty string when no allowlisted scalar values are present.
	 *
	 * @param array<string, mixed> $context Original log context.
	 *
	 * @return string JSON-encoded sanitized context, or empty string.
	 */
	public static function sanitize( array $context ): string {
		$safe = array();

		foreach ( self::ALLOWED_KEYS as $key ) {
			if ( ! array_key_exists( $key, $context ) ) {
				continue;
			}

			$value = $context[ $key ];

			if ( ! is_scalar( $value ) ) {
				continue;
			}

			if ( is_string( $value ) && mb_strlen( $value ) > self::MAX_VALUE_LENGTH ) {
				$value = mb_substr( $value, 0, self::MAX_VALUE_LENGTH );
			}

			$safe[ $key ] = $value;
		}

		if ( array() === $safe ) {
			return '';
		}

		$json = wp_json_encode( $safe );

		return is_string( $json ) ? $json : '';
	}
}
