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
 * The plugin's own scripts send the reason next to an empty session ID. When
 * a request carries neither a session ID field nor a reason, the plugin's
 * scripts did not handle it, and the server records NOT_SUBMITTED. This is
 * not an extension contract: the field name and the reasons may change.
 */
class SessionIdError {

	/**
	 * Name of the request field and request data key carrying the reason.
	 */
	public const FIELD = 'wc_fraud_protection_session_id_error';

	/**
	 * Reason recorded when the request has no session ID field and no reason.
	 */
	public const NOT_SUBMITTED = 'not_submitted';

	/**
	 * Reasons the browser can report.
	 *
	 * `not_ready` means the request was sent before the session ID was ready,
	 * for example because another script let the submit continue.
	 */
	private const REPORTED = array( 'timeout', 'sdk_error', 'sdk_unavailable', 'not_ready' );

	/**
	 * Reason used when the browser reported no valid reason.
	 */
	private const UNKNOWN = 'unknown';

	/**
	 * Get the reason from the request data.
	 *
	 * @param array $request_data Request data that may contain FIELD.
	 * @return string A reported reason, NOT_SUBMITTED, or `unknown` when none is valid.
	 */
	public static function from_request_data( array $request_data ): string {
		$error = $request_data[ self::FIELD ] ?? null;

		return is_string( $error ) && ( self::NOT_SUBMITTED === $error || in_array( $error, self::REPORTED, true ) )
			? $error
			: self::UNKNOWN;
	}
}
