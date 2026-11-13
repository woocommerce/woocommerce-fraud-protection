<?php
/**
 * ExtensionNativeSupport class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\Compat;

use Automattic\WooCommerce\FraudProtection\FraudProtectionIntegration;
use Automattic\WooCommerce\FraudProtection\NativeIntegrationRegistry;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\FraudProtectionController;

defined( 'ABSPATH' ) || exit;

/**
 * Answers whether an extension declares a native integration.
 *
 * Extensions other than payment gateways cannot declare native support through
 * `WC_Payment_Gateway::supports()`, so they register {@see FraudProtectionIntegration}
 * strings under their own plugin slug, with {@see NativeIntegrationRegistry::register()}
 * from the `woocommerce_fraud_protection_register_native_integrations` action. The action
 * fires once per request, the first time a declaration is needed. A compatibility layer asks
 * for its extension's slug and the integration it provides, inside its hook callbacks, and
 * stands aside when the extension declares it. Any failure answers "not declared" for the
 * registrations it prevents, which keeps the matching compatibility layers on.
 */
class ExtensionNativeSupport {

	/**
	 * The action that collects the extensions' native integrations.
	 */
	private const ACTION = 'woocommerce_fraud_protection_register_native_integrations';

	/**
	 * Log context event source.
	 */
	private const EVENT_SOURCE = 'extension_native_support';

	/**
	 * The registered integrations, once the action has fired in this request.
	 *
	 * @var array<string, string[]>|null
	 */
	private ?array $declarations = null;

	/**
	 * Extension and integration pairs already logged as declared in this request.
	 *
	 * @var array<string, true>
	 */
	private array $logged_declarations = array();

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
	 * Fires the registration action the first time it is called after WordPress `init`, and
	 * keeps the result for the rest of the request. Before `init`, while plugins may still be
	 * adding their callbacks, it declares nothing and fires nothing. Unknown integration
	 * strings are kept, so that a misspelled declaration shows up in
	 * `wp wc fraud-protection native-integrations list`.
	 *
	 * @return array<string, string[]> Declared integrations keyed by plugin slug, for extensions that declare at least one.
	 */
	public function get_declarations(): array {
		if ( null !== $this->declarations ) {
			return $this->declarations;
		}

		if ( ! did_action( 'init' ) ) {
			return array();
		}

		// A callback that asks for the declarations while the action runs gets none.
		$this->declarations = array();
		$registry           = new NativeIntegrationRegistry();

		try {
			/**
			 * Fires once per request so that extensions other than payment gateways can declare
			 * their native integrations.
			 *
			 * Each extension calls `$registry->register()` under its own plugin slug, with each
			 * integration it implements, such as `change_payment_method`. When this plugin ships
			 * a built-in compatibility layer for that extension's flow, the layer stands aside.
			 * Payment gateways declare their integration through `WC_Payment_Gateway::supports()`
			 * instead. A callback that throws stops the action; registrations made before it count.
			 *
			 * @since 0.3.2
			 *
			 * @param NativeIntegrationRegistry $registry The registry to declare integrations in.
			 */
			do_action( self::ACTION, $registry );
		} catch ( \Throwable $e ) {
			FraudProtectionController::log(
				'warning',
				'A `woocommerce_fraud_protection_register_native_integrations` callback threw; later registrations are skipped and their compatibility layers stay on',
				array(
					'event_source'      => self::EVENT_SOURCE,
					'hook'              => self::ACTION,
					'exception_class'   => $e::class,
					'exception_message' => $e->getMessage(),
				),
				true
			);
		} finally {
			$registry->close();
		}

		$this->declarations = $registry->get_registered();

		return $this->declarations;
	}
}
