<?php
/**
 * PayPalCompatTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin\Compat;

require_once dirname( __DIR__, 4 ) . '/Support/PayPalPPCPStubs.php';

use Automattic\WooCommerce\FraudProtection\BlackboxScriptHandler;
use Automattic\WooCommerce\FraudProtection\Schemas\FraudDecision;
use Automattic\WooCommerce\FraudProtection\BlockedSessionMessage;
use Automattic\WooCommerce\FraudProtection\MessageContext;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Compat\PayPalCompat;
use Automattic\WooCommerce\FraudProtection\SessionVerifier;
use Automattic\WooCommerce\FraudProtection\SessionIdNormalizer;
use Automattic\WooCommerce\FraudProtection\SuppliedDecision;
use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;
use Automattic\WooCommerce\FraudProtection\Tests\Support\FakePayPalOrder;
use Automattic\WooCommerce\FraudProtection\Tests\Support\PayPalContainerStub;
use Automattic\WooCommerce\FraudProtection\Tests\Support\PayPalJsonResponseCapture;
use Automattic\WooCommerce\FraudProtection\Tests\Support\PayPalPPCPStub;
use Automattic\WooCommerce\FraudProtection\Tests\Support\ThrowingPayPalOrder;
use Automattic\WooCommerce\Blocks\BlockTypes\AbstractBlock;

/** PayPal request-data stub. */
class TestPayPalRequestData {

	/** @var array */
	public static array $data = array();

	/** @var ?\Throwable */
	public static ?\Throwable $error = null;

	/**
	 * Return controlled request data.
	 *
	 * @param string $nonce Nonce action.
	 * @return array
	 */
	public function read_request( string $nonce ): array {
		if ( null !== self::$error ) {
			throw self::$error;
		}

		return self::$data + array( 'validated_nonce' => $nonce );
	}
}

/** Subscriptions runtime stub. */
class TestWCSubscriptions {}

/**
 * Tests for the PayPalCompat class.
 *
 * @covers \Automattic\WooCommerce\Internal\FraudProtectionPlugin\Compat\PayPalCompat
 */
class PayPalCompatTest extends FraudProtectionUnitTestCase {

	/**
	 * The System Under Test.
	 *
	 * @var PayPalCompat
	 */
	private PayPalCompat $sut;

	/**
	 * Mock session verifier.
	 *
	 * @var SessionVerifier&\PHPUnit\Framework\MockObject\MockObject
	 */
	private $session_verifier;

	/**
	 * Mock blocked-session message generator.
	 *
	 * @var BlockedSessionMessage&\PHPUnit\Framework\MockObject\MockObject
	 */
	private $blocked_session_message;

	/**
	 * Session ID normalizer.
	 *
	 * @var SessionIdNormalizer
	 */
	private $session_id_normalizer;

	/**
	 * PayPal settings before the test.
	 *
	 * @var array<string, array{exists: bool, value: mixed}>
	 */
	private array $original_paypal_options = array();

	/**
	 * Whether the test changed PayPal's smart-button handle.
	 *
	 * @var bool
	 */
	private bool $touched_smart_button_handle = false;

	/**
	 * Whether the test registered PayPal's block integration handle.
	 *
	 * @var bool
	 */
	private bool $registered_block_handle = false;

	/** @var bool */
	private bool $touched_add_payment_method_handle = false;

	/** @var mixed */
	private $original_cart;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		if ( 'test_missing_paypal_classes_verify_without_session_id' !== $this->getName() && ! class_exists( '\WooCommerce\PayPalCommerce\PPCP', false ) ) {
			class_alias( PayPalPPCPStub::class, 'WooCommerce\PayPalCommerce\PPCP' );
		}
		if ( ! class_exists( 'WC_Subscriptions' ) ) {
			class_alias( TestWCSubscriptions::class, 'WC_Subscriptions' );
		}
		TestPayPalRequestData::$data  = array();
		TestPayPalRequestData::$error = null;
		PayPalContainerStub::reset();
		PayPalPPCPStub::set_error( null );
		PayPalJsonResponseCapture::reset();
		$this->original_cart = WC()->cart;

		$this->session_verifier        = $this->createMock( SessionVerifier::class );
		$this->blocked_session_message = $this->createMock( BlockedSessionMessage::class );
		$this->session_id_normalizer    = new SessionIdNormalizer();
		$this->remember_paypal_option( 'woocommerce-ppcp-settings' );
		$this->remember_paypal_option( 'woocommerce-ppcp-data-styling' );
		$this->remember_paypal_option( 'woocommerce-ppcp-version' );

		$this->blocked_session_message
			->method( 'get_plaintext' )
			->willReturn( 'We are unable to process this request online. Please contact support (test@example.com) to complete your purchase.' );

		$this->sut = new PayPalCompat();
		$this->sut->init(
			$this->session_verifier,
			$this->blocked_session_message,
			$this->session_id_normalizer,
			$this->make_blackbox_script_handler()
		);
	}

	/**
	 * Tear down after each test.
	 */
	public function tearDown(): void {
		global $wp;

		remove_all_filters( 'wp_doing_ajax' );
		remove_all_filters( 'wp_die_ajax_handler' );
		remove_all_filters( 'woocommerce_fraud_protection_skip_session_verify' );
		remove_all_filters( 'ppcp_request_args' );
		PayPalContainerStub::reset();
		PayPalPPCPStub::set_error( null );
		PayPalJsonResponseCapture::reset();
		$this->restore_paypal_options();
		if ( $this->touched_smart_button_handle ) {
			wp_dequeue_script( 'ppcp-smart-button' );
			wp_deregister_script( 'ppcp-smart-button' );
		}
		if ( $this->registered_block_handle ) {
			wp_dequeue_script( 'ppcp-checkout-block' );
			wp_deregister_script( 'ppcp-checkout-block' );
		}
		if ( $this->touched_add_payment_method_handle ) {
			wp_dequeue_script( 'ppcp-add-payment-method' );
			wp_deregister_script( 'ppcp-add-payment-method' );
		}
		remove_all_actions( 'woocommerce_paypal_payments_create_order_request_started' );
		remove_all_actions( 'woocommerce_paypal_payments_paypal_order_created' );
		foreach ( $this->paypal_button_render_hook_provider() as $case ) {
			remove_action( $case[0], array( $this->sut, 'enqueue_paypal_script' ), 10 );
		}
		remove_action( 'woocommerce_blocks_enqueue_checkout_block_scripts_before', array( $this->sut, 'enqueue_paypal_block_script_if_registered' ), 20 );
		remove_action( 'woocommerce_blocks_enqueue_cart_block_scripts_before', array( $this->sut, 'enqueue_paypal_block_script_if_registered' ), 20 );
		remove_action( 'woocommerce_before_mini_cart', array( $this->sut, 'enqueue_paypal_mini_cart_script_if_enabled' ), 10 );
		remove_filter( 'woocommerce_widget_cart_is_hidden', array( $this->sut, 'enqueue_paypal_script_for_visible_mini_cart_widget' ), 20 );
		remove_action( 'woocommerce_checkout_before_order_review', array( $this->sut, 'enqueue_paypal_script_if_smart_button_enqueued' ), 20 );
		remove_action( 'before_woocommerce_pay_form', array( $this->sut, 'enqueue_paypal_script_if_smart_button_enqueued' ), 20 );
		remove_action( 'woocommerce_add_payment_method_form_bottom', array( $this->sut, 'enqueue_paypal_script_for_add_payment_method' ), 20 );
		remove_action( 'woocommerce_subscriptions_change_payment_after_submit', array( $this->sut, 'enqueue_paypal_script_if_add_payment_method_enqueued' ), 20 );
		WC()->cart = $this->original_cart;
		$this->reset_fraud_protection_scripts();

		if ( WC()->session ) {
			WC()->session->set( 'ppcp', null );
			WC()->session->set( '_fraud_protection_paypal_verification', null );
			WC()->session->set( '_fraud_protection_paypal_verified_session_id', null );
		}

		unset( $_GET['wc-ajax'] );
		unset( $wp->query_vars['order-pay'] );
		unset( $wp->query_vars['order-received'] );
		set_current_screen( 'front' );

		parent::tearDown();
	}

	/*
	|--------------------------------------------------------------------------
	| register() Tests
	|--------------------------------------------------------------------------
	*/

	/**
	 * @testdox register() hooks verification and the exact PayPal render signals.
	 */
	public function test_register_hooks(): void {
		$this->sut->register();

		$this->assertNotFalse(
			has_action( 'woocommerce_paypal_payments_create_order_request_started', array( $this->sut, 'verify_and_block_create_order' ) ),
			'create_order_request_started action should be registered'
		);

		foreach ( $this->paypal_button_render_hook_provider() as $case ) {
			$this->assertSame( 10, has_action( $case[0], array( $this->sut, 'enqueue_paypal_script' ) ) );
		}

		$this->assertSame( 20, has_action( 'woocommerce_blocks_enqueue_checkout_block_scripts_before', array( $this->sut, 'enqueue_paypal_block_script_if_registered' ) ) );
		$this->assertSame( 20, has_action( 'woocommerce_blocks_enqueue_cart_block_scripts_before', array( $this->sut, 'enqueue_paypal_block_script_if_registered' ) ) );
		$this->assertSame( 10, has_action( 'woocommerce_before_mini_cart', array( $this->sut, 'enqueue_paypal_mini_cart_script_if_enabled' ) ) );
		$this->assertSame( 20, has_filter( 'woocommerce_widget_cart_is_hidden', array( $this->sut, 'enqueue_paypal_script_for_visible_mini_cart_widget' ) ) );
		$this->assertSame( 20, has_action( 'woocommerce_checkout_before_order_review', array( $this->sut, 'enqueue_paypal_script_if_smart_button_enqueued' ) ) );
		$this->assertSame( 20, has_action( 'before_woocommerce_pay_form', array( $this->sut, 'enqueue_paypal_script_if_smart_button_enqueued' ) ) );
		$this->assertSame( 20, has_action( 'woocommerce_add_payment_method_form_bottom', array( $this->sut, 'enqueue_paypal_script_for_add_payment_method' ) ) );
		$this->assertSame( 20, has_action( 'woocommerce_subscriptions_change_payment_after_submit', array( $this->sut, 'enqueue_paypal_script_if_add_payment_method_enqueued' ) ) );
		$this->assertSame( 10, has_filter( 'ppcp_request_args', array( $this->sut, 'verify_protected_paypal_request' ) ) );
		$this->assertFalse( has_action( 'wp_enqueue_scripts', array( $this->sut, 'enqueue_paypal_script' ) ) );
		$this->assertFalse( has_action( 'wp_enqueue_scripts', array( $this->sut, 'enqueue_paypal_mini_cart_script_if_enabled' ) ) );
		$this->assertNotFalse(
			has_filter( 'woocommerce_fraud_protection_skip_session_verify', array( $this->sut, 'supply_decision_for_paypal_express' ) ),
			'skip_session_verify filter should be registered'
		);
		$this->assertNotFalse(
			has_action( 'woocommerce_paypal_payments_paypal_order_created', array( $this->sut, 'bind_created_order_to_verification' ) ),
			'paypal_order_created action should be registered'
		);
	}

	/*
	|--------------------------------------------------------------------------
	| verify_and_block_create_order() Tests
	|--------------------------------------------------------------------------
	*/

	/**
	 * @testdox verify_and_block_create_order() extracts session_id from data and calls verify_session — allows on ALLOW.
	 */
	public function test_verify_allows_on_allow_decision(): void {
		$data = array( SessionVerifier::SESSION_ID_FIELD => 'test-session-abc' );

		$this->session_verifier
			->expects( $this->once() )
			->method( 'verify_session' )
			->with( 'test-session-abc', 'paypal_express_order_creation', 0, $data )
			->willReturn( FraudDecision::Allow );

		$this->session_verifier
			->method( 'last_verified_session_id' )
			->willReturn( 'test-session-abc' );

		// Should return normally without terminating.
		$this->sut->verify_and_block_create_order( $data );

		$this->assertSame(
			array(
				'origin'     => 'paypal_express_order_creation',
				'session_id' => 'test-session-abc',
				'decision'   => FraudDecision::Allow,
				'used'       => false,
				'order_id'   => '',
				'cart_hash'  => '',
			),
			WC()->session->get( '_fraud_protection_paypal_verification' )
		);
	}

	/**
	 * @testdox verify_and_block_create_order() forwards a complete PayPal payment request unchanged.
	 */
	public function test_verify_forwards_complete_paypal_payment_request(): void {
		$data = array(
			SessionVerifier::SESSION_ID_FIELD => 'test-session-abc',
			'payment_method'                  => 'ppcp-credit-card-gateway',
			'payment_data'                    => array( 'card_number' => 'tokenized-value' ),
		);

		$this->session_verifier
			->expects( $this->once() )
			->method( 'verify_session' )
			->with( 'test-session-abc', 'paypal_express_order_creation', 0, $data )
			->willReturn( FraudDecision::Allow );
		$this->session_verifier->method( 'last_verified_session_id' )->willReturn( 'test-session-abc' );

		$this->sut->verify_and_block_create_order( $data );

		$this->assertSame( FraudDecision::Allow, WC()->session->get( '_fraud_protection_paypal_verification' )['decision'] );
	}

	/**
	 * @testdox verify_and_block_create_order() removes a prior record when no response-backed ID exists.
	 */
	public function test_verify_removes_prior_record_without_response_session_id(): void {
		$data = array( 'context' => 'product' );
		WC()->session->set(
			'_fraud_protection_paypal_verification',
			array(
				'session_id'  => 'prior-session',
				'stand_downs' => 0,
				'decision'    => FraudDecision::Allow,
			)
		);

		$this->session_verifier
			->method( 'verify_session' )
			->willReturn( FraudDecision::Allow );

		$this->sut->verify_and_block_create_order( $data );

		$this->assertNull( WC()->session->get( '_fraud_protection_paypal_verification' ) );
	}

	/**
	 * @testdox verify_and_block_create_order() passes the submitted value to SessionVerifier
	 *
	 * @dataProvider submitted_session_value_provider
	 *
	 * @param mixed $value Submitted value.
	 */
	public function test_verify_passes_submitted_value_to_session_verifier( $value ): void {
		$data = array(
			SessionVerifier::SESSION_ID_FIELD => $value,
			'context'                         => array( 'source' => 'product' ),
		);

		$this->session_verifier
			->expects( $this->once() )
			->method( 'verify_session' )
			->with( $value, 'paypal_express_order_creation', 0, $this->anything() )
			->willReturn( FraudDecision::Allow );
		$this->session_verifier->method( 'last_verified_session_id' )->willReturn( 'response-id' );

		$this->sut->verify_and_block_create_order( $data );
		$this->sut->bind_created_order_to_verification( new FakePayPalOrder( 'PP-123' ) );

		$this->assertNull( WC()->session->get( '_fraud_protection_paypal_verification' ) );
	}

	/**
	 * Submitted session values.
	 *
	 * @return array<string, array{mixed}>
	 */
	public function submitted_session_value_provider(): array {
		return array(
			'empty string'       => array( '' ),
			'invalid characters' => array( '.' ),
			'null'               => array( null ),
			'array'              => array( array( 'private' ) ),
		);
	}

	/**
	 * @testdox verify_and_block_create_order() sends JSON error with 403 on BLOCK decision.
	 */
	public function test_verify_blocks_on_block_decision(): void {
		$data = array( SessionVerifier::SESSION_ID_FIELD => 'test-session-blocked' );

		$this->session_verifier
			->expects( $this->once() )
			->method( 'verify_session' )
			->with( 'test-session-blocked', 'paypal_express_order_creation', 0, $data )
			->willReturn( FraudDecision::Block );

		$this->blocked_session_message
			->expects( $this->once() )
			->method( 'get_plaintext' )
			->with( MessageContext::Purchase );

		// wp_send_json_error() echoes JSON then calls wp_die(). Force AJAX
		// context (otherwise it calls bare die()) and override the die
		// handler to throw a catchable exception.
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter(
			'wp_die_ajax_handler',
			function () {
				return function () {
					throw new \WPDieException();
				};
			}
		);

		$this->expectException( \WPDieException::class );
		$this->expectOutputRegex( '/"success":false.*unable to process this request/' );

		$this->sut->verify_and_block_create_order( $data );
	}

	/**
	 * @testdox verify_and_block_create_order() still enforces the block when recording the verification throws.
	 *
	 * The record is best-effort, but it is written before the block check. A
	 * throw from the session read/write must not escape past that check: the
	 * block stays enforced.
	 */
	public function test_verify_enforces_the_block_when_recording_throws(): void {
		$data = array( SessionVerifier::SESSION_ID_FIELD => 'blocked-session' );

		$this->session_verifier->method( 'verify_session' )->willReturn( FraudDecision::Block );
		$this->session_verifier->method( 'last_verified_session_id' )->willReturn( 'blocked-session' );

		// A WC session whose reads and writes throw, so update_verification_record() fails.
		$original_session = WC()->session;
		WC()->session     = new class() {
			public function get( $key, $default = null ) { // phpcs:ignore
				throw new \RuntimeException( 'session unavailable' );
			}
			public function set( $key, $value = null ) { // phpcs:ignore
				throw new \RuntimeException( 'session unavailable' );
			}
		};

		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter(
			'wp_die_ajax_handler',
			function () {
				return function () {
					throw new \WPDieException();
				};
			}
		);

		$blocked = false;
		ob_start();
		try {
			$this->sut->verify_and_block_create_order( $data );
		} catch ( \WPDieException $e ) {
			$blocked = true;
		} finally {
			ob_end_clean();
			WC()->session = $original_session;
		}

		$this->assertTrue( $blocked, 'The block must still be enforced when recording the verification throws.' );
		$this->assertLogged(
			'warning',
			'Recording the PayPal request verification failed',
			array(
				'event_source'      => 'paypal_express_order_creation',
				'session_id'        => 'blocked-session',
				'exception_class'   => 'RuntimeException',
				'exception_message' => 'session unavailable',
			)
		);
	}

	/** @testdox A failed current record write retires an older record and leaves no order binding marker. */
	public function test_failed_record_write_leaves_no_order_binding_marker(): void {
		$original_session = WC()->session;
		$record           = array(
			'origin'     => 'paypal_express_order_creation',
			'session_id' => 'response-session',
			'decision'   => FraudDecision::Allow,
			'used'       => false,
			'order_id'   => '',
			'cart_hash'  => '',
		);
		$original_session->set( '_fraud_protection_paypal_verification', $record );
		WC()->session = new class( $original_session ) {
			/** @var mixed */
			private $session;

			private bool $fail_next_write = true;

			public function __construct( $session ) { // phpcs:ignore
				$this->session = $session;
			}

			public function get( $key, $default = null ) { // phpcs:ignore
				return $this->session->get( $key, $default );
			}

			public function set( $key, $value = null ): void { // phpcs:ignore
				if ( $this->fail_next_write ) {
					$this->fail_next_write = false;
					throw new \RuntimeException( 'session write unavailable' );
				}

				$this->session->set( $key, $value );
			}
		};
		$this->session_verifier->method( 'verify_session' )->willReturn( FraudDecision::Allow );
		$this->session_verifier->method( 'last_verified_session_id' )->willReturn( 'response-session' );

		try {
			$this->sut->verify_and_block_create_order( array( SessionVerifier::SESSION_ID_FIELD => 'browser-session' ) );
			$this->assertNull( $original_session->get( '_fraud_protection_paypal_verification' ) );
			$original_session->set( '_fraud_protection_paypal_verification', $record );
			$this->sut->bind_created_order_to_verification( new FakePayPalOrder( 'PP-NEW' ) );
		} finally {
			WC()->session = $original_session;
		}

		$record = $original_session->get( '_fraud_protection_paypal_verification' );
		$this->assertIsArray( $record );
		$this->assertSame( '', $record['order_id'] );
		$this->assertLogged(
			'warning',
			'Recording the PayPal request verification failed',
			array(
				'event_source'      => 'paypal_express_order_creation',
				'session_id'        => 'response-session',
				'exception_class'   => 'RuntimeException',
				'exception_message' => 'session write unavailable',
			)
		);
	}

	/** @testdox A record update failure before storage retires an older reusable record. */
	public function test_record_update_failure_retires_an_older_reusable_record(): void {
		WC()->session->set(
			'_fraud_protection_paypal_verification',
			array(
				'origin'     => 'paypal_setup_token_creation',
				'session_id' => 'old-session',
				'decision'   => FraudDecision::Allow,
				'used'       => false,
				'order_id'   => '',
				'cart_hash'  => 'old-cart',
			)
		);
		$cart = $this->getMockBuilder( \WC_Cart::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_empty' ) )
			->getMock();
		$cart->method( 'is_empty' )->willThrowException( new \RuntimeException( 'cart unavailable' ) );
		WC()->cart = $cart;

		$this->configure_paypal_request_data( array( SessionVerifier::SESSION_ID_FIELD => 'browser-session' ) );
		$this->session_verifier->method( 'verify_session' )->willReturn( FraudDecision::Allow );
		$this->session_verifier->method( 'last_verified_session_id' )->willReturn( 'new-session' );

		$this->run_protected_request( 'wc_ajax_ppc-create-setup-token', array( 'method' => 'POST' ), '/v3/vault/setup-tokens' );
		$this->set_setup_cart( 'old-cart' );

		$this->assertFalse( $this->ask( 'blocks_checkout', 'ppcp-gateway', 'old-session' ) );
		$this->assertNull( WC()->session->get( '_fraud_protection_paypal_verification' ) );
		$this->assertLogged(
			'warning',
			'Recording the PayPal request verification failed',
			array(
				'event_source'      => 'paypal_setup_token_creation',
				'session_id'        => 'new-session',
				'exception_class'   => 'RuntimeException',
				'exception_message' => 'cart unavailable',
			)
		);
	}

	/**
	 * @testdox verify_and_block_create_order() calls verify with empty session_id when field is missing.
	 */
	public function test_verify_with_missing_session_id(): void {
		$data = array( 'context' => 'product' );

		$this->session_verifier
			->expects( $this->once() )
			->method( 'verify_session' )
			->with( '', 'paypal_express_order_creation', 0, $data )
			->willReturn( FraudDecision::Allow );

		$this->sut->verify_and_block_create_order( $data );
	}

	/**
	 * @testdox Only the exact protected action, method, and PayPal path verify.
	 *
	 * @dataProvider protected_request_gate_provider
	 */
	public function test_protected_request_gates( string $action, string $method, string $path, bool $expected ): void {
		$this->configure_paypal_request_data( array( SessionVerifier::SESSION_ID_FIELD => 'browser-session' ) );
		$this->session_verifier
			->expects( $expected ? $this->once() : $this->never() )
			->method( 'verify_session' )
			->willReturn( FraudDecision::Allow );
		$this->session_verifier->method( 'last_verified_session_id' )->willReturn( 'response-session' );

		$result = $this->run_protected_request( $action, array( 'method' => $method, 'body' => '{}' ), $path );

		$this->assertSame( array( 'method' => $method, 'body' => '{}' ), $result );
	}

	/** @return array<string, array{string, string, string, bool}> */
	public function protected_request_gate_provider(): array {
		return array(
			'setup'        => array( 'wc_ajax_ppc-create-setup-token', 'POST', '/v3/vault/setup-tokens', true ),
			'vault'        => array( 'wc_ajax_ppc-vault-create-order', 'POST', '/v2/checkout/orders', true ),
			'wrong action' => array( 'wc_ajax_ppc-create-order', 'POST', '/v3/vault/setup-tokens', false ),
			'wrong method' => array( 'wc_ajax_ppc-create-setup-token', 'GET', '/v3/vault/setup-tokens', false ),
			'wrong path'   => array( 'wc_ajax_ppc-vault-create-order', 'POST', '/v2/checkout/orders/PP-1', false ),
		);
	}

	/**
	 * @testdox Protected requests use PayPal's validated data and exact nonce action.
	 *
	 * @dataProvider protected_request_provider
	 */
	public function test_protected_request_uses_validated_data( string $action, string $path, string $source, string $nonce ): void {
		$this->configure_paypal_request_data( array( SessionVerifier::SESSION_ID_FIELD => 'browser-session' ) );
		$this->session_verifier
			->expects( $this->once() )
			->method( 'verify_session' )
			->with(
				'browser-session',
				$source,
				0,
				array(
					SessionVerifier::SESSION_ID_FIELD => 'browser-session',
					'validated_nonce'                 => $nonce,
				)
			)
			->willReturn( FraudDecision::Allow );
		$this->session_verifier->method( 'last_verified_session_id' )->willReturn( 'response-session' );

		$this->run_protected_request( $action, array( 'method' => 'POST' ), $path );
	}

	/** @return array<string, array{string, string, string, string}> */
	public function protected_request_provider(): array {
		return array(
			'setup' => array( 'wc_ajax_ppc-create-setup-token', '/v3/vault/setup-tokens', 'paypal_setup_token_creation', 'ppc-create-setup-token' ),
			'vault' => array( 'wc_ajax_ppc-vault-create-order', '/v2/checkout/orders', 'paypal_vault_order_creation', 'ppc-vault-create-order' ),
		);
	}

	/**
	 * @testdox An unusable submitted session ID verifies but creates no reusable record.
	 *
	 * @dataProvider unusable_session_id_provider
	 */
	public function test_unusable_validated_session_id_is_not_recorded( array $data, $session_id ): void {
		$validated = $data + array( 'validated_nonce' => 'ppc-create-setup-token' );
		$this->configure_paypal_request_data( $data );
		$this->session_verifier
			->expects( $this->once() )
			->method( 'verify_session' )
			->with( $session_id, 'paypal_setup_token_creation', 0, $validated )
			->willReturn( FraudDecision::Allow );
		$this->session_verifier->method( 'last_verified_session_id' )->willReturn( 'response-session' );

		$this->run_protected_request( 'wc_ajax_ppc-create-setup-token', array( 'method' => 'POST' ), '/v3/vault/setup-tokens' );

		$this->assertNull( WC()->session->get( '_fraud_protection_paypal_verification' ) );
	}

	/** @return array<string, array{array, mixed}> */
	public function unusable_session_id_provider(): array {
		return array(
			'missing'   => array( array(), '' ),
			'empty'     => array( array( SessionVerifier::SESSION_ID_FIELD => '' ), '' ),
			'malformed' => array( array( SessionVerifier::SESSION_ID_FIELD => array( 'invalid' ) ), array( 'invalid' ) ),
		);
	}

	/**
	 * @testdox Protected routes return the plaintext purchase message on Block.
	 *
	 * @dataProvider protected_request_provider
	 */
	public function test_protected_request_blocks_before_transport( string $action, string $path, string $source, string $nonce ): void {
		unset( $source, $nonce );
		$this->configure_paypal_request_data( array( SessionVerifier::SESSION_ID_FIELD => 'browser-session' ) );
		$this->session_verifier->method( 'verify_session' )->willReturn( FraudDecision::Block );
		$this->session_verifier->method( 'last_verified_session_id' )->willReturn( 'response-session' );
		PayPalJsonResponseCapture::$enabled = true;

		try {
			$this->run_protected_request( $action, array( 'method' => 'POST' ), $path );
			$this->fail( 'Expected the Block response to terminate the request.' );
		} catch ( \WPDieException $e ) {
			unset( $e );
		}

		$this->assertSame( 403, PayPalJsonResponseCapture::$status_code );
		$this->assertIsArray( PayPalJsonResponseCapture::$data );
		$this->assertStringContainsString( 'unable to process this request', PayPalJsonResponseCapture::$data['message'] );
	}

	/**
	 * @testdox PayPal RequestData failures verify without a submitted session ID and store no reusable record.
	 *
	 * @dataProvider request_data_failure_provider
	 */
	public function test_request_data_failure_verifies_without_session_id( string $failure, string $action, string $path, string $source ): void {
		$this->configure_paypal_request_data( array( SessionVerifier::SESSION_ID_FIELD => 'browser-session' ), $failure );
		$this->session_verifier
			->expects( $this->once() )
			->method( 'verify_session' )
			->with( '', $source, 0, array() )
			->willReturn( FraudDecision::Allow );
		$this->session_verifier->method( 'last_verified_session_id' )->willReturn( 'response-session' );

		$this->run_protected_request( $action, array( 'method' => 'POST' ), $path );

		$this->assertNull( WC()->session->get( '_fraud_protection_paypal_verification' ) );
	}

	/** @return array<string, array{string, string, string, string}> */
	public function request_data_failure_provider(): array {
		return array(
			'setup container'            => array( 'container', 'wc_ajax_ppc-create-setup-token', '/v3/vault/setup-tokens', 'paypal_setup_token_creation' ),
			'setup incompatible service' => array( 'service', 'wc_ajax_ppc-create-setup-token', '/v3/vault/setup-tokens', 'paypal_setup_token_creation' ),
			'setup read or nonce'        => array( 'read', 'wc_ajax_ppc-create-setup-token', '/v3/vault/setup-tokens', 'paypal_setup_token_creation' ),
			'vault container'            => array( 'container', 'wc_ajax_ppc-vault-create-order', '/v2/checkout/orders', 'paypal_vault_order_creation' ),
			'vault incompatible service' => array( 'service', 'wc_ajax_ppc-vault-create-order', '/v2/checkout/orders', 'paypal_vault_order_creation' ),
			'vault read or nonce'        => array( 'read', 'wc_ajax_ppc-vault-create-order', '/v2/checkout/orders', 'paypal_vault_order_creation' ),
		);
	}

	/**
	 * @testdox Missing PayPal classes verify without a submitted session ID.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_missing_paypal_classes_verify_without_session_id(): void {
		$this->session_verifier
			->expects( $this->once() )
			->method( 'verify_session' )
			->with( '', 'paypal_setup_token_creation', 0, array() )
			->willReturn( FraudDecision::Allow );

		$this->run_protected_request( 'wc_ajax_ppc-create-setup-token', array( 'method' => 'POST' ), '/v3/vault/setup-tokens' );
	}

	/**
	 * @testdox Protected PayPal request sources preserve an incoming supplied decision.
	 *
	 * @dataProvider protected_paypal_request_source_provider
	 */
	public function test_protected_paypal_request_sources_preserve_supplied_decision( string $record_type, string $source, string $final_source ): void {
		$request  = $this->create_protected_paypal_request_record( $record_type );
		$supplied = new SuppliedDecision( FraudDecision::Block );

		$this->assertSame( $supplied, $this->sut->supply_decision_for_paypal_express( $supplied, $source, $request, 'response-session' ) );
		$final = $this->sut->supply_decision_for_paypal_express( false, $final_source, $request, 'response-session' );
		$this->assertInstanceOf( SuppliedDecision::class, $final );
		$this->assertSame( FraudDecision::Allow, $final->decision );
		$this->assertFalse( $this->sut->supply_decision_for_paypal_express( false, $final_source, $request, 'response-session' ) );
	}

	/** @return array<string, array{string, string, string}> */
	public function protected_paypal_request_source_provider(): array {
		return array(
			'create order' => array( 'create', 'paypal_express_order_creation', 'blocks_checkout' ),
			'setup token'  => array( 'setup', 'paypal_setup_token_creation', 'blocks_checkout' ),
			'vault order'  => array( 'vault', 'paypal_vault_order_creation', 'subscriptions_change_payment' ),
		);
	}

	/** @testdox Mixed PayPal filter inputs pass through without verification. */
	public function test_protected_request_preserves_mixed_filter_inputs(): void {
		$this->session_verifier->expects( $this->never() )->method( 'verify_session' );

		$this->assertSame( 'invalid', $this->sut->verify_protected_paypal_request( 'invalid', array() ) );
	}

	/*
	|--------------------------------------------------------------------------
	| enqueue_paypal_script() Tests
	|--------------------------------------------------------------------------
	*/

	/**
	 * @testdox Each PayPal button render action requests the shared scripts and enqueues the interceptor.
	 *
	 * @dataProvider paypal_button_render_hook_provider
	 *
	 * @param string $hook PayPal button render action.
	 */
	public function test_button_render_hooks_enqueue_paypal_script( string $hook ): void {
		$this->mock_jetpack_blog_id( 12345 );
		$this->sut->register();

		do_action( $hook );

		$this->assertTrue( wp_script_is( 'wc-fraud-protection-blackbox-init', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'wc-fraud-protection-paypal-express', 'enqueued' ) );
	}

	/**
	 * @testdox A Cart block rendered from template content reaches the PayPal block follower.
	 */
	public function test_cart_block_template_render_enqueues_paypal_script(): void {
		$this->mock_jetpack_blog_id( 12345 );
		$this->register_paypal_block_handle();
		$this->sut->register();
		$previous_enqueue_state = $this->set_cart_block_enqueue_state( false );

		try {
			do_blocks( '<!-- wp:woocommerce/cart --><div class="wp-block-woocommerce-cart"></div><!-- /wp:woocommerce/cart -->' );

			$this->assertTrue( wp_script_is( 'wc-fraud-protection-blackbox-init', 'enqueued' ) );
			$this->assertTrue( wp_script_is( 'wc-fraud-protection-paypal-express', 'enqueued' ) );
		} finally {
			$this->set_cart_block_enqueue_state( $previous_enqueue_state );
		}
	}

	/**
	 * @testdox A Cart block without the PayPal block integration queues no fraud scripts.
	 */
	public function test_cart_block_template_render_without_paypal_skips_scripts(): void {
		$this->mock_jetpack_blog_id( 12345 );
		$this->sut->register();
		$previous_enqueue_state = $this->set_cart_block_enqueue_state( false );

		try {
			do_blocks( '<!-- wp:woocommerce/cart --><div class="wp-block-woocommerce-cart"></div><!-- /wp:woocommerce/cart -->' );

			$this->assertFalse( wp_script_is( 'wc-fraud-protection-blackbox-init', 'enqueued' ) );
			$this->assertFalse( wp_script_is( 'wc-fraud-protection-paypal-express', 'enqueued' ) );
		} finally {
			$this->set_cart_block_enqueue_state( $previous_enqueue_state );
		}
	}

	/**
	 * PayPal button actions that prove the wrapper rendered.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function paypal_button_render_hook_provider(): array {
		return array(
			'single product' => array( 'woocommerce_paypal_payments_single_product_button_render' ),
			'cart'           => array( 'woocommerce_paypal_payments_cart_button_render' ),
			'checkout'       => array( 'woocommerce_paypal_payments_checkout_button_render' ),
			'pay for order'  => array( 'woocommerce_paypal_payments_payorder_button_render' ),
			'mini-cart'      => array( 'woocommerce_paypal_payments_minicart_button_render' ),
		);
	}

	/**
	 * @testdox The block follower uses PayPal's registered block integration as its early signal.
	 */
	public function test_block_follower_enqueues_when_paypal_block_integration_is_registered(): void {
		$this->register_paypal_block_handle();
		$handler = $this->createMock( BlackboxScriptHandler::class );
		$handler->expects( $this->once() )->method( 'request_scripts' )->willReturn( true );
		$sut = $this->make_compat_with_script_handler( $handler );

		$sut->enqueue_paypal_block_script_if_registered();

		$this->assertTrue( wp_script_is( 'wc-fraud-protection-paypal-express', 'enqueued' ) );
	}

	/**
	 * @testdox The block follower ignores another PPCP gateway without the PayPal block integration.
	 */
	public function test_block_follower_skips_other_ppcp_gateway_without_paypal_block_integration(): void {
		$gateway     = $this->getMockBuilder( \WC_Payment_Gateway::class )
			->disableOriginalConstructor()
			->getMock();
		$gateway->id = 'ppcp-applepay';
		$add_gateway = function ( array $gateways ) use ( $gateway ): array {
			$gateways[ $gateway->id ] = $gateway;
			return $gateways;
		};
		$handler = $this->createMock( BlackboxScriptHandler::class );
		$handler->expects( $this->never() )->method( 'request_scripts' );
		$sut = $this->make_compat_with_script_handler( $handler );
		add_filter( 'woocommerce_available_payment_gateways', $add_gateway );

		try {
			$sut->enqueue_paypal_block_script_if_registered();
		} finally {
			remove_filter( 'woocommerce_available_payment_gateways', $add_gateway );
		}

		$this->assertFalse( wp_script_is( 'wc-fraud-protection-paypal-express', 'enqueued' ) );
	}

	/**
	 * @testdox The standard-form follower prepares for a PayPal button that can become eligible later.
	 */
	public function test_standard_form_follower_uses_enqueued_smart_button_without_gateway_snapshot(): void {
		$this->register_and_enqueue_paypal_smart_button();
		$handler = $this->createMock( BlackboxScriptHandler::class );
		$handler->expects( $this->once() )->method( 'request_scripts' )->willReturn( true );
		$sut = $this->make_compat_with_script_handler( $handler );

		$sut->enqueue_paypal_script_if_smart_button_enqueued();

		$this->assertTrue( wp_script_is( 'wc-fraud-protection-paypal-express', 'enqueued' ) );
	}

	/**
	 * @testdox The standard-form follower requires PayPal's smart-button handle to be registered and enqueued.
	 *
	 * @dataProvider unavailable_smart_button_provider
	 *
	 * @param bool $registered Whether the handle is registered.
	 * @param bool $enqueued   Whether the handle is enqueued.
	 */
	public function test_standard_form_follower_requires_registered_and_enqueued_smart_button( bool $registered, bool $enqueued ): void {
		$this->configure_paypal_smart_button( $registered, $enqueued );
		$handler = $this->createMock( BlackboxScriptHandler::class );
		$handler->expects( $this->never() )->method( 'request_scripts' );
		$sut = $this->make_compat_with_script_handler( $handler );

		$sut->enqueue_paypal_script_if_smart_button_enqueued();

		$this->assertFalse( wp_script_is( 'wc-fraud-protection-paypal-express', 'enqueued' ) );
	}

	/**
	 * Unavailable smart-button handle states.
	 *
	 * @return array<string, array{bool, bool}>
	 */
	public function unavailable_smart_button_provider(): array {
		return array(
			'not registered' => array( false, true ),
			'not enqueued'   => array( true, false ),
		);
	}

	/**
	 * @testdox The block follower skips Checkout endpoint fallbacks.
	 *
	 * @dataProvider checkout_endpoint_provider
	 *
	 * @param string $endpoint Endpoint query variable.
	 */
	public function test_paypal_block_follower_skips_checkout_endpoint( string $endpoint ): void {
		global $wp;

		$handler = $this->createMock( BlackboxScriptHandler::class );
		$handler->expects( $this->never() )->method( 'request_scripts' );
		$sut = $this->make_compat_with_script_handler( $handler );
		$wp->query_vars[ $endpoint ] = '123';

		$sut->enqueue_paypal_block_script_if_registered();

		$this->assertFalse( wp_script_is( 'wc-fraud-protection-paypal-express', 'enqueued' ) );
	}

	/**
	 * @testdox The mini-cart follower loads when PayPal enables and enqueues its fragment-aware script.
	 */
	public function test_mini_cart_follower_enqueues_for_paypal_fragment_script(): void {
		$this->configure_paypal_mini_cart( true, true, true );
		$handler = $this->createMock( BlackboxScriptHandler::class );
		$handler->expects( $this->once() )->method( 'request_scripts' )->willReturn( true );
		$sut = $this->make_compat_with_script_handler( $handler );

		$sut->enqueue_paypal_mini_cart_script_if_enabled();

		$this->assertTrue( wp_script_is( 'wc-fraud-protection-paypal-express', 'enqueued' ) );
	}

	/**
	 * @testdox A visible classic cart widget prepares the page for PayPal fragments without changing visibility.
	 */
	public function test_visible_cart_widget_prepares_paypal_fragments(): void {
		$this->configure_paypal_mini_cart( true, true, true );
		$handler = $this->createMock( BlackboxScriptHandler::class );
		$handler->expects( $this->once() )->method( 'request_scripts' )->willReturn( true );
		$sut = $this->make_compat_with_script_handler( $handler );

		$this->assertFalse( $sut->enqueue_paypal_script_for_visible_mini_cart_widget( false ) );
		$this->assertTrue( wp_script_is( 'wc-fraud-protection-paypal-express', 'enqueued' ) );
	}

	/**
	 * @testdox A hidden classic cart widget stays hidden and requests no scripts.
	 */
	public function test_hidden_cart_widget_requests_nothing(): void {
		$handler = $this->createMock( BlackboxScriptHandler::class );
		$handler->expects( $this->never() )->method( 'request_scripts' );
		$sut = $this->make_compat_with_script_handler( $handler );

		$this->assertTrue( $sut->enqueue_paypal_script_for_visible_mini_cart_widget( true ) );
		$this->assertFalse( wp_script_is( 'wc-fraud-protection-paypal-express', 'enqueued' ) );
	}

	/**
	 * @testdox Malformed cart widget visibility values pass through without requesting scripts.
	 *
	 * @dataProvider malformed_cart_widget_visibility_provider
	 *
	 * @param mixed $value Malformed filter value.
	 */
	public function test_malformed_cart_widget_visibility_passes_through_without_scripts( $value ): void {
		$handler = $this->createMock( BlackboxScriptHandler::class );
		$handler->expects( $this->never() )->method( 'request_scripts' );
		$sut = $this->make_compat_with_script_handler( $handler );
		$result = $sut->enqueue_paypal_script_for_visible_mini_cart_widget( $value );

		$this->assertSame( $value, $result );
		$this->assertFalse( wp_script_is( 'wc-fraud-protection-paypal-express', 'enqueued' ) );
	}

	/**
	 * Malformed cart widget visibility values.
	 *
	 * @return array<string, array{mixed}>
	 */
	public function malformed_cart_widget_visibility_provider(): array {
		return array(
			'integer' => array( 0 ),
			'array'  => array( array() ),
			'object' => array( new \stdClass() ),
		);
	}

	/**
	 * @testdox An empty real mini-cart requests one idempotent script stack before later PayPal fragments.
	 */
	public function test_empty_mini_cart_render_prepares_for_later_paypal_fragment(): void {
		WC()->cart->empty_cart();
		$this->mock_jetpack_blog_id( 12345 );
		$this->configure_paypal_mini_cart( true, true, true );
		$this->sut->register();

		ob_start();
		woocommerce_mini_cart();
		ob_end_clean();
		do_action( 'woocommerce_paypal_payments_minicart_button_render' );

		$this->assertSame( 1, array_count_values( wp_scripts()->queue )['wc-fraud-protection-blackbox'] );
		$this->assertSame( 1, array_count_values( wp_scripts()->queue )['wc-fraud-protection-blackbox-init'] );
		$this->assertSame( 1, array_count_values( wp_scripts()->queue )['wc-fraud-protection-paypal-express'] );
	}

	/**
	 * @testdox The mini-cart follower skips when its PayPal location or executable script is absent.
	 *
	 * @dataProvider unavailable_mini_cart_provider
	 *
	 * @param bool $location_enabled Whether the PayPal mini-cart setting is enabled.
	 * @param bool $script_registered Whether the PayPal smart-button script is registered.
	 * @param bool $script_enqueued   Whether the PayPal smart-button script is enqueued.
	 */
	public function test_mini_cart_follower_requires_paypal_location_and_script( bool $location_enabled, bool $script_registered, bool $script_enqueued ): void {
		$this->configure_paypal_mini_cart( $location_enabled, $script_registered, $script_enqueued );
		$handler = $this->createMock( BlackboxScriptHandler::class );
		$handler->expects( $this->never() )->method( 'request_scripts' );
		$sut = $this->make_compat_with_script_handler( $handler );

		$sut->enqueue_paypal_mini_cart_script_if_enabled();

		$this->assertFalse( wp_script_is( 'wc-fraud-protection-paypal-express', 'enqueued' ) );
	}

	/**
	 * Unavailable classic mini-cart cases.
	 *
	 * @return array<string, array{bool, bool, bool}>
	 */
	public function unavailable_mini_cart_provider(): array {
		return array(
			'location disabled'    => array( false, true, true ),
			'script not registered' => array( true, false, true ),
			'script not enqueued'   => array( true, true, false ),
		);
	}

	/**
	 * @testdox The mini-cart follower supports the legacy location setting when current styling is absent.
	 */
	public function test_mini_cart_follower_supports_legacy_location_setting(): void {
		update_option( 'woocommerce-ppcp-version', '4.0.0' );
		delete_option( 'woocommerce-ppcp-data-styling' );
		update_option( 'woocommerce-ppcp-settings', array( 'smart_button_locations' => array( 'mini-cart' ) ) );
		$this->register_and_enqueue_paypal_smart_button();
		$handler = $this->createMock( BlackboxScriptHandler::class );
		$handler->expects( $this->once() )->method( 'request_scripts' )->willReturn( true );
		$sut = $this->make_compat_with_script_handler( $handler );

		$sut->enqueue_paypal_mini_cart_script_if_enabled();

		$this->assertTrue( wp_script_is( 'wc-fraud-protection-paypal-express', 'enqueued' ) );
	}

	/**
	 * @testdox Current disabled mini-cart styling overrides an enabled legacy location.
	 */
	public function test_mini_cart_current_styling_overrides_legacy_location(): void {
		$this->configure_paypal_mini_cart( false, true, true );
		update_option( 'woocommerce-ppcp-settings', array( 'smart_button_locations' => array( 'mini-cart' ) ) );
		$handler = $this->createMock( BlackboxScriptHandler::class );
		$handler->expects( $this->never() )->method( 'request_scripts' );
		$sut = $this->make_compat_with_script_handler( $handler );

		$sut->enqueue_paypal_mini_cart_script_if_enabled();

		$this->assertFalse( wp_script_is( 'wc-fraud-protection-paypal-express', 'enqueued' ) );
	}

	/**
	 * @testdox PayPal Payments 3.4 uses its enabled legacy mini-cart location over disabled styling data.
	 */
	public function test_paypal_34_uses_enabled_legacy_mini_cart_location(): void {
		$this->configure_paypal_mini_cart( false, true, true );
		update_option( 'woocommerce-ppcp-version', '3.4.1' );
		update_option( 'woocommerce-ppcp-settings', array( 'smart_button_locations' => array( 'mini-cart' ) ) );
		$handler = $this->createMock( BlackboxScriptHandler::class );
		$handler->expects( $this->once() )->method( 'request_scripts' )->willReturn( true );
		$sut = $this->make_compat_with_script_handler( $handler );

		$sut->enqueue_paypal_mini_cart_script_if_enabled();

		$this->assertTrue( wp_script_is( 'wc-fraud-protection-paypal-express', 'enqueued' ) );
	}

	/**
	 * @testdox PayPal Payments 3.4 ignores enabled styling data when its legacy mini-cart location is disabled.
	 */
	public function test_paypal_34_uses_disabled_legacy_mini_cart_location(): void {
		$this->configure_paypal_mini_cart( true, true, true );
		update_option( 'woocommerce-ppcp-version', '3.4.1' );
		update_option( 'woocommerce-ppcp-settings', array( 'smart_button_locations' => array() ) );
		$handler = $this->createMock( BlackboxScriptHandler::class );
		$handler->expects( $this->never() )->method( 'request_scripts' );
		$sut = $this->make_compat_with_script_handler( $handler );

		$sut->enqueue_paypal_mini_cart_script_if_enabled();

		$this->assertFalse( wp_script_is( 'wc-fraud-protection-paypal-express', 'enqueued' ) );
	}

	/**
	 * Checkout endpoint cases.
	 *
	 * @return array<string, array{string}>
	 */
	public function checkout_endpoint_provider(): array {
		return array(
			'order pay'      => array( 'order-pay' ),
			'order received' => array( 'order-received' ),
		);
	}

	/**
	 * @testdox A named PayPal render action still skips the interceptor when shared scripts are unavailable.
	 */
	public function test_named_paypal_render_skips_when_shared_scripts_are_unavailable(): void {
		$handler = $this->createMock( BlackboxScriptHandler::class );
		$handler->expects( $this->once() )->method( 'request_scripts' )->willReturn( false );
		$sut = $this->make_compat_with_script_handler( $handler );

		$sut->enqueue_paypal_script();

		$this->assertFalse( wp_script_is( 'wc-fraud-protection-paypal-express', 'enqueued' ) );
	}

	/**
	 * @testdox enqueue_paypal_script() uses the injected handler before it enqueues the interceptor.
	 */
	public function test_enqueue_paypal_script_uses_injected_handler(): void {
		$handler = $this->createMock( BlackboxScriptHandler::class );
		$handler->expects( $this->once() )->method( 'request_scripts' )->willReturn( true );
		$sut = $this->make_compat_with_script_handler( $handler );

		$sut->enqueue_paypal_script();

		$this->assertTrue( wp_script_is( 'wc-fraud-protection-paypal-express', 'enqueued' ) );
		$script = wp_scripts()->query( 'wc-fraud-protection-paypal-express', 'registered' );
		$this->assertNotFalse( $script );
		$this->assertSame(
			plugins_url( 'assets/js/paypal-express.js', WC_FRAUD_PROTECTION_PLUGIN_FILE ),
			$script->src
		);
		$this->assertContains( 'wc-fraud-protection-blackbox-init', $script->deps );
	}

	/**
	 * Build a PayPal compatibility layer with a controlled script handler.
	 *
	 * @param BlackboxScriptHandler $handler Script handler.
	 * @return PayPalCompat
	 */
	private function make_compat_with_script_handler( BlackboxScriptHandler $handler ): PayPalCompat {
		$sut = new PayPalCompat();
		$sut->init( $this->session_verifier, $this->blocked_session_message, $this->session_id_normalizer, $handler );

		return $sut;
	}

	/**
	 * Configure PayPal's current mini-cart setting and smart-button handle.
	 *
	 * @param bool $location_enabled Whether the mini-cart location is enabled.
	 * @param bool $script_registered Whether the smart-button handle is registered.
	 * @param bool $script_enqueued   Whether the smart-button handle is enqueued.
	 */
	private function configure_paypal_mini_cart( bool $location_enabled, bool $script_registered, bool $script_enqueued ): void {
		update_option( 'woocommerce-ppcp-version', '4.0.0' );
		$mini_cart          = new \stdClass();
		$mini_cart->enabled = $location_enabled;
		update_option( 'woocommerce-ppcp-data-styling', array( 'mini_cart' => $mini_cart ) );

		if ( $script_registered ) {
			wp_register_script( 'ppcp-smart-button', 'https://example.com/paypal-button.js', array(), '1.0', true );
			$this->touched_smart_button_handle = true;
		}

		if ( $script_enqueued ) {
			wp_enqueue_script( 'ppcp-smart-button' );
			$this->touched_smart_button_handle = true;
		}
	}

	/**
	 * Register and enqueue PayPal's smart-button handle.
	 */
	private function register_and_enqueue_paypal_smart_button(): void {
		$this->configure_paypal_smart_button( true, true );
	}

	/**
	 * Configure PayPal's smart-button handle state.
	 *
	 * @param bool $registered Whether the handle is registered.
	 * @param bool $enqueued   Whether the handle is enqueued.
	 */
	private function configure_paypal_smart_button( bool $registered, bool $enqueued ): void {
		if ( $registered ) {
			wp_register_script( 'ppcp-smart-button', 'https://example.com/paypal-button.js', array(), '1.0', true );
		}

		if ( $enqueued ) {
			wp_enqueue_script( 'ppcp-smart-button' );
		}

		$this->touched_smart_button_handle = $registered || $enqueued;
	}

	/**
	 * Register PayPal's Checkout block integration handle.
	 */
	private function register_paypal_block_handle(): void {
		wp_register_script( 'ppcp-checkout-block', 'https://example.com/paypal-block.js', array(), '1.0', true );
		$this->registered_block_handle = true;
	}

	/**
	 * Set WooCommerce's shared Cart block enqueue state.
	 *
	 * @param bool $state New enqueue state.
	 * @return bool Previous enqueue state.
	 */
	private function set_cart_block_enqueue_state( bool $state ): bool {
		$block_type = \WP_Block_Type_Registry::get_instance()->get_registered( 'woocommerce/cart' );
		$this->assertInstanceOf( \WP_Block_Type::class, $block_type );
		$this->assertIsCallable( $block_type->render_callback );
		$this->assertIsArray( $block_type->render_callback );
		$this->assertInstanceOf( AbstractBlock::class, $block_type->render_callback[0] );

		$property = new \ReflectionProperty( AbstractBlock::class, 'enqueued_assets' );
		$property->setAccessible( true );
		$previous = (bool) $property->getValue( $block_type->render_callback[0] );
		$property->setValue( $block_type->render_callback[0], $state );

		return $previous;
	}

	/**
	 * Remember a PayPal option so the test can restore it.
	 *
	 * @param string $option_name Option name.
	 */
	private function remember_paypal_option( string $option_name ): void {
		$missing = new \stdClass();
		$value   = get_option( $option_name, $missing );

		$this->original_paypal_options[ $option_name ] = array(
			'exists' => $missing !== $value,
			'value'  => $value,
		);
	}

	/**
	 * Restore PayPal options changed by a test.
	 */
	private function restore_paypal_options(): void {
		foreach ( $this->original_paypal_options as $option_name => $original ) {
			if ( $original['exists'] ) {
				update_option( $option_name, $original['value'] );
			} else {
				delete_option( $option_name );
			}
		}
	}

	/*
	|--------------------------------------------------------------------------
	| supply_decision_for_paypal_express() Tests
	|--------------------------------------------------------------------------
	*/

	/**
	 * Drive the filter callback the way SessionVerifier does.
	 *
	 * Seeds the call with `false`, the filter's default. A deferral passes that
	 * default through untouched, so it comes back as `false`.
	 *
	 * @param string $source         Source identifier.
	 * @param string $payment_method Gateway id on the request.
	 * @param string $session_id     Blackbox session ID on the request.
	 * @return mixed A FraudDecision when answered, false when deferred.
	 */
	private function ask( string $source, string $payment_method, string $session_id ) {
		$supplied_decision = $this->sut->supply_decision_for_paypal_express(
			false,
			$source,
			array( 'payment_method' => $payment_method ),
			$session_id
		);

		return $supplied_decision instanceof SuppliedDecision ? $supplied_decision->decision : false;
	}

	/**
	 * Assert that a deferral preserves the exact incoming decision object.
	 *
	 * @param string $source       Verification source.
	 * @param array  $request_data Request data.
	 * @param string $session_id   Submitted session ID.
	 */
	private function assert_incoming_decision_is_preserved( string $source, array $request_data, string $session_id ): void {
		$incoming = new SuppliedDecision( FraudDecision::Block );

		$this->assertSame(
			$incoming,
			$this->sut->supply_decision_for_paypal_express( $incoming, $source, $request_data, $session_id )
		);
	}

	/**
	 * Run a create-order verification with the given decision.
	 *
	 * @param string        $session_id          The session ID the request presents.
	 * @param FraudDecision $decision            What the verifier returns.
	 * @param ?string       $resolved_session_id The session ID the verifier resolves, when it differs.
	 */
	private function score_create_order( string $session_id, FraudDecision $decision, ?string $resolved_session_id = null ): void {
		$this->session_verifier
			->method( 'verify_session' )
			->willReturn( $decision );

		// A completed verification exposes the session ID it resolved; the
		// record is keyed by that, not by what the request presented.
		$this->session_verifier
			->method( 'last_verified_session_id' )
			->willReturn( $resolved_session_id ?? $session_id );

		if ( FraudDecision::Block !== $decision ) {
			$this->sut->verify_and_block_create_order(
				array( SessionVerifier::SESSION_ID_FIELD => $session_id )
			);
			return;
		}

		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter(
			'wp_die_ajax_handler',
			function () {
				return function () {
					throw new \WPDieException();
				};
			}
		);

		try {
			$this->sut->verify_and_block_create_order(
				array( SessionVerifier::SESSION_ID_FIELD => $session_id )
			);
			$this->fail( 'Expected the block response to terminate the request.' );
		} catch ( \WPDieException $e ) {
			unset( $e );
		}
	}

	/**
	 * @testdox An approved order alone no longer answers for any gateway.
	 *
	 * Deliberate 0.1.6 behavior change, not a regression: this route used to
	 * answer whenever any order sat in PayPal's session slot, whatever it
	 * was and whoever put it there. It now answers only for the order the
	 * recorded verification minted; with nothing recorded, every request
	 * defers to a real verify.
	 */
	public function test_supply_defers_for_an_approved_order_nothing_here_scored(): void {
		WC()->session->set( 'ppcp', array( 'order' => new FakePayPalOrder( 'PP-FOREIGN' ) ) );

		$gateways = array( 'ppcp-gateway', 'ppcp-credit-card-gateway', 'ppcp-applepay', 'ppcp-googlepay', 'ppcp-axo-gateway' );

		foreach ( $gateways as $gateway ) {
			$this->assertFalse(
				$this->ask( 'blocks_checkout', $gateway, 'some-session-id' ),
				"Expected a deferral for gateway: $gateway"
			);
		}
	}

	/**
	 * @testdox The ppc-create-order query parameter alone answers for nothing.
	 *
	 * The query string is supplied by whoever made the request. A request that
	 * merely says it is ppc-create-order must never be enough to omit verification.
	 */
	public function test_supply_defers_on_the_create_order_query_parameter(): void {
		$_GET['wc-ajax'] = 'ppc-create-order';

		$this->assertFalse(
			$this->ask( 'shortcode_checkout', 'ppcp-gateway', 'some-session-id' ),
			'A request parameter must never be enough to omit verification entirely.'
		);
	}

	/**
	 * @testdox A record under the retired pre-0.1.6 session key is not read.
	 *
	 * Records written by earlier versions are orphaned by the key rename, not
	 * migrated: they age out with their WC session. A shopper updating
	 * mid-checkout gets one extra real verification — never a skip.
	 */
	public function test_supply_ignores_a_record_under_the_retired_session_key(): void {
		WC()->session->set( '_fraud_protection_paypal_verified_session_id', 'test-session-abc' );

		$this->assertFalse( $this->ask( 'blocks_checkout', 'ppcp-credit-card-gateway', 'test-session-abc' ) );
	}

	/**
	 * @testdox Two empty session IDs are not a match.
	 */
	public function test_supply_defers_when_both_session_ids_are_empty(): void {
		$this->set_verification_record( session_id: '', order_id: 'PP-123' );

		$this->assertFalse( $this->ask( 'blocks_checkout', 'ppcp-gateway', '' ) );
	}

	/**
	 * @testdox A session ID that does not match the recorded one is not answered for.
	 */
	public function test_supply_defers_when_session_id_does_not_match(): void {
		$this->set_verification_record( session_id: 'old-session', order_id: 'PP-123' );

		$this->assertFalse( $this->ask( 'blocks_checkout', 'ppcp-ideal', 'new-session' ) );
	}

	/**
	 * @testdox A ppcp-* flow with nothing recorded is not answered for.
	 */
	public function test_supply_defers_for_paypal_without_anything_recorded(): void {
		$this->assertFalse( $this->ask( 'blocks_checkout', 'ppcp-gateway', 'some-session-id' ) );
	}

	/**
	 * @testdox A record this code could not have written is not answered from.
	 *
	 * Only the shape update_verification_record() writes counts as a
	 * record. A matching session ID whose decision no verification produced
	 * must be verified for real, not served a normalized allow.
	 */
	public function test_supply_defers_when_the_record_is_malformed(): void {
		WC()->session->set(
			'_fraud_protection_paypal_verification',
			array(
				'session_id'  => 'some-session-id',
				'stand_downs' => 0,
				'decision'    => 'block',
			)
		);

		$this->assert_incoming_decision_is_preserved(
			'blocks_checkout',
			array( 'payment_method' => 'ppcp-gateway' ),
			'some-session-id'
		);
	}

	/**
	 * @testdox A non-PayPal gateway is never answered for, even with an approved order in session.
	 */
	public function test_supply_defers_for_non_paypal_gateway(): void {
		WC()->session->set( 'ppcp', array( 'order' => new \stdClass() ) );

		$this->assertFalse( $this->ask( 'blocks_checkout', 'stripe', 'some-session-id' ) );
	}

	/**
	 * @testdox A non-string payment method defers and preserves the incoming value.
	 */
	public function test_supply_defers_for_non_string_payment_method(): void {
		$supplied_decision = new SuppliedDecision( FraudDecision::Block );

		$this->assertSame(
			$supplied_decision,
			$this->sut->supply_decision_for_paypal_express(
				$supplied_decision,
				'blocks_checkout',
				array( 'payment_method' => array( 'ppcp-gateway' ) ),
				'some-session-id'
			)
		);
	}

	/**
	 * @testdox This class does not answer for its own verification source.
	 */
	public function test_supply_defers_for_own_source(): void {
		WC()->session->set( 'ppcp', array( 'order' => new \stdClass() ) );

		$this->assertFalse( $this->ask( 'paypal_express_order_creation', 'ppcp-gateway', 'some-session-id' ) );
	}

	/**
	 * @testdox Create-order verification does not answer for an unidentified later request.
	 */
	public function test_supply_defers_for_an_unidentified_request_after_create_order_verification(): void {
		$this->score_create_order( 'scored-session', FraudDecision::Allow );

		$this->assertFalse( $this->ask( 'shortcode_checkout', '', '' ) );
	}

	/**
	 * @testdox An unrelated saved Allow does not override an earlier Block.
	 */
	public function test_supply_does_not_override_an_earlier_block_with_an_unrelated_saved_allow(): void {
		$this->score_create_order( 'scored-session', FraudDecision::Allow );
		$supplied_decision = new SuppliedDecision( FraudDecision::Block );

		$this->assertSame(
			$supplied_decision,
			$this->sut->supply_decision_for_paypal_express(
				$supplied_decision,
				'shortcode_checkout',
				array( 'payment_method' => 'ppcp-gateway' ),
				'different-session'
			)
		);
	}

	/**
	 * @testdox An earlier consumer's decision is passed through when this class defers.
	 *
	 * Every deferral path returns the value received, so a decision put in the
	 * chain by an earlier consumer survives a request this class has nothing
	 * to say about.
	 */
	public function test_supply_passes_an_earlier_decision_through_when_it_defers(): void {
		$supplied_decision = new SuppliedDecision( FraudDecision::Block );

		$this->assertSame(
			$supplied_decision,
			$this->sut->supply_decision_for_paypal_express(
				$supplied_decision,
				'blocks_checkout',
				array( 'payment_method' => 'stripe' ),
				'some-session-id'
			)
		);
	}

	/**
	 * @testdox The record answers at this callback's priority, over an earlier consumer's decision.
	 *
	 * Standard filter arbitration: this callback answers from its record at its
	 * own priority, whatever an earlier consumer returned. A consumer that wants
	 * the last word registers with a later priority.
	 */
	public function test_supply_answers_from_its_record_over_an_earlier_decision(): void {
		$this->set_verification_record( session_id: 'some-session-id', order_id: 'PP-123' );
		WC()->session->set( 'ppcp', array( 'order' => new FakePayPalOrder( 'PP-123' ) ) );

		$supplied_decision = new SuppliedDecision( FraudDecision::Block );

		$returned = $this->sut->supply_decision_for_paypal_express(
			$supplied_decision,
			'blocks_checkout',
			array( 'payment_method' => 'ppcp-gateway' ),
			'some-session-id'
		);

		$this->assertInstanceOf( SuppliedDecision::class, $returned );
		$this->assertSame( FraudDecision::Allow, $returned->decision );
	}

	/** @testdox A final record read failure preserves the incoming decision and retires stored state. */
	public function test_supply_read_failure_preserves_incoming_decision_and_retires(): void {
		$request          = $this->create_protected_paypal_request_record( 'create' );
		$original_session = WC()->session;
		$session          = new class( $original_session ) {
			/** @var mixed */
			private $session;

			public bool $retired = false;

			public function __construct( $session ) { // phpcs:ignore
				$this->session = $session;
			}

			public function get( $key, $default = null ) { // phpcs:ignore
				throw new \RuntimeException( 'session read unavailable' );
			}

			public function set( $key, $value = null ): void { // phpcs:ignore
				$this->retired = null === $value;
				$this->session->set( $key, $value );
			}
		};
		WC()->session     = $session;
		$incoming         = new SuppliedDecision( FraudDecision::Block );
		$returned         = null;

		try {
			$returned = $this->sut->supply_decision_for_paypal_express( $incoming, 'blocks_checkout', $request, 'response-session' );
		} finally {
			WC()->session = $original_session;
		}

		$this->assertSame( $incoming, $returned );
		$this->assertTrue( $session->retired );
		$this->assertNull( $original_session->get( '_fraud_protection_paypal_verification' ) );
		$this->assertLogged(
			'warning',
			'Reading or consuming the PayPal request verification record failed',
			array(
				'event_source'      => 'blocks_checkout',
				'exception_class'   => 'RuntimeException',
				'exception_message' => 'session read unavailable',
			)
		);
	}

	/** @testdox A final used-state write failure preserves the incoming decision and retires stored state. */
	public function test_supply_write_failure_preserves_incoming_decision_and_retires(): void {
		$request          = $this->create_protected_paypal_request_record( 'create' );
		$original_session = WC()->session;
		$session          = new class( $original_session ) {
			/** @var mixed */
			private $session;

			private bool $fail_next_write = true;

			public bool $retired = false;

			public function __construct( $session ) { // phpcs:ignore
				$this->session = $session;
			}

			public function get( $key, $default = null ) { // phpcs:ignore
				return $this->session->get( $key, $default );
			}

			public function set( $key, $value = null ): void { // phpcs:ignore
				if ( $this->fail_next_write ) {
					$this->fail_next_write = false;
					throw new \RuntimeException( 'session write unavailable' );
				}

				$this->retired = null === $value;
				$this->session->set( $key, $value );
			}
		};
		WC()->session     = $session;
		$incoming         = new SuppliedDecision( FraudDecision::Block );
		$returned         = null;

		try {
			$returned = $this->sut->supply_decision_for_paypal_express( $incoming, 'blocks_checkout', $request, 'response-session' );
		} finally {
			WC()->session = $original_session;
		}

		$this->assertSame( $incoming, $returned );
		$this->assertTrue( $session->retired );
		$this->assertNull( $original_session->get( '_fraud_protection_paypal_verification' ) );
		$this->assertLogged(
			'warning',
			'Reading or consuming the PayPal request verification record failed',
			array(
				'event_source'      => 'blocks_checkout',
				'session_id'        => 'response-session',
				'exception_class'   => 'RuntimeException',
				'exception_message' => 'session write unavailable',
			)
		);
	}

	/**
	 * @testdox A malformed value in the chain fails loudly instead of being silently ignored.
	 *
	 * The declared parameter type is the warning: an earlier consumer returning
	 * something that is neither a SuppliedDecision nor the default raises a
	 * TypeError, which SessionVerifier logs as a warning and answers with a
	 * real verify.
	 */
	public function test_supply_rejects_a_malformed_earlier_value_loudly(): void {
		$this->expectException( \TypeError::class );

		$this->sut->supply_decision_for_paypal_express(
			'allow',
			'blocks_checkout',
			array( 'payment_method' => 'ppcp-gateway' ),
			'some-session-id'
		);
	}

	/*
	|--------------------------------------------------------------------------
	| Shared supplied use
	|--------------------------------------------------------------------------
	*/

	/**
	 * @testdox The record is keyed by the session ID the verification resolved, not the one presented.
	 */
	public function test_verify_keys_the_record_by_the_resolved_session_id(): void {
		$this->score_create_order( 'presented-session', FraudDecision::Allow, 'resolved-session' );
		$this->sut->bind_created_order_to_verification( new FakePayPalOrder( 'PP-123' ) );
		WC()->session->set( 'ppcp', array( 'order' => new FakePayPalOrder( 'PP-123' ) ) );

		$record = WC()->session->get( '_fraud_protection_paypal_verification' );

		$this->assertIsArray( $record );
		$this->assertSame( 'resolved-session', $record['session_id'] );

		$this->assertSame(
			FraudDecision::Allow,
			$this->ask( 'blocks_checkout', 'ppcp-credit-card-gateway', 'resolved-session' )
		);

		$this->score_create_order( 'presented-session', FraudDecision::Allow, 'resolved-session' );
		$this->sut->bind_created_order_to_verification( new FakePayPalOrder( 'PP-123' ) );
		$this->assertFalse(
			$this->ask( 'blocks_checkout', 'ppcp-credit-card-gateway', 'presented-session' ),
			'The ID the request presented is not the one that was scored; it is verified for real.'
		);
	}

	/**
	 * @testdox Nothing is recorded when the call completed no verification.
	 */
	public function test_verify_records_nothing_when_no_verification_completed(): void {
		WC()->session->set(
			'_fraud_protection_paypal_verification',
			array(
				'session_id'  => 'prior-session',
				'stand_downs' => 0,
				'decision'    => FraudDecision::Block,
			)
		);

		$this->score_create_order( 'presented-session', FraudDecision::Allow, '' );

		$this->assertNull( WC()->session->get( '_fraud_protection_paypal_verification' ) );
	}

	/**
	 * @testdox Exact-session replay normalizes a stored session ID written before the byte limit.
	 */
	public function test_exact_session_replay_normalizes_legacy_stored_session_id(): void {
		$normalized = str_repeat( 'a', 255 );
		$stored     = $normalized . 'b';

		$this->score_create_order( $normalized, FraudDecision::Allow );
		$record = WC()->session->get( '_fraud_protection_paypal_verification' );
		$this->assertIsArray( $record );
		$record['session_id'] = $stored;
		$record['decision']   = FraudDecision::Block;
		$record['order_id']   = 'PP-123';
		WC()->session->set( '_fraud_protection_paypal_verification', $record );
		WC()->session->set( 'ppcp', array( 'order' => new FakePayPalOrder( 'PP-123' ) ) );

		$this->assertSame( FraudDecision::Block, $this->ask( 'blocks_checkout', 'ppcp-gateway', $normalized ) );
		$stored_record = WC()->session->get( '_fraud_protection_paypal_verification' );
		$this->assertSame( $stored, $stored_record['session_id'] );
		$this->assertTrue( $stored_record['used'] );
	}

	/**
	 * @testdox Invalid stored session IDs do not match a normalized submitted session ID.
	 *
	 * @dataProvider invalid_stored_session_id_provider
	 *
	 * @param string $stored_session_id Stored session ID.
	 */
	public function test_invalid_stored_session_id_does_not_match_submitted_session( string $stored_session_id ): void {
		$this->score_create_order( 'scored-session', FraudDecision::Allow );
		$record = WC()->session->get( '_fraud_protection_paypal_verification' );
		$this->assertIsArray( $record );
		$record['session_id'] = $stored_session_id;
		$record['decision']   = FraudDecision::Block;
		WC()->session->set( '_fraud_protection_paypal_verification', $record );

		$this->assertFalse( $this->ask( 'blocks_checkout', 'ppcp-gateway', 'wcfp-invalid-characters' ) );
	}

	/**
	 * Invalid stored session IDs.
	 *
	 * @return array<string, array{string}>
	 */
	public function invalid_stored_session_id_provider(): array {
		return array(
			'single dot' => array( '.' ),
			'double dot' => array( '..' ),
		);
	}

	/** @testdox An invalid stored session ID does not match an empty submitted session ID. */
	public function test_invalid_stored_session_id_does_not_match_empty_submitted_session(): void {
		$this->score_create_order( 'scored-session', FraudDecision::Allow );
		$this->sut->bind_created_order_to_verification( new FakePayPalOrder( 'PP-123' ) );
		$record = WC()->session->get( '_fraud_protection_paypal_verification' );
		$this->assertIsArray( $record );
		$record['session_id'] = '.';
		WC()->session->set( '_fraud_protection_paypal_verification', $record );
		WC()->session->set( 'ppcp', array( 'order' => new FakePayPalOrder( 'PP-123' ) ) );

		$this->assert_incoming_decision_is_preserved(
			'blocks_checkout',
			array( 'payment_method' => 'ppcp-gateway' ),
			''
		);
		$this->assertNull( WC()->session->get( '_fraud_protection_paypal_verification' ) );
	}

	/*
	|--------------------------------------------------------------------------
	| Recorded decision
	|--------------------------------------------------------------------------
	*/

	/**
	 * @testdox An unbound blocked create-order does not satisfy a final request.
	 *
	 * This is WOOSUBS-1831. The blocked session used to reach a later request and
	 * be waved through, because "already handled" was expressed as "allowed".
	 */
	public function test_supply_answers_a_blocked_session_with_its_block(): void {
		$this->score_create_order( 'blocked-session', FraudDecision::Block );

		$this->assertFalse(
			$this->ask( 'shortcode_checkout', 'ppcp-gateway', 'blocked-session' ),
			'A blocked PayPal request creates no order to bind to a final request.'
		);
	}

	/**
	 * @testdox The verified-session record survives a blocked create-order.
	 *
	 * The blocked attempt is the one whose record matters most: the request after
	 * it is answered from that record, and the block is what comes back.
	 */
	public function test_verify_records_the_verified_session_even_on_block(): void {
		$this->score_create_order( 'blocked-session', FraudDecision::Block );

		$this->assertSame(
			array(
				'origin'     => 'paypal_express_order_creation',
				'session_id' => 'blocked-session',
				'decision'   => FraudDecision::Block,
				'used'       => false,
				'order_id'   => '',
				'cart_hash'  => '',
			),
			WC()->session->get( '_fraud_protection_paypal_verification' ),
			'A blocked create-order must still record the session it scored.'
		);
	}

	/**
	 * @testdox An allowed create-order hands its allow back within the attempt.
	 *
	 * The completion leg of a create-order-by-AJAX flow presents the same session
	 * ID in a later request. It is answered from the record — the allow that
	 * verification produced — rather than scored a second time, which Blackbox
	 * would score harder as a reused session.
	 */
	public function test_supply_answers_an_allowed_session_with_its_allow(): void {
		$this->score_create_order( 'clean-session', FraudDecision::Allow );
		$this->sut->bind_created_order_to_verification( new FakePayPalOrder( 'PP-123' ) );
		WC()->session->set( 'ppcp', array( 'order' => new FakePayPalOrder( 'PP-123' ) ) );

		$this->assertSame(
			FraudDecision::Allow,
			$this->ask( 'blocks_checkout', 'ppcp-credit-card-gateway', 'clean-session' )
		);
	}

	/**
	 * @testdox A recorded allow never answers for another gateway.
	 *
	 * The regression that sank the first central design: a stored ppcp allow at
	 * one amount satisfied a cod checkout at another with no verification at all.
	 * The gateway gate sits above every record read, so a non-PayPal checkout
	 * presenting the recorded session ID is verified for real — even with
	 * PayPal's approved-order slot populated.
	 */
	public function test_supply_does_not_apply_a_recorded_allow_to_another_gateway(): void {
		$this->score_create_order( 'paypal-scored-session', FraudDecision::Allow );
		$this->sut->bind_created_order_to_verification( new FakePayPalOrder( 'PP-SCORED' ) );

		// The scored order itself sits in the slot, so both the session-keyed
		// and the order-bound reads would answer; only the gateway gate stands
		// between them and this request.
		WC()->session->set( 'ppcp', array( 'order' => new FakePayPalOrder( 'PP-SCORED' ) ) );

		$this->assertFalse(
			$this->ask( 'shortcode_checkout', 'cod', 'paypal-scored-session' ),
			'A recorded allow must never answer for a non-PayPal checkout.'
		);
	}

	/**
	 * @testdox A recorded block does not answer for another gateway either; the request verifies.
	 *
	 * Same gate, other verdict. A non-PayPal checkout presenting a blocked ID
	 * gets a real verification instead of the record — the record answers only
	 * requests of the gateway whose flow produced it.
	 */
	public function test_supply_does_not_apply_a_recorded_block_to_another_gateway(): void {
		$this->score_create_order( 'blocked-session', FraudDecision::Block );

		$this->assertFalse(
			$this->ask( 'shortcode_checkout', 'cod', 'blocked-session' ),
			'The record must not answer for a non-PayPal checkout, whatever it holds.'
		);
	}

	/**
	 * @testdox A block recorded for one session does not answer for another.
	 *
	 * Guards the read side independently of the write side. The record is keyed
	 * on the session ID that was scored; a block must not become a property of
	 * the shopper, which is the sticky-block behaviour deliberately removed in
	 * #73. The expectation changed with 0.1.6's order binding — deliberately,
	 * not as a regression: this setup used to be answered with an allow by the
	 * approved-order route; unbound, it now defers to a real verify, which
	 * still proves the block did not stick.
	 */
	public function test_supply_does_not_apply_a_block_recorded_for_another_session(): void {
		$this->set_verification_record( session_id: 'a-different-blocked-session', decision: FraudDecision::Block, order_id: 'PP-FOREIGN' );
		WC()->session->set( 'ppcp', array( 'order' => new FakePayPalOrder( 'PP-FOREIGN' ) ) );

		$this->assertFalse(
			$this->ask( 'blocks_checkout', 'ppcp-credit-card-gateway', 'this-session' ),
			'Another session being blocked says nothing about this one; it verifies for real.'
		);
	}

	/*
	|--------------------------------------------------------------------------
	| Order binding
	|--------------------------------------------------------------------------
	*/

	/**
	 * @testdox The order created by a verified create-order request is bound to its record.
	 */
	public function test_verify_binds_the_created_order_to_the_record(): void {
		$this->score_create_order( 'scored-session', FraudDecision::Allow );

		$this->sut->bind_created_order_to_verification( new FakePayPalOrder( 'PP-123' ) );

		$this->assertSame(
			array(
				'origin'     => 'paypal_express_order_creation',
				'session_id' => 'scored-session',
				'decision'   => FraudDecision::Allow,
				'used'       => false,
				'order_id'   => 'PP-123',
				'cart_hash'  => '',
			),
			WC()->session->get( '_fraud_protection_paypal_verification' )
		);
	}

	/**
	 * @testdox A bound-order id() that throws leaves the record unbound and is logged.
	 *
	 * The order is foreign code's object; a throw reading its ID must not escape
	 * into ppcp's create-order request, which minted the order already and would
	 * otherwise fail the shopper's checkout. Fail open: the record keeps its
	 * empty order_id, so a later completion leg verifies for real.
	 */
	public function test_bind_fails_open_when_the_order_id_throws(): void {
		$this->score_create_order( 'scored-session', FraudDecision::Allow );

		$this->sut->bind_created_order_to_verification( new ThrowingPayPalOrder() );

		$record = WC()->session->get( '_fraud_protection_paypal_verification' );

		$this->assertIsArray( $record );
		$this->assertSame( '', $record['order_id'], 'A throwing order must leave the record unbound.' );
		$this->assertLogged(
			'warning',
			'Binding the created PayPal order threw',
			array(
				'session_id'        => 'scored-session',
				'exception_class'   => 'RuntimeException',
				'exception_message' => 'id() is unavailable',
			)
		);
	}

	/**
	 * @testdox The scored order's completion is answered with its decision.
	 */
	public function test_supply_answers_the_scored_orders_completion_with_its_decision(): void {
		$this->score_create_order( 'scored-session', FraudDecision::Allow );
		$this->sut->bind_created_order_to_verification( new FakePayPalOrder( 'PP-123' ) );

		WC()->session->set( 'ppcp', array( 'order' => new FakePayPalOrder( 'PP-123' ) ) );

		$supplied_decision = $this->sut->supply_decision_for_paypal_express(
			false,
			'blocks_checkout',
			array( 'payment_method' => 'ppcp-gateway' ),
			'scored-session'
		);

		$this->assertInstanceOf( SuppliedDecision::class, $supplied_decision );
		$this->assertSame( FraudDecision::Allow, $supplied_decision->decision );
		$this->assertSame( 'scored-session', $supplied_decision->session_id_for_order );
	}

	/**
	 * @testdox A bound approved order does not replay with an empty session ID.
	 */
	public function test_supply_answers_bound_order_with_empty_session_id(): void {
		$this->score_create_order( 'scored-session', FraudDecision::Allow );
		$this->sut->bind_created_order_to_verification( new FakePayPalOrder( 'PP-123' ) );
		WC()->session->set( 'ppcp', array( 'order' => new FakePayPalOrder( 'PP-123' ) ) );

		$this->assertFalse( $this->ask( 'blocks_checkout', 'ppcp-gateway', '' ) );
	}

	/**
	 * @testdox An approved order that is not the scored one is not answered for.
	 */
	public function test_supply_defers_when_the_approved_order_is_not_the_scored_one(): void {
		$this->score_create_order( 'scored-session', FraudDecision::Allow );
		$this->sut->bind_created_order_to_verification( new FakePayPalOrder( 'PP-123' ) );

		WC()->session->set( 'ppcp', array( 'order' => new FakePayPalOrder( 'PP-999' ) ) );

		$this->assert_incoming_decision_is_preserved(
			'blocks_checkout',
			array( 'payment_method' => 'ppcp-gateway' ),
			'scored-session'
		);
	}

	/** @testdox An explicit final order ID takes precedence over the WC PayPal session order. */
	public function test_explicit_final_order_mismatch_defers_and_retires(): void {
		$request = $this->create_protected_paypal_request_record( 'create' );
		WC()->session->set( 'ppcp', array( 'order' => new FakePayPalOrder( 'PP-123' ) ) );
		$request['payment_data']['paypal_order_id'] = 'PP-OTHER';

		$this->assert_incoming_decision_is_preserved( 'blocks_checkout', $request, 'response-session' );
		$this->assertNull( WC()->session->get( '_fraud_protection_paypal_verification' ) );
	}

	/**
	 * @testdox An unbound record does not answer for an approved order.
	 *
	 * The record is current and valid, but names no order, so the approved-order
	 * route defers.
	 */
	public function test_supply_defers_for_an_unbound_record_with_an_approved_order(): void {
		$this->set_verification_record();
		WC()->session->set( 'ppcp', array( 'order' => new FakePayPalOrder( 'PP-123' ) ) );

		$this->assertFalse( $this->ask( 'blocks_checkout', 'ppcp-gateway', 'scored-session' ) );
	}

	/**
	 * @testdox Two empty order IDs are not a match.
	 */
	public function test_supply_defers_when_neither_side_names_an_order(): void {
		$this->set_verification_record();

		$this->assert_incoming_decision_is_preserved(
			'blocks_checkout',
			array( 'payment_method' => 'ppcp-gateway' ),
			'scored-session'
		);
	}

	/**
	 * @testdox A slot order that cannot be read defers.
	 */
	public function test_supply_defers_when_the_slot_order_is_not_readable(): void {
		$this->score_create_order( 'scored-session', FraudDecision::Allow );
		$this->sut->bind_created_order_to_verification( new FakePayPalOrder( 'PP-123' ) );

		WC()->session->set( 'ppcp', array( 'order' => new \stdClass() ) );

		$this->assertFalse( $this->ask( 'blocks_checkout', 'ppcp-gateway', 'scored-session' ) );
	}

	/**
	 * @testdox An order created without a verification in this request binds nothing.
	 *
	 * The shape of a server-side order creation — a subscription renewal, for
	 * instance: PayPal's order-created hook fires, but no create-order
	 * verification ran in the request, so there is nothing to bind to.
	 */
	public function test_bind_appends_nothing_without_a_verification_in_this_request(): void {
		$this->set_verification_record();

		$this->sut->bind_created_order_to_verification( new FakePayPalOrder( 'PP-123' ) );

		$record = WC()->session->get( '_fraud_protection_paypal_verification' );

		$this->assertIsArray( $record );
		$this->assertSame( '', $record['order_id'], 'A request that verified nothing must bind nothing.' );
	}

	/**
	 * @testdox A blocked create-order binds nothing.
	 */
	public function test_bind_appends_nothing_on_a_blocked_create_order(): void {
		$this->score_create_order( 'blocked-session', FraudDecision::Block );

		$this->sut->bind_created_order_to_verification( new FakePayPalOrder( 'PP-123' ) );

		$record = WC()->session->get( '_fraud_protection_paypal_verification' );

		$this->assertIsArray( $record );
		$this->assertSame( FraudDecision::Block, $record['decision'] );
		$this->assertSame( '', $record['order_id'], 'The blocked request died before an order existed; nothing may bind.' );
	}

	/**
	 * @testdox One verification binds only the one order its request creates.
	 */
	public function test_bind_covers_only_the_one_order_a_request_creates(): void {
		$this->score_create_order( 'scored-session', FraudDecision::Allow );

		$this->sut->bind_created_order_to_verification( new FakePayPalOrder( 'PP-1' ) );
		$this->sut->bind_created_order_to_verification( new FakePayPalOrder( 'PP-2' ) );

		$record = WC()->session->get( '_fraud_protection_paypal_verification' );

		$this->assertIsArray( $record );
		$this->assertSame( 'PP-1', $record['order_id'], 'The binding state is consumed on read.' );
	}

	/**
	 * @testdox A record that is no longer this verification's is not bound.
	 */
	public function test_bind_ignores_a_record_for_another_session(): void {
		$this->score_create_order( 'scored-session', FraudDecision::Allow );

		// The record was replaced before the order was created.
		$replaced = array(
			'origin'     => 'paypal_express_order_creation',
			'session_id' => 'another-session',
			'decision'   => FraudDecision::Allow,
			'used'       => false,
			'order_id'   => '',
			'cart_hash'  => '',
		);
		WC()->session->set( '_fraud_protection_paypal_verification', $replaced );

		$this->sut->bind_created_order_to_verification( new FakePayPalOrder( 'PP-123' ) );

		$this->assertSame(
			$replaced,
			WC()->session->get( '_fraud_protection_paypal_verification' ),
			'Another verification\'s record must not inherit this order.'
		);
	}

	/**
	 * @testdox A bound record's decision is what the bound route replays, whatever it is.
	 *
	 * A bound Block cannot be produced today — a blocked create-order dies
	 * before its order exists — but the route's contract is "replay the
	 * recorded decision", and this pin is what stops a future change from
	 * turning a bound record into a verdict-blind allow.
	 */
	public function test_supply_answers_a_bound_block_with_its_block(): void {
		$this->set_verification_record( order_id: 'PP-BOUND', decision: FraudDecision::Block );
		WC()->session->set( 'ppcp', array( 'order' => new FakePayPalOrder( 'PP-BOUND' ) ) );

		$this->assertSame(
			FraudDecision::Block,
			$this->ask( 'blocks_checkout', 'ppcp-gateway', 'scored-session' )
		);
	}

	/**
	 * @testdox Scoring again replaces the prior use with a fresh unbound record.
	 */
	public function test_verify_scoring_again_starts_the_record_unbound(): void {
		$this->score_create_order( 'scored-session', FraudDecision::Allow );
		$this->sut->bind_created_order_to_verification( new FakePayPalOrder( 'PP-1' ) );

		WC()->session->set( 'ppcp', array( 'order' => new FakePayPalOrder( 'PP-1' ) ) );
		$this->ask( 'blocks_checkout', 'ppcp-gateway', 'post-reset-spend' );

		// The same session is scored again; the mocks still resolve it.
		$this->sut->verify_and_block_create_order(
			array( SessionVerifier::SESSION_ID_FIELD => 'scored-session' )
		);

		$record = WC()->session->get( '_fraud_protection_paypal_verification' );

		$this->assertIsArray( $record );
		$this->assertSame( '', $record['order_id'], 'A superseded scoring\'s order must not carry over.' );
		$this->assertFalse( $record['used'], 'A fresh verification must start unused.' );
	}

	/**
	 * @testdox Order IDs match as strings, never numerically.
	 */
	public function test_supply_matches_order_ids_as_strings_not_numbers(): void {
		$this->set_verification_record( order_id: '100' );
		WC()->session->set( 'ppcp', array( 'order' => new FakePayPalOrder( '1e2' ) ) );

		$this->assertFalse(
			$this->ask( 'blocks_checkout', 'ppcp-gateway', 'scored-session' ),
			'"1e2" is not "100"; a numeric comparison would say it is.'
		);
	}

	/**
	 * @testdox A non-string order_id reads as unbound, never as a castable value.
	 */
	public function test_supply_treats_a_non_string_order_id_as_unbound(): void {
		$this->set_verification_record( order_id: 100 );
		WC()->session->set( 'ppcp', array( 'order' => new FakePayPalOrder( '100' ) ) );

		$this->assertFalse( $this->ask( 'blocks_checkout', 'ppcp-gateway', 'scored-session' ) );
	}

	/**
	 * @testdox A new verification replaces the used record.
	 */
	public function test_new_verification_replaces_the_used_record(): void {
		$this->score_create_order( 'scored-session', FraudDecision::Allow );
		$this->sut->bind_created_order_to_verification( new FakePayPalOrder( 'PP-1' ) );
		WC()->session->set( 'ppcp', array( 'order' => new FakePayPalOrder( 'PP-1' ) ) );

		$this->assertSame( FraudDecision::Allow, $this->ask( 'blocks_checkout', 'ppcp-gateway', 'scored-session' ) );

		// The retry: the same session is scored again and mints a new order.
		$this->sut->verify_and_block_create_order(
			array( SessionVerifier::SESSION_ID_FIELD => 'scored-session' )
		);
		$this->sut->bind_created_order_to_verification( new FakePayPalOrder( 'PP-2' ) );
		WC()->session->set( 'ppcp', array( 'order' => new FakePayPalOrder( 'PP-2' ) ) );

		$this->assertSame(
			FraudDecision::Allow,
			$this->ask( 'blocks_checkout', 'ppcp-gateway', 'scored-session' ),
			'The new unused record must answer once.'
		);
	}

	/**
	 * @testdox A record in the retired shape is not reused.
	 */
	public function test_supply_does_not_reuse_the_retired_record_shape(): void {
		WC()->session->set(
			'_fraud_protection_paypal_verification',
			array(
				'session_id'  => 'scored-session',
				'stand_downs' => 0,
				'decision'    => FraudDecision::Allow,
				'order_id'    => 'PP-123',
			)
		);
		WC()->session->set( 'ppcp', array( 'order' => new FakePayPalOrder( 'PP-123' ) ) );

		$this->assertFalse( $this->ask( 'blocks_checkout', 'ppcp-gateway', 'scored-session' ) );
		$this->assertNull( WC()->session->get( '_fraud_protection_paypal_verification' ) );
	}

	/**
	 * @testdox Binding preserves the record's unused state.
	 */
	public function test_bind_preserves_the_unused_state(): void {
		$this->score_create_order( 'reused-session', FraudDecision::Allow );
		$this->sut->bind_created_order_to_verification( new FakePayPalOrder( 'PP-9' ) );

		$record = WC()->session->get( '_fraud_protection_paypal_verification' );

		$this->assertIsArray( $record );
		$this->assertFalse( $record['used'] );
		$this->assertSame( 'PP-9', $record['order_id'] );
	}

	/**
	 * @testdox Each record has one shared use across its allowed final sources.
	 *
	 * @dataProvider final_order_source_provider
	 */
	public function test_records_supply_once_across_allowed_sources( string $origin, string $source, string $second_source ): void {
		$request = $this->create_protected_paypal_request_record( $origin );
		$first = $this->sut->supply_decision_for_paypal_express( false, $source, $request, 'response-session' );

		$this->assertInstanceOf( SuppliedDecision::class, $first );
		$this->assertSame( 'response-session', $first->session_id_for_order );
		$this->assert_incoming_decision_is_preserved( $second_source, $request, 'response-session' );
	}

	/** @return array<string, array{string, string, string}> */
	public function final_order_source_provider(): array {
		return array(
			'create Classic'       => array( 'create', 'shortcode_checkout', 'blocks_checkout' ),
			'create Blocks'        => array( 'create', 'blocks_checkout', 'shortcode_checkout' ),
			'create pay for order' => array( 'create', 'pay_for_order', 'blocks_checkout' ),
			'vault Classic'        => array( 'vault', 'shortcode_checkout', 'blocks_checkout' ),
			'vault Blocks'         => array( 'vault', 'blocks_checkout', 'shortcode_checkout' ),
			'vault pay for order'  => array( 'vault', 'pay_for_order', 'subscriptions_change_payment' ),
			'vault subscription'   => array( 'vault', 'subscriptions_change_payment', 'pay_for_order' ),
			'setup Classic'        => array( 'setup', 'shortcode_checkout', 'blocks_checkout' ),
			'setup Blocks'         => array( 'setup', 'blocks_checkout', 'shortcode_checkout' ),
		);
	}

	/**
	 * @testdox $origin records do not supply to unsupported $source requests.
	 *
	 * @dataProvider unsupported_final_source_provider
	 */
	public function test_records_reject_unsupported_final_sources( string $origin, string $source ): void {
		$request = $this->create_protected_paypal_request_record( $origin );

		$this->assert_incoming_decision_is_preserved( $source, $request, 'response-session' );
		$this->assertNull( WC()->session->get( '_fraud_protection_paypal_verification' ) );
	}

	/** @return array<string, array{string, string}> */
	public function unsupported_final_source_provider(): array {
		return array(
			'setup pay for order'       => array( 'setup', 'pay_for_order' ),
			'setup change payment'      => array( 'setup', 'subscriptions_change_payment' ),
			'create change payment'     => array( 'create', 'subscriptions_change_payment' ),
			'vault add payment method'  => array( 'vault', 'add_payment_method' ),
		);
	}

	/**
	 * @testdox Setup records reject non-checkout sources and recheck final eligibility.
	 *
	 * @dataProvider disallowed_setup_source_provider
	 */
	public function test_setup_record_requires_current_eligible_cart( string $disallowed_source ): void {
		$this->set_setup_cart( 'cart-hash' );
		$this->configure_paypal_request_data( array( SessionVerifier::SESSION_ID_FIELD => 'browser-session' ) );
		$this->session_verifier->method( 'verify_session' )->willReturn( FraudDecision::Allow );
		$this->session_verifier->method( 'last_verified_session_id' )->willReturn( 'response-session' );
		$this->run_protected_request( 'wc_ajax_ppc-create-setup-token', array( 'method' => 'POST' ), '/v3/vault/setup-tokens' );
		$request = array( 'payment_method' => 'ppcp-gateway' );

		$this->assert_incoming_decision_is_preserved( $disallowed_source, $request, 'response-session' );

		$this->run_protected_request( 'wc_ajax_ppc-create-setup-token', array( 'method' => 'POST' ), '/v3/vault/setup-tokens' );
		$this->set_setup_cart( 'cart-hash', array(), false );
		$this->assert_incoming_decision_is_preserved( 'blocks_checkout', $request, 'response-session' );
		$this->set_setup_cart( 'cart-hash' );
		$this->run_protected_request( 'wc_ajax_ppc-create-setup-token', array( 'method' => 'POST' ), '/v3/vault/setup-tokens' );
		$this->set_setup_cart( 'changed-hash' );
		$this->assert_incoming_decision_is_preserved( 'blocks_checkout', $request, 'response-session' );

		$this->set_setup_cart( 'cart-hash' );
		$this->run_protected_request( 'wc_ajax_ppc-create-setup-token', array( 'method' => 'POST' ), '/v3/vault/setup-tokens' );
		$this->assertInstanceOf(
			SuppliedDecision::class,
			$this->sut->supply_decision_for_paypal_express( false, 'shortcode_checkout', $request, 'response-session' )
		);

		$this->run_protected_request( 'wc_ajax_ppc-create-setup-token', array( 'method' => 'POST' ), '/v3/vault/setup-tokens' );
		$this->assertInstanceOf(
			SuppliedDecision::class,
			$this->sut->supply_decision_for_paypal_express( false, 'blocks_checkout', $request, 'response-session' )
		);
	}

	/** @return array<string, array{string}> */
	public function disallowed_setup_source_provider(): array {
		return array(
			'add payment method'            => array( 'add_payment_method' ),
			'subscriptions change payment' => array( 'subscriptions_change_payment' ),
		);
	}

	/**
	 * @testdox Setup records require each material eligibility fact at final use.
	 *
	 * @dataProvider final_setup_eligibility_provider
	 */
	public function test_setup_record_rechecks_material_eligibility( string $total, bool $empty, bool $managed_plan ): void {
		$this->set_setup_cart( 'cart-hash' );
		$this->configure_paypal_request_data( array( SessionVerifier::SESSION_ID_FIELD => 'browser-session' ) );
		$this->session_verifier->method( 'verify_session' )->willReturn( FraudDecision::Allow );
		$this->session_verifier->method( 'last_verified_session_id' )->willReturn( 'response-session' );
		$this->run_protected_request( 'wc_ajax_ppc-create-setup-token', array( 'method' => 'POST' ), '/v3/vault/setup-tokens' );

		$items = array();
		if ( $managed_plan ) {
			$product = $this->createMock( \WC_Product::class );
			$product->method( 'get_meta' )->with( 'ppcp_subscription_plan' )->willReturn( 'plan-id' );
			$items = array( array( 'data' => $product ) );
		}
		$this->set_setup_cart( 'cart-hash', $items, true, $total, $empty );

		$this->assert_incoming_decision_is_preserved(
			'blocks_checkout',
			array( 'payment_method' => 'ppcp-gateway' ),
			'response-session'
		);
	}

	/** @return array<string, array{string, bool, bool}> */
	public function final_setup_eligibility_provider(): array {
		return array(
			'positive total'      => array( '1', false, false ),
			'empty cart'          => array( '0', true, false ),
			'PayPal-managed plan' => array( '0', false, true ),
		);
	}

	/**
	 * @testdox Ineligible setup carts do not create reusable records at storage time.
	 *
	 * @dataProvider setup_storage_ineligibility_provider
	 *
	 * @param mixed $plan_metadata PayPal plan metadata.
	 */
	public function test_ineligible_setup_cart_is_not_recorded( string $total, bool $empty, bool $needs_payment, $plan_metadata ): void {
		$items = array();
		if ( null !== $plan_metadata ) {
			$product = $this->createMock( \WC_Product::class );
			$product->method( 'get_meta' )->with( 'ppcp_subscription_plan' )->willReturn( $plan_metadata );
			$items = array( array( 'data' => $product ) );
		}
		$this->set_setup_cart( 'cart-hash', $items, $needs_payment, $total, $empty );
		$this->configure_paypal_request_data( array( SessionVerifier::SESSION_ID_FIELD => 'browser-session' ) );
		$this->session_verifier->method( 'verify_session' )->willReturn( FraudDecision::Allow );
		$this->session_verifier->method( 'last_verified_session_id' )->willReturn( 'response-session' );

		$this->run_protected_request( 'wc_ajax_ppc-create-setup-token', array( 'method' => 'POST' ), '/v3/vault/setup-tokens' );

		$this->assertNull( WC()->session->get( '_fraud_protection_paypal_verification' ) );
	}

	/** @return array<string, array{string, bool, bool, mixed}> */
	public function setup_storage_ineligibility_provider(): array {
		return array(
			'empty cart'               => array( '0', true, true, null ),
			'positive total'           => array( '1', false, true, null ),
			'payment not needed'       => array( '0', false, false, null ),
			'PayPal-managed plan data' => array( '0', false, true, 'plan-id' ),
		);
	}

	/** @testdox Nonempty array plan metadata prevents setup record storage. */
	public function test_array_plan_metadata_prevents_setup_record(): void {
		$product = $this->createMock( \WC_Product::class );
		$product->method( 'get_meta' )->with( 'ppcp_subscription_plan' )->willReturn( array( 'plan-id' ) );
		$this->set_setup_cart( 'cart-hash', array( array( 'data' => $product ) ) );
		$this->configure_paypal_request_data( array( SessionVerifier::SESSION_ID_FIELD => 'browser-session' ) );
		$this->session_verifier->method( 'verify_session' )->willReturn( FraudDecision::Allow );
		$this->session_verifier->method( 'last_verified_session_id' )->willReturn( 'response-session' );

		$this->run_protected_request( 'wc_ajax_ppc-create-setup-token', array( 'method' => 'POST' ), '/v3/vault/setup-tokens' );

		$this->assertNull( WC()->session->get( '_fraud_protection_paypal_verification' ) );
	}

	/** @testdox Setup record storage initializes the cart before checking its payment requirement. */
	public function test_setup_record_initializes_cart_totals(): void {
		$totals_calculated = false;
		$cart              = $this->getMockBuilder( \WC_Cart::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_empty', 'get_total', 'needs_payment', 'get_cart', 'get_cart_hash', 'calculate_totals' ) )
			->getMock();
		$cart->method( 'is_empty' )->willReturn( false );
		$cart->method( 'get_total' )->willReturn( '0' );
		$cart->method( 'needs_payment' )->willReturnCallback(
			static function () use ( &$totals_calculated ): bool {
				return $totals_calculated;
			}
		);
		$cart->method( 'get_cart' )->willReturn( array() );
		$cart->method( 'get_cart_hash' )->willReturn( 'cart-hash' );
		$cart->expects( $this->once() )->method( 'calculate_totals' )->willReturnCallback(
			static function () use ( &$totals_calculated ): void {
				$totals_calculated = true;
			}
		);
		WC()->cart = $cart;

		$this->configure_paypal_request_data( array( SessionVerifier::SESSION_ID_FIELD => 'browser-session' ) );
		$this->session_verifier->method( 'verify_session' )->willReturn( FraudDecision::Allow );
		$this->session_verifier->method( 'last_verified_session_id' )->willReturn( 'response-session' );
		$this->run_protected_request( 'wc_ajax_ppc-create-setup-token', array( 'method' => 'POST' ), '/v3/vault/setup-tokens' );

		$record = WC()->session->get( '_fraud_protection_paypal_verification' );
		$this->assertIsArray( $record );
		$this->assertSame( 'cart-hash', $record['cart_hash'] );
	}

	/** @testdox Subscriptions render requests the interceptor only for the active PayPal script. */
	public function test_subscriptions_render_requires_active_paypal_script(): void {
		$handler = $this->createMock( BlackboxScriptHandler::class );
		$handler->expects( $this->once() )->method( 'request_scripts' )->willReturn( true );
		$sut = $this->make_compat_with_script_handler( $handler );
		$sut->register();
		do_action( 'woocommerce_subscriptions_change_payment_after_submit' );
		wp_register_script( 'ppcp-add-payment-method', 'https://example.com/add.js', array(), '1.0', true );
		wp_enqueue_script( 'ppcp-add-payment-method' );
		$this->touched_add_payment_method_handle = true;

		do_action( 'woocommerce_subscriptions_change_payment_after_submit' );

		$this->assertTrue( wp_script_is( 'wc-fraud-protection-paypal-express', 'enqueued' ) );
	}

	/** @testdox My Account render requires the page and add-payment-method query variable. */
	public function test_my_account_render_requires_both_page_checks(): void {
		global $wp;

		$handler = $this->createMock( BlackboxScriptHandler::class );
		$handler->expects( $this->once() )->method( 'request_scripts' )->willReturn( true );
		$sut = $this->make_compat_with_script_handler( $handler );
		$page_id = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$previous_page_id       = get_option( 'woocommerce_myaccount_page_id', null );
		$had_endpoint_query_var = array_key_exists( 'add-payment-method', $wp->query_vars );
		$previous_query_var     = $wp->query_vars['add-payment-method'] ?? null;
		try {
			update_option( 'woocommerce_myaccount_page_id', $page_id );
			wp_register_script( 'ppcp-add-payment-method', 'https://example.com/add.js', array(), '1.0', true );
			wp_enqueue_script( 'ppcp-add-payment-method' );
			$this->touched_add_payment_method_handle = true;

			$this->go_to( home_url( '/' ) );
			$wp->query_vars['add-payment-method'] = '';
			$sut->enqueue_paypal_script_for_add_payment_method();

			$this->go_to( get_permalink( $page_id ) );
			unset( $wp->query_vars['add-payment-method'] );
			$sut->enqueue_paypal_script_for_add_payment_method();
			$wp->query_vars['add-payment-method'] = '';
			$sut->enqueue_paypal_script_for_add_payment_method();

			$this->assertTrue( wp_script_is( 'wc-fraud-protection-paypal-express', 'enqueued' ) );
		} finally {
			if ( null === $previous_page_id ) {
				delete_option( 'woocommerce_myaccount_page_id' );
			} else {
				update_option( 'woocommerce_myaccount_page_id', $previous_page_id );
			}
			if ( $had_endpoint_query_var ) {
				$wp->query_vars['add-payment-method'] = $previous_query_var;
			} else {
				unset( $wp->query_vars['add-payment-method'] );
			}
		}
	}

	/**
	 * Configure PayPal request-data compatibility stubs.
	 *
	 * @param array  $data    Request data.
	 * @param string $failure Failure mode.
	 */
	private function configure_paypal_request_data( array $data, string $failure = '' ): void {
		if ( ! class_exists( 'WooCommerce\\PayPalCommerce\\Button\\Endpoint\\RequestData' ) ) {
			class_alias( TestPayPalRequestData::class, 'WooCommerce\\PayPalCommerce\\Button\\Endpoint\\RequestData' );
		}

		TestPayPalRequestData::$data  = $data;
		TestPayPalRequestData::$error = 'read' === $failure ? new \RuntimeException( 'invalid request' ) : null;
		PayPalPPCPStub::set_error( 'container' === $failure ? new \RuntimeException( 'container unavailable' ) : null );
		$service = match ( $failure ) {
			'service'   => new \stdClass(),
			default     => new TestPayPalRequestData(),
		};
		PayPalContainerStub::set_service( 'button.request-data', $service );

	}

	/**
	 * Create a reusable record through its protected PayPal request path.
	 *
	 * @param string $record_type Record type.
	 * @return array Final request data.
	 */
	private function create_protected_paypal_request_record( string $record_type ): array {
		$this->session_verifier->method( 'verify_session' )->willReturn( FraudDecision::Allow );
		$this->session_verifier->method( 'last_verified_session_id' )->willReturn( 'response-session' );

		if ( 'create' === $record_type ) {
			$this->sut->verify_and_block_create_order( array( SessionVerifier::SESSION_ID_FIELD => 'browser-session' ) );
		} else {
			$this->configure_paypal_request_data( array( SessionVerifier::SESSION_ID_FIELD => 'browser-session' ) );
			$action = 'setup' === $record_type ? 'wc_ajax_ppc-create-setup-token' : 'wc_ajax_ppc-vault-create-order';
			$path   = 'setup' === $record_type ? '/v3/vault/setup-tokens' : '/v2/checkout/orders';
			if ( 'setup' === $record_type ) {
				$this->set_setup_cart( 'cart-hash' );
			}
			$this->run_protected_request( $action, array( 'method' => 'POST' ), $path );
		}

		$request = array( 'payment_method' => 'ppcp-gateway' );
		if ( 'setup' !== $record_type ) {
			$this->sut->bind_created_order_to_verification( new FakePayPalOrder( 'PP-123' ) );
			$request['payment_data'] = array( 'paypal_order_id' => 'PP-123' );
		}

		return $request;
	}

	/**
	 * Store a valid PayPal verification record for a final-request test.
	 *
	 * @param string        $origin     Verification origin.
	 * @param string        $session_id Response-backed session ID.
	 * @param FraudDecision $decision   Recorded decision.
	 * @param bool          $used       Whether the shared use is spent.
	 * @param mixed         $order_id   Bound PayPal order ID.
	 * @param string        $cart_hash  Bound setup cart hash.
	 */
	private function set_verification_record(
		string $origin = 'paypal_express_order_creation',
		string $session_id = 'scored-session',
		FraudDecision $decision = FraudDecision::Allow,
		bool $used = false,
		$order_id = '',
		string $cart_hash = ''
	): void {
		WC()->session->set(
			'_fraud_protection_paypal_verification',
			array(
				'origin'     => $origin,
				'session_id' => $session_id,
				'decision'   => $decision,
				'used'       => $used,
				'order_id'   => $order_id,
				'cart_hash'  => $cart_hash,
			)
		);
	}

	/**
	 * Run a request filter while its WooCommerce AJAX action is active.
	 *
	 * @param string $action Action name.
	 * @param array  $args   HTTP arguments.
	 * @param string $path   PayPal URL path.
	 * @return mixed Filter result.
	 */
	private function run_protected_request( string $action, array $args, string $path ) {
		$this->sut->register();
		$result   = null;
		$callback = function () use ( &$result, $args, $path ): void {
			$result = apply_filters( 'ppcp_request_args', $args, 'https://api-m.paypal.com' . $path );
		};
		add_action( $action, $callback );
		try {
			do_action( $action );
		} finally {
			remove_action( $action, $callback );
		}

		return $result;
	}

	/**
	 * Set a controlled eligible setup cart.
	 *
	 * @param string $hash          Cart hash.
	 * @param array  $items         Cart items.
	 * @param bool   $needs_payment Whether the cart needs a payment method.
	 * @param mixed  $total         Cart total.
	 * @param bool   $empty         Whether the cart is empty.
	 */
	private function set_setup_cart( string $hash, array $items = array(), bool $needs_payment = true, $total = '0', bool $empty = false ): void {
		$cart = $this->getMockBuilder( \WC_Cart::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_empty', 'get_total', 'needs_payment', 'get_cart', 'get_cart_hash', 'calculate_totals' ) )
			->getMock();
		$cart->method( 'is_empty' )->willReturn( $empty );
		$cart->method( 'get_total' )->willReturn( $total );
		$cart->method( 'needs_payment' )->willReturn( $needs_payment );
		$cart->method( 'get_cart' )->willReturn( $items );
		$cart->method( 'get_cart_hash' )->willReturn( $hash );
		WC()->cart = $cart;
	}

}
