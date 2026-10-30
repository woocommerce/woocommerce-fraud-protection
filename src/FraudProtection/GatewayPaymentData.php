<?php
/**
 * GatewayPaymentData class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\FraudProtection;

use Automattic\WooCommerce\FraudProtection\Schemas\PaymentMethodData;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Compat\GatewayNativeSupport;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\FraudProtectionController;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\GatewayIdPattern;

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
 * A gateway's resolver always takes precedence over the plugin's built-in compatibility
 * layer for that gateway, whatever the order of registration.
 *
 * Resolve it from the WooCommerce container with
 * `wc_get_container()->get( GatewayPaymentData::class )` rather than constructing it:
 * the container supplies its dependencies through `init()`.
 *
 * @since 0.3.1
 */
class GatewayPaymentData {

	/**
	 * Resolvers registered through {@see register_resolver()}, keyed by gateway ID pattern.
	 *
	 * @var array<string, callable>
	 */
	private array $resolvers = array();

	/**
	 * Resolvers of the plugin's compatibility layers and the gateways whose declaration covers each one, keyed by gateway ID pattern.
	 *
	 * @var array<string, array{resolver: callable, covering_gateway_ids: string[]}>
	 */
	private array $compatibility_resolvers = array();

	/**
	 * Native gateway support checker.
	 *
	 * @var GatewayNativeSupport
	 */
	private GatewayNativeSupport $native_support;

	/**
	 * Initialize dependencies.
	 *
	 * @internal
	 *
	 * @param GatewayNativeSupport $native_support Native gateway support checker.
	 */
	final public function init( GatewayNativeSupport $native_support ): void {
		$this->native_support = $native_support;
	}

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
	 * with a gateway that `$gateway_id` matches, with two arguments:
	 *
	 * - `array $payment_fields`: the gateway fields submitted with the attempt, or the payment
	 *   data passed to the verification. Always an array.
	 * - `PaymentMethodData $resolved`: the data resolved so far, pre-filled from the WooCommerce
	 *   payment token when the customer pays with a saved method.
	 *
	 * Return a {@see PaymentMethodData} for the attempt's gateway, or null to keep `$resolved`. A
	 * resolver that throws, or returns another value or the data of another gateway, keeps
	 * `$resolved`. A later call with the same `$gateway_id` replaces the resolver.
	 *
	 * End `$gateway_id` with `*` to match every gateway whose ID starts with the text before it,
	 * such as `my_gateway_*` for one gateway per payment method. An exact gateway ID takes
	 * precedence over a pattern, and a longer pattern over a shorter one.
	 *
	 * @param string   $gateway_id ID of the gateway, as in the attempt's payment method, or a prefix followed by `*`.
	 * @param callable $resolver   Receives `array $payment_fields, PaymentMethodData $resolved` and returns `?PaymentMethodData`.
	 *
	 * @since 0.3.1
	 */
	public function register_resolver( string $gateway_id, callable $resolver ): void {
		$this->resolvers[ $gateway_id ] = $resolver;
	}

	/**
	 * Register the resolver of one of the plugin's compatibility layers.
	 *
	 * It runs only while no resolver from {@see register_resolver()} matches the attempt's gateway
	 * and neither that gateway nor a covering gateway declares {@see FraudProtectionFeature::PAYMENT_DATA},
	 * so a gateway's native integration always replaces it, whichever registers first.
	 *
	 * @internal
	 *
	 * @param string   $gateway_id              ID of the gateway, or a prefix followed by `*`, as for {@see register_resolver()}.
	 * @param callable $resolver                Same contract as for {@see register_resolver()}.
	 * @param string   ...$covering_gateway_ids Gateways whose declaration also covers `$gateway_id`, such as the main gateway of its family.
	 */
	public function register_compatibility_resolver( string $gateway_id, callable $resolver, string ...$covering_gateway_ids ): void {
		$this->compatibility_resolvers[ $gateway_id ] = array(
			'resolver'             => $resolver,
			'covering_gateway_ids' => $covering_gateway_ids,
		);
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
		$resolver   = $this->get_resolver( $gateway_id );
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

	/**
	 * Get the resolver that describes a gateway's attempts.
	 *
	 * @param string $gateway_id The attempt's gateway.
	 * @return callable|null The best-matching gateway resolver, else a compatibility layer's that the gateway has not replaced.
	 */
	private function get_resolver( string $gateway_id ): ?callable {
		$pattern = GatewayIdPattern::find_best_match( array_keys( $this->resolvers ), $gateway_id );
		if ( null !== $pattern ) {
			return $this->resolvers[ $pattern ];
		}

		$pattern = GatewayIdPattern::find_best_match( array_keys( $this->compatibility_resolvers ), $gateway_id );
		if ( null === $pattern ) {
			return null;
		}

		$compatibility = $this->compatibility_resolvers[ $pattern ];
		if ( $this->native_support->declared( FraudProtectionFeature::PAYMENT_DATA, $gateway_id, ...$compatibility['covering_gateway_ids'] ) ) {
			return null;
		}

		return $compatibility['resolver'];
	}
}
