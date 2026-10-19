<?php
/**
 * SessionIdError class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\Sessions;

defined( 'ABSPATH' ) || exit;

/**
 * Why the browser sent no Blackbox session ID.
 *
 * The plugin's own scripts send the reason next to an empty session ID. This
 * is not an extension contract: the field name and the reasons may change.
 */
class SessionIdError {

	/**
	 * Name of the request field and request data key carrying the reason.
	 */
	public const FIELD = 'wc_fraud_protection_session_id_error';

	/**
	 * Reasons the browser can report.
	 */
	private const REPORTED = array( 'timeout', 'sdk_error', 'sdk_unavailable' );

	/**
	 * Reason used when the browser reported no valid reason.
	 */
	private const UNKNOWN = 'unknown';

	/**
	 * Get the reason from the request data.
	 *
	 * @param array $request_data Request data that may contain FIELD.
	 * @return string A reported reason, or `unknown` when none is valid.
	 */
	public static function from_request_data( array $request_data ): string {
		$error = $request_data[ self::FIELD ] ?? null;

		return is_string( $error ) && in_array( $error, self::REPORTED, true ) ? $error : self::UNKNOWN;
	}
}
