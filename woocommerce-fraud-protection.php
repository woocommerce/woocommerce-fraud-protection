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
use Automattic\WooCommerce\FraudProtection\FraudProtectionReporter;
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
 * @deprecated 0.1.4 Resolve FraudProtectionReporter from wc_get_container() and call its run() method instead.
 *
 * @param \WC_Order          $order   The order to report on.
 * @param string             $source  The source of the event. Use ApiClient::REPORT_SOURCE_* constants; an unknown value defaults to REPORT_SOURCE_API.
 * @param ?ReportContextData $context The normalized event context, or null to skip.
 * @param string             $notes   Free-form notes. Must not contain raw gateway or customer data.
 */
function wc_fraud_protection_report( \WC_Order $order, string $source, ?ReportContextData $context, string $notes = '' ): void {
	if ( ! did_action( 'woocommerce_init' ) || ! function_exists( 'wc_get_container' ) || ! class_exists( FraudProtectionController::class, false ) ) {
		return;
	}

	wc_doing_it_wrong(
		__FUNCTION__,
		sprintf(
			'%s() is deprecated. Resolve %s from wc_get_container() and call its run() method instead.',
			__FUNCTION__,
			FraudProtectionReporter::class
		),
		'0.1.4'
	);

	wc_get_container()->get( FraudProtectionReporter::class )->run( $order, $source, $context, $notes );
}
