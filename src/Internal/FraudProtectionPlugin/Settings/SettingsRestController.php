<?php
/**
 * SettingsRestController class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\Settings;

use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Sessions\SessionEventStore;
use Automattic\WooCommerce\Internal\RestApiControllerBase;

defined( 'ABSPATH' ) || exit;

/**
 * Provides the merchant settings endpoint.
 */
class SettingsRestController extends RestApiControllerBase {

	/**
	 * Settings endpoint route, relative to the REST API root.
	 */
	public const SETTINGS_ROUTE = '/wc-admin/fraud-protection/settings';

	/**
	 * The root namespace for the JSON REST API endpoints.
	 *
	 * @var non-falsy-string
	 */
	protected string $route_namespace = 'wc-admin';

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected string $rest_base = 'fraud-protection/settings';

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
		$this->automatic_protection = $automatic_protection;
		$this->updater              = $updater;
		$this->event_store          = $event_store;
	}

	/**
	 * Get the WooCommerce REST API namespace for the class.
	 *
	 * @return string
	 */
	protected function get_rest_api_namespace(): string {
		return 'wc-admin-fraud-protection-settings';
	}

	/**
	 * Register the supported settings operations.
	 *
	 * @internal
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->route_namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => fn() => $this->get_settings(),
					'permission_callback' => fn( \WP_REST_Request $request ) => $this->check_permission( $request, 'manage_woocommerce' ),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => fn( \WP_REST_Request $request ) => $this->update_settings( $request ),
					'permission_callback' => fn( \WP_REST_Request $request ) => $this->check_permission( $request, 'manage_woocommerce' ),
					'args'                => array(
						'automatic_protection' => array(
							'type'              => 'boolean',
							'required'          => true,
							'validate_callback' => 'rest_validate_request_arg',
							'sanitize_callback' => 'rest_sanitize_boolean',
						),
					),
				),
				'schema' => fn() => $this->get_item_schema(),
			)
		);

		register_rest_route(
			$this->route_namespace,
			'/' . $this->rest_base . '/opt-out',
			array(
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => fn( \WP_REST_Request $request ) => $this->opt_out( $request ),
					'permission_callback' => fn( \WP_REST_Request $request ) => $this->check_permission( $request, 'manage_woocommerce' ),
					'args'                => array(
						'source' => array(
							'type'     => 'string',
							'required' => true,
							'enum'     => array( 'inbox', 'settings' ),
						),
					),
				),
			)
		);
	}

	/**
	 * Read the effective settings.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function get_settings(): \WP_REST_Response|\WP_Error {
		try {
			$performance = $this->event_store->get_performance_counts();
		} catch ( \RuntimeException ) {
			return new \WP_Error( 'woocommerce_fraud_protection_settings_not_loaded', __( 'The fraud prevention settings could not be loaded.', 'woocommerce-fraud-protection' ), array( 'status' => 500 ) );
		}

		return rest_ensure_response(
			array(
				'automatic_protection'            => $this->automatic_protection->is_enabled(),
				'automatic_protection_opted_out'  => $this->automatic_protection->is_opted_out(),
				'automatic_protection_enabled_at' => $this->get_enabled_at_rfc3339(),
				'performance'                     => $performance,
			)
		);
	}

	/**
	 * Update allowlisted settings.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function update_settings( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$enabled = true === $request->get_param( 'automatic_protection' );

		if ( ! $this->updater->set_enabled( $enabled, SettingsChangeChannel::Settings ) ) {
			return new \WP_Error( 'woocommerce_fraud_protection_setting_not_saved', __( 'The fraud prevention setting could not be saved.', 'woocommerce-fraud-protection' ), array( 'status' => 500 ) );
		}

		return rest_ensure_response( $this->get_setting_values() );
	}

	/**
	 * Store an automatic-enrollment opt-out.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function opt_out( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$source = 'inbox' === $request->get_param( 'source' ) ? 'inbox' : 'settings';

		if ( ! $this->updater->opt_out( $source ) ) {
			return new \WP_Error( 'woocommerce_fraud_protection_setting_not_saved', __( 'We could not opt you out of automatic blocking.', 'woocommerce-fraud-protection' ), array( 'status' => 500 ) );
		}

		return rest_ensure_response( $this->get_setting_values() );
	}

	/**
	 * Get the response schema.
	 *
	 * @return array<string, mixed>
	 */
	private function get_item_schema(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'woocommerce_fraud_protection_settings',
			'type'       => 'object',
			'properties' => array(
				'automatic_protection'            => array(
					'description' => __( 'Whether automatic fraud prevention is enabled.', 'woocommerce-fraud-protection' ),
					'type'        => 'boolean',
					'context'     => array( 'view', 'edit' ),
				),
				'automatic_protection_opted_out'  => array(
					'description' => __( 'Whether automatic fraud prevention enrollment was declined.', 'woocommerce-fraud-protection' ),
					'type'        => 'boolean',
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'automatic_protection_enabled_at' => array(
					'description' => __( 'The date automatic fraud prevention was last turned on, or null when off.', 'woocommerce-fraud-protection' ),
					'type'        => array( 'string', 'null' ),
					'format'      => 'date-time',
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'performance'                     => array(
					'description' => __( 'Fraud prevention performance for the previous 30 days.', 'woocommerce-fraud-protection' ),
					'type'        => 'object',
					'context'     => array( 'view' ),
					'readonly'    => true,
					'properties'  => array(
						'flagged_by_fraud_prevention' => array( 'type' => 'integer' ),
						'blocked_automatically'       => array( 'type' => 'integer' ),
						'allowed_by_rules'            => array( 'type' => 'integer' ),
						'blocked_by_rules'            => array( 'type' => 'integer' ),
					),
				),
			),
		);
	}

	/**
	 * Get the stored setting values.
	 *
	 * @return array{automatic_protection: bool, automatic_protection_opted_out: bool, automatic_protection_enabled_at: ?string}
	 */
	private function get_setting_values(): array {
		return array(
			'automatic_protection'            => $this->automatic_protection->is_enabled(),
			'automatic_protection_opted_out'  => $this->automatic_protection->is_opted_out(),
			'automatic_protection_enabled_at' => $this->get_enabled_at_rfc3339(),
		);
	}

	/**
	 * Get the enable date as an RFC3339 UTC datetime for the client.
	 *
	 * The setting stores the date as 'Y-m-d H:i:s'; the list formats it in the
	 * site timezone, matching how session and rule dates are returned.
	 *
	 * @return string|null The RFC3339 datetime, or null when protection is off.
	 */
	private function get_enabled_at_rfc3339(): ?string {
		$enabled_at = $this->automatic_protection->get_enabled_at();

		return null === $enabled_at ? null : mysql_to_rfc3339( $enabled_at );
	}
}
