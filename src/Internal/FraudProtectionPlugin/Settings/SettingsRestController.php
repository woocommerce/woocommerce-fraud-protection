<?php
/**
 * SettingsRestController class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\Settings;

use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Sessions\SessionEventStore;

defined( 'ABSPATH' ) || exit;

/**
 * Provides the merchant settings endpoint.
 */
class SettingsRestController extends \WP_REST_Controller {

	private const REST_NAMESPACE = 'wc-fraud-protection/v1';

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
	 * Session event store.
	 *
	 * @var SessionEventStore
	 */
	private SessionEventStore $event_store;

	/**
	 * Initialize with dependencies.
	 *
	 * @internal
	 *
	 * @param AutomaticProtectionSetting        $automatic_protection Automatic-protection setting.
	 * @param AutomaticProtectionSettingUpdater $updater              Automatic-protection setting updater.
	 * @param SessionEventStore                 $event_store          Session event store.
	 */
	final public function init( AutomaticProtectionSetting $automatic_protection, AutomaticProtectionSettingUpdater $updater, SessionEventStore $event_store ): void {
		$this->namespace            = self::REST_NAMESPACE;
		$this->rest_base            = 'settings';
		$this->automatic_protection = $automatic_protection;
		$this->updater              = $updater;
		$this->event_store          = $event_store;
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
			self::REST_NAMESPACE,
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
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_settings(): \WP_REST_Response|\WP_Error {
		try {
			$performance = $this->event_store->get_performance_counts();
		} catch ( \RuntimeException ) {
			return new \WP_Error( 'woocommerce_fraud_protection_settings_not_loaded', __( 'The fraud prevention settings could not be loaded.', 'woocommerce-fraud-protection' ), array( 'status' => 500 ) );
		}

		return rest_ensure_response(
			array(
				'automatic_protection' => $this->automatic_protection->is_enabled(),
				'performance'          => $performance,
			)
		);
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
			return new \WP_Error( 'woocommerce_fraud_protection_setting_not_saved', __( 'The fraud prevention setting could not be saved.', 'woocommerce-fraud-protection' ), array( 'status' => 500 ) );
		}

		return rest_ensure_response( array( 'automatic_protection' => $this->automatic_protection->is_enabled() ) );
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
				'performance'          => array(
					'description' => __( 'Fraud prevention performance for the previous 30 days.', 'woocommerce-fraud-protection' ),
					'type'        => 'object',
					'context'     => array( 'view' ),
					'readonly'    => true,
					'properties'  => array(
						'recommended_for_blocking' => array( 'type' => 'integer' ),
						'blocked_automatically'    => array( 'type' => 'integer' ),
						'allowed_by_rules'         => array( 'type' => 'integer' ),
						'blocked_by_rules'         => array( 'type' => 'integer' ),
					),
				),
			),
		);
	}
}
