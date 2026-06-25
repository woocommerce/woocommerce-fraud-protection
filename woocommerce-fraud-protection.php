<?php
/**
 * Plugin Name: WooCommerce Fraud Protection
 * Description: A plugin to protect WooCommerce from fraud.
 * Version: 0.1.3
 * Author: Automattic
 * Requires Plugins: woocommerce
 * WC requires at least: 8.5.0
 *
 * @package WooCommerce\FraudProtection
 */

declare( strict_types = 1 );

use Automattic\WooCommerce\FraudProtection\FraudProtectionController;
use Automattic\WooCommerce\FraudProtection\OrderEventsTracker;
use Automattic\WooCommerce\FraudProtection\PluginInitializer;
use Automattic\WooCommerce\FraudProtection\Schemas\ReportContextData;

defined( 'ABSPATH' ) || exit;

// Kill-switch: define WC_FRAUD_PROTECTION_DISABLED as true to disable.
if ( defined( 'WC_FRAUD_PROTECTION_DISABLED' ) && WC_FRAUD_PROTECTION_DISABLED ) {
	return;
}

require_once __DIR__ . '/src/PluginInitializer.php';
PluginInitializer::run( __FILE__ );

/**
 * Report a normalized payment-outcome event to the Blackbox API.
 *
 * This is the public API for 3rd-party plugins (e.g. payment gateways) to report
 * payment, dispute, and refund outcomes correlated with the original fraud-check
 * session. Build `$context` with `ReportContextData::from_array()`, which returns null for an
 * unmappable event — passing that here is safe and simply skips the report.
 *
 * Must be called after the session ID has been persisted to order meta
 * (i.e. after `woocommerce_store_api_checkout_order_processed`).
 *
 * @param \WC_Order          $order   The order to report on.
 * @param string             $source  The source of the event. Use ApiClient::REPORT_SOURCE_* constants; an unknown value defaults to REPORT_SOURCE_API.
 * @param ?ReportContextData $context The normalized event context, or null to skip.
 * @param string             $notes   Free-form notes. Must not contain raw gateway or customer data.
 */
function wc_fraud_protection_report( \WC_Order $order, string $source, ?ReportContextData $context, string $notes = '' ): void {
	if ( ! function_exists( 'WC' ) || ! function_exists( 'wc_get_container' ) ) {
		return;
	}

	// Callers may reach this before woocommerce_loaded fires, so the autoloader
	// may not yet be required. Defensively load it before touching any namespaced class.
	if ( ! class_exists( FraudProtectionController::class, false ) ) {
		$autoload = WC_FRAUD_PROTECTION_PLUGIN_DIR . '/vendor/autoload.php';
		if ( ! is_readable( $autoload ) ) {
			return;
		}
		require_once $autoload;
	}

	if ( ! class_exists( FraudProtectionController::class ) || ! FraudProtectionController::feature_is_enabled() ) {
		return;
	}

	// from_array() returns null for an unmappable event; skip rather than fatal at the caller.
	if ( null === $context ) {
		FraudProtectionController::log( 'warning', 'wc_fraud_protection_report() received no reportable context; skipping.' );
		return;
	}

	$order_events_tracker = wc_get_container()->get( OrderEventsTracker::class );
	$order_events_tracker->fraud_protection_report( $order, $source, $context, $notes );
}
