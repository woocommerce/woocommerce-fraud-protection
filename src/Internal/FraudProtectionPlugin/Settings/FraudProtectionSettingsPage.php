<?php
/**
 * FraudProtectionSettingsPage class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\Settings;

use Automattic\WooCommerce\Internal\FraudProtectionPlugin\PluginInitializer;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Logging\FraudProtectionLogger;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the Fraud prevention page to WooCommerce settings.
 */
class FraudProtectionSettingsPage extends \WC_Settings_Page {

	public const PAGE_ID = 'woocommerce_fraud_protection';

	private const SCRIPT_HANDLE = 'wc-fraud-protection-admin-settings';

	/**
	 * Routes that show their own breadcrumb header instead of the WooCommerce
	 * settings header and tabs.
	 */
	private const DRILL_DOWN_ROUTES = array( '/rules', '/checkout-attempts' );

	/**
	 * Logger instance.
	 *
	 * @var FraudProtectionLogger
	 */
	private FraudProtectionLogger $logger;

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
	 * @param FraudProtectionLogger $logger Logger instance.
	 */
	final public function init( FraudProtectionLogger $logger ): void {
		$this->logger = $logger;
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

		// The React app keeps the drill-down class in sync on client-side
		// navigation. Setting it here hides the settings header and tabs from
		// the first paint, before the app mounts.
		$classes = 'wc-settings-prevent-change-event';
		if ( in_array( $this->get_route_path(), self::DRILL_DOWN_ROUTES, true ) ) {
			$classes .= ' is-drill-down';
		}

		echo '<div id="wc-fraud-protection-settings" class="' . esc_attr( $classes ) . '"></div>';
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
			array( 'wp-components', 'wc-admin-style' ),
			$asset['version']
		);
		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			plugins_url( 'build/admin-settings.js', WC_FRAUD_PROTECTION_PLUGIN_FILE ),
			$asset['dependencies'],
			$asset['version'],
			array( 'in_footer' => true )
		);
		wp_set_script_translations( self::SCRIPT_HANDLE, 'woocommerce-fraud-protection', PluginInitializer::get_script_translation_dir() );
		$this->maybe_preload_settings_data();
	}

	/**
	 * Preload the settings REST data on the routes that read it.
	 *
	 * Both the settings pane and the checkout attempts list read the
	 * automatic-protection state from the settings store, so its initial GET is
	 * preloaded on either route. Other routes do not, so the query does not run
	 * where it is not needed.
	 */
	private function maybe_preload_settings_data(): void {
		$route_path = $this->get_route_path();
		if ( null !== $route_path && '/' !== $route_path && '/checkout-attempts' !== $route_path ) {
			return;
		}

		$preload_data = rest_preload_api_request( array(), SettingsRestController::SETTINGS_ROUTE );
		wp_add_inline_script(
			self::SCRIPT_HANDLE,
			sprintf(
				'wp.apiFetch.use( wp.apiFetch.createPreloadingMiddleware( %s ) );',
				wp_json_encode( $preload_data, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES )
			),
			'before'
		);
	}

	/**
	 * Get the React route path requested in the URL.
	 *
	 * @return string|null The route path, or null when none is requested.
	 */
	private function get_route_path(): ?string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The route only selects read-only page output.
		return isset( $_GET['path'] ) && is_string( $_GET['path'] ) ? sanitize_text_field( wp_unslash( $_GET['path'] ) ) : null;
	}
}
