<?php
/**
 * PayForOrderProtectorTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin\Protectors;

use Automattic\WooCommerce\FraudProtection\BlackboxScriptHandler;
use Automattic\WooCommerce\FraudProtection\Schemas\FraudDecision;
use Automattic\WooCommerce\FraudProtection\BlockedSessionMessage;
use Automattic\WooCommerce\FraudProtection\MessageContext;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\ClassicFormDataExtractionTrait;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Protectors\PayForOrderProtector;
use Automattic\WooCommerce\FraudProtection\SessionVerifier;
use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;

/**
 * Tests for the PayForOrderProtector class.
 *
 * @covers \Automattic\WooCommerce\Internal\FraudProtectionPlugin\Protectors\PayForOrderProtector
 */
class PayForOrderProtectorTest extends FraudProtectionUnitTestCase {

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
	 * @var BlockedSessionMessage&\PHPUnit\Framework\MockObject\MockObject
	 */
	private $blocked_session_message;

	/**
	 * Mock Blackbox script handler.
	 *
	 * @var BlackboxScriptHandler&\PHPUnit\Framework\MockObject\MockObject
	 */
	private $blackbox_script_handler;

	/**
	 * Order used by pay-form render tests.
	 *
	 * @var \WC_Order|null
	 */
	private $order;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->session_verifier       = $this->createMock( SessionVerifier::class );
		$this->blocked_session_message = $this->createMock( BlockedSessionMessage::class );
		$this->blackbox_script_handler = $this->createMock( BlackboxScriptHandler::class );

		$this->blocked_session_message
			->method( 'get_html' )
			->willReturn( 'We are unable to process this request online. Please <a href="mailto:test@example.com">contact support (test@example.com)</a> for assistance.' );

		$this->sut = new PayForOrderProtector();
		$this->sut->init(
			$this->session_verifier,
			$this->blocked_session_message,
			$this->blackbox_script_handler
		);
	}

	/**
	 * Tear down after each test.
	 */
	public function tearDown(): void {
		$_POST = array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		wc_clear_notices();

		remove_action( 'before_woocommerce_pay_form', array( $this->sut, 'enqueue_pay_for_order_script' ), 10 );
		remove_filter( 'woocommerce_order_email_verification_required', '__return_true' );
		remove_filter( 'woocommerce_order_email_verification_grace_period', '__return_zero' );
		unset( $GLOBALS['wp']->query_vars['order-pay'] );
		unset( $_GET['pay_for_order'], $_GET['key'] );
		$this->reset_fraud_protection_scripts();
		wp_set_current_user( 0 );

		if ( $this->order instanceof \WC_Order ) {
			\WC_Helper_Order::delete_order( $this->order->get_id() );
			$this->order = null;
		}

		parent::tearDown();
	}

	/*
	|--------------------------------------------------------------------------
	| enqueue_pay_for_order_script() Tests
	|--------------------------------------------------------------------------
	*/

	/**
	 * @testdox enqueue_pay_for_order_script() enqueues its script when the shared scripts are available.
	 */
	public function test_enqueue_pay_for_order_script_when_shared_scripts_are_available(): void {
		$this->blackbox_script_handler->expects( $this->once() )->method( 'request_scripts' )->willReturn( true );

		$this->sut->enqueue_pay_for_order_script();

		$this->assertTrue( wp_script_is( 'wc-fraud-protection-pay-for-order', 'enqueued' ) );
		$script = wp_scripts()->query( 'wc-fraud-protection-pay-for-order', 'registered' );
		$this->assertNotFalse( $script );
		$this->assertContains( 'wc-fraud-protection-blackbox-init', $script->deps );
	}

	/**
	 * @testdox enqueue_pay_for_order_script() does not enqueue when the shared scripts are unavailable.
	 */
	public function test_enqueue_pay_for_order_script_skips_when_shared_scripts_are_unavailable(): void {
		$this->blackbox_script_handler->expects( $this->once() )->method( 'request_scripts' )->willReturn( false );

		$this->sut->enqueue_pay_for_order_script();

		$this->assertFalse( wp_script_is( 'wc-fraud-protection-pay-for-order', 'enqueued' ) );
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
	 * @testdox register() hooks verification and the pay-form render signal.
	 */
	public function test_register_hooks(): void {
		$this->sut->register();

		$this->assertNotFalse(
			has_action( 'woocommerce_before_pay_action', array( $this->sut, 'verify_and_block' ) ),
			'woocommerce_before_pay_action action should be registered'
		);
		$this->assertSame(
			10,
			has_action( 'before_woocommerce_pay_form', array( $this->sut, 'enqueue_pay_for_order_script' ) )
		);
		$this->assertFalse( has_action( 'wp_enqueue_scripts', array( $this->sut, 'enqueue_pay_for_order_script' ) ) );
	}

	/**
	 * @testdox The real validated pay form requests shared and protector scripts.
	 */
	public function test_validated_pay_form_render_enqueues_scripts(): void {
		$this->mock_jetpack_blog_id( 12345 );
		$this->sut->init(
			$this->session_verifier,
			$this->blocked_session_message,
			$this->make_blackbox_script_handler()
		);
		$this->order = \WC_Helper_Order::create_order( 1 );
		wp_set_current_user( 1 );
		$this->sut->register();

		$this->render_order_pay( $this->order, true, $this->order->get_order_key() );

		$this->assertTrue( wp_script_is( 'wc-fraud-protection-blackbox-init', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'wc-fraud-protection-pay-for-order', 'enqueued' ) );
	}

	/**
	 * @testdox Pay-page returns before the form hook enqueue no scripts.
	 *
	 * @dataProvider pay_form_early_return_provider
	 *
	 * @param string $case Early-return case.
	 */
	public function test_pay_page_early_returns_do_not_enqueue_scripts( string $case ): void {
		$this->blackbox_script_handler->expects( $this->never() )->method( 'request_scripts' );
		$this->sut->register();

		if ( 'invalid_order' === $case ) {
			$this->render_order_pay_id( 999999, true, 'bad-key' );
		} else {
			$customer_id = 'login' === $case ? $this->factory()->user->create( array( 'role' => 'customer' ) ) : 1;
			$this->order = \WC_Helper_Order::create_order( $customer_id );
			wp_set_current_user( 'login' === $case ? 0 : 1 );
			$key           = 'invalid_key' === $case ? 'bad-key' : $this->order->get_order_key();
			$pay_for_order = 'receipt' !== $case;

			if ( 'email_verification' === $case ) {
				$customer_id = $this->factory()->user->create(
					array(
						'role'       => 'customer',
						'user_email' => 'paying-customer@example.org',
					)
				);
				$this->order->set_customer_id( 0 );
				$this->order->set_billing_email( 'paying-customer@example.org' );
				$this->order->set_date_created( time() - HOUR_IN_SECONDS );
				$this->order->save();
				wp_set_current_user( $customer_id );
				add_filter( 'woocommerce_order_email_verification_grace_period', '__return_zero' );
				add_filter( 'woocommerce_order_email_verification_required', '__return_true' );
			}

			if ( 'no_payment' === $case ) {
				$this->order->set_status( 'completed' );
				$this->order->save();
			}

			$this->render_order_pay( $this->order, $pay_for_order, $key );
		}

		$this->assertFalse( wp_script_is( 'wc-fraud-protection-blackbox-init', 'enqueued' ) );
		$this->assertFalse( wp_script_is( 'wc-fraud-protection-pay-for-order', 'enqueued' ) );
	}

	/**
	 * Pay-page paths that do not render the payment form.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function pay_form_early_return_provider(): array {
		return array(
			'invalid order'      => array( 'invalid_order' ),
			'invalid key'        => array( 'invalid_key' ),
			'login'              => array( 'login' ),
			'email verification' => array( 'email_verification' ),
			'no payment needed'  => array( 'no_payment' ),
			'receipt output'     => array( 'receipt' ),
		);
	}

	/**
	 * Render the order-pay endpoint for an order.
	 *
	 * @param \WC_Order $order         Order to render.
	 * @param bool      $pay_for_order Whether this is the validated payment form path.
	 * @param string    $key           Order key supplied by the request.
	 */
	private function render_order_pay( \WC_Order $order, bool $pay_for_order, string $key ): void {
		$this->render_order_pay_id( $order->get_id(), $pay_for_order, $key );
	}

	/**
	 * Render the order-pay endpoint and discard its HTML.
	 *
	 * @param int    $order_id      Order ID.
	 * @param bool   $validated_pay Whether to include the pay-for-order request flag.
	 * @param string $key           Order key supplied by the request.
	 */
	private function render_order_pay_id( int $order_id, bool $validated_pay, string $key ): void {
		$GLOBALS['wp']->query_vars['order-pay'] = (string) $order_id;
		$_GET['key']                             = $key;

		if ( $validated_pay ) {
			$_GET['pay_for_order'] = 'true';
		} else {
			unset( $_GET['pay_for_order'] );
		}

		ob_start();
		\WC_Shortcode_Checkout::output( array() );
		ob_end_clean();
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
			->willReturn( FraudDecision::Allow );

		$this->sut->verify_and_block( $order );

		$this->assertFalse( wc_has_notice( $this->blocked_session_message->get_html( MessageContext::Purchase ), 'error' ) );
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
			->willReturn( FraudDecision::Block );

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

		$this->blocked_session_message
			->expects( $this->once() )
			->method( 'get_html' )
			->with( MessageContext::Purchase );

		$this->session_verifier
			->method( 'verify_session' )
			->willReturn( FraudDecision::Block );

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
			->willReturn( FraudDecision::Allow );

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
			->willReturn( FraudDecision::Block );

		// Pre-add the same notice.
		$message = $this->blocked_session_message->get_html( MessageContext::Purchase );
		wc_add_notice( $message, 'error' );

		$this->sut->verify_and_block( $order );

		// Should still be just 1 notice, not 2.
		$this->assertSame( 1, wc_notice_count( 'error' ) );
	}
}
