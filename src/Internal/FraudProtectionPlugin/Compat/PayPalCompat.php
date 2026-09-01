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
use WooCommerce\PayPalCommerce\Button\Endpoint\RequestData;
use WooCommerce\PayPalCommerce\PPCP;

defined( 'ABSPATH' ) || exit;

/**
 * Integrates Blackbox fraud protection into PayPal Payments express checkout flows.
 *
 * Verifies protected PayPal artifacts and carries one response-backed decision
 * to the matching final request.
 */
class PayPalCompat {

	/**
	 * Source identifier for verify requests from PayPal express flows.
	 */
	private const ORDER_CREATION_SOURCE = 'paypal_express_order_creation';

	/**
	 * Source identifier for setup-token requests.
	 */
	private const SETUP_TOKEN_CREATION_SOURCE = 'paypal_setup_token_creation';

	/**
	 * Source identifier for vault-order requests.
	 */
	private const VAULT_ORDER_CREATION_SOURCE = 'paypal_vault_order_creation';

	/**
	 * Gateway ID prefix shared by all PayPal Payments gateways.
	 */
	private const PAYPAL_GATEWAY_PREFIX = 'ppcp-';

	/**
	 * First PayPal Payments version that uses the styling option at runtime.
	 */
	private const PAYPAL_STYLING_SETTINGS_VERSION = '4.0.0';

	/**
	 * WC session key for the current PayPal verification record.
	 */
	private const VERIFICATION_RECORD_KEY = '_fraud_protection_paypal_verification';

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
	 * The session ID this request's order verification recorded, if any.
	 *
	 * @var string
	 */
	private string $session_recorded_this_request = '';

	/**
	 * The origin of this request's order verification.
	 *
	 * @var string
	 */
	private string $origin_recorded_this_request = '';

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
		add_filter( 'ppcp_request_args', array( $this, 'verify_protected_paypal_request' ), 10, 2 );
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
		add_action( 'woocommerce_add_payment_method_form_bottom', array( $this, 'enqueue_paypal_script_for_add_payment_method' ), 20, 0 );
		add_action( 'woocommerce_subscriptions_change_payment_after_submit', array( $this, 'enqueue_paypal_script_if_add_payment_method_enqueued' ), 20, 0 );
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
	 * @param mixed $data The CreateOrder request data from PayPal Payments.
	 * @return void
	 */
	public function verify_and_block_create_order( $data ): void {
		$this->verify_and_block_artifact( is_array( $data ) ? $data : array(), self::ORDER_CREATION_SOURCE, true );
	}

	/**
	 * Verify matching setup-token and vault-order transport requests.
	 *
	 * @internal
	 *
	 * @param mixed $args HTTP request arguments.
	 * @param mixed $url  PayPal request URL.
	 * @return mixed The unchanged HTTP request arguments.
	 */
	public function verify_protected_paypal_request( $args, $url ) {
		if ( ! is_array( $args ) || ! is_string( $url ) ) {
			return $args;
		}
		$origin = $this->protected_request_origin( $args, $url );
		if ( '' === $origin ) {
			return $args;
		}

		$data           = array();
		$record_allowed = false;
		try {
			$data              = $this->read_protected_request_data( $origin );
			$submitted_carrier = $data[ SessionVerifier::SESSION_ID_FIELD ] ?? '';
			$record_allowed    = '' !== $this->session_id_normalizer->normalize_stored( $submitted_carrier );
		} catch ( \Throwable $e ) {
			FraudProtectionController::log(
				'warning',
				'Reading protected PayPal request data failed; verifying without a browser session',
				array(
					'hook'            => 'ppcp_request_args',
					'exception_class' => $e::class,
				),
				true
			);
		}

		$this->verify_and_block_artifact( $data, $origin, $record_allowed );

		return $args;
	}

	/**
	 * Read one protected request through PayPal Payments.
	 *
	 * @param string $origin Verification source.
	 * @return array Validated request data.
	 * @throws \RuntimeException When PayPal request data is unavailable.
	 * @throws \UnexpectedValueException When the request-data service is incompatible.
	 */
	private function read_protected_request_data( string $origin ): array {
		if ( ! class_exists( PPCP::class ) || ! class_exists( RequestData::class ) ) {
			throw new \RuntimeException( 'PayPal request data is unavailable.' );
		}

		$request_data = PPCP::container()->get( 'button.request-data' );
		if ( ! $request_data instanceof RequestData ) {
			throw new \UnexpectedValueException( 'PayPal request data is incompatible.' );
		}

		return $request_data->read_request( self::SETUP_TOKEN_CREATION_SOURCE === $origin ? 'ppc-create-setup-token' : 'ppc-vault-create-order' );
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
		$origin     = $this->origin_recorded_this_request;

		$this->session_recorded_this_request = '';
		$this->origin_recorded_this_request  = '';

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

			if ( null === $record || $record['session_id'] !== $session_id || $record['origin'] !== $origin ) {
				return;
			}

			$record['order_id'] = $order_id;

			WC()->session->set( self::VERIFICATION_RECORD_KEY, $record );
		} catch ( \Throwable $e ) {
			FraudProtectionController::log(
				'warning',
				'Binding the created PayPal order threw; leaving the verification unbound',
				array(
					'hook'            => 'woocommerce_paypal_payments_paypal_order_created',
					'session_id'      => $session_id,
					'exception_class' => $e::class,
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
			plugins_url( 'assets/js/paypal-express.js', WC_FRAUD_PROTECTION_PLUGIN_FILE ),
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
	 * Enqueue the interceptor for the My Account add-payment-method form.
	 *
	 * @internal
	 *
	 * @return void
	 */
	public function enqueue_paypal_script_for_add_payment_method(): void {
		if ( ! is_add_payment_method_page() || ! is_wc_endpoint_url( 'add-payment-method' ) ) {
			return;
		}

		$this->enqueue_paypal_script_if_add_payment_method_enqueued();
	}

	/**
	 * Enqueue the interceptor when PayPal's add-payment-method script is active.
	 *
	 * @internal
	 *
	 * @return void
	 */
	public function enqueue_paypal_script_if_add_payment_method_enqueued(): void {
		if ( ! wp_script_is( 'ppcp-add-payment-method', 'registered' ) || ! wp_script_is( 'ppcp-add-payment-method', 'enqueued' ) ) {
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

		try {
			$record = $this->get_verified_session_record();
			if ( null === $record || $record['used'] || $this->session_id_normalizer->normalize_stored( $record['session_id'] ) !== $session_id ) {
				$this->retire_verification_record();
				return $supplied_decision;
			}

			$matches = self::SETUP_TOKEN_CREATION_SOURCE === $record['origin']
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
			FraudProtectionController::log(
				'warning',
				'Reading or consuming the PayPal verification record failed; final request will verify',
				array(
					'hook'            => 'woocommerce_fraud_protection_skip_session_verify',
					'exception_class' => $e::class,
				),
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
	 * Verify one protected PayPal artifact and enforce its decision.
	 *
	 * @param array  $data           Validated request data.
	 * @param string $origin         Verification source.
	 * @param bool   $record_allowed Whether the browser carrier can identify this response.
	 */
	private function verify_and_block_artifact( array $data, string $origin, bool $record_allowed ): void {
		$submitted_session_id = array_key_exists( SessionVerifier::SESSION_ID_FIELD, $data ) ? $data[ SessionVerifier::SESSION_ID_FIELD ] : '';
		$decision             = $this->session_verifier->verify_session( $submitted_session_id, $origin, 0, $data );
		$resolved_session_id  = $this->session_verifier->last_verified_session_id();

		try {
			$this->update_verification_record( $origin, $resolved_session_id, $decision, $record_allowed );
		} catch ( \Throwable $e ) {
			FraudProtectionController::log(
				'warning',
				'Recording the PayPal artifact verification failed; final request will verify',
				array(
					'hook'            => 'paypal_artifact_verification',
					'session_id'      => $resolved_session_id,
					'exception_class' => $e::class,
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

		if ( $record_allowed && in_array( $origin, array( self::ORDER_CREATION_SOURCE, self::VAULT_ORDER_CREATION_SOURCE ), true ) ) {
			$this->session_recorded_this_request = $resolved_session_id;
			$this->origin_recorded_this_request  = $origin;
		}
	}

	/**
	 * Resolve a supported protected request from its active action and target.
	 *
	 * @param array  $args HTTP request arguments.
	 * @param string $url  PayPal request URL.
	 * @return string Verification source, or an empty string.
	 */
	private function protected_request_origin( array $args, string $url ): string {
		if ( 'POST' !== ( $args['method'] ?? null ) ) {
			return '';
		}

		$path = wp_parse_url( $url, PHP_URL_PATH );
		$path = is_string( $path ) ? '/' . ltrim( untrailingslashit( $path ), '/' ) : '';

		if ( doing_action( 'wc_ajax_ppc-create-setup-token' ) && '/v3/vault/setup-tokens' === $path ) {
			return self::SETUP_TOKEN_CREATION_SOURCE;
		}

		if ( doing_action( 'wc_ajax_ppc-vault-create-order' ) && '/v2/checkout/orders' === $path ) {
			return self::VAULT_ORDER_CREATION_SOURCE;
		}

		return '';
	}

	/**
	 * Store the current response-backed artifact decision.
	 *
	 * @param string        $origin         Verification source.
	 * @param string        $session_id     Response-backed session ID.
	 * @param FraudDecision $decision       Applied decision.
	 * @param bool          $record_allowed Whether this result can be matched by the browser carrier.
	 */
	private function update_verification_record( string $origin, string $session_id, FraudDecision $decision, bool $record_allowed ): void {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}

		$record = null;
		if ( $record_allowed && '' !== $session_id && in_array( $decision, FraudDecision::ACTIONABLE, true ) ) {
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
			$product = is_array( $cart_item ) ? ( $cart_item['data'] ?? null ) : null;
			if ( is_object( $product ) && method_exists( $product, 'get_meta' ) && '' !== (string) $product->get_meta( 'ppcp_subscription_plan' ) ) {
				return '';
			}
		}

		$cart_hash = $cart->get_cart_hash();

		return is_string( $cart_hash ) ? $cart_hash : '';
	}

	/**
	 * Retire the current verification record without affecting the request.
	 *
	 * @return void
	 */
	private function retire_verification_record(): void {
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
