<?php
/**
 * FraudProtectionSettingsPage class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\Settings;

use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Logging\FraudProtectionLogger;

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
		$this->setup_rest_preload();
	}

	/**
	 * Preload the settings REST response for the application.
	 */
	private function setup_rest_preload(): void {
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
