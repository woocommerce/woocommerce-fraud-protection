<?php
/**
 * SettingsTelemetry class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\Settings;

use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Logging\FraudProtectionLogger;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Rules\RuleStore;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Sessions\SessionEventStore;

defined( 'ABSPATH' ) || exit;

/**
 * Records settings actions and adds settings state to WooCommerce Tracker.
 */
class SettingsTelemetry {

	/**
	 * Merchant-facing features gate.
	 *
	 * @var MerchantFacingFeaturesGate
	 */
	private MerchantFacingFeaturesGate $merchant_facing_features_gate;

	/**
	 * Automatic-protection setting.
	 *
	 * @var AutomaticProtectionSetting
	 */
	private AutomaticProtectionSetting $automatic_protection;

	/**
	 * Session event store.
	 *
	 * @var SessionEventStore
	 */
	private SessionEventStore $session_event_store;

	/**
	 * Rule store.
	 *
	 * @var RuleStore
	 */
	private RuleStore $rule_store;

	/**
	 * Logger instance.
	 *
	 * @var FraudProtectionLogger
	 */
	private FraudProtectionLogger $logger;

	/**
	 * Initialize with dependencies.
	 *
	 * @internal
	 *
	 * @param MerchantFacingFeaturesGate $merchant_facing_features_gate Merchant-facing features gate.
	 * @param AutomaticProtectionSetting $automatic_protection          Automatic-protection setting.
	 * @param SessionEventStore          $session_event_store           Session event store.
	 * @param RuleStore                  $rule_store                    Rule store.
	 * @param FraudProtectionLogger      $logger                        Logger instance.
	 */
	final public function init(
		MerchantFacingFeaturesGate $merchant_facing_features_gate,
		AutomaticProtectionSetting $automatic_protection,
		SessionEventStore $session_event_store,
		RuleStore $rule_store,
		FraudProtectionLogger $logger
	): void {
		$this->merchant_facing_features_gate = $merchant_facing_features_gate;
		$this->automatic_protection          = $automatic_protection;
		$this->session_event_store           = $session_event_store;
		$this->rule_store                    = $rule_store;
		$this->logger                        = $logger;
	}

	/**
	 * Register Tracks and Tracker integration.
	 */
	public function register(): void {
		add_filter( 'woocommerce_tracker_data', array( $this, 'add_tracker_data' ) );
		add_filter( 'woocommerce_tracks_event_properties', array( $this, 'add_settings_view_source' ), 10, 2 );
	}

	/**
	 * Add the source to Fraud Protection settings-page views.
	 *
	 * @internal
	 *
	 * @param mixed $properties Existing event properties.
	 * @param mixed $event_name Prefixed Tracks event name.
	 * @return mixed
	 */
	public function add_settings_view_source( $properties, $event_name ) {
		if (
			'wcadmin_settings_view' !== $event_name
			|| ! is_array( $properties )
			|| FraudProtectionSettingsPage::PAGE_ID !== ( $properties['tab'] ?? null )
		) {
			return $properties;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The exact marker is compared before a fixed allowlisted value is used.
		$request_source       = is_string( $_GET['source'] ?? null ) ? wp_unslash( $_GET['source'] ) : '';
		$properties['source'] = 'inbox' === $request_source ? 'inbox' : 'settings';

		return $properties;
	}

	/**
	 * Add this plugin's current state and recent outcomes to WooCommerce Tracker.
	 *
	 * @internal
	 *
	 * @param mixed $data Existing Tracker data.
	 * @return array<string, mixed>
	 */
	public function add_tracker_data( $data ): array {
		$data       = is_array( $data ) ? $data : array();
		$extensions = is_array( $data['extensions'] ?? null ) ? $data['extensions'] : array();
		$plugin     = is_array( $extensions['woocommerce_fraud_protection'] ?? null ) ? $extensions['woocommerce_fraud_protection'] : array();

		$plugin['merchant_facing_features_status'] = $this->merchant_facing_features_gate->get_status()->value;
		$plugin['automatic_protection_status']     = $this->automatic_protection->get_status()->value;
		$plugin['automatic_protection_source']     = $this->automatic_protection->get_source()->value;

		try {
			$performance                               = $this->session_event_store->get_performance_counts();
			$plugin['automatic_blocks_suppressed_30d'] = $performance['recommended_for_blocking'];
			$plugin['automatic_blocks_applied_30d']    = $performance['blocked_automatically'];
			$plugin['allow_rule_matches_30d']          = $performance['allowed_by_rules'];
			$plugin['block_rule_matches_30d']          = $performance['blocked_by_rules'];
		} catch ( \Throwable $error ) {
			$this->log_aggregate_failure( 'performance_counts', $error );
		}

		try {
			$plugin = array_merge( $plugin, $this->session_event_store->get_tracker_counts() );
		} catch ( \Throwable $error ) {
			$this->log_aggregate_failure( 'tracker_counts', $error );
		}

		try {
			$plugin = array_merge( $plugin, $this->rule_store->get_active_counts() );
		} catch ( \Throwable $error ) {
			$this->log_aggregate_failure( 'rule_totals', $error );
		}

		$extensions['woocommerce_fraud_protection'] = $plugin;
		$data['extensions']                         = $extensions;

		return $data;
	}

	/**
	 * Record a confirmed automatic-protection transition.
	 *
	 * @param AutomaticProtectionChange $change Setting change.
	 * @param SettingsChangeChannel     $channel Change channel.
	 */
	public function record_automatic_protection_change( AutomaticProtectionChange $change, SettingsChangeChannel $channel ): void {
		$properties = array(
			'state'   => $change->value,
			'channel' => $channel->value,
		);

		try {
			$properties = array_merge( $properties, $this->session_event_store->get_automatic_block_counts() );
		} catch ( \Throwable $error ) {
			$this->log_aggregate_failure( 'automatic_block_counts', $error );
		}

		try {
			$properties = array_merge( $properties, $this->rule_store->get_creation_counts() );
		} catch ( \Throwable $error ) {
			$this->log_aggregate_failure( 'rule_creation_counts', $error );
		}

		try {
			\WC_Tracks::record_event( 'fraud_protection_automatic_protection_changed', $properties );
		} catch ( \Throwable $error ) {
			$this->logger->log(
				'warning',
				'Unable to record a Fraud Protection Tracks event.',
				array(
					'exception_class'   => $error::class,
					'exception_message' => $error->getMessage(),
				)
			);
		}
	}

	/**
	 * Log an isolated aggregate-query failure.
	 *
	 * @param string     $aggregate Aggregate identifier.
	 * @param \Throwable $error     Query error.
	 */
	private function log_aggregate_failure( string $aggregate, \Throwable $error ): void {
		$this->logger->log(
			'warning',
			'Unable to collect Fraud Protection telemetry counts.',
			array(
				'aggregate'         => $aggregate,
				'exception_class'   => $error::class,
				'exception_message' => $error->getMessage(),
			)
		);
	}
}
