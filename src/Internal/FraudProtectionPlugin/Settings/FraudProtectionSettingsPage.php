<?php
/**
 * FraudProtectionSettingsPage class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\Settings;

use Automattic\WooCommerce\Internal\FraudProtectionPlugin\FraudProtectionController;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the Fraud prevention page to WooCommerce settings.
 */
class FraudProtectionSettingsPage extends \WC_Settings_Page {

	public const PAGE_ID = 'woocommerce_fraud_protection';

	private const SCRIPT_HANDLE = 'wc-fraud-protection-admin-settings';

	/**
	 * Initialize the WooCommerce settings page.
	 */
	public function __construct() {
		$this->id    = self::PAGE_ID;
		$this->label = __( 'Fraud prevention', 'woocommerce-fraud-protection' );

		parent::__construct();
	}

	/**
	 * Register this settings page's assets.
	 */
	public function register(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
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

		echo '<div id="wc-fraud-protection-settings"></div>';
	}

	/**
	 * Load settings assets only on this WooCommerce settings tab.
	 *
	 * @internal
	 */
	public function enqueue_assets(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only routing parameters.
		$page = isset( $_GET['page'] ) && is_string( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$tab  = isset( $_GET['tab'] ) && is_string( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( 'wc-settings' !== $page || self::PAGE_ID !== $tab ) {
			return;
		}

		$asset_file = dirname( WC_FRAUD_PROTECTION_PLUGIN_FILE ) . '/build/admin-settings.asset.php';
		if ( ! is_readable( $asset_file ) ) {
			FraudProtectionController::log( 'error', 'Fraud Protection settings asset metadata is unavailable.' );
			return;
		}

		$asset = require $asset_file;
		if ( ! is_array( $asset ) || ! is_array( $asset['dependencies'] ?? null ) || ! is_string( $asset['version'] ?? null ) ) {
			FraudProtectionController::log( 'error', 'Fraud Protection settings asset metadata is invalid.' );
			return;
		}

		wp_enqueue_style( 'wp-components' );
		wp_enqueue_style(
			self::SCRIPT_HANDLE,
			plugins_url( 'build/admin-settings.css', WC_FRAUD_PROTECTION_PLUGIN_FILE ),
			array( 'wp-components' ),
			$asset['version']
		);
		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			plugins_url( 'build/admin-settings.js', WC_FRAUD_PROTECTION_PLUGIN_FILE ),
			$asset['dependencies'],
			$asset['version'],
			array( 'in_footer' => true )
		);
	}
}
