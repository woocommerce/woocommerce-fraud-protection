<?php
/**
 * FraudProtectionSettingsPage class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\Settings;

use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Logging\FraudProtectionLogger;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Sessions\PaymentMethodTitleResolver;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Sessions\SessionEventPruner;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Sessions\SessionEventStore;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the Fraud prevention page to WooCommerce settings.
 */
class FraudProtectionSettingsPage extends \WC_Settings_Page {

	public const PAGE_ID = 'woocommerce_fraud_protection';

	private const SCRIPT_HANDLE = 'wc-fraud-protection-admin-settings';

	/**
	 * Logger instance.
	 *
	 * @var FraudProtectionLogger
	 */
	private FraudProtectionLogger $logger;

	/**
	 * Automatic protection setting.
	 *
	 * @var AutomaticProtectionSetting
	 */
	private AutomaticProtectionSetting $automatic_protection;

	/**
	 * Session event store.
	 *
	 * @var SessionEventStore
	 */
	private SessionEventStore $event_store;

	/**
	 * Payment method title resolver.
	 *
	 * @var PaymentMethodTitleResolver
	 */
	private PaymentMethodTitleResolver $payment_method_titles;

	/**
	 * Whether the settings asset metadata failed to load.
	 *
	 * @var bool
	 */
	private bool $asset_error = false;

	/**
	 * Initialize the WooCommerce settings page.
	 */
	public function __construct() {
		$this->id    = self::PAGE_ID;
		$this->label = __( 'Fraud prevention', 'woocommerce-fraud-protection' );

		parent::__construct();
	}

	/**
	 * Initialize with dependencies.
	 *
	 * @internal
	 *
	 * @param FraudProtectionLogger      $logger                Logger instance.
	 * @param AutomaticProtectionSetting $automatic_protection  Automatic protection setting.
	 * @param SessionEventStore          $event_store           Session event store.
	 * @param PaymentMethodTitleResolver $payment_method_titles Payment method title resolver.
	 */
	final public function init( FraudProtectionLogger $logger, AutomaticProtectionSetting $automatic_protection, SessionEventStore $event_store, PaymentMethodTitleResolver $payment_method_titles ): void {
		$this->logger                = $logger;
		$this->automatic_protection  = $automatic_protection;
		$this->event_store           = $event_store;
		$this->payment_method_titles = $payment_method_titles;
	}

	/**
	 * Return no classic settings fields.
	 *
	 * @param string $section_id Settings section ID.
	 * @return array<int, array<string, mixed>>
	 */
	protected function get_settings_for_section_core( $section_id ) {
		unset( $section_id );

		return array();
	}

	/**
	 * Render the plugin-owned React mount.
	 */
	public function output(): void {
		$GLOBALS['hide_save_button'] = true;

		if ( $this->asset_error ) {
			echo '<div class="notice notice-error"><p>';
			esc_html_e( 'The fraud prevention settings could not be loaded.', 'woocommerce-fraud-protection' );
			echo '</p></div>';
			return;
		}

		echo '<div id="wc-fraud-protection-settings" class="wc-settings-prevent-change-event"></div>';
	}

	/**
	 * Load settings assets only on this WooCommerce settings tab.
	 *
	 * @internal
	 *
	 * @param mixed $hook_suffix Current admin page hook suffix.
	 */
	public function enqueue_assets( $hook_suffix ): void {
		global $current_tab;

		if ( 'woocommerce_page_wc-settings' !== $hook_suffix || ! is_string( $current_tab ) || self::PAGE_ID !== $current_tab ) {
			return;
		}

		$asset_file = dirname( WC_FRAUD_PROTECTION_PLUGIN_FILE ) . '/build/admin-settings.asset.php';
		if ( ! is_readable( $asset_file ) ) {
			$this->asset_error = true;
			$this->logger->log( 'error', 'Fraud Protection settings asset metadata is unavailable.', array(), true );
			return;
		}

		$asset = require $asset_file;
		if ( ! is_array( $asset ) || ! is_array( $asset['dependencies'] ?? null ) || ! is_string( $asset['version'] ?? null ) ) {
			$this->asset_error = true;
			$this->logger->log( 'error', 'Fraud Protection settings asset metadata is invalid.', array(), true );
			return;
		}

		wp_enqueue_style(
			self::SCRIPT_HANDLE,
			plugins_url( 'build/admin-settings.css', WC_FRAUD_PROTECTION_PLUGIN_FILE ),
			array(),
			$asset['version']
		);
		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			plugins_url( 'build/admin-settings.js', WC_FRAUD_PROTECTION_PLUGIN_FILE ),
			$asset['dependencies'],
			$asset['version'],
			array( 'in_footer' => true )
		);
		wp_set_script_translations( self::SCRIPT_HANDLE, 'woocommerce-fraud-protection', dirname( WC_FRAUD_PROTECTION_PLUGIN_FILE ) . '/languages' );
		$this->inject_checkout_attempts_config();
		$this->maybe_preload_settings_data();
	}

	/**
	 * Expose the checkout attempts list configuration to its React route.
	 *
	 * The list route lives inside this single-page settings app, so the config
	 * is injected on every route: automatic-protection state (for the flagged
	 * tooltip and the "turn it on" row action), the settings page URL that
	 * action opens in a new tab, and the payment methods present in the data
	 * (for the provider filter). The value reflects page-load state; a change
	 * made without a reload is picked up on the next one.
	 */
	private function inject_checkout_attempts_config(): void {
		$config = array(
			'automaticProtection'          => $this->automatic_protection->is_enabled(),
			'automaticProtectionEnabledAt' => null,
			'settingsUrl'                  => admin_url( 'admin.php?page=wc-settings&tab=' . self::PAGE_ID ),
			'paymentMethods'               => $this->checkout_attempts_payment_methods(),
		);

		wp_add_inline_script(
			self::SCRIPT_HANDLE,
			'window.wcFraudProtectionCheckoutAttempts = ' . wp_json_encode( $config, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES ) . ';',
			'before'
		);
	}

	/**
	 * Build the payment method options present in the retained checkout attempts.
	 *
	 * @return array<int, array{id: string, title: string}>
	 */
	private function checkout_attempts_payment_methods(): array {
		try {
			$ids = $this->event_store->get_payment_methods( SessionEventPruner::RETENTION_DAYS );
		} catch ( \RuntimeException ) {
			// The provider filter simply offers no options (for example before the
			// sessions table exists); the list still loads, and its REST endpoint
			// reports any genuine query failure when the merchant opens it.
			return array();
		}

		$options = array();
		foreach ( $ids as $id ) {
			$options[] = array(
				'id'    => $id,
				'title' => $this->payment_method_titles->resolve( $id ),
			);
		}

		return $options;
	}

	/**
	 * Preload settings data on the settings route.
	 */
	private function maybe_preload_settings_data(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The route only controls which read-only data is preloaded.
		$route_path = isset( $_GET['path'] ) ? sanitize_text_field( wp_unslash( $_GET['path'] ) ) : null;
		if ( null !== $route_path && '/' !== $route_path ) {
			return;
		}

		$preload_data = rest_preload_api_request( array(), '/wc-fraud-protection/v1/settings' );
		wp_add_inline_script(
			self::SCRIPT_HANDLE,
			sprintf(
				'wp.apiFetch.use( wp.apiFetch.createPreloadingMiddleware( %s ) );',
				wp_json_encode( $preload_data, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES )
			),
			'before'
		);
	}
}
