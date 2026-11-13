<?php
/**
 * NativeIntegrationRegistry class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\FraudProtection;

use Automattic\WooCommerce\Internal\FraudProtectionPlugin\FraudProtectionController;

defined( 'ABSPATH' ) || exit;

/**
 * Collects the native integrations that extensions other than payment gateways declare.
 *
 * Fraud Protection passes an instance to the `woocommerce_fraud_protection_register_native_integrations`
 * action. Hook it while your plugin loads, and call register() from the callback under your
 * own plugin slug:
 *
 *     add_action(
 *         'woocommerce_fraud_protection_register_native_integrations',
 *         function ( NativeIntegrationRegistry $registry ): void {
 *             $registry->register( 'my-extension', 'change_payment_method' );
 *         }
 *     );
 *
 * The action fires at most once per request, the first time Fraud Protection needs the
 * declarations, and not at all while Fraud Protection is inactive. Registrations made
 * outside it are ignored.
 *
 * Payment gateways declare their integrations through `WC_Payment_Gateway::supports()`
 * instead; see {@see FraudProtectionFeature}.
 *
 * @since 0.3.2
 */
final class NativeIntegrationRegistry {

	/**
	 * Registered integrations, keyed by plugin slug.
	 *
	 * @var array<string, string[]>
	 */
	private array $registered = array();

	/**
	 * Whether the registry still accepts registrations.
	 *
	 * @var bool
	 */
	private bool $open = true;

	/**
	 * Declare that an extension implements an integration natively.
	 *
	 * Call it once per integration, from the `woocommerce_fraud_protection_register_native_integrations`
	 * action. A registration with an empty slug or integration, or made after the action
	 * finished, is ignored and logged.
	 *
	 * @param string $extension   Your plugin slug, as on WordPress.org or WooCommerce.com, such as `woocommerce-subscriptions`.
	 * @param string $integration The integration your extension implements, such as `change_payment_method`; see {@see FraudProtectionIntegration}.
	 *
	 * @since 0.3.2
	 */
	public function register( string $extension, string $integration ): void {
		if ( ! $this->open ) {
			$this->log_ignored( 'A native integration was registered outside the registration action and is ignored', $extension, $integration );
			return;
		}

		if ( '' === $extension || '' === $integration ) {
			$this->log_ignored( 'A native integration with an empty plugin slug or integration is ignored', $extension, $integration );
			return;
		}

		if ( ! in_array( $integration, $this->registered[ $extension ] ?? array(), true ) ) {
			$this->registered[ $extension ][] = $integration;
		}
	}

	/**
	 * Stop accepting registrations.
	 *
	 * @internal
	 */
	public function close(): void {
		$this->open = false;
	}

	/**
	 * Get the registered integrations.
	 *
	 * @internal
	 *
	 * @return array<string, string[]> Registered integrations, keyed by plugin slug.
	 */
	public function get_registered(): array {
		return $this->registered;
	}

	/**
	 * Log an ignored registration.
	 *
	 * @param string $message     Log message.
	 * @param string $extension   The plugin slug passed to register().
	 * @param string $integration The integration passed to register().
	 */
	private function log_ignored( string $message, string $extension, string $integration ): void {
		FraudProtectionController::log(
			'warning',
			$message,
			array(
				'event_source' => 'extension_native_support',
				'extension'    => $extension,
				'integration'  => $integration,
			)
		);
	}
}
