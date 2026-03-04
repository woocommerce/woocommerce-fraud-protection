<?php
/**
 * PayPalCompatTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\FraudProtection\Compat;

use Automattic\WooCommerce\FraudProtection\ApiClient;
use Automattic\WooCommerce\FraudProtection\BlackboxScriptHandler;
use Automattic\WooCommerce\FraudProtection\BlockedSessionNotice;
use Automattic\WooCommerce\FraudProtection\Compat\PayPalCompat;
use Automattic\WooCommerce\FraudProtection\SessionVerifier;
use WC_Unit_Test_Case;

/**
 * Tests for the PayPalCompat class.
 *
 * @covers \Automattic\WooCommerce\FraudProtection\Compat\PayPalCompat
 */
class PayPalCompatTest extends WC_Unit_Test_Case {

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
	 * Mock blocked session notice.
	 *
	 * @var BlockedSessionNotice&\PHPUnit\Framework\MockObject\MockObject
	 */
	private $blocked_session_notice;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->session_verifier       = $this->createMock( SessionVerifier::class );
		$this->blocked_session_notice = $this->createMock( BlockedSessionNotice::class );

		$this->blocked_session_notice
			->method( 'get_message_plaintext' )
			->willReturn( 'We are unable to process this request online. Please contact support (test@example.com) to complete your purchase.' );

		$this->sut = new PayPalCompat();
		$this->sut->init(
			$this->session_verifier,
			$this->blocked_session_notice
		);
	}

	/**
	 * Tear down after each test.
	 */
	public function tearDown(): void {
		remove_all_filters( 'wp_doing_ajax' );
		remove_all_filters( 'wp_die_ajax_handler' );
		remove_all_filters( 'woocommerce_fraud_protection_enqueue_blackbox_scripts' );
		remove_all_actions( 'woocommerce_paypal_payments_create_order_request_started' );
		remove_all_actions( 'wp_enqueue_scripts' );
		wp_dequeue_script( 'wc-fraud-protection-blackbox-init' );
		wp_dequeue_script( 'wc-fraud-protection-paypal-express' );

		parent::tearDown();
	}

	/*
	|--------------------------------------------------------------------------
	| register() Tests
	|--------------------------------------------------------------------------
	*/

	/**
	 * @testdox register() hooks the create_order action, enqueue filter, and script action.
	 */
	public function test_register_hooks(): void {
		$this->sut->register();

		$this->assertNotFalse(
			has_action( 'woocommerce_paypal_payments_create_order_request_started', array( $this->sut, 'verify_and_block_create_order' ) ),
			'create_order_request_started action should be registered'
		);
		$this->assertNotFalse(
			has_filter( 'woocommerce_fraud_protection_enqueue_blackbox_scripts', array( $this->sut, 'should_enqueue_blackbox' ) ),
			'enqueue_blackbox_scripts filter should be registered'
		);
		$this->assertNotFalse(
			has_action( 'wp_enqueue_scripts', array( $this->sut, 'enqueue_paypal_script' ) ),
			'wp_enqueue_scripts action should be registered'
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
		$data = array( BlackboxScriptHandler::SESSION_ID_FIELD => 'test-session-abc' );

		$this->session_verifier
			->expects( $this->once() )
			->method( 'verify_session' )
			->with( 'test-session-abc', 'paypal_express_order_creation', 0, $data )
			->willReturn( ApiClient::DECISION_ALLOW );

		// Should return normally without terminating.
		$this->sut->verify_and_block_create_order( $data );
	}

	/**
	 * @testdox verify_and_block_create_order() sends JSON error with 403 on BLOCK decision.
	 */
	public function test_verify_blocks_on_block_decision(): void {
		$data = array( BlackboxScriptHandler::SESSION_ID_FIELD => 'test-session-blocked' );

		$this->session_verifier
			->expects( $this->once() )
			->method( 'verify_session' )
			->with( 'test-session-blocked', 'paypal_express_order_creation', 0, $data )
			->willReturn( ApiClient::DECISION_BLOCK );

		$this->blocked_session_notice
			->expects( $this->once() )
			->method( 'get_message_plaintext' )
			->with( 'purchase' );

		// wp_send_json_error calls wp_die() only when wp_doing_ajax() is true,
		// otherwise it calls die() directly. Force AJAX context and override
		// the AJAX die handler to throw an exception we can catch.
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter(
			'wp_die_ajax_handler',
			function () {
				return function () {
					throw new \WPDieException( 'wp_die called' );
				};
			}
		);

		// Buffer output to capture the JSON echoed by wp_send_json_error.
		ob_start();
		try {
			$this->sut->verify_and_block_create_order( $data );
			$this->fail( 'Expected WPDieException was not thrown' );
		} catch ( \WPDieException $e ) {
			$json = (string) ob_get_clean();
			$body = json_decode( $json, true );
			$this->assertFalse( $body['success'] );
			$this->assertStringContainsString( 'unable to process', $body['data']['message'] );
		}
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
			->willReturn( ApiClient::DECISION_ALLOW );

		$this->sut->verify_and_block_create_order( $data );
	}

	/*
	|--------------------------------------------------------------------------
	| should_enqueue_blackbox() Tests
	|--------------------------------------------------------------------------
	*/

	/**
	 * @testdox should_enqueue_blackbox() returns true when already set to enqueue.
	 */
	public function test_should_enqueue_blackbox_passthrough_when_already_true(): void {
		$this->assertTrue( $this->sut->should_enqueue_blackbox( true ) );
	}

	/**
	 * @testdox should_enqueue_blackbox() returns false when PayPal is not available.
	 */
	public function test_should_enqueue_blackbox_false_when_no_paypal(): void {
		// No PayPal gateways registered by default.
		$this->assertFalse( $this->sut->should_enqueue_blackbox( false ) );
	}

	/*
	|--------------------------------------------------------------------------
	| enqueue_paypal_script() Tests
	|--------------------------------------------------------------------------
	*/

	/**
	 * @testdox enqueue_paypal_script() enqueues when blackbox-init is already enqueued.
	 */
	public function test_enqueue_paypal_script_when_blackbox_init_enqueued(): void {
		wp_enqueue_script( 'wc-fraud-protection-blackbox-init', 'https://example.com/blackbox-init.js', array(), '1.0', true );

		$this->sut->enqueue_paypal_script();

		$this->assertTrue( wp_script_is( 'wc-fraud-protection-paypal-express', 'enqueued' ) );
	}

	/**
	 * @testdox enqueue_paypal_script() does not enqueue when blackbox-init is absent.
	 */
	public function test_enqueue_paypal_script_skips_when_blackbox_init_absent(): void {
		$this->sut->enqueue_paypal_script();

		$this->assertFalse( wp_script_is( 'wc-fraud-protection-paypal-express', 'enqueued' ) );
	}
}
