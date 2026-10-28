<?php
/**
 * FP_Example_Change_Payment class file.
 *
 * @package WooCommerce\FraudProtection\Examples
 */

declare( strict_types=1 );

use Automattic\WooCommerce\FraudProtection\BlackboxScriptHandler;
use Automattic\WooCommerce\FraudProtection\BlockedSessionMessage;
use Automattic\WooCommerce\FraudProtection\ClassicFormRequestData;
use Automattic\WooCommerce\FraudProtection\MessageContext;
use Automattic\WooCommerce\FraudProtection\Schemas\FraudDecision;
use Automattic\WooCommerce\FraudProtection\SessionVerifier;

defined( 'ABSPATH' ) || exit;

/**
 * A stand-in for the WooCommerce Subscriptions change-payment-method flow, integrated natively.
 *
 * Subscriptions handles its pay form itself on `wp`, after its own nonce check, and fires
 * `woocommerce_subscription_change_payment_method_via_pay_shortcode` before it updates the
 * payment method. This class does the same for a regular order, and shows the three things
 * Subscriptions needs for a native integration:
 *
 * 1. Load the pay-form protector script when the form renders, with
 *    {@see BlackboxScriptHandler::request_pay_for_order_script()}.
 * 2. Verify the submitted form with {@see ClassicFormRequestData} and
 *    {@see SessionVerifier} before changing anything, and stop on a Block decision.
 * 3. Declare the `change_payment_method` integration under the extension's plugin slug
 *    through the `woocommerce_fraud_protection_native_integrations` filter, so the plugin's
 *    built-in compatibility layer stands aside instead of verifying the same request a
 *    second time.
 *
 * The form renders through the `[fp_example_change_payment]` shortcode.
 */
class FP_Example_Change_Payment {

	/**
	 * Verification source. Stable, ASCII, at most 32 characters.
	 */
	public const SOURCE = 'fp_example_change_payment';

	/**
	 * Query variable carrying the order ID of a submission.
	 */
	public const QUERY_VAR = 'fp_example_change_payment';

	/**
	 * Plugin slug the integration is declared under: the slug of the plugin this stand-in plays.
	 */
	private const EXTENSION_SLUG = 'woocommerce-subscriptions';

	/**
	 * Nonce action and field of the form.
	 */
	private const NONCE_ACTION = 'fp_example_change_payment';
	private const NONCE_FIELD  = '_fp_example_nonce';

	/**
	 * Register the flow's hooks.
	 */
	public function register(): void {
		add_shortcode( 'fp_example_change_payment', array( $this, 'render_form' ) );
		add_action( 'wp', array( $this, 'handle_submission' ), 20 );

		if ( class_exists( SessionVerifier::class ) ) {
			add_filter( 'woocommerce_fraud_protection_native_integrations', array( $this, 'declare_native_integration' ) );
		}
	}

	/**
	 * Tell Fraud Protection that this flow verifies its own submissions.
	 *
	 * An extension declares its integrations under its own plugin slug. This stand-in plays
	 * WooCommerce Subscriptions, so it uses that plugin's slug, which is the one the built-in
	 * compatibility layer for the change-payment-method form checks.
	 *
	 * @param mixed $integrations The integrations declared so far, keyed by plugin slug.
	 * @return mixed The integrations, with this flow's integration added.
	 */
	public function declare_native_integration( $integrations ) {
		// Values from a filter are untrusted: another callback may have changed them.
		if ( ! is_array( $integrations ) ) {
			return $integrations;
		}

		$own = $integrations[ self::EXTENSION_SLUG ] ?? array();
		if ( ! is_array( $own ) ) {
			$own = array();
		}

		$own[]                                = 'change_payment_method';
		$integrations[ self::EXTENSION_SLUG ] = $own;

		return $integrations;
	}

	/**
	 * Render the change-payment form for the current user's latest order.
	 *
	 * @return string
	 */
	public function render_form(): string {
		if ( ! is_user_logged_in() ) {
			return '';
		}

		$orders = wc_get_orders(
			array(
				'customer_id' => get_current_user_id(),
				'limit'       => 1,
				'orderby'     => 'date',
				'order'       => 'DESC',
			)
		);
		$order  = is_array( $orders ) ? ( $orders[0] ?? null ) : null;
		if ( ! $order instanceof WC_Order ) {
			return '<p>' . esc_html__( 'Place an order first.', 'fp-example-subscriptions' ) . '</p>';
		}

		// Fraud Protection: the pay-form protector holds the submit of the `#order_review` form
		// until it has added the session ID; verify() reads it back on the server.
		if ( class_exists( BlackboxScriptHandler::class ) ) {
			wc_get_container()->get( BlackboxScriptHandler::class )->request_pay_for_order_script();
		}

		ob_start();
		?>
		<form id="order_review" method="post" action="<?php echo esc_url( add_query_arg( self::QUERY_VAR, $order->get_id() ) ); ?>">
			<?php foreach ( WC()->payment_gateways()->get_available_payment_gateways() as $gateway ) : ?>
				<p>
					<label>
						<input type="radio" name="payment_method" value="<?php echo esc_attr( $gateway->id ); ?>" <?php checked( $gateway->id, $order->get_payment_method() ); ?> />
						<?php echo esc_html( $gateway->get_title() ); ?>
					</label>
				</p>
			<?php endforeach; ?>
			<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
			<button type="submit" class="button"><?php esc_html_e( 'Change payment method', 'fp-example-subscriptions' ); ?></button>
		</form>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Handle a submitted form.
	 *
	 * Mirrors Subscriptions: the nonce check happens here, then the request is verified and
	 * the payment method changes.
	 */
	public function handle_submission(): void {
		if ( ! isset( $_GET[ self::QUERY_VAR ], $_POST[ self::NONCE_FIELD ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified below.
			return;
		}

		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION ) ) {
			return;
		}

		$order = wc_get_order( absint( $_GET[ self::QUERY_VAR ] ) );
		if ( ! $order instanceof WC_Order || ! current_user_can( 'pay_for_order', $order->get_id() ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce order capability.
			return;
		}

		$this->change_payment_method( $order );
	}

	/**
	 * Verify the request, then change the order's payment method and redirect.
	 *
	 * @param WC_Order $order The order being changed.
	 */
	public function change_payment_method( WC_Order $order ): void {
		if ( ! $this->verify( $order ) ) {
			wp_safe_redirect( $order->get_view_order_url() );
			exit;
		}

		/**
		 * Fires before the payment method changes, as WooCommerce Subscriptions does for other extensions.
		 *
		 * @param WC_Order $order The order being changed.
		 */
		do_action( 'woocommerce_subscription_change_payment_method_via_pay_shortcode', $order );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in handle_submission().
		$order->set_payment_method( sanitize_text_field( wp_unslash( $_POST['payment_method'] ?? '' ) ) );
		$order->save();
		wc_add_notice( __( 'Payment method updated.', 'fp-example-subscriptions' ) );

		wp_safe_redirect( $order->get_view_order_url() );
		exit;
	}

	/**
	 * Verify the submitted form with Fraud Protection.
	 *
	 * @param WC_Order $order The order being changed.
	 * @return bool False when the change must stop.
	 */
	private function verify( WC_Order $order ): bool {
		if ( ! class_exists( SessionVerifier::class ) ) {
			return true;
		}

		$form = new ClassicFormRequestData();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in handle_submission().
		$request_data = $form->build_request_data( $_POST );

		$decision = wc_get_container()->get( SessionVerifier::class )->verify_session(
			$form->get_submitted_session_id(),
			self::SOURCE,
			$order->get_id(),
			$request_data
		);

		if ( FraudDecision::Block !== $decision ) {
			return true;
		}

		wc_add_notice( ( new BlockedSessionMessage() )->get_html( MessageContext::Generic ), 'error' );

		return false;
	}
}
