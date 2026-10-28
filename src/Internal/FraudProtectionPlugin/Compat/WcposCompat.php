<?php
/**
 * WcposCompat class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\Compat;

use Automattic\WooCommerce\FraudProtection\Schemas\FraudDecision;
use Automattic\WooCommerce\FraudProtection\SuppliedDecision;
use WCPOS\WooCommercePOS\Services\Auth;

defined( 'ABSPATH' ) || exit;

/**
 * Skips verification for payments that a cashier records in WooCommerce POS (WCPOS).
 *
 * WCPOS records its own tenders, such as cash, by paying the order on its own
 * pay page, from the cashier's device. Its pay page does not load this plugin's
 * scripts, and it submits these tenders without running submit handlers, so the
 * request never carries a browser session. There is no shopper browser to assess.
 *
 * The decision is supplied only when all of these hold:
 *
 * - the attempt pays an existing order (`pay_for_order`);
 * - the request is a WCPOS request;
 * - the request carries a valid WCPOS token of a user who can access the POS.
 *   WCPOS also requires this token on its pay requests, but the check is
 *   repeated here so that the decision does not depend on its hook order;
 * - the selected gateway is a WCPOS gateway.
 *
 * The order may have been created anywhere: a cashier can also take payment at
 * the till for an order that the customer placed online.
 *
 * Other gateways used inside the POS, and orders paid through the regular pay
 * page, are verified as before.
 *
 * Unlike other decisions to skip verification, this one relies on evidence
 * from WCPOS rather than from this plugin: a cashier token that the class
 * validates itself, so it cannot be forged by a shopper. A recorded session
 * cannot serve instead, since a POS payment has no shopper browser, and the
 * decision of an earlier attempt must not apply to this one.
 *
 * Temporary: WCPOS can supply the same decision through the public filter, and
 * this class uses only the public API so that it can move there unchanged.
 */
class WcposCompat {

	/**
	 * Verification source of the pay-for-order protector.
	 *
	 * @var string
	 */
	private const PAY_FOR_ORDER_SOURCE = 'pay_for_order';

	/**
	 * Base class of the WCPOS payment gateways.
	 *
	 * @var string
	 */
	private const POS_GATEWAY_CLASS = 'WCPOS\WooCommercePOS\Payments\Abstract_POS_Gateway';

	/**
	 * Capability that WCPOS gives to the users who can access the POS.
	 *
	 * @var string
	 */
	private const POS_CAPABILITY = 'access_woocommerce_pos';

	/**
	 * Register the supplied-decision filter.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'woocommerce_fraud_protection_skip_session_verify', array( $this, 'supply_decision_for_pos_tender' ), 10, 3 );
	}

	/**
	 * Supply an allow decision for a tender that a cashier records in WCPOS.
	 *
	 * @internal
	 *
	 * @param mixed $supplied_decision The earlier result to apply, or false.
	 * @param mixed $source            Source identifier.
	 * @param mixed $request_data      Request data with payment_method, payment_data, etc.
	 * @return mixed The supplied result, or the value passed in to defer.
	 */
	public function supply_decision_for_pos_tender( mixed $supplied_decision, mixed $source, mixed $request_data ): mixed {
		// An earlier result for this attempt, possibly a block, stays in place.
		if ( false !== $supplied_decision || self::PAY_FOR_ORDER_SOURCE !== $source ) {
			return $supplied_decision;
		}

		if ( ! function_exists( 'wcpos_request' ) || true !== wcpos_request() ) {
			return $supplied_decision;
		}

		if ( ! $this->has_cashier_token() ) {
			return $supplied_decision;
		}

		$payment_method = is_array( $request_data ) && is_string( $request_data['payment_method'] ?? null ) ? $request_data['payment_method'] : '';
		if ( ! $this->is_pos_gateway( $payment_method ) ) {
			return $supplied_decision;
		}

		return new SuppliedDecision( FraudDecision::Allow );
	}

	/**
	 * Check whether the request carries a valid WCPOS token of a user who can access the POS.
	 *
	 * Reads the token where WCPOS reads it on its pay requests. WCPOS switches
	 * the current user to the order's customer before the payment, so the
	 * capability is checked on the token's user.
	 *
	 * @return bool Whether the request carries a valid cashier token.
	 */
	private function has_cashier_token(): bool {
		if ( ! class_exists( Auth::class ) ) {
			return false;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The token is the credential being checked, and is sanitized below.
		$token = wp_unslash( $_GET['wcpos_jwt'] ?? $_GET['token'] ?? null );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( ! is_string( $token ) || '' === $token ) {
			return false;
		}

		$decoded = Auth::instance()->validate_token( sanitize_text_field( $token ) );
		if ( ! is_object( $decoded ) || is_wp_error( $decoded ) ) {
			return false;
		}

		$user_id = $decoded->data->user->id ?? null;

		return is_numeric( $user_id ) && (int) $user_id > 0 && user_can( (int) $user_id, self::POS_CAPABILITY );
	}

	/**
	 * Check whether a gateway ID belongs to a WCPOS gateway.
	 *
	 * WCPOS adds its gateways to the available gateways of its own requests,
	 * which WooCommerce also uses to process the payment.
	 *
	 * @param string $payment_method Gateway ID.
	 * @return bool Whether it is a WCPOS gateway.
	 */
	private function is_pos_gateway( string $payment_method ): bool {
		if ( '' === $payment_method ) {
			return false;
		}

		$gateway = WC()->payment_gateways()->get_available_payment_gateways()[ $payment_method ] ?? null;

		return is_a( $gateway, self::POS_GATEWAY_CLASS );
	}
}
