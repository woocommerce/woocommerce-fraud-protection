<?php
/**
 * SettingsRestController class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Provides the merchant settings endpoint.
 */
class SettingsRestController extends \WP_REST_Controller {

	/**
	 * Automatic-protection setting.
	 *
	 * @var AutomaticProtectionSetting
	 */
	private AutomaticProtectionSetting $automatic_protection;

	/**
	 * Automatic-protection setting updater.
	 *
	 * @var AutomaticProtectionSettingUpdater
	 */
	private AutomaticProtectionSettingUpdater $updater;

	/**
	 * Initialize with dependencies.
	 *
	 * @internal
	 *
	 * @param AutomaticProtectionSetting        $automatic_protection Automatic-protection setting.
	 * @param AutomaticProtectionSettingUpdater $updater              Automatic-protection setting updater.
	 */
	final public function init( AutomaticProtectionSetting $automatic_protection, AutomaticProtectionSettingUpdater $updater ): void {
		$this->namespace            = 'wc-fraud-protection/v1';
		$this->rest_base            = 'settings';
		$this->automatic_protection = $automatic_protection;
		$this->updater              = $updater;
	}

	/**
	 * Register the route.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the supported settings operations.
	 *
	 * @internal
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_settings' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(
						'automatic_protection' => array(
							'type'              => 'boolean',
							'required'          => true,
							'validate_callback' => 'rest_validate_request_arg',
							'sanitize_callback' => 'rest_sanitize_boolean',
						),
					),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
	}

	/**
	 * Check access to merchant settings.
	 *
	 * @internal
	 */
	public function permissions_check(): bool {
		return current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Read the effective settings.
	 *
	 * @internal
	 *
	 * @return \WP_REST_Response
	 */
	public function get_settings(): \WP_REST_Response {
		return rest_ensure_response( array( 'automatic_protection' => $this->automatic_protection->is_enabled() ) );
	}

	/**
	 * Update allowlisted settings.
	 *
	 * @internal
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_settings( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$enabled = true === $request->get_param( 'automatic_protection' );

		if ( ! $this->updater->set_enabled( $enabled, SettingsChangeChannel::Settings ) ) {
			return new \WP_Error( 'woocommerce_fraud_protection_setting_not_saved', __( 'The Fraud Protection setting could not be saved.', 'woocommerce-fraud-protection' ), array( 'status' => 500 ) );
		}

		return $this->get_settings();
	}

	/**
	 * Get the public response schema.
	 *
	 * @return array<string, mixed>
	 */
	public function get_public_item_schema(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'woocommerce_fraud_protection_settings',
			'type'       => 'object',
			'properties' => array(
				'automatic_protection' => array(
					'description' => __( 'Whether automatic protection is enabled.', 'woocommerce-fraud-protection' ),
					'type'        => 'boolean',
					'context'     => array( 'view', 'edit' ),
				),
			),
		);
	}
}
