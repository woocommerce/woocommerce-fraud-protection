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
	 * PayPal runs WC form validation inside the create-order request it already
	 * verified, and that validation presents no session ID of its own. A plain
	 * object property on purpose: request-local, unforgeable, consumed on read.
	 *
	 * @var bool
	 */
	private bool $create_order_request_verified = false;

	/**
	 * The session ID this request's create-order verification recorded, if any.
	 *
	 * Lets the PayPal order created later in the same request be bound to the
	 * record it belongs to. Request-local and consumed on read, like the marker
	 * above: a PayPal order created outside a verified create-order request —
	 * a subscription renewal, for instance — binds nothing.
	 *
	 * @var string
	 */
	private string $session_recorded_this_request = '';

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
		add_action( 'woocommerce_paypal_payments_paypal_order_created', array( $this, 'bind_created_order_to_verification' ) );
		add_filter( 'woocommerce_fraud_protection_enqueue_blackbox_scripts', array( $this, 'should_enqueue_blackbox' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_paypal_script' ), 20 );
		add_filter( 'woocommerce_fraud_protection_skip_session_verify', array( $this, 'supply_decision_for_paypal_express' ), 10, 4 );
	}

	/**
	 * Verify the session and block the PayPal CreateOrder request if needed.
	 *
	 * Runs on `woocommerce_paypal_payments_create_order_request_started`. On
	 * BLOCK it responds and terminates via wp_send_json_error(), so the write
	 * ordering around that response is deliberate: the record first — the
	 * blocked attempt is the one whose verdict must outlive its request — and
	 * the in-request marker after, so a blocked request leaves nothing behind.
	 *
	 * @internal
	 *
	 * @param array $data The CreateOrder request data from PayPal Payments.
	 * @return void
	 */
	public function verify_and_block_create_order( array $data ): void {
		$session_id = sanitize_text_field( $data[ SessionVerifier::SESSION_ID_FIELD ] ?? '' );

		$decision = $this->session_verifier->verify_session( $session_id, self::ORDER_CREATION_SOURCE, 0, $data );

		$resolved_session_id = $this->session_verifier->last_verified_session_id();

		$this->record_create_order_verification( $resolved_session_id, $decision );

		if ( FraudDecision::Block === $decision ) {
			wp_send_json_error(
				array( 'message' => $this->blocked_session_message->get_plaintext( MessageContext::Purchase ) ),
				403
			);
		}

		$this->create_order_request_verified = true;
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
		$session_id = $this->session_recorded_this_request;

		$this->session_recorded_this_request = '';

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

		$record['order_id'] = $order_id;

		WC()->session->set( self::VERIFICATION_RECORD_KEY, $record );
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
	 * Answers requests this class already scored with the decision that scoring
	 * produced, so one payment attempt is not scored twice; everything else is
	 * deferred and verified normally. Standard filter arbitration: a consumer
	 * that wants the last word registers with a later priority. The parameter
	 * type is the contract — anything else in the chain fails loudly and ends
	 * in a real verify.
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

		// Same Blackbox session already scored by ppc-create-order in this flow.
		if ( $this->take_stand_down_for_verified_session( $session_id ) ) {
			return $this->decision_for_verified_attempt( $session_id );
		}

		// After express approval: only the order this record's verification
		// minted answers, with the decision that verification produced.
		$bound_decision = $this->decision_for_scored_order_in_session();
		if ( null !== $bound_decision ) {
			return $bound_decision;
		}

		// All other ppcp-* flows (Blocks "Place Order" with ppcp-gateway, APMs): defer.
		return $decision;
	}

	/**
	 * The decision to hand back for a request this class already scored.
	 *
	 * The recorded decision on an exact session-ID match; otherwise allow,
	 * which only the marker route reaches — the validation leg it covers
	 * presents no matchable ID, and its origin verify allowed (a blocked one
	 * dies before the marker is set).
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
	 * The decision to hand back when the approved order in PayPal's session
	 * slot is the one this record's verification minted.
	 *
	 * Bound by order identity because session IDs cannot match here: Blackbox
	 * was reset after create-order. An unbound record, a foreign order in the
	 * slot, or no order at all defers to a real verify.
	 *
	 * @return ?FraudDecision The recorded decision, or null to defer.
	 */
	private function decision_for_scored_order_in_session(): ?FraudDecision {
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

		return $record['decision'];
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
	 * Record that ppc-create-order scored a Blackbox session, and what it got.
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
				'order_id'    => '',
			)
		);
	}

	/**
	 * Answer for a session ppc-create-order already scored, if budget remains.
	 *
	 * Spends one stand-down; past the budget the caller verifies with Blackbox.
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
	 * as no record, so the request falls through to a real verify. The one
	 * tolerated absence is `order_id`, which records written before the
	 * binding existed lack: they read as unbound, never as no record.
	 *
	 * @return ?array{session_id: string, stand_downs: int, decision: FraudDecision, order_id: string} The record, or null when the session holds none this code wrote.
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
			'order_id'    => is_string( $stored['order_id'] ?? null ) ? $stored['order_id'] : '',
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
