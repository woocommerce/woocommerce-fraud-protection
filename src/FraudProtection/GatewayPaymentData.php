<?php
/**
 * GatewayPaymentData class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\FraudProtection;

use Automattic\WooCommerce\FraudProtection\Schemas\PaymentMethodData;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\FraudProtectionController;

defined( 'ABSPATH' ) || exit;

/**
 * Lets a payment gateway describe the payment instrument of its own attempts.
 *
 * A gateway registers a resolver with {@see register_resolver()}. Before each verification of
 * an attempt paid with that gateway, the plugin calls the resolver through the
 * `woocommerce_fraud_protection_resolved_payment_data` filter and uses the
 * {@see PaymentMethodData} it returns. Attempts paid with other gateways never reach it, and
 * a resolver that throws or returns anything else keeps the data resolved so far.
 *
 * Resolve it from the WooCommerce container with
 * `wc_get_container()->get( GatewayPaymentData::class )` rather than constructing it.
 *
 * @since 0.3.1
 */
class GatewayPaymentData {

	/**
	 * Resolvers registered through {@see register_resolver()}, keyed by gateway ID.
	 *
	 * @var array<string, callable>
	 */
	private array $resolvers = array();

	/**
	 * Register the filter that runs the resolvers.
	 *
	 * @internal
	 */
	public function register(): void {
		add_filter( 'woocommerce_fraud_protection_resolved_payment_data', array( $this, 'apply_resolver' ), 10, 2 );
	}

	/**
	 * Register the resolver that describes the payment instrument of a gateway's attempts.
	 *
	 * Call it once while your plugin loads. The plugin calls `$resolver` only for attempts paid
	 * with `$gateway_id`, with two arguments:
	 *
	 * - `array $payment_fields`: the gateway fields submitted with the attempt, or the payment
	 *   data passed to the verification. Always an array.
	 * - `PaymentMethodData $resolved`: the data resolved so far, pre-filled from the WooCommerce
	 *   payment token when the customer pays with a saved method.
	 *
	 * Return a {@see PaymentMethodData} for `$gateway_id`, or null to keep `$resolved`. A resolver
	 * that throws, or returns another value or the data of another gateway, keeps `$resolved`.
	 * A later call for the same gateway replaces the resolver.
	 *
	 * @param string   $gateway_id ID of the gateway, as in the attempt's payment method.
	 * @param callable $resolver   Receives `array $payment_fields, PaymentMethodData $resolved` and returns `?PaymentMethodData`.
	 *
	 * @since 0.3.1
	 */
	public function register_resolver( string $gateway_id, callable $resolver ): void {
		$this->resolvers[ $gateway_id ] = $resolver;
	}

	/**
	 * Run the resolver registered for the attempt's gateway.
	 *
	 * @internal
	 *
	 * @param mixed $resolved       The payment data resolved so far.
	 * @param mixed $payment_fields Flat key-value map of the attempt's payment fields.
	 * @return mixed The resolver's payment data, or the incoming value.
	 */
	public function apply_resolver( $resolved, $payment_fields ) {
		if ( ! $resolved instanceof PaymentMethodData ) {
			return $resolved;
		}

		$gateway_id = $resolved->get_gateway();
		$resolver   = $this->resolvers[ $gateway_id ] ?? null;
		if ( null === $resolver ) {
			return $resolved;
		}

		try {
			$result = $resolver( is_array( $payment_fields ) ? $payment_fields : array(), $resolved );
		} catch ( \Throwable $e ) {
			FraudProtectionController::log(
				'warning',
				'Payment data resolver threw; keeping the resolved payment data',
				array(
					'filter'            => 'woocommerce_fraud_protection_resolved_payment_data',
					'payment_type'      => $gateway_id,
					'exception_class'   => $e::class,
					'exception_message' => $e->getMessage(),
					'exception_file'    => $e->getFile(),
					'exception_line'    => $e->getLine(),
				),
				true
			);

			return $resolved;
		}

		if ( null === $result ) {
			return $resolved;
		}

		if ( ! $result instanceof PaymentMethodData || $result->get_gateway() !== $gateway_id ) {
			$log_context = array(
				'filter'        => 'woocommerce_fraud_protection_resolved_payment_data',
				'payment_type'  => $gateway_id,
				'argument_type' => get_debug_type( $result ),
			);
			if ( $result instanceof PaymentMethodData ) {
				$log_context['returned_gateway'] = $result->get_gateway();
			}

			FraudProtectionController::log(
				'warning',
				'Payment data resolver returned an unusable value; keeping the resolved payment data',
				$log_context,
				true
			);

			return $resolved;
		}

		return $result;
	}
}
