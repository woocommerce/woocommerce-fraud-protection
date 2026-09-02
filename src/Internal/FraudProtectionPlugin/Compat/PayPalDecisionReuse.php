<?php
/**
 * PayPalDecisionReuse class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\Compat;

use Automattic\WooCommerce\FraudProtection\Schemas\FraudDecision;
use Automattic\WooCommerce\FraudProtection\SessionIdNormalizer;
use Automattic\WooCommerce\FraudProtection\SuppliedDecision;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\FraudProtectionController;

defined( 'ABSPATH' ) || exit;

/**
 * Preserves one PayPal request decision for its matching final request.
 */
class PayPalDecisionReuse {

	/** Create-order verification source. */
	public const ORDER_CREATION_SOURCE = 'paypal_express_order_creation';

	/** Setup-token verification source. */
	public const SETUP_TOKEN_CREATION_SOURCE = 'paypal_setup_token_creation';

	/** Vault-order verification source. */
	public const VAULT_ORDER_CREATION_SOURCE = 'paypal_vault_order_creation';

	/** PayPal Payments gateway prefix. */
	private const PAYPAL_GATEWAY_PREFIX = 'ppcp-';

	/** WooCommerce session record key. */
	private const VERIFICATION_RECORD_KEY = '_fraud_protection_paypal_verification';

	/**
	 * Session ID normalizer.
	 *
	 * @var SessionIdNormalizer
	 */
	private SessionIdNormalizer $session_id_normalizer;

	/**
	 * Initialize with dependencies.
	 *
	 * @internal
	 *
	 * @param SessionIdNormalizer $session_id_normalizer Session ID normalizer.
	 */
	final public function init( SessionIdNormalizer $session_id_normalizer ): void {
		$this->session_id_normalizer = $session_id_normalizer;
	}

	/**
	 * Register the supplied-decision filter.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'woocommerce_fraud_protection_skip_session_verify', array( $this, 'supply_decision_for_paypal_express' ), 10, 4 );
	}

	/**
	 * Associate a created PayPal order with a request verification.
	 *
	 * @internal
	 *
	 * @param mixed  $order      PayPal order entity.
	 * @param string $session_id Recorded session ID.
	 * @param string $origin     Verification source.
	 * @return void
	 */
	public function associate_created_order( $order, string $session_id, string $origin ): void {
		try {
			if ( '' === $session_id || ! function_exists( 'WC' ) || ! WC()->session ) {
				return;
			}

			if ( ! is_object( $order ) || ! method_exists( $order, 'id' ) ) {
				return;
			}

			$order_id = $order->id();
			if ( ! is_string( $order_id ) || '' === $order_id ) {
				return;
			}

			$record = $this->get_verified_session_record();
			if ( null === $record || $record['session_id'] !== $session_id || $record['origin'] !== $origin ) {
				return;
			}

			$record['order_id'] = $order_id;
			WC()->session->set( self::VERIFICATION_RECORD_KEY, $record );
		} catch ( \Throwable $e ) {
			FraudProtectionController::log(
				'warning',
				'Associating the created PayPal order threw; leaving the verification without an associated order',
				array(
					'hook'              => 'woocommerce_paypal_payments_paypal_order_created',
					'session_id'        => $session_id,
					'exception_class'   => $e::class,
					'exception_message' => $e->getMessage(),
				),
				true
			);
		}
	}

	/**
	 * Skip redundant verification for PayPal flows handled by PayPalCompat.
	 *
	 * Answers requests this class already scored with the decision that scoring
	 * produced, so one payment attempt is not scored twice; everything else is
	 * deferred and verified normally. Standard filter arbitration: a consumer
	 * that wants the last word registers with a later priority.
	 *
	 * @internal
	 *
	 * @param SuppliedDecision|false $supplied_decision The filter's default (false), or what an earlier consumer returned.
	 * @param string                 $source            Source identifier.
	 * @param array                  $request_data      Request data with payment_method, payment_data, etc.
	 * @param string                 $session_id        The Blackbox session ID being verified.
	 * @return SuppliedDecision|false The supplied result, or the value passed in to defer.
	 */
	public function supply_decision_for_paypal_express( SuppliedDecision|false $supplied_decision, string $source, array $request_data, string $session_id ): SuppliedDecision|false {
		if ( in_array( $source, array( self::ORDER_CREATION_SOURCE, self::SETUP_TOKEN_CREATION_SOURCE, self::VAULT_ORDER_CREATION_SOURCE ), true ) ) {
			return $supplied_decision;
		}

		$payment_method = is_string( $request_data['payment_method'] ?? null ) ? $request_data['payment_method'] : '';

		// Not a PayPal gateway — nothing for this filter to do.
		if ( ! $this->is_paypal_gateway( $payment_method ) ) {
			return $supplied_decision;
		}

		$resolved_session_id = '';
		try {
			$record            = $this->get_verified_session_record();
			$stored_session_id = null === $record ? '' : $this->session_id_normalizer->normalize_stored( $record['session_id'] );
			if ( null === $record || $record['used'] || '' === $session_id || '' === $stored_session_id || $stored_session_id !== $session_id ) {
				$this->retire_verification_record();
				return $supplied_decision;
			}

			$resolved_session_id = $stored_session_id;
			$matches             = self::SETUP_TOKEN_CREATION_SOURCE === $record['origin']
				? $this->setup_record_matches( $record, $source )
				: $this->order_record_matches( $record, $source, $request_data );
			if ( ! $matches ) {
				$this->retire_verification_record();
				return $supplied_decision;
			}

			$record['used'] = true;
			WC()->session->set( self::VERIFICATION_RECORD_KEY, $record );

			return new SuppliedDecision( $record['decision'], $record['session_id'] );
		} catch ( \Throwable $e ) {
			$this->retire_verification_record();
			$context = array(
				'event_source'      => $source,
				'exception_class'   => $e::class,
				'exception_message' => $e->getMessage(),
			);
			if ( '' !== $resolved_session_id ) {
				$context['session_id'] = $resolved_session_id;
			}
			FraudProtectionController::log(
				'warning',
				'Reading or consuming the PayPal request verification record failed; final request will verify',
				$context,
				true
			);

			return $supplied_decision;
		}
	}

	/**
	 * The ID of the approved PayPal order in the WC session, if one is there.
	 *
	 * PayPal Payments keeps its order entity under the 'ppcp' session key;
	 * the entity is foreign code's object, so it is read defensively.
	 *
	 * @return string The order ID, or empty string.
	 */
	private function paypal_order_id_in_session(): string {
		$ppcp_session = WC()->session->get( 'ppcp' );

		if ( ! is_array( $ppcp_session ) ) {
			return '';
		}

		$order = $ppcp_session['order'] ?? null;

		if ( ! is_object( $order ) || ! method_exists( $order, 'id' ) ) {
			return '';
		}

		$order_id = $order->id();

		return is_string( $order_id ) ? $order_id : '';
	}

	/**
	 * Store the current response-backed PayPal request decision.
	 *
	 * @internal
	 *
	 * @param string        $origin         Verification source.
	 * @param string        $session_id     Response-backed session ID.
	 * @param FraudDecision $decision       Applied decision.
	 * @param bool          $can_store_result Whether this result can be matched by the submitted session ID.
	 * @return bool Whether the current actionable record was stored.
	 */
	public function record_verification( string $origin, string $session_id, FraudDecision $decision, bool $can_store_result ): bool {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return false;
		}

		$record = null;
		if ( $can_store_result && '' !== $session_id && in_array( $decision, FraudDecision::ACTIONABLE, true ) ) {
			$record = array(
				'origin'     => $origin,
				'session_id' => $session_id,
				'decision'   => $decision,
				'used'       => false,
				'order_id'   => '',
				'cart_hash'  => '',
			);

			if ( self::SETUP_TOKEN_CREATION_SOURCE === $origin ) {
				$cart_hash = $this->eligible_setup_cart_hash();
				$record    = '' === $cart_hash ? null : array_merge( $record, array( 'cart_hash' => $cart_hash ) );
			}
		}

		WC()->session->set( self::VERIFICATION_RECORD_KEY, $record );

		return null !== $record;
	}

	/**
	 * Read the current verification record from the WC session.
	 *
	 * @return ?array{origin: string, session_id: string, decision: FraudDecision, used: bool, order_id: string, cart_hash: string} The record, or null.
	 */
	private function get_verified_session_record(): ?array {
		$stored = WC()->session->get( self::VERIFICATION_RECORD_KEY );

		if ( ! is_array( $stored ) ) {
			return null;
		}

		$origin     = $stored['origin'] ?? null;
		$session_id = $stored['session_id'] ?? null;
		$decision   = $stored['decision'] ?? null;
		$used       = $stored['used'] ?? null;

		if (
			! is_string( $origin )
			|| ! in_array( $origin, array( self::ORDER_CREATION_SOURCE, self::SETUP_TOKEN_CREATION_SOURCE, self::VAULT_ORDER_CREATION_SOURCE ), true )
			|| ! is_string( $session_id )
			|| '' === $session_id
			|| ! $decision instanceof FraudDecision
			|| ! in_array( $decision, FraudDecision::ACTIONABLE, true )
			|| ! is_bool( $used )
		) {
			return null;
		}

		return array(
			'origin'     => $origin,
			'session_id' => $session_id,
			'decision'   => $decision,
			'used'       => $used,
			'order_id'   => is_string( $stored['order_id'] ?? null ) ? $stored['order_id'] : '',
			'cart_hash'  => is_string( $stored['cart_hash'] ?? null ) ? $stored['cart_hash'] : '',
		);
	}

	/**
	 * Check an order record against its permitted final request.
	 *
	 * @param array{origin: string, session_id: string, decision: FraudDecision, used: bool, order_id: string, cart_hash: string} $record       Verification record.
	 * @param string                                                                                                              $source       Verification source.
	 * @param array                                                                                                               $request_data Final request data.
	 * @return bool Whether the request matches.
	 */
	private function order_record_matches( array $record, string $source, array $request_data ): bool {
		$allowed_sources = self::VAULT_ORDER_CREATION_SOURCE === $record['origin']
			? array( 'shortcode_checkout', 'blocks_checkout', 'pay_for_order', 'subscriptions_change_payment' )
			: array( 'shortcode_checkout', 'blocks_checkout', 'pay_for_order' );
		if ( ! in_array( $source, $allowed_sources, true ) || '' === $record['order_id'] ) {
			return false;
		}

		$payment_data = is_array( $request_data['payment_data'] ?? null ) ? $request_data['payment_data'] : array();
		$order_id     = is_string( $payment_data['paypal_order_id'] ?? null ) ? $payment_data['paypal_order_id'] : '';
		if ( '' === $order_id ) {
			$order_id = $this->paypal_order_id_in_session();
		}

		return '' !== $order_id && $record['order_id'] === $order_id;
	}

	/**
	 * Check a setup record against its permitted final request.
	 *
	 * @param array{origin: string, session_id: string, decision: FraudDecision, used: bool, order_id: string, cart_hash: string} $record Verification record.
	 * @param string                                                                                                              $source Verification source.
	 * @return bool Whether the request matches.
	 */
	private function setup_record_matches( array $record, string $source ): bool {
		return in_array( $source, array( 'shortcode_checkout', 'blocks_checkout' ), true )
			&& '' !== $record['cart_hash']
			&& $record['cart_hash'] === $this->eligible_setup_cart_hash();
	}

	/**
	 * Get the cart hash when the current cart can use a setup-token decision.
	 *
	 * @return string Eligible nonempty cart hash, or an empty string.
	 */
	private function eligible_setup_cart_hash(): string {
		if ( ! class_exists( 'WC_Subscriptions' ) || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return '';
		}

		$cart = WC()->cart;
		if ( ! $cart->is_empty() && true !== $cart->needs_payment() ) {
			$cart->calculate_totals();
		}

		$total = $cart->get_total( 'edit' );
		if ( $cart->is_empty() || ! is_numeric( $total ) || (float) $total > 0 || true !== $cart->needs_payment() ) {
			return '';
		}

		foreach ( $cart->get_cart() as $cart_item ) {
			$product           = is_array( $cart_item ) ? ( $cart_item['data'] ?? null ) : null;
			$subscription_plan = is_object( $product ) && method_exists( $product, 'get_meta' ) ? $product->get_meta( 'ppcp_subscription_plan' ) : null;
			if ( ! empty( $subscription_plan ) ) {
				return '';
			}
		}

		$cart_hash = $cart->get_cart_hash();

		return is_string( $cart_hash ) ? $cart_hash : '';
	}

	/**
	 * Retire the current verification record without affecting the request.
	 *
	 * @internal
	 *
	 * @return void
	 */
	public function retire_verification_record(): void {
		try {
			if ( function_exists( 'WC' ) && WC()->session ) {
				WC()->session->set( self::VERIFICATION_RECORD_KEY, null );
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * Check if a gateway ID belongs to PayPal Payments.
	 *
	 * @param string $gateway_id The gateway ID to check.
	 * @return bool
	 */
	private function is_paypal_gateway( string $gateway_id ): bool {
		return str_starts_with( $gateway_id, self::PAYPAL_GATEWAY_PREFIX );
	}
}
