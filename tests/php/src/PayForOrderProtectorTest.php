<?php
/**
 * PayForOrderProtectorTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\FraudProtection\FraudProtection;

use Automattic\WooCommerce\FraudProtection\ApiClient;
use Automattic\WooCommerce\FraudProtection\BlockedSessionNotice;
use Automattic\WooCommerce\FraudProtection\ClassicFormDataExtractionTrait;
use Automattic\WooCommerce\FraudProtection\PayForOrderProtector;
use Automattic\WooCommerce\FraudProtection\SessionVerifier;
use WC_Unit_Test_Case;

/**
 * Tests for the PayForOrderProtector class.
 *
 * @covers \Automattic\WooCommerce\FraudProtection\PayForOrderProtector
 */
class PayForOrderProtectorTest extends WC_Unit_Test_Case {

	/**
	 * The System Under Test.
	 *
	 * @var PayForOrderProtector
	 */
	private PayForOrderProtector $sut;

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
			->method( 'get_message_html' )
			->willReturn( 'We are unable to process this request online. Please <a href="mailto:test@example.com">contact support (test@example.com)</a> for assistance.' );

		$this->sut = new PayForOrderProtector();
		$this->sut->init(
			$this->session_verifier,
			$this->blocked_session_notice
		);
	}

	/**
	 * Tear down after each test.
	 */
	public function tearDown(): void {
		$_POST = array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		wc_clear_notices();

		parent::tearDown();
	}

	/**
	 * @testdox PayForOrderProtector uses ClassicFormDataExtractionTrait.
	 */
	public function test_uses_classic_form_data_extraction_trait(): void {
		$this->assertContains(
			ClassicFormDataExtractionTrait::class,
			class_uses( PayForOrderProtector::class )
		);
	}

	/*
	|--------------------------------------------------------------------------
	| register() Tests
	|--------------------------------------------------------------------------
	*/

	/**
	 * @testdox register() hooks woocommerce_before_pay_action and wp_enqueue_scripts.
	 */
	public function test_register_hooks(): void {
		$this->sut->register();

		$this->assertNotFalse(
			has_action( 'woocommerce_before_pay_action', array( $this->sut, 'verify_and_block' ) ),
			'woocommerce_before_pay_action action should be registered'
		);
		$this->assertNotFalse(
			has_action( 'wp_enqueue_scripts', array( $this->sut, 'enqueue_pay_for_order_script' ) ),
			'wp_enqueue_scripts hook should be registered'
		);
	}

	/*
	|--------------------------------------------------------------------------
	| verify_and_block() Tests
	|--------------------------------------------------------------------------
	*/

	/**
	 * @testdox verify_and_block() passes session_id, order_id, and request_data to SessionVerifier — no notice on ALLOW.
	 */
	public function test_verify_allows_on_allow_decision(): void {
		$_POST['wc_fraud_protection_session_id'] = 'test-session-123';
		$_POST['payment_method']                 = 'stripe';

		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_id' )->willReturn( 42 );

		$this->session_verifier
			->expects( $this->once() )
			->method( 'verify_session' )
			->with( 'test-session-123', 'pay_for_order', 42, $this->isType( 'array' ) )
			->willReturn( ApiClient::DECISION_ALLOW );

		$this->sut->verify_and_block( $order );

		$this->assertFalse( wc_has_notice( $this->blocked_session_notice->get_message_html( 'purchase' ), 'error' ) );
	}

	/**
	 * @testdox verify_and_block() adds notice on BLOCK decision.
	 */
	public function test_verify_adds_notice_on_block_decision(): void {
		$_POST['wc_fraud_protection_session_id'] = 'test-session-456';
		$_POST['payment_method']                 = 'woocommerce_payments';

		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_id' )->willReturn( 10 );

		$this->session_verifier
			->expects( $this->once() )
			->method( 'verify_session' )
			->willReturn( ApiClient::DECISION_BLOCK );

		$this->sut->verify_and_block( $order );

		$this->assertTrue(
			wc_has_notice(
				'We are unable to process this request online. Please <a href="mailto:test@example.com">contact support (test@example.com)</a> for assistance.',
				'error'
			)
		);
	}

	/**
	 * @testdox verify_and_block() uses purchase context for blocked message.
	 */
	public function test_verify_uses_purchase_context_for_blocked_message(): void {
		$_POST['payment_method'] = 'stripe';

		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_id' )->willReturn( 1 );

		$this->blocked_session_notice
			->expects( $this->once() )
			->method( 'get_message_html' )
			->with( 'purchase' );

		$this->session_verifier
			->method( 'verify_session' )
			->willReturn( ApiClient::DECISION_BLOCK );

		$this->sut->verify_and_block( $order );
	}

	/**
	 * @testdox verify_and_block() passes the order ID to SessionVerifier.
	 */
	public function test_verify_passes_order_id_to_session_verifier(): void {
		$_POST['payment_method'] = 'stripe';

		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_id' )->willReturn( 99 );

		$this->session_verifier
			->expects( $this->once() )
			->method( 'verify_session' )
			->with(
				$this->isType( 'string' ),
				'pay_for_order',
				99,
				$this->isType( 'array' )
			)
			->willReturn( ApiClient::DECISION_ALLOW );

		$this->sut->verify_and_block( $order );
	}

	/**
	 * @testdox verify_and_block() deduplicates blocked notice.
	 */
	public function test_verify_deduplicates_blocked_notice(): void {
		$_POST['payment_method'] = 'stripe';

		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_id' )->willReturn( 1 );

		$this->session_verifier
			->method( 'verify_session' )
			->willReturn( ApiClient::DECISION_BLOCK );

		// Pre-add the same notice.
		$message = $this->blocked_session_notice->get_message_html( 'purchase' );
		wc_add_notice( $message, 'error' );

		$this->sut->verify_and_block( $order );

		// Should still be just 1 notice, not 2.
		$this->assertSame( 1, wc_notice_count( 'error' ) );
	}
}
