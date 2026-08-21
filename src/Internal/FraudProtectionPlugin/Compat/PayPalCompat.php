<?php
/**
 * PayPalCompat class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\Compat;

use Automattic\WooCommerce\FraudProtection\BlockedSessionMessage;
use Automattic\WooCommerce\FraudProtection\BlackboxScriptHandler;
use Automattic\WooCommerce\FraudProtection\MessageContext;
use Automattic\WooCommerce\FraudProtection\Schemas\FraudDecision;
use Automattic\WooCommerce\FraudProtection\SessionVerifier;
use Automattic\WooCommerce\FraudProtection\SessionIdNormalizer;
use Automattic\WooCommerce\FraudProtection\SuppliedDecision;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\FraudProtectionController;

defined( 'ABSPATH' ) || exit;

/**
 * Integrates Blackbox fraud protection into PayPal Payments express checkout flows.
 *
 * PayPal express checkout bypasses the standard WC checkout pipeline, so this
 * class verifies sessions from PayPal's CreateOrder AJAX endpoint instead. A
 * PayPal checkout is one attempt spread over several requests and only
 * ppc-create-order verifies — a repeat of the same session scores worse — so
 * this class records the session it scored, the decision it received, and the
 * PayPal order that verification minted, and answers the attempt's other
 * requests with that decision through the
 * `woocommerce_fraud_protection_skip_session_verify` filter: by session ID
 * before express approval, by order ID after it, never a blanket allow. The
 * record reads sit below the ppcp-* gateway gate on purpose: a non-PayPal
 * checkout presenting the recorded session ID is verified for real.
 */
class PayPalCompat {

	/**
	 * Source identifier for verify requests from PayPal express flows.
	 */
	private const ORDER_CREATION_SOURCE = 'paypal_express_order_creation';

	/**
	 * Gateway ID prefix shared by all PayPal Payments gateways.
	 */
	private const PAYPAL_GATEWAY_PREFIX = 'ppcp-';

	/**
	 * First PayPal Payments version that uses the styling option at runtime.
	 */
	private const PAYPAL_STYLING_SETTINGS_VERSION = '4.0.0';

	/**
	 * WC session key for the record of the session ppc-create-order scored:
	 * its ID, the stand-downs spent, and the decision it received.
	 *
	 * Records under the retired pre-0.1.6 key are deliberately orphaned, not
	 * migrated; they age out with their WC session.
	 */
	private const VERIFICATION_RECORD_KEY = '_fraud_protection_paypal_verification';

	/**
	 * How many later protectors one create-order verification may answer for.
	 *
	 * One is the most a genuine flow presents the same session ID again (card
	 * fields on blocks checkout); every other repeat mints a fresh session.
	 * The bound is also what keeps one verification from answering a whole
	 * Store API batch.
	 */
	private const MAX_STAND_DOWNS_PER_SESSION = 1;

	/**
	 * How many completion legs one bound order may answer for.
	 *
	 * One is all a genuine flow needs. A second consult on the same bound
	 * order can genuinely happen — a declined-payment retry keeps PayPal's
	 * slot, and pay-for-order consults before the terms check — and past the
	 * bound it gets an ordinary real verify of a typically fresh session ID,
	 * which a genuine retry passes. Forcing that re-verify on every second
	 * attempt, genuine or hostile alike, is the point of the cap.
	 */
	private const MAX_STAND_DOWNS_PER_ORDER = 1;

	/**
	 * Session verifier instance.
	 *
	 * @var SessionVerifier
	 */
	private SessionVerifier $session_verifier;

	/**
	 * Blocked-session message generator.
	 *
	 * @var BlockedSessionMessage
	 */
	private BlockedSessionMessage $blocked_session_message;

	/**
	 * Session ID normalizer.
	 *
	 * @var SessionIdNormalizer
	 */
	private SessionIdNormalizer $session_id_normalizer;

	/**
	 * The session ID this request's create-order verification recorded, if any.
	 *
	 * Lets the PayPal order created later in the same request be bound to the
	 * record it belongs to. Request-local and consumed on read: a PayPal order
	 * created outside a verified create-order request — a subscription renewal,
	 * for instance — binds nothing.
	 *
	 * @var string
	 */
	private string $session_recorded_this_request = '';

	/**
	 * Blackbox script handler, asked for the shared scripts on express surfaces.
	 *
	 * @var BlackboxScriptHandler
	 */
	private BlackboxScriptHandler $blackbox_script_handler;

	/**
	 * Initialize with dependencies.
	 *
	 * @internal
	 *
	 * @param SessionVerifier       $session_verifier        The session verifier instance.
	 * @param BlockedSessionMessage $blocked_session_message The blocked-session message generator.
	 * @param SessionIdNormalizer   $session_id_normalizer    The session ID normalizer.
	 * @param BlackboxScriptHandler $blackbox_script_handler The shared Blackbox script handler.
	 */
	final public function init(
		SessionVerifier $session_verifier,
		BlockedSessionMessage $blocked_session_message,
		SessionIdNormalizer $session_id_normalizer,
		BlackboxScriptHandler $blackbox_script_handler
	): void {
		$this->session_verifier        = $session_verifier;
		$this->blocked_session_message = $blocked_session_message;
		$this->session_id_normalizer   = $session_id_normalizer;
		$this->blackbox_script_handler = $blackbox_script_handler;
	}

	/**
	 * Register hooks for PayPal express fraud protection.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'woocommerce_paypal_payments_create_order_request_started', array( $this, 'verify_and_block_create_order' ) );
		add_action( 'woocommerce_paypal_payments_paypal_order_created', array( $this, 'bind_created_order_to_verification' ) );
		add_action( 'woocommerce_paypal_payments_single_product_button_render', array( $this, 'enqueue_paypal_script' ), 10, 0 );
		add_action( 'woocommerce_paypal_payments_cart_button_render', array( $this, 'enqueue_paypal_script' ), 10, 0 );
		add_action( 'woocommerce_paypal_payments_checkout_button_render', array( $this, 'enqueue_paypal_script' ), 10, 0 );
		add_action( 'woocommerce_paypal_payments_payorder_button_render', array( $this, 'enqueue_paypal_script' ), 10, 0 );
		add_action( 'woocommerce_paypal_payments_minicart_button_render', array( $this, 'enqueue_paypal_script' ), 10, 0 );
		add_action( 'woocommerce_before_mini_cart', array( $this, 'enqueue_paypal_mini_cart_script_if_enabled' ), 10, 0 );
		// Respect earlier filters that hide the classic cart widget.
		add_filter( 'woocommerce_widget_cart_is_hidden', array( $this, 'enqueue_paypal_script_for_visible_mini_cart_widget' ), 20, 1 );
		// Run wider-surface followers after first-party protectors on the same hooks.
		add_action( 'woocommerce_blocks_enqueue_checkout_block_scripts_before', array( $this, 'enqueue_paypal_block_script_if_registered' ), 20, 0 );
		add_action( 'woocommerce_blocks_enqueue_cart_block_scripts_before', array( $this, 'enqueue_paypal_block_script_if_registered' ), 20, 0 );
		add_action( 'woocommerce_checkout_before_order_review', array( $this, 'enqueue_paypal_script_if_smart_button_enqueued' ), 20, 0 );
		add_action( 'before_woocommerce_pay_form', array( $this, 'enqueue_paypal_script_if_smart_button_enqueued' ), 20, 0 );
		add_filter( 'woocommerce_fraud_protection_skip_session_verify', array( $this, 'supply_decision_for_paypal_express' ), 10, 4 );
	}

	/**
	 * Verify the session and block the PayPal CreateOrder request if needed.
	 *
	 * Runs on `woocommerce_paypal_payments_create_order_request_started`. On
	 * BLOCK it responds and terminates via wp_send_json_error(), so the record is
	 * written first: the blocked attempt is the one whose verdict must outlive
	 * its request.
	 *
	 * @internal
	 *
	 * @param array $data The CreateOrder request data from PayPal Payments.
	 * @return void
	 */
	public function verify_and_block_create_order( array $data ): void {
		$submitted_session_id = array_key_exists( SessionVerifier::SESSION_ID_FIELD, $data ) ? $data[ SessionVerifier::SESSION_ID_FIELD ] : '';

		$decision = $this->session_verifier->verify_session( $submitted_session_id, self::ORDER_CREATION_SOURCE, 0, $data );

		$resolved_session_id = $this->session_verifier->last_verified_session_id();

		// The record is best-effort — only the completion-leg replay depends on
		// it — and this runs on a ppcp hook outside SessionVerifier's guard, so a
		// session read/write throw here would otherwise escape into ppcp's
		// create-order request BEFORE the block check below. Guard only this
		// call, and continue: on a throw, skip the record and fall through so
		// the block is still enforced and completions re-verify. wp_send_json_error()
		// stays outside the catch — a Throwable catch over it would swallow its
		// wp_die() and turn a Block into a bypass.
		try {
			$this->update_create_order_verification_record( $resolved_session_id, $decision );
		} catch ( \Throwable $e ) {
			FraudProtectionController::log(
				'warning',
				'Recording the create-order verification threw; completion legs will re-verify',
				array(
					'hook'              => 'woocommerce_paypal_payments_create_order_request_started',
					'session_id'        => $resolved_session_id,
					'exception_class'   => $e::class,
					'exception_message' => $e->getMessage(),
				),
				true
			);
		}

		if ( FraudDecision::Block === $decision ) {
			wp_send_json_error(
				array( 'message' => $this->blocked_session_message->get_plaintext( MessageContext::Purchase ) ),
				403
			);
		}

		$this->session_recorded_this_request = $resolved_session_id;
	}

	/**
	 * Bind the PayPal order just created to the verification that covered it.
	 *
	 * Runs on `woocommerce_paypal_payments_paypal_order_created`, which fires
	 * in the same request as the create-order verification, once the order
	 * exists — the identity the create-order hook fires too early to know.
	 * The order ID is what the approved-order route matches on later. Without
	 * a verification recorded by this request — a server-side order creation,
	 * say — there is nothing to bind to.
	 *
	 * @internal
	 *
	 * @param mixed $order The PayPal order entity; foreign code's object, read defensively.
	 * @return void
	 */
	public function bind_created_order_to_verification( $order ): void {
		// Consumed on read, before the try, so the session ID state is always
		// spent and remains available to the fail-open log.
		$session_id = $this->session_recorded_this_request;

		$this->session_recorded_this_request = '';

		// This runs on a ppcp hook, outside SessionVerifier's fail-open guard,
		// inside the create-order request that already minted the order — so any
		// throw here (the foreign order object, a bad session deserialize, the
		// write) would fail the shopper's checkout. Fail open: on any throw,
		// leave the verification unbound so a later completion leg verifies.
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

			if ( null === $record || $record['session_id'] !== $session_id ) {
				return;
			}

			$record['order_id']          = $order_id;
			$record['order_stand_downs'] = 0;

			WC()->session->set( self::VERIFICATION_RECORD_KEY, $record );
		} catch ( \Throwable $e ) {
			FraudProtectionController::log(
				'warning',
				'Binding the created PayPal order threw; leaving the verification unbound',
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
	 * Request the shared scripts and enqueue the PayPal fetch interceptor.
	 *
	 * @internal
	 *
	 * @return void
	 */
	public function enqueue_paypal_script(): void {
		if ( ! $this->blackbox_script_handler->request_scripts() ) {
			return;
		}

		wp_enqueue_script(
			'wc-fraud-protection-paypal-express',
			WC_FRAUD_PROTECTION_PLUGIN_URL . 'assets/js/paypal-express.js',
			array( 'wc-fraud-protection-blackbox-init' ),
			WC_FRAUD_PROTECTION_VERSION,
			array( 'in_footer' => true )
		);
	}

	/**
	 * Enqueue the PayPal interceptor for a frontend Cart or Checkout block.
	 *
	 * @internal
	 *
	 * @return void
	 */
	public function enqueue_paypal_block_script_if_registered(): void {
		// WooCommerce fires the Checkout block hook before it swaps these endpoints for their
		// classic views. The block hook does not prove that a Checkout block will render.
		if ( is_wc_endpoint_url( 'order-pay' ) || is_wc_endpoint_url( 'order-received' ) ) {
			return;
		}

		if ( ! wp_script_is( 'ppcp-checkout-block', 'registered' ) ) {
			return;
		}

		$this->enqueue_paypal_script();
	}

	/**
	 * Enqueue the interceptor before a mini-cart can gain a PayPal button through AJAX fragments.
	 *
	 * @internal
	 *
	 * @return void
	 */
	public function enqueue_paypal_mini_cart_script_if_enabled(): void {
		if (
			! $this->is_paypal_mini_cart_enabled()
			|| ! wp_script_is( 'ppcp-smart-button', 'registered' )
			|| ! wp_script_is( 'ppcp-smart-button', 'enqueued' )
		) {
			return;
		}

		$this->enqueue_paypal_script();
	}

	/**
	 * Prepare a visible classic cart widget for PayPal AJAX fragments.
	 *
	 * @internal
	 *
	 * @param mixed $hidden Whether WooCommerce will hide the cart widget.
	 * @return mixed The unchanged widget visibility decision.
	 */
	public function enqueue_paypal_script_for_visible_mini_cart_widget( $hidden ) {
		if ( false === $hidden ) {
			$this->enqueue_paypal_mini_cart_script_if_enabled();
		}

		return $hidden;
	}

	/**
	 * Enqueue the PayPal interceptor when its smart-button script can render later.
	 *
	 * @internal
	 *
	 * @return void
	 */
	public function enqueue_paypal_script_if_smart_button_enqueued(): void {
		if ( ! wp_script_is( 'ppcp-smart-button', 'registered' ) || ! wp_script_is( 'ppcp-smart-button', 'enqueued' ) ) {
			return;
		}

		$this->enqueue_paypal_script();
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
	 * @param mixed $supplied_decision The earlier filter value. Expected SuppliedDecision.
	 * @param mixed $source            Source identifier. Expected string.
	 * @param mixed $request_data      Request data with payment_method, payment_data, etc. Expected array.
	 * @param mixed $session_id        The Blackbox session ID being verified. Expected string.
	 * @return mixed The same incoming value.
	 */
	public function supply_decision_for_paypal_express( mixed $supplied_decision, mixed $source, mixed $request_data, mixed $session_id ): mixed {
		if ( ! ( $supplied_decision instanceof SuppliedDecision ) || ! is_string( $source ) || ! is_array( $request_data ) || ! is_string( $session_id ) ) {
			return $supplied_decision;
		}

		// Don't answer for this class's own verification sources.
		if ( self::ORDER_CREATION_SOURCE === $source ) {
			return $supplied_decision;
		}

		$payment_method = $request_data['payment_method'] ?? '';
		if ( ! is_string( $payment_method ) ) {
			return $supplied_decision;
		}

		// Not a PayPal gateway — nothing for this filter to do.
		if ( ! $this->is_paypal_gateway( $payment_method ) ) {
			return $supplied_decision;
		}

		// Same Blackbox session already scored by ppc-create-order in this flow.
		$accepted_record = $this->take_stand_down_for_verified_session( $session_id );
		if ( null !== $accepted_record ) {
			$supplied_decision->supply( $accepted_record['decision'] );

			if ( '' !== $accepted_record['order_id'] && $this->paypal_order_id_in_session() === $accepted_record['order_id'] ) {
				$supplied_decision->supply( $accepted_record['decision'], $accepted_record['session_id'] );
			}

			return $supplied_decision;
		}

		// After express approval: only the order this record's verification
		// minted answers, with the decision that verification produced.
		$accepted_record = $this->record_for_scored_order_in_session();

		if ( null === $accepted_record ) {
			// All other ppcp-* flows (Blocks "Place Order" with ppcp-gateway, APMs): defer.
			return $supplied_decision;
		}

		$supplied_decision->supply( $accepted_record['decision'], $accepted_record['session_id'] );

		return $supplied_decision;
	}

	/**
	 * The accepted record when the approved order in PayPal's session
	 * slot is the one this record's verification minted.
	 *
	 * Bound by order identity because session IDs cannot match here: Blackbox
	 * was reset after create-order. An unbound record, a foreign order in the
	 * slot, or no order at all defers to a real verify.
	 *
	 * The replay is also counted: one bound order answers at most
	 * MAX_STAND_DOWNS_PER_ORDER times, with a fresh budget when a new order is
	 * bound. The order's amount can still be patched after scoring, so the one
	 * replay may honor an allow scored on a since-changed cart.
	 *
	 * @return ?array{session_id: string, stand_downs: int, decision: FraudDecision, order_id: string, order_stand_downs: int} The accepted record, or null to defer.
	 */
	private function record_for_scored_order_in_session(): ?array {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return null;
		}

		$record = $this->get_verified_session_record();

		if ( null === $record || '' === $record['order_id'] ) {
			return null;
		}

		if ( $this->paypal_order_id_in_session() !== $record['order_id'] ) {
			return null;
		}

		if ( $record['order_stand_downs'] >= self::MAX_STAND_DOWNS_PER_ORDER ) {
			return null;
		}

		++$record['order_stand_downs'];

		WC()->session->set( self::VERIFICATION_RECORD_KEY, $record );

		return $record;
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
	 * Update the record of the session and decision scored by ppc-create-order.
	 *
	 * Kept in the WC session so the verdict outlives a blocked create-order,
	 * which dies inside its own JSON response. Keyed by the session ID the
	 * verification resolved; the stand-down budget belongs to that session,
	 * so a same-session overwrite carries the spent count forward. Every
	 * scoring starts unbound: the PayPal order it mints binds afterwards, and
	 * an order minted under a superseded scoring does not carry over.
	 *
	 * @param string        $session_id The session ID the verification resolved, empty when it completed none.
	 * @param FraudDecision $decision   The decision that verification produced.
	 */
	private function update_create_order_verification_record( string $session_id, FraudDecision $decision ): void {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}

		if ( '' === $session_id ) {
			WC()->session->set( self::VERIFICATION_RECORD_KEY, null );
			return;
		}

		$record = $this->get_verified_session_record();

		WC()->session->set(
			self::VERIFICATION_RECORD_KEY,
			array(
				'session_id'        => $session_id,
				'stand_downs'       => null !== $record && $record['session_id'] === $session_id ? $record['stand_downs'] : 0,
				'decision'          => $decision,
				'order_id'          => '',
				'order_stand_downs' => 0,
			)
		);
	}

	/**
	 * Answer for a session ppc-create-order already scored, if budget remains.
	 *
	 * Spends one stand-down; past the budget the caller verifies with Blackbox.
	 *
	 * @param string $session_id The session ID from the current verify call.
	 * @return ?array{session_id: string, stand_downs: int, decision: FraudDecision, order_id: string, order_stand_downs: int} The accepted record after spending its count, or null.
	 */
	private function take_stand_down_for_verified_session( string $session_id ): ?array {
		if ( '' === $session_id || ! function_exists( 'WC' ) || ! WC()->session ) {
			return null;
		}

		$record = $this->get_verified_session_record();

		if ( null === $record || $this->session_id_normalizer->normalize_stored( $record['session_id'] ) !== $session_id ) {
			return null;
		}

		if ( $record['stand_downs'] >= self::MAX_STAND_DOWNS_PER_SESSION ) {
			return null;
		}

		++$record['stand_downs'];

		WC()->session->set( self::VERIFICATION_RECORD_KEY, $record );

		return $record;
	}

	/**
	 * Read the create-order verification record from the WC session.
	 *
	 * Only the shape {@see update_create_order_verification_record()} writes counts
	 * as a record. Anything else — corruption, another plugin's write — reads
	 * as no record, so the request falls through to a real verify. The order
	 * fields tolerate absence — records written before the binding, or before
	 * its counter, existed lack them: they read as unbound with an unspent
	 * budget, never as no record.
	 *
	 * @return ?array{session_id: string, stand_downs: int, decision: FraudDecision, order_id: string, order_stand_downs: int} The record, or null when the session holds none this code wrote.
	 */
	private function get_verified_session_record(): ?array {
		$stored = WC()->session->get( self::VERIFICATION_RECORD_KEY );

		if ( ! is_array( $stored ) ) {
			return null;
		}

		$session_id = $stored['session_id'] ?? null;
		$decision   = $stored['decision'] ?? null;

		if ( ! is_string( $session_id ) || '' === $session_id || ! $decision instanceof FraudDecision ) {
			return null;
		}

		return array(
			'session_id'        => $session_id,
			'stand_downs'       => (int) ( $stored['stand_downs'] ?? 0 ),
			'decision'          => $decision,
			'order_id'          => is_string( $stored['order_id'] ?? null ) ? $stored['order_id'] : '',
			'order_stand_downs' => (int) ( $stored['order_stand_downs'] ?? 0 ),
		);
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

	/**
	 * Check whether PayPal Payments enables its classic mini-cart button.
	 *
	 * @return bool
	 */
	private function is_paypal_mini_cart_enabled(): bool {
		$paypal_version = get_option( 'woocommerce-ppcp-version', '' );

		if (
			is_string( $paypal_version )
			&& '' !== $paypal_version
			&& version_compare( $paypal_version, self::PAYPAL_STYLING_SETTINGS_VERSION, '<' )
		) {
			return $this->is_legacy_paypal_mini_cart_enabled();
		}

		$styling = get_option( 'woocommerce-ppcp-data-styling', null );

		if ( null !== $styling ) {
			if ( ! is_array( $styling ) || ! array_key_exists( 'mini_cart', $styling ) ) {
				return false;
			}

			$mini_cart = $styling['mini_cart'];

			if ( is_object( $mini_cart ) ) {
				$mini_cart = get_object_vars( $mini_cart );
			}

			if ( is_array( $mini_cart ) && array_key_exists( 'enabled', $mini_cart ) ) {
				return true === $mini_cart['enabled'];
			}

			return false;
		}

		return $this->is_legacy_paypal_mini_cart_enabled();
	}

	/**
	 * Check the PayPal Payments mini-cart location in its legacy settings.
	 *
	 * @return bool
	 */
	private function is_legacy_paypal_mini_cart_enabled(): bool {
		$settings = get_option( 'woocommerce-ppcp-settings', array() );

		if ( ! is_array( $settings ) ) {
			return false;
		}

		$locations = $settings['smart_button_locations'] ?? array();

		return is_array( $locations ) && in_array( 'mini-cart', $locations, true );
	}
}
