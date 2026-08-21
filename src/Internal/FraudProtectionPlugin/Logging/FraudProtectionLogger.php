<?php
/**
 * FraudProtectionLogger class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\Logging;

use Automattic\WooCommerce\Internal\FraudProtectionPlugin\EncodablePayload;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Sessions\SessionIdentityManager;
use Automattic\WooCommerce\Proxies\LegacyProxy;

defined( 'ABSPATH' ) || exit;

/**
 * Writes Fraud Protection log entries.
 */
class FraudProtectionLogger {

	/**
	 * Prefix for entries forwarded to the PHP error log.
	 */
	private const PLATFORM_LOG_TAG = 'woo-fraud-protection';

	/**
	 * App-level severity encoded in the forwarded line number.
	 */
	private const LEVEL_LINE_CODES = array(
		'warning'   => -10,
		'error'     => -20,
		'critical'  => -30,
		'alert'     => -40,
		'emergency' => -50,
	);

	/**
	 * Legacy proxy instance.
	 *
	 * @var LegacyProxy
	 */
	private LegacyProxy $legacy_proxy;

	/**
	 * Initialize with dependencies.
	 *
	 * @internal
	 *
	 * @param LegacyProxy $legacy_proxy The legacy proxy instance.
	 */
	final public function init( LegacyProxy $legacy_proxy ): void {
		$this->legacy_proxy = $legacy_proxy;
	}

	/**
	 * Write a local log entry and optionally forward a sanitized copy.
	 *
	 * @internal
	 *
	 * @param string               $level                   Log level.
	 * @param string               $message                 Log message.
	 * @param array<string, mixed> $context                 Optional context data.
	 * @param bool                 $forward_to_platform_log Whether to also forward to the PHP error log.
	 */
	public function log( string $level, string $message, array $context = array(), bool $forward_to_platform_log = false ): void {
		$message = self::prefix_message_with_identity( $message );
		$context = EncodablePayload::for_log( $context );

		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->log(
				$level,
				$message,
				array_merge( $context, array( 'source' => 'woo-fraud-protection' ) )
			);
		}

		if ( $forward_to_platform_log ) {
			$this->forward_to_platform_log( $level, $message, $context );
		}
	}

	/**
	 * Forward a sanitized, tagged log entry to the PHP error log.
	 *
	 * @param string               $level   Log level.
	 * @param string               $message Log message.
	 * @param array<string, mixed> $context Log context.
	 */
	private function forward_to_platform_log( string $level, string $message, array $context ): void {
		$sanitized = LogContextSanitizer::sanitize( $context );
		$line_code = self::LEVEL_LINE_CODES[ $level ] ?? self::LEVEL_LINE_CODES['warning'];

		$body = sprintf( '[%s %s] %s', self::PLATFORM_LOG_TAG, $level, $message );
		if ( '' !== $sanitized ) {
			$body .= ' ' . $sanitized;
		}

		$line = sprintf(
			'PHP Warning: %s in %s on line %d',
			$body,
			self::get_plugin_marker_file(),
			$line_code
		);

		$this->legacy_proxy->call_function( 'error_log', $line );
	}

	/**
	 * Get the fixed plugin path used in forwarded entries.
	 *
	 * @return string Absolute plugin path.
	 */
	private static function get_plugin_marker_file(): string {
		return dirname( __DIR__, 4 ) . '/woocommerce-fraud-protection.php';
	}

	/**
	 * Prefix a log message with the current session identity.
	 *
	 * @param string $message Original message.
	 * @return string Prefixed message when an identity is available.
	 */
	private static function prefix_message_with_identity( string $message ): string {
		$identity_id = self::get_session_identity_id();
		if ( '' === $identity_id ) {
			return $message;
		}

		return sprintf( 'Identity: %s | %s', $identity_id, $message );
	}

	/**
	 * Get the identity ID from the current WooCommerce session.
	 *
	 * @return string Identity ID, or an empty string when unavailable.
	 */
	private static function get_session_identity_id(): string {
		$wc = function_exists( 'WC' ) ? WC() : null;
		if ( ! $wc instanceof \WooCommerce || ! $wc->session instanceof \WC_Session ) {
			return '';
		}

		$identity_id = $wc->session->get( SessionIdentityManager::CUSTOMER_IDENTITY_ID_KEY );

		return is_string( $identity_id ) ? $identity_id : '';
	}
}
