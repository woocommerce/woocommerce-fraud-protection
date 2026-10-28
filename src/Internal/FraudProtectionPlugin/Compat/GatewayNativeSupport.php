<?php
/**
 * GatewayNativeSupport class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\Compat;

use Automattic\WooCommerce\FraudProtection\FraudProtectionFeature;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\FraudProtectionController;

defined( 'ABSPATH' ) || exit;

/**
 * Answers whether a registered payment gateway declares a native Fraud Protection feature.
 *
 * A compatibility layer asks this before doing the gateway's work, and stands aside when
 * the gateway declares the matching {@see FraudProtectionFeature} through
 * `WC_Payment_Gateway::supports()`. Gateway instances exist only once
 * `WC_Payment_Gateways` has initialized them, after WordPress `init`, so the check runs
 * inside hook callbacks and never at registration time. Any failure answers "not
 * declared", which keeps the compatibility layer on.
 */
class GatewayNativeSupport {

	/**
	 * Log context event source.
	 */
	private const EVENT_SOURCE = 'gateway_native_support';

	/**
	 * Feature and gateway pairs already logged as declared in this request.
	 *
	 * @var array<string, true>
	 */
	private array $logged_declarations = array();

	/**
	 * Whether a check before `init` was already logged in this request.
	 *
	 * @var bool
	 */
	private bool $logged_early_check = false;

	/**
	 * Check whether any of the given gateways declares a feature.
	 *
	 * Pass the gateway of the current attempt first and the family's main gateway last,
	 * so a declaration on the main gateway covers its payment-method instances.
	 *
	 * @param string $feature        The feature name, one of the {@see FraudProtectionFeature} constants.
	 * @param string ...$gateway_ids Gateway IDs to ask, in order. Unregistered IDs are skipped.
	 * @return bool True when a registered gateway declares the feature.
	 */
	public function declared( string $feature, string ...$gateway_ids ): bool {
		if ( ! did_action( 'init' ) ) {
			$this->log_early_check( $feature );
			return false;
		}

		try {
			$gateways = $this->get_registered_gateways();

			foreach ( array_unique( $gateway_ids ) as $gateway_id ) {
				if ( '' === $gateway_id || ! isset( $gateways[ $gateway_id ] ) ) {
					continue;
				}

				if ( true === $gateways[ $gateway_id ]->supports( $feature ) ) {
					$this->log_declaration( $feature, $gateway_id );
					return true;
				}
			}
		} catch ( \Throwable $e ) {
			FraudProtectionController::log(
				'warning',
				'Checking a gateway for native Fraud Protection support failed; the compatibility layer stays on',
				array(
					'event_source'      => self::EVENT_SOURCE,
					'feature'           => $feature,
					'exception_class'   => $e::class,
					'exception_message' => $e->getMessage(),
				)
			);
		}

		return false;
	}

	/**
	 * Get the features every registered gateway declares.
	 *
	 * @return array<string, string[]> Declared feature names keyed by gateway ID, for gateways that declare at least one.
	 */
	public function get_declarations(): array {
		if ( ! did_action( 'init' ) ) {
			return array();
		}

		$declarations = array();

		try {
			foreach ( $this->get_registered_gateways() as $gateway_id => $gateway ) {
				$features = array_values(
					array_filter(
						FraudProtectionFeature::ALL,
						static fn( string $feature ): bool => true === $gateway->supports( $feature )
					)
				);

				if ( array() !== $features ) {
					$declarations[ $gateway_id ] = $features;
				}
			}
		} catch ( \Throwable $e ) {
			FraudProtectionController::log(
				'warning',
				'Listing the gateways with native Fraud Protection support failed',
				array(
					'event_source'      => self::EVENT_SOURCE,
					'exception_class'   => $e::class,
					'exception_message' => $e->getMessage(),
				)
			);

			return array();
		}

		return $declarations;
	}

	/**
	 * Get the registered payment gateways keyed by ID.
	 *
	 * @return array<string, \WC_Payment_Gateway>
	 */
	private function get_registered_gateways(): array {
		if ( ! function_exists( 'WC' ) ) {
			return array();
		}

		$gateways = array();
		foreach ( WC()->payment_gateways()->payment_gateways() as $gateway_id => $gateway ) {
			if ( is_string( $gateway_id ) && $gateway instanceof \WC_Payment_Gateway ) {
				$gateways[ $gateway_id ] = $gateway;
			}
		}

		return $gateways;
	}

	/**
	 * Log a declaration once per feature and gateway per request.
	 *
	 * @param string $feature    The declared feature.
	 * @param string $gateway_id The declaring gateway.
	 */
	private function log_declaration( string $feature, string $gateway_id ): void {
		$key = $feature . '|' . $gateway_id;
		if ( isset( $this->logged_declarations[ $key ] ) ) {
			return;
		}

		$this->logged_declarations[ $key ] = true;

		FraudProtectionController::log(
			'info',
			'Gateway declares native Fraud Protection support; the built-in compatibility layer stands aside',
			array(
				'event_source' => self::EVENT_SOURCE,
				'payment_type' => $gateway_id,
				'feature'      => $feature,
			)
		);
	}

	/**
	 * Log a check made before gateways can exist, once per request.
	 *
	 * @param string $feature The feature that was checked.
	 */
	private function log_early_check( string $feature ): void {
		if ( $this->logged_early_check ) {
			return;
		}

		$this->logged_early_check = true;

		FraudProtectionController::log(
			'warning',
			'Native gateway support was checked before `init`, when gateways are not registered yet; the compatibility layer stays on',
			array(
				'event_source' => self::EVENT_SOURCE,
				'feature'      => $feature,
			)
		);
	}
}
