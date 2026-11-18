<?php
/**
 * PayForOrderProtectorTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin\Protectors;

use Automattic\WooCommerce\FraudProtection\BlackboxScriptHandler;
use Automattic\WooCommerce\FraudProtection\BlockedSessionMessage;
use Automattic\WooCommerce\FraudProtection\MessageContext;
use Automattic\WooCommerce\FraudProtection\Schemas\FraudDecision;
use Automattic\WooCommerce\FraudProtection\SessionIdNormalizer;
use Automattic\WooCommerce\FraudProtection\SessionVerifier;
use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\ApiClient;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\ClassicFormDataExtractionTrait;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\DecisionHandler;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\PaymentDataResolver;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Protectors\PayForOrderProtector;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Rules\RuleEvaluator;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Sessions\SessionDataCollector;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Sessions\SessionEventRecorder;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Sessions\SessionIdError;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Settings\AutomaticProtectionSetting;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\VisitorIpResolver;

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

		$this->session_verifier        = $this->createMock( SessionVerifier::class );
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
		$this->assertSame(
			plugins_url( 'assets/js/pay-for-order.js', WC_FRAUD_PROTECTION_PLUGIN_FILE ),
			$script->src
		);
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
	 * @param string $test_case Early-return case.
	 */
	public function test_pay_page_early_returns_do_not_enqueue_scripts( string $test_case ): void {
		$this->blackbox_script_handler->expects( $this->never() )->method( 'request_scripts' );
		$this->sut->register();

		if ( 'invalid_order' === $test_case ) {
			$this->render_order_pay_id( 999999, true, 'bad-key' );
		} else {
			$customer_id = 'login' === $test_case ? $this->factory()->user->create( array( 'role' => 'customer' ) ) : 1;
			$this->order = \WC_Helper_Order::create_order( $customer_id );
			wp_set_current_user( 'login' === $test_case ? 0 : 1 );
			$key           = 'invalid_key' === $test_case ? 'bad-key' : $this->order->get_order_key();
			$pay_for_order = 'receipt' !== $test_case;

			if ( 'email_verification' === $test_case ) {
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

			if ( 'no_payment' === $test_case ) {
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
		$_GET['key']                            = $key;

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

	/**
	 * @testdox verify_and_block() verifies without a session, with the `reused` reason, when the submitted session ID is the one stored on the order.
	 */
	public function test_verify_without_session_when_submitted_id_is_the_order_session_id(): void {
		$this->order = \WC_Helper_Order::create_order();
		$this->order->update_meta_data( SessionVerifier::ORDER_BLACKBOX_SESSION_ID_KEY, 'sess-first-attempt' );
		$this->order->save_meta_data();
		$_POST['wc_fraud_protection_session_id'] = 'sess-first-attempt';
		$_POST['payment_method']                 = 'stripe';

		$this->session_verifier
			->expects( $this->once() )
			->method( 'verify_session' )
			->with(
				'',
				'pay_for_order',
				$this->order->get_id(),
				$this->callback(
					fn( array $request_data ): bool => SessionIdError::REUSED === ( $request_data[ SessionIdError::FIELD ] ?? null )
						&& 'stripe' === $request_data['payment_method']
				)
			)
			->willReturn( FraudDecision::Allow );

		$this->sut->verify_and_block( $this->order );

		$this->assertLogged(
			'info',
			'already verified for the order',
			array(
				'session_id' => 'sess-first-attempt',
				'order_id'   => $this->order->get_id(),
			)
		);
	}

	/**
	 * @testdox verify_and_block() sends the submitted session ID unchanged when it is not the one stored on the order.
	 *
	 * @dataProvider session_id_not_stored_on_order_provider
	 *
	 * @param mixed  $stored    Session ID stored on the order, or null for none.
	 * @param string $submitted Submitted session ID.
	 */
	public function test_verify_sends_submitted_session_id_not_stored_on_order( $stored, string $submitted ): void {
		$this->order = \WC_Helper_Order::create_order();
		if ( null !== $stored ) {
			$this->order->update_meta_data( SessionVerifier::ORDER_BLACKBOX_SESSION_ID_KEY, $stored );
			$this->order->save_meta_data();
		}
		$_POST['wc_fraud_protection_session_id'] = $submitted;

		$this->session_verifier
			->expects( $this->once() )
			->method( 'verify_session' )
			->with(
				$submitted,
				'pay_for_order',
				$this->order->get_id(),
				$this->callback( fn( array $request_data ): bool => ! array_key_exists( SessionIdError::FIELD, $request_data ) )
			)
			->willReturn( FraudDecision::Allow );

		$this->sut->verify_and_block( $this->order );
	}

	/**
	 * Data provider for submitted session IDs that are not the order's session ID.
	 *
	 * @return array<string, array{0: mixed, 1: string}>
	 */
	public function session_id_not_stored_on_order_provider(): array {
		return array(
			'no stored session ID'            => array( null, 'sess-new-attempt' ),
			'another stored session ID'       => array( 'sess-first-attempt', 'sess-new-attempt' ),
			'empty submitted and stored'      => array( '', '' ),
			'stored session ID is not string' => array( array( 'sess-new-attempt' ), 'sess-new-attempt' ),
		);
	}

	/**
	 * @testdox verify_and_block() verifies without a session every time the same session ID is submitted again, not only the first time.
	 */
	public function test_every_later_submission_of_a_used_session_id_is_verified_without_a_session(): void {
		$this->order = \WC_Helper_Order::create_order();
		$sent_ids    = array();

		// Only the transport is replaced. A verification without a session
		// answers with a session ID of its own, which replaces the one stored
		// on the order.
		$api_client = $this->getMockBuilder( ApiClient::class )
			->onlyMethods( array( 'jetpack_remote_request' ) )
			->getMock();
		$api_client
			->expects( $this->exactly( 4 ) )
			->method( 'jetpack_remote_request' )
			->willReturnCallback(
				function ( array $request_args, string $body ) use ( &$sent_ids ): array {
					$payload    = json_decode( $body, true );
					$sent_id    = is_string( $payload['session_id'] ?? null ) ? $payload['session_id'] : '';
					$sent_ids[] = $sent_id;

					return array(
						'response' => array( 'code' => 200 ),
						'body'     => wp_json_encode(
							array(
								'data' => array(
									'decision'   => 'allow',
									'session_id' => '' === $sent_id ? 'sessionless-' . count( $sent_ids ) : $sent_id,
								),
							)
						),
					);
				}
			);
		$session_id_normalizer = new SessionIdNormalizer();
		$api_client->init( wc_get_container()->get( VisitorIpResolver::class ), $session_id_normalizer );

		$automatic_protection = new AutomaticProtectionSetting();
		$decision_handler     = new DecisionHandler();
		$decision_handler->init( $this->createMock( SessionEventRecorder::class ), $this->createMock( RuleEvaluator::class ), $automatic_protection );

		$session_verifier = new SessionVerifier();
		$session_verifier->init(
			wc_get_container()->get( SessionDataCollector::class ),
			$api_client,
			$decision_handler,
			wc_get_container()->get( PaymentDataResolver::class ),
			$session_id_normalizer
		);

		$sut = new PayForOrderProtector();
		$sut->init( $session_verifier, $this->blocked_session_message, $this->blackbox_script_handler );

		$_POST['payment_method'] = 'cheque';
		foreach ( array( 'sess-first', 'sess-first', 'sess-first', 'sess-second' ) as $submitted ) {
			$_POST['wc_fraud_protection_session_id'] = $submitted;
			// Each submission is a request of its own, with the order read again.
			$sut->verify_and_block( wc_get_order( $this->order->get_id() ) );
		}

		$this->assertSame( array( 'sess-first', '', '', 'sess-second' ), $sent_ids );
	}

	/**
	 * @testdox verify_and_block() does not record the session ID as used when the verification did not complete.
	 */
	public function test_session_id_of_a_failed_verification_can_be_sent_again(): void {
		$this->order                             = \WC_Helper_Order::create_order();
		$_POST['wc_fraud_protection_session_id'] = 'sess-first-attempt';

		// A verification that fails open has no verified session ID.
		$this->session_verifier->method( 'last_verified_session_id' )->willReturn( '' );
		$this->session_verifier
			->expects( $this->exactly( 2 ) )
			->method( 'verify_session' )
			->with( 'sess-first-attempt', 'pay_for_order', $this->order->get_id(), $this->anything() )
			->willReturn( FraudDecision::Allow );

		$this->sut->verify_and_block( wc_get_order( $this->order->get_id() ) );
		$this->sut->verify_and_block( wc_get_order( $this->order->get_id() ) );
	}

	/**
	 * @testdox verify_and_block() stores the used session ID without changing the order passed to the hook.
	 */
	public function test_used_session_id_is_stored_without_changing_the_hook_order(): void {
		$this->order                             = \WC_Helper_Order::create_order();
		$_POST['wc_fraud_protection_session_id'] = 'sess-first-attempt';

		$this->session_verifier->method( 'last_verified_session_id' )->willReturn( 'sess-first-attempt' );
		$this->session_verifier->method( 'verify_session' )->willReturn( FraudDecision::Allow );

		// WooCommerce saves this order before it processes the payment, so a
		// change left on it would be written, or a failed write retried, then.
		$hook_order = wc_get_order( $this->order->get_id() );
		$this->sut->verify_and_block( $hook_order );

		$this->assertSame( '', $hook_order->get_meta( '_wc_fraud_protection_pay_for_order_used_session_ids' ) );
		$this->assertSame(
			array( 'sess-first-attempt' ),
			wc_get_order( $this->order->get_id() )->get_meta( '_wc_fraud_protection_pay_for_order_used_session_ids' )
		);
	}

	/**
	 * @testdox verify_and_block() keeps a bounded list of used session IDs on the order.
	 */
	public function test_used_session_ids_list_is_bounded(): void {
		$this->order = \WC_Helper_Order::create_order();

		$this->session_verifier->method( 'last_verified_session_id' )->willReturn( 'response-session-id' );
		$this->session_verifier->method( 'verify_session' )->willReturn( FraudDecision::Allow );

		for ( $attempt = 1; $attempt <= 12; $attempt++ ) {
			$_POST['wc_fraud_protection_session_id'] = 'sess-' . $attempt;
			$this->sut->verify_and_block( wc_get_order( $this->order->get_id() ) );
		}

		$used = wc_get_order( $this->order->get_id() )->get_meta( '_wc_fraud_protection_pay_for_order_used_session_ids' );

		$this->assertSame( array_map( fn( int $attempt ): string => 'sess-' . $attempt, range( 3, 12 ) ), $used );
	}
}
