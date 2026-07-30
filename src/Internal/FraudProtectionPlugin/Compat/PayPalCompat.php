<?php
/**
 * PayPalCompat class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\Compat;

use Automattic\WooCommerce\FraudProtection\BlockedSessionMessage;
use Automattic\WooCommerce\FraudProtection\MessageContext;
use Automattic\WooCommerce\FraudProtection\Schemas\FraudDecision;
use Automattic\WooCommerce\FraudProtection\SessionVerifier;

defined( 'ABSPATH' ) || exit;

/**
 * Integrates Blackbox fraud protection into PayPal Payments express checkout flows.
 *
 * PayPal express checkout (product page, cart, mini-cart) bypasses the standard
 * WC checkout pipeline. This class hooks into PayPal's CreateOrder AJAX endpoint
 * to verify sessions before PayPal order creation.
 *
 * The JS fetch interceptor resets Blackbox after the CreateOrder fetch returns,
 * so subsequent payment attempts (retry, different method) get a fresh session.
 *
 * A PayPal checkout is one attempt spread over several requests, and only
 * ppc-create-order verifies. The rest of the attempt must not be scored again —
 * Blackbox sessions are effectively single-use and a repeat scores worse — so
 * this class answers for those requests through the
 * `woocommerce_fraud_protection_skip_session_verify` filter.
 *
 * What it answers with is the point: standing down is not the same as allowing.
 * The record of the session ppc-create-order scored carries the decision that
 * verification produced — allow or block — and a request that stands down on it
 * gets that decision back, never a blanket allow. What keeps a recorded allow
 * from answering for another gateway is placement: the stand-down read sits
 * below the ppcp-* gateway gate, so a non-PayPal checkout presenting the
 * recorded session ID is verified for real. The record is also read on two
 * routes that spend no stand-down: the in-request marker, which is
 * request-local, consumed on read and never set on a block; and the
 * approved-order predicate, which answers for as long as PayPal's session
 * slot holds an approved order — bounded only by PayPal clearing it.
 *
 * All of this is PayPal's problem and lives here. SessionVerifier knows only that
 * some consumer supplied a decision.
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
	 * WC session key for the record of the session ppc-create-order scored:
	 * its ID, the stand-downs spent, and the decision it received.
	 *
	 * Earlier versions kept a bare session ID string under
	 * `_fraud_protection_paypal_verified_session_id`. That key is deliberately
	 * left behind rather than migrated: an orphaned record ages out with its WC
	 * session, and the requests it would have answered are verified for real.
	 */
	private const VERIFICATION_RECORD_KEY = '_fraud_protection_paypal_verification';

	/**
	 * How many later protectors one create-order verification may answer for.
	 *
	 * Set to the most a genuine order flow produces, which is one: card fields on
	 * blocks checkout, where blocks-checkout.js puts the session ID in the checkout
	 * extension data before PayPal's createOrder callback runs, so ppc-create-order
	 * scores that ID for real and the Store API request that follows presents it
	 * once. Every other repeat of ppc-create-order mints a fresh Blackbox session
	 * (the fetch interceptor resets in a `finally`), so it is scored for real.
	 *
	 * Express from the classic checkout page looks like a contradiction — it stands
	 * down twice per order — but those two are a different predicate each (the
	 * in-request marker, then the approved-order check) and the second carries a
	 * *different* session ID, so this per-session-ID bound never sees more than one.
	 *
	 * The bound is also what stops one verification answering for a whole Store API
	 * batch: sub-request 1 is answered, 2..N are not, so they verify for real. Past
	 * the bound nothing is answered — Blackbox scores a reused session harder, so
	 * reuse beyond the genuine shape is decided by the model rather than served here.
	 */
	private const MAX_STAND_DOWNS_PER_SESSION = 1;

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
	 * Whether this class has verified a create-order request that is still in flight.
	 *
	 * PayPal's CreateOrderEndpoint fires our verify action and then runs WooCommerce
	 * form validation in the same request. The protector that validation reaches has
	 * no session ID to verify — PayPal serialized the checkout form before the submit
	 * that adds our field — so without this it would score an anonymous session rather
	 * than the shopper's.
	 *
	 * A plain object property on purpose: it lives and dies with the PHP request, and
	 * nothing in the request can forge it. Consumed on read, so it covers the one form
	 * validation a create-order request performs and nothing further.
	 *
	 * @var bool
	 */
	private bool $create_order_request_verified = false;

	/**
	 * Initialize with dependencies.
	 *
	 * @internal
	 *
	 * @param SessionVerifier       $session_verifier        The session verifier instance.
	 * @param BlockedSessionMessage $blocked_session_message The blocked-session message generator.
	 */
	final public function init(
		SessionVerifier $session_verifier,
		BlockedSessionMessage $blocked_session_message
	): void {
		$this->session_verifier        = $session_verifier;
		$this->blocked_session_message = $blocked_session_message;
	}

	/**
	 * Register hooks for PayPal express fraud protection.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'woocommerce_paypal_payments_create_order_request_started', array( $this, 'verify_and_block_create_order' ) );
		add_filter( 'woocommerce_fraud_protection_enqueue_blackbox_scripts', array( $this, 'should_enqueue_blackbox' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_paypal_script' ), 20 );
		add_filter( 'woocommerce_fraud_protection_skip_session_verify', array( $this, 'supply_decision_for_paypal_express' ), 10, 4 );
	}

	/**
	 * Verify the session and block the PayPal CreateOrder request if needed.
	 *
	 * Called during `woocommerce_paypal_payments_create_order_request_started`,
	 * which fires after nonce validation but before PayPal order creation.
	 *
	 * On BLOCK, sends a JSON error response and terminates execution via
	 * wp_send_json_error(). On ALLOW, returns normally and the PayPal flow
	 * continues.
	 *
	 * Two writes happen here and their ordering around the block response is
	 * deliberate:
	 *
	 * - The verified-session record, carrying the decision, before the response
	 *   and regardless of what the decision is. A request that dies inside
	 *   wp_send_json_error() is exactly the one whose verdict has to outlive it —
	 *   without this the blocked attempt's next request would stand down to an
	 *   allow, which is the defect.
	 * - The in-request marker, after the response, so a blocked request leaves
	 *   nothing behind for anything still running in it.
	 *
	 * @internal
	 *
	 * @param array $data The CreateOrder request data from PayPal Payments.
	 * @return void
	 */
	public function verify_and_block_create_order( array $data ): void {
		$session_id = sanitize_text_field( $data[ SessionVerifier::SESSION_ID_FIELD ] ?? '' );

		$decision = $this->session_verifier->verify_session( $session_id, self::ORDER_CREATION_SOURCE, 0, $data );

		$this->record_create_order_verification( $this->session_verifier->last_verified_session_id(), $decision );

		if ( FraudDecision::Block === $decision ) {
			wp_send_json_error(
				array( 'message' => $this->blocked_session_message->get_plaintext( MessageContext::Purchase ) ),
				403
			);
		}

		$this->create_order_request_verified = true;
	}

	/**
	 * Filter whether Blackbox scripts should be enqueued on the current page.
	 *
	 * Extends the default enqueue logic (checkout, pay-for-order, add-payment-method)
	 * to also load on pages where PayPal express buttons can trigger checkout:
	 * product pages, cart pages, and any page when mini-cart buttons are enabled
	 * (the mini-cart renders in the header/template, so buttons appear on every page).
	 *
	 * @internal
	 *
	 * @param bool $should Whether scripts are already set to be enqueued.
	 * @return bool
	 */
	public function should_enqueue_blackbox( bool $should ): bool {
		if ( $should ) {
			return true;
		}

		if ( ! $this->is_paypal_available() ) {
			return false;
		}

		return is_product()
			|| is_cart()
			|| has_block( 'woocommerce/cart' )
			|| $this->is_paypal_mini_cart_enabled();
	}

	/**
	 * Enqueue the PayPal express fetch interceptor script.
	 *
	 * Only enqueues when Blackbox init script is already loaded (which means
	 * Blackbox is configured and ready on the current page).
	 *
	 * @internal
	 *
	 * @return void
	 */
	public function enqueue_paypal_script(): void {
		if ( ! wp_script_is( 'wc-fraud-protection-blackbox-init', 'enqueued' ) ) {
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
	 * Skip redundant verification for PayPal flows handled by PayPalCompat.
	 *
	 * Answers for requests this class already scored, so one payment attempt is
	 * not scored twice. Each branch below is a verification performed here, for a
	 * request that carries no session ID we could match instead.
	 *
	 * What comes back is the decision that attempt received, not a blanket allow:
	 * if ppc-create-order blocked it, that block is what these requests get.
	 *
	 * Flows that never reach ppc-create-order (Blocks "Place Order" with
	 * ppcp-gateway, APM gateways) have nothing recorded here, so they are deferred
	 * and verified normally.
	 *
	 * Standard filter arbitration: this callback answers from its record when it
	 * has one and passes the value through when it defers; a consumer that wants
	 * the last word registers with a later priority. The parameter type is the
	 * contract — an earlier consumer that put anything else in the chain fails
	 * loudly here, and SessionVerifier turns that into a logged warning and a
	 * real verify, never a skip.
	 *
	 * @internal
	 *
	 * @param FraudDecision|false $decision     The filter's default (false), or what an earlier consumer returned.
	 * @param string              $source       Source identifier.
	 * @param array               $request_data Request data with payment_method, payment_data, etc.
	 * @param string              $session_id   The Blackbox session ID being verified.
	 * @return FraudDecision|false A FraudDecision to apply, or the value passed in to defer.
	 */
	public function supply_decision_for_paypal_express( FraudDecision|false $decision, string $source, array $request_data, string $session_id ): FraudDecision|false {
		// Don't answer for this class's own verification sources.
		if ( self::ORDER_CREATION_SOURCE === $source ) {
			return $decision;
		}

		// Before anything read from the request: the form PayPal rebuilds for
		// validation is not ours to make assumptions about.
		if ( $this->consume_create_order_verification() ) {
			return $this->decision_for_verified_attempt( $session_id );
		}

		$payment_method = (string) ( $request_data['payment_method'] ?? '' );

		// Not a PayPal gateway — nothing for this filter to do.
		if ( ! $this->is_paypal_gateway( $payment_method ) ) {
			return $decision;
		}

		// Same Blackbox session already verified during ppc-create-order in this
		// payment flow (e.g. card fields on blocks checkout where blocks-checkout.js
		// captured the session ID before ppc-create-order ran).
		if ( $this->take_stand_down_for_verified_session( $session_id ) ) {
			return $this->decision_for_verified_attempt( $session_id );
		}

		// After express approval: PayPal order in session means ppc-create-order
		// already verified (Blackbox was reset since, so session IDs won't match).
		if ( $this->has_paypal_order_in_session() ) {
			return $this->decision_for_verified_attempt( $session_id );
		}

		// All other ppcp-* flows (Blocks "Place Order" with ppcp-gateway, APMs): defer.
		return $decision;
	}

	/**
	 * The decision to hand back for a request this class already scored.
	 *
	 * The recorded decision, when the presented session ID is the one that was
	 * scored. A session ID that is not the recorded one reaches here only through
	 * the approved-order predicate (the record cannot match a fresh post-reset
	 * ID), and that path has always meant allow.
	 *
	 * @param string $session_id The Blackbox session ID being verified.
	 * @return FraudDecision
	 */
	private function decision_for_verified_attempt( string $session_id ): FraudDecision {
		if ( '' === $session_id || ! function_exists( 'WC' ) || ! WC()->session ) {
			return FraudDecision::Allow;
		}

		$record = $this->get_verified_session_record();

		if ( null !== $record && $record['session_id'] === $session_id ) {
			return $record['decision'];
		}

		return FraudDecision::Allow;
	}

	/**
	 * Take the create-order verification marker, if this request has one.
	 *
	 * One verification covers one deferral: a create-order request runs form
	 * validation once, and anything beyond that verifies for itself.
	 *
	 * @return bool Whether this request had already been verified here.
	 */
	private function consume_create_order_verification(): bool {
		$verified = $this->create_order_request_verified;

		$this->create_order_request_verified = false;

		return $verified;
	}

	/**
	 * Check if an approved PayPal order exists in the WC session.
	 *
	 * PayPal Payments stores the approved order in the 'ppcp' session key
	 * after ppc-create-order and ppc-approve-order. Its presence indicates
	 * the checkout is completing a flow where ppc-create-order already ran.
	 *
	 * @return bool
	 */
	private function has_paypal_order_in_session(): bool {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return false;
		}

		$ppcp_session = WC()->session->get( 'ppcp' );

		return is_array( $ppcp_session ) && ! empty( $ppcp_session['order'] );
	}

	/**
	 * Record that ppc-create-order scored a Blackbox session, and what it got.
	 *
	 * One write carries both facts: that this session was scored here, and the
	 * decision that scoring produced. Kept in the WC session so the decision
	 * outlives the request — a blocked create-order dies inside its own JSON
	 * response, and it is exactly the attempt whose verdict must survive.
	 *
	 * The record is keyed by the session ID the verification resolved, and the
	 * stand-down budget belongs to that session: scoring the same session again
	 * does not top it up. A fresh session starts a fresh record, budget and all.
	 *
	 * @param string        $session_id The session ID the verification resolved, empty when it completed none.
	 * @param FraudDecision $decision   The decision that verification produced.
	 */
	private function record_create_order_verification( string $session_id, FraudDecision $decision ): void {
		if ( '' === $session_id || ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}

		$record = $this->get_verified_session_record();

		WC()->session->set(
			self::VERIFICATION_RECORD_KEY,
			array(
				'session_id'  => $session_id,
				'stand_downs' => null !== $record && $record['session_id'] === $session_id ? $record['stand_downs'] : 0,
				'decision'    => $decision,
			)
		);
	}

	/**
	 * Answer for a session ppc-create-order already scored, if budget remains.
	 *
	 * Spends one of this verification's stand-downs. Once they are gone the answer
	 * is no, and the caller verifies with Blackbox instead.
	 *
	 * @param string $session_id The session ID from the current verify call.
	 * @return bool Whether this request may be answered from the record.
	 */
	private function take_stand_down_for_verified_session( string $session_id ): bool {
		if ( '' === $session_id || ! function_exists( 'WC' ) || ! WC()->session ) {
			return false;
		}

		$record = $this->get_verified_session_record();

		if ( null === $record || $record['session_id'] !== $session_id ) {
			return false;
		}

		if ( $record['stand_downs'] >= self::MAX_STAND_DOWNS_PER_SESSION ) {
			return false;
		}

		++$record['stand_downs'];

		WC()->session->set( self::VERIFICATION_RECORD_KEY, $record );

		return true;
	}

	/**
	 * Read the create-order verification record from the WC session.
	 *
	 * Only the shape {@see record_create_order_verification()} writes counts
	 * as a record. Anything else — corruption, another plugin's write — reads
	 * as no record, so the request falls through to a real verify.
	 *
	 * @return ?array{session_id: string, stand_downs: int, decision: FraudDecision} The record, or null when the session holds none this code wrote.
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
			'session_id'  => $session_id,
			'stand_downs' => (int) ( $stored['stand_downs'] ?? 0 ),
			'decision'    => $decision,
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
	 * Check if any PayPal Payments gateway is available.
	 *
	 * @return bool
	 */
	private function is_paypal_available(): bool {
		if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
			return false;
		}

		$gateways = WC()->payment_gateways()->get_available_payment_gateways();

		foreach ( array_keys( $gateways ) as $id ) {
			if ( $this->is_paypal_gateway( $id ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if PayPal Payments has mini-cart smart buttons enabled.
	 *
	 * When enabled, PayPal renders express buttons inside the mini-cart widget
	 * which appears on every frontend page (header/template part). Reads the
	 * same `smart_button_locations` setting that PayPal uses to decide where
	 * to load its scripts.
	 *
	 * @return bool
	 */
	private function is_paypal_mini_cart_enabled(): bool {
		$ppcp_settings = get_option( 'woocommerce-ppcp-settings', array() );

		if ( ! is_array( $ppcp_settings ) ) {
			return false;
		}

		$locations = $ppcp_settings['smart_button_locations'] ?? array();

		return is_array( $locations ) && in_array( 'mini-cart', $locations, true );
	}
}
