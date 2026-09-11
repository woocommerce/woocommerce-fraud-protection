<?php
/**
 * PaymentDataResolver class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin;

use Automattic\WooCommerce\FraudProtection\Schemas\PaymentInstrumentData;
use Automattic\WooCommerce\FraudProtection\Schemas\PaymentMethodData;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves payment data into structured PaymentMethodData.
 *
 * Accepts a flat key-value map of payment data and applies the
 * `woocommerce_fraud_protection_resolved_payment_data` filter to let
 * gateway compat layers resolve it into a PaymentMethodData record object.
 *
 * Callers are responsible for normalizing their input into a flat map
 * before calling resolve() (e.g. BlocksCheckoutProtector normalizes the
 * Store API [{key, value}, ...] format; shortcode checkout already has flat POST data).
 *
 * Implements a fail-open pattern: falls back to the baseline (with just the
 * gateway ID) if resolution fails for any reason.
 */
class PaymentDataResolver {

	/**
	 * Resolve the plugin version for a payment gateway.
	 *
	 * @param string $payment_method The gateway ID.
	 * @return string The plugin version, or an empty string when unavailable.
	 *
	 * @since 0.2.4
	 */
	public function resolve_gateway_plugin_version( string $payment_method ): string {
		if ( '' === $payment_method || ! function_exists( 'WC' ) ) {
			return '';
		}

		try {
			$gateways = WC()->payment_gateways()->payment_gateways();
			$gateway  = $gateways[ $payment_method ] ?? null;
			if ( null === $gateway ) {
				return '';
			}

			$gateway_file = ( new \ReflectionClass( $gateway ) )->getFileName();
			return is_string( $gateway_file ) ? $this->resolve_active_plugin_version( $gateway_file ) : '';
		} catch ( \Throwable $e ) {
			FraudProtectionController::log(
				'warning',
				'Payment gateway plugin version resolution failed',
				array(
					'payment_type'      => $payment_method,
					'hook'              => 'payment_gateway_plugin_version_resolution',
					'exception_class'   => $e::class,
					'exception_message' => $e->getMessage(),
					'exception_file'    => $e->getFile(),
					'exception_line'    => $e->getLine(),
				)
			);
			return '';
		}
	}

	/**
	 * Resolve payment data into structured PaymentMethodData.
	 *
	 * @param string $payment_method        The gateway ID (e.g. 'woocommerce_payments', 'stripe').
	 * @param array  $checkout_payment_fields Flat key-value map of checkout payment fields.
	 * @return PaymentMethodData Resolved payment data (at minimum contains the gateway ID).
	 */
	public function resolve( string $payment_method, array $checkout_payment_fields ): PaymentMethodData {
		$pre_resolved_payment_data = $this->resolve_from_wc_token( $payment_method, $checkout_payment_fields )
			?? new PaymentMethodData( $payment_method );

		try {
			/**
			 * Filters the resolved payment method data for fraud protection.
			 *
			 * Gateway compat layers hook into this filter to resolve raw payment data
			 * into a structured PaymentMethodData record object containing normalized
			 * payment instrument details (card brand, last4, funding type, etc.).
			 *
			 * The initial value is always a PaymentMethodData: either pre-resolved
			 * from a saved WC payment token, or a baseline with just the gateway ID.
			 * Compat layers may enrich it or replace it entirely. The gateway ID
			 * is available via $resolved->get_gateway().
			 *
			 * @since 0.1.0
			 *
			 * @param PaymentMethodData $resolved               The resolved payment data (baseline or pre-resolved from WC token).
			 * @param array             $checkout_payment_fields Flat key-value map of checkout payment fields.
			 */
			$resolved_payment_data = apply_filters(
				'woocommerce_fraud_protection_resolved_payment_data',
				$pre_resolved_payment_data,
				$checkout_payment_fields
			);
		} catch ( \Throwable $e ) {
			FraudProtectionController::log(
				'warning',
				'Filter `woocommerce_fraud_protection_resolved_payment_data` threw',
				array(
					'filter'                    => 'woocommerce_fraud_protection_resolved_payment_data',
					'payment_type'              => $payment_method,
					'exception_class'           => $e::class,
					'exception_message'         => $e->getMessage(),
					'exception_file'            => $e->getFile(),
					'exception_line'            => $e->getLine(),
					'pre_resolved_payment_data' => $pre_resolved_payment_data->to_array(),
				),
				true
			);
			return $pre_resolved_payment_data;
		}

		if ( ! $resolved_payment_data instanceof PaymentMethodData && null !== $resolved_payment_data ) {
			$log_context = array(
				'filter'                    => 'woocommerce_fraud_protection_resolved_payment_data',
				'payment_type'              => $payment_method,
				'argument_type'             => gettype( $resolved_payment_data ),
				'pre_resolved_payment_data' => $pre_resolved_payment_data->to_array(),
			);
			if ( is_object( $resolved_payment_data ) ) {
				$log_context['argument_class'] = $resolved_payment_data::class;
			}

			FraudProtectionController::log(
				'warning',
				sprintf(
					'Filter `woocommerce_fraud_protection_resolved_payment_data` returned unexpected type: %s',
					gettype( $resolved_payment_data )
				),
				$log_context,
				true
			);
		}

		return $resolved_payment_data instanceof PaymentMethodData ? $resolved_payment_data : $pre_resolved_payment_data;
	}

	/**
	 * Attempt to resolve payment data from a WC payment token.
	 *
	 * When a saved payment method is used, the checkout request includes a
	 * `token` key with the WC token ID. This method resolves card details
	 * from the stored token, providing a universal fallback for all gateways.
	 *
	 * @param string $payment_method        The gateway ID (e.g. 'stripe').
	 * @param array  $checkout_payment_fields Flat key-value checkout payment fields.
	 * @return ?PaymentMethodData Resolved data from token, or null.
	 */
	private function resolve_from_wc_token( string $payment_method, array $checkout_payment_fields ): ?PaymentMethodData {
		// Both classic and blocks checkout send 'wc-{gateway}-payment-token'.
		// Blocks checkout also sends a bare 'token' key (used as fallback).
		// Some gateways (e.g. Square via SkyVerge) dasherize the gateway ID in field names,
		// so we try the raw key first, then the dasherized variant.
		$token_id = $checkout_payment_fields[ 'wc-' . $payment_method . '-payment-token' ] ?? '';

		if ( empty( $token_id ) ) {
			$dasherized = str_replace( '_', '-', $payment_method );
			if ( $dasherized !== $payment_method ) {
				$token_id = $checkout_payment_fields[ 'wc-' . $dasherized . '-payment-token' ] ?? '';
			}
		}

		if ( empty( $token_id ) ) {
			$token_id = $checkout_payment_fields['token'] ?? '';
		}

		// "new" means the customer chose to enter a new payment method.
		if ( empty( $token_id ) || 'new' === $token_id ) {
			return null;
		}

		$token = \WC_Payment_Tokens::get( (int) $token_id );
		if ( ! $token instanceof \WC_Payment_Token_CC ) {
			return null;
		}

		if ( $token->get_user_id() !== get_current_user_id() ) {
			return null;
		}

		if ( $token->get_gateway_id() !== $payment_method ) {
			return null;
		}

		return new PaymentMethodData(
			$payment_method,
			'card',
			true,
			PaymentInstrumentData::from_array(
				array(
					'brand'     => $token->get_card_type() ? $token->get_card_type() : null,
					'last4'     => $token->get_last4() ? $token->get_last4() : null,
					'exp_month' => $token->get_expiry_month() ? $token->get_expiry_month() : null,
					'exp_year'  => $token->get_expiry_year() ? $token->get_expiry_year() : null,
				)
			)
		);
	}

	/**
	 * Resolve the active plugin version for a file loaded from that plugin.
	 *
	 * @param string $loaded_file A file declared by the plugin.
	 * @return string The plugin version, or an empty string when unavailable.
	 */
	private function resolve_active_plugin_version( string $loaded_file ): string {
		$loaded_file_path = realpath( $loaded_file );
		if ( false === $loaded_file_path ) {
			return '';
		}

		$active_plugins      = get_option( 'active_plugins', array() );
		$active_plugin_files = is_array( $active_plugins ) ? $active_plugins : array();

		if ( is_multisite() ) {
			$network_plugins = get_site_option( 'active_sitewide_plugins', array() );
			if ( is_array( $network_plugins ) ) {
				$active_plugin_files = array_merge( $active_plugin_files, array_keys( $network_plugins ) );
			}
		}

		$loaded_file_path = wp_normalize_path( $loaded_file_path );
		$checked_plugins  = array();
		$matching_plugins = array();
		foreach ( $active_plugin_files as $plugin_file ) {
			if ( ! is_string( $plugin_file ) ) {
				continue;
			}

			$plugin_file = wp_normalize_path( $plugin_file );
			if (
				isset( $checked_plugins[ $plugin_file ] )
				|| '' === $plugin_file
				|| str_contains( $plugin_file, "\0" )
				|| str_starts_with( $plugin_file, '/' )
				|| ! str_ends_with( $plugin_file, '.php' )
				|| 0 !== validate_file( $plugin_file )
			) {
				continue;
			}
			$checked_plugins[ $plugin_file ] = true;

			$plugin_main_file = WP_PLUGIN_DIR . '/' . $plugin_file;
			if ( ! is_file( $plugin_main_file ) || ! is_readable( $plugin_main_file ) ) {
				continue;
			}

			$plugin_main_path = realpath( $plugin_main_file );
			if ( false === $plugin_main_path ) {
				continue;
			}

			$plugin_directory = dirname( $plugin_file );
			if ( '.' === $plugin_directory ) {
				$is_match = wp_normalize_path( $plugin_main_path ) === $loaded_file_path;
			} else {
				$plugin_directory_path = realpath( dirname( $plugin_main_file ) );
				$is_match              = false !== $plugin_directory_path
					&& str_starts_with( $loaded_file_path, trailingslashit( wp_normalize_path( $plugin_directory_path ) ) );
			}

			if ( $is_match ) {
				$matching_plugins[ wp_normalize_path( $plugin_main_path ) ] = $plugin_main_file;
			}
		}

		if ( 1 !== count( $matching_plugins ) ) {
			return '';
		}

		$plugin_data = get_file_data( reset( $matching_plugins ), array( 'Version' => 'Version' ) );
		$version     = $plugin_data['Version'] ?? '';

		return is_string( $version ) ? trim( $version ) : '';
	}
}
