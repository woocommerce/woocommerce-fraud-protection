<?php
/**
 * AddPaymentMethodProtectorTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\FraudProtection\FraudProtection;

use Automattic\WooCommerce\FraudProtection\AddPaymentMethodProtector;
use Automattic\WooCommerce\FraudProtection\ApiClient;
use Automattic\WooCommerce\FraudProtection\BlockedSessionNotice;
use Automattic\WooCommerce\FraudProtection\PaymentDataResolver;
use Automattic\WooCommerce\FraudProtection\Schemas\CardPaymentMethodData;
use Automattic\WooCommerce\FraudProtection\Schemas\PaymentMethodData;
use Automattic\WooCommerce\FraudProtection\SessionVerifier;
use Automattic\WooCommerce\RestApi\UnitTests\LoggerSpyTrait;
use WC_Unit_Test_Case;

/**
 * Tests for the AddPaymentMethodProtector class.
 *
 * @covers \Automattic\WooCommerce\FraudProtection\AddPaymentMethodProtector
 */
class AddPaymentMethodProtectorTest extends WC_Unit_Test_Case {

	use LoggerSpyTrait;

	/**
	 * The System Under Test.
	 *
	 * @var AddPaymentMethodProtector
	 */
	private AddPaymentMethodProtector $sut;

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
	 * Mock payment data resolver.
	 *
	 * @var PaymentDataResolver&\PHPUnit\Framework\MockObject\MockObject
	 */
	private $payment_data_resolver;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->session_verifier       = $this->createMock( SessionVerifier::class );
		$this->blocked_session_notice = $this->createMock( BlockedSessionNotice::class );
		$this->payment_data_resolver  = $this->createMock( PaymentDataResolver::class );

		$this->blocked_session_notice
			->method( 'get_message_html' )
			->willReturn( 'We are unable to process this request online. Please <a href="mailto:test@example.com">contact support (test@example.com)</a> for assistance.' );

		$this->sut = new AddPaymentMethodProtector();
		$this->sut->init(
			$this->session_verifier,
			$this->blocked_session_notice,
			$this->payment_data_resolver
		);
	}

	/**
	 * Tear down after each test.
	 */
	public function tearDown(): void {
		$_POST = array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		parent::tearDown();
	}

	/*
	|--------------------------------------------------------------------------
	| register() Tests
	|--------------------------------------------------------------------------
	*/

	/**
	 * @testdox register() hooks woocommerce_add_payment_method_form_is_valid and wp_enqueue_scripts.
	 */
	public function test_register_hooks(): void {
		$this->sut->register();

		$this->assertNotFalse(
			has_filter( 'woocommerce_add_payment_method_form_is_valid', array( $this->sut, 'verify_and_block' ) ),
			'woocommerce_add_payment_method_form_is_valid filter should be registered'
		);
		$this->assertNotFalse(
			has_action( 'wp_enqueue_scripts', array( $this->sut, 'enqueue_add_payment_method_script' ) ),
			'wp_enqueue_scripts hook should be registered'
		);
	}

	/*
	|--------------------------------------------------------------------------
	| verify_and_block() Tests
	|--------------------------------------------------------------------------
	*/

	/**
	 * @testdox verify_and_block() passes session_id and request_data to SessionVerifier, returns true on ALLOW.
	 */
	public function test_verify_returns_true_on_allow_decision(): void {
		$_POST['wc_fraud_protection_session_id'] = 'test-session-123';
		$_POST['payment_method']                 = 'stripe';

		$this->session_verifier
			->expects( $this->once() )
			->method( 'verify_session' )
			->with( 'test-session-123', 0, 'add_payment_method', $this->isType( 'array' ), null )
			->willReturn( ApiClient::DECISION_ALLOW );

		$result = $this->sut->verify_and_block( true );

		$this->assertTrue( $result );
	}

	/**
	 * @testdox verify_and_block() returns false and adds notice on BLOCK decision.
	 */
	public function test_verify_returns_false_and_adds_notice_on_block_decision(): void {
		$_POST['wc_fraud_protection_session_id'] = 'test-session-456';
		$_POST['payment_method']                 = 'woocommerce_payments';

		$this->session_verifier
			->expects( $this->once() )
			->method( 'verify_session' )
			->willReturn( ApiClient::DECISION_BLOCK );

		$result = $this->sut->verify_and_block( true );

		$this->assertFalse( $result );
		$this->assertTrue(
			wc_has_notice(
				'We are unable to process this request online. Please <a href="mailto:test@example.com">contact support (test@example.com)</a> for assistance.',
				'error'
			)
		);
	}

	/**
	 * @testdox verify_and_block() uses generic context for blocked message.
	 */
	public function test_verify_uses_generic_context_for_blocked_message(): void {
		$_POST['payment_method'] = 'stripe';

		$this->blocked_session_notice
			->expects( $this->once() )
			->method( 'get_message_html' )
			->with( 'generic' );

		$this->session_verifier
			->method( 'verify_session' )
			->willReturn( ApiClient::DECISION_BLOCK );

		$this->sut->verify_and_block( true );
	}

	/**
	 * @testdox verify_and_block() fails open when verify_session() throws.
	 */
	public function test_verify_fails_open_when_verify_session_throws(): void {
		$this->session_verifier
			->expects( $this->once() )
			->method( 'verify_session' )
			->willThrowException( new \TypeError( 'Unexpected type in collected data' ) );

		$result = $this->sut->verify_and_block( true );

		$this->assertTrue( $result );
		$this->assertLogged( 'error', 'verify_and_block failed, allowing add payment method: Unexpected type in collected data' );
	}

	/**
	 * @testdox verify_and_block() fails open when resolver throws, still calls verify.
	 */
	public function test_verify_fails_open_when_resolver_throws(): void {
		$_POST['payment_method'] = 'stripe';

		$this->payment_data_resolver
			->expects( $this->once() )
			->method( 'resolve' )
			->willThrowException( new \RuntimeException( 'Compat layer exploded' ) );

		$this->session_verifier
			->expects( $this->once() )
			->method( 'verify_session' )
			->with( '', 0, 'add_payment_method', $this->isType( 'array' ), null )
			->willReturn( ApiClient::DECISION_ALLOW );

		$result = $this->sut->verify_and_block( true );

		$this->assertTrue( $result );
		$this->assertLogged( 'warning', 'Payment data resolution failed: Compat layer exploded' );
	}

	/**
	 * @testdox verify_and_block() passes resolved PaymentMethodData to SessionVerifier.
	 */
	public function test_verify_passes_resolved_payment_data(): void {
		$_POST['wc_fraud_protection_session_id'] = 'test-session-600';
		$_POST['payment_method']                 = 'woocommerce_payments';

		$resolved = new PaymentMethodData(
			'woocommerce_payments',
			'card',
			false,
			new CardPaymentMethodData( 'visa', 'credit', '4242' )
		);

		$this->payment_data_resolver
			->expects( $this->once() )
			->method( 'resolve' )
			->willReturn( $resolved );

		$this->session_verifier
			->expects( $this->once() )
			->method( 'verify_session' )
			->with(
				'test-session-600',
				0,
				'add_payment_method',
				$this->isType( 'array' ),
				$this->identicalTo( $resolved )
			)
			->willReturn( ApiClient::DECISION_ALLOW );

		$result = $this->sut->verify_and_block( true );

		$this->assertTrue( $result );
	}

	/**
	 * @testdox verify_and_block() respects prior validation failure and skips verification.
	 */
	public function test_verify_respects_prior_validation_failure(): void {
		$this->session_verifier
			->expects( $this->never() )
			->method( 'verify_session' );

		$result = $this->sut->verify_and_block( false );

		$this->assertFalse( $result );
	}

	/*
	|--------------------------------------------------------------------------
	| build_request_data() Tests
	|--------------------------------------------------------------------------
	*/

	/**
	 * @testdox build_request_data includes payment_method from POST.
	 */
	public function test_build_request_data_includes_payment_method(): void {
		$_POST['payment_method'] = 'stripe';

		$captured_request_data = null;

		$this->session_verifier
			->expects( $this->once() )
			->method( 'verify_session' )
			->willReturnCallback( function ( $session_id, $order_id, $source, $request_data ) use ( &$captured_request_data ) {
				$captured_request_data = $request_data;
				return ApiClient::DECISION_ALLOW;
			} );

		$this->sut->verify_and_block( true );

		$this->assertSame( 'stripe', $captured_request_data['payment_method'] );
	}

	/**
	 * @testdox build_request_data has no billing or shipping address fields.
	 */
	public function test_build_request_data_has_no_address_fields(): void {
		$_POST['payment_method'] = 'stripe';

		$captured_request_data = null;

		$this->session_verifier
			->expects( $this->once() )
			->method( 'verify_session' )
			->willReturnCallback( function ( $session_id, $order_id, $source, $request_data ) use ( &$captured_request_data ) {
				$captured_request_data = $request_data;
				return ApiClient::DECISION_ALLOW;
			} );

		$this->sut->verify_and_block( true );

		$this->assertArrayNotHasKey( 'billing_address', $captured_request_data );
		$this->assertArrayNotHasKey( 'shipping_address', $captured_request_data );
	}

	/*
	|--------------------------------------------------------------------------
	| extract_payment_data() Tests
	|--------------------------------------------------------------------------
	*/

	/**
	 * @testdox extract_payment_data excludes known non-payment keys and prefixes.
	 */
	public function test_extract_payment_data_excludes_non_payment_keys(): void {
		$_POST = array(
			'wc_fraud_protection_session_id'       => 'sess-123',
			'woocommerce_add_payment_method'       => '1',
			'woocommerce-add-payment-method-nonce' => 'abc123',
			'_wpnonce'                             => 'xyz789',
			'payment_method'                       => 'stripe',
			'wc-stripe-payment-method'             => 'pm_123',
			'wc-stripe-payment-token'              => 'new',
		);

		$captured_request_data = null;

		$this->session_verifier
			->expects( $this->once() )
			->method( 'verify_session' )
			->willReturnCallback( function ( $session_id, $order_id, $source, $request_data ) use ( &$captured_request_data ) {
				$captured_request_data = $request_data;
				return ApiClient::DECISION_ALLOW;
			} );

		$this->sut->verify_and_block( true );

		$payment_data = $captured_request_data['payment_data'];

		// Should include gateway-specific keys.
		$this->assertArrayHasKey( 'wc-stripe-payment-method', $payment_data );
		$this->assertSame( 'pm_123', $payment_data['wc-stripe-payment-method'] );
		$this->assertArrayHasKey( 'wc-stripe-payment-token', $payment_data );

		// Should exclude non-payment keys.
		$this->assertArrayNotHasKey( 'wc_fraud_protection_session_id', $payment_data );
		$this->assertArrayNotHasKey( 'woocommerce_add_payment_method', $payment_data );
		$this->assertArrayNotHasKey( 'woocommerce-add-payment-method-nonce', $payment_data );
		$this->assertArrayNotHasKey( '_wpnonce', $payment_data );
	}
}
