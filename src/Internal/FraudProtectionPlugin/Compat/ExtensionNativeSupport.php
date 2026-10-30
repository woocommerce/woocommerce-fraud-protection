<?php
/**
 * ExtensionNativeSupport class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\Compat;

use Automattic\WooCommerce\FraudProtection\FraudProtectionIntegration;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\FraudProtectionController;

defined( 'ABSPATH' ) || exit;

/**
 * Answers whether an extension declares a native integration.
 *
 * Extensions other than payment gateways cannot declare native support through
 * `WC_Payment_Gateway::supports()`, so they declare {@see FraudProtectionIntegration}
 * strings through the `woocommerce_fraud_protection_native_integrations` filter, keyed by
 * their own plugin slug. A compatibility layer asks for its extension's slug and the
 * integration it provides, inside its hook callbacks, and stands aside when the extension
 * declares it. Any failure answers "not declared", which keeps the compatibility layer on.
 */
class ExtensionNativeSupport {

	/**
	 * The filter extensions use to declare their native integrations.
	 */
	private const FILTER = 'woocommerce_fraud_protection_native_integrations';

	/**
	 * Log context event source.
	 */
	private const EVENT_SOURCE = 'extension_native_support';

	/**
	 * Extension and integration pairs already logged as declared in this request.
	 *
	 * @var array<string, true>
	 */
	private array $logged_declarations = array();

	/**
	 * Whether an invalid filter result was already logged in this request.
	 *
	 * @var bool
	 */
	private bool $logged_failure = false;

	/**
	 * Check whether an extension declares an integration.
	 *
	 * @param string $extension   The extension's plugin slug, such as `woocommerce-subscriptions`.
	 * @param string $integration The integration, one of the {@see FraudProtectionIntegration} constants.
	 * @return bool True when the extension declares the integration.
	 */
	public function declared( string $extension, string $integration ): bool {
		if ( ! in_array( $integration, $this->get_declarations()[ $extension ] ?? array(), true ) ) {
			return false;
		}

		$key = $extension . '|' . $integration;
		if ( ! isset( $this->logged_declarations[ $key ] ) ) {
			$this->logged_declarations[ $key ] = true;

			FraudProtectionController::log(
				'info',
				'An extension declares native Fraud Protection support; the built-in compatibility layer stands aside',
				array(
					'event_source' => self::EVENT_SOURCE,
					'extension'    => $extension,
					'integration'  => $integration,
				)
			);
		}

		return true;
	}

	/**
	 * Get the integrations every extension declares.
	 *
	 * Invalid entries are skipped and the valid ones are kept. Unknown integration strings
	 * are kept too, so that a misspelled declaration shows up in `wp wc fraud-protection native-integrations list`.
	 *
	 * @return array<string, string[]> Declared integrations keyed by plugin slug, for extensions that declare at least one.
	 */
	public function get_declarations(): array {
		try {
			/**
			 * Filters the native integrations that extensions other than payment gateways declare.
			 *
			 * Each extension adds an entry under its own plugin slug, with the integrations it
			 * implements, such as `change_payment_method`. When this plugin ships a built-in
			 * compatibility layer for that extension's flow, the layer stands aside. Payment
			 * gateways declare their integration through `WC_Payment_Gateway::supports()` instead.
			 * A non-array result keeps every layer on; invalid entries are skipped.
			 *
			 * @since 0.3.1
			 *
			 * @param array<string, string[]> $integrations Declared integrations keyed by plugin slug. Default empty.
			 */
			$integrations = apply_filters( self::FILTER, array() );
		} catch ( \Throwable $e ) {
			$this->log_failure(
				'`woocommerce_fraud_protection_native_integrations` filter threw; the compatibility layers stay on',
				array(
					'exception_class'   => $e::class,
					'exception_message' => $e->getMessage(),
				)
			);

			return array();
		}

		if ( ! is_array( $integrations ) ) {
			$this->log_failure( '`woocommerce_fraud_protection_native_integrations` filter returned a non-array; the compatibility layers stay on', array() );

			return array();
		}

		$declarations = array();
		foreach ( $integrations as $extension => $extension_integrations ) {
			if ( ! is_string( $extension ) || '' === $extension || ! is_array( $extension_integrations ) ) {
				continue;
			}

			$valid = array_values( array_unique( array_filter( $extension_integrations, 'is_string' ) ) );
			if ( array() !== $valid ) {
				$declarations[ $extension ] = $valid;
			}
		}

		return $declarations;
	}

	/**
	 * Log an invalid filter result once per request.
	 *
	 * @param string $message Log message.
	 * @param array  $context Additional log context.
	 */
	private function log_failure( string $message, array $context ): void {
		if ( $this->logged_failure ) {
			return;
		}

		$this->logged_failure = true;

		FraudProtectionController::log(
			'warning',
			$message,
			array_merge(
				array(
					'event_source' => self::EVENT_SOURCE,
					'filter'       => self::FILTER,
				),
				$context
			),
			true
		);
	}
}
