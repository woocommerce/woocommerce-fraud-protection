<?php
/**
 * ShortcodeCheckoutProtectorTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtection;

use Automattic\WooCommerce\FraudProtection\ApiClient;
use Automattic\WooCommerce\FraudProtection\BlockedSessionNotice;
use Automattic\WooCommerce\FraudProtection\PaymentDataResolver;
use Automattic\WooCommerce\FraudProtection\Schemas\CardPaymentMethodData;
use Automattic\WooCommerce\FraudProtection\Schemas\PaymentMethodData;
use Automattic\WooCommerce\FraudProtection\SessionVerifier;
use Automattic\WooCommerce\FraudProtection\ShortcodeCheckoutProtector;
use Automattic\WooCommerce\RestApi\UnitTests\LoggerSpyTrait;
use WC_Unit_Test_Case;

/**
 * Tests for the ShortcodeCheckoutProtector class.
 *
 * @covers \Automattic\WooCommerce\FraudProtection\ShortcodeCheckoutProtector
 */
class ShortcodeCheckoutProtectorTest extends WC_Unit_Test_Case {

	use LoggerSpyTrait;

	/**
	 * The System Under Test.
	 *
	 * @var ShortcodeCheckoutProtector
	 */
	private ShortcodeCheckoutProtector $sut;

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
			->method( 'get_message_plaintext' )
			->willReturn( 'We are unable to process this request online. Please contact support (test@example.com) to complete your purchase.' );

		$this->sut = new ShortcodeCheckoutProtector();
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
	 * @testdox register() hooks woocommerce_after_checkout_validation and wp_enqueue_scripts.
	 */
	public function test_register_hooks(): void {
		$this->sut->register();

		$this->assertNotFalse(
			has_action( 'woocommerce_after_checkout_validation', array( $this->sut, 'verify_and_block' ) ),
			'woocommerce_after_checkout_validation hook should be registered'
		);
		$this->assertNotFalse(
			has_action( 'wp_enqueue_scripts', array( $this->sut, 'enqueue_shortcode_checkout_script' ) ),
			'wp_enqueue_scripts hook should be registered'
		);
	}

	/*
	|--------------------------------------------------------------------------
	| verify_and_block() Tests
	|--------------------------------------------------------------------------
	*/

	/**
	 * @testdox verify_and_block() passes session_id and request_data to SessionVerifier, allows on ALLOW.
	 */
	public function test_verify_allows_on_allow_decision(): void {
		$_POST['wc_fraud_protection_session_id'] = 'test-session-123';

		$posted_data = array(
			'billing_first_name' => 'Bob',
			'payment_method'     => 'stripe',
		);

		$this->session_verifier
			->expects( $this->once() )
			->method( 'verify_session' )
			->with( 'test-session-123', 0, 'shortcode_checkout', $this->isType( 'array' ), null )
			->willReturn( ApiClient::DECISION_ALLOW );

		$errors = new \WP_Error();
		$this->sut->verify_and_block( $posted_data, $errors );

		$this->assertEmpty( $errors->get_error_codes() );
	}

	/**
	 * @testdox verify_and_block() adds error on BLOCK decision.
	 */
	public function test_verify_adds_error_on_block_decision(): void {
		$_POST['wc_fraud_protection_session_id'] = 'test-session-456';

		$posted_data = array(
			'billing_first_name' => 'Jane',
			'payment_method'     => 'woocommerce_payments',
		);

		$this->session_verifier
			->expects( $this->once() )
			->method( 'verify_session' )
			->willReturn( ApiClient::DECISION_BLOCK );

		$errors = new \WP_Error();
		$this->sut->verify_and_block( $posted_data, $errors );

		$this->assertContains( 'woocommerce_checkout_error', $errors->get_error_codes() );
		$this->assertStringContainsString(
			'We are unable to process this request online',
			$errors->get_error_message( 'woocommerce_checkout_error' )
		);
	}

	/**
	 * @testdox verify_and_block() fails open when verify_session() throws.
	 */
	public function test_verify_fails_open_when_verify_session_throws(): void {
		$this->session_verifier
			->expects( $this->once() )
			->method( 'verify_session' )
			->willThrowException( new \TypeError( 'Unexpected type in collected data' ) );

		$errors = new \WP_Error();
		$this->sut->verify_and_block( array(), $errors );

		$this->assertEmpty( $errors->get_error_codes() );
		$this->assertLogged( 'error', 'verify_and_block failed, allowing checkout: Unexpected type in collected data' );
	}

	/**
	 * @testdox verify_and_block() fails open when resolver throws, still calls verify.
	 */
	public function test_verify_fails_open_when_resolver_throws(): void {
		$this->payment_data_resolver
			->expects( $this->once() )
			->method( 'resolve' )
			->willThrowException( new \RuntimeException( 'Compat layer exploded' ) );

		$this->session_verifier
			->expects( $this->once() )
			->method( 'verify_session' )
			->with( '', 0, 'shortcode_checkout', $this->isType( 'array' ), null )
			->willReturn( ApiClient::DECISION_ALLOW );

		$errors = new \WP_Error();
		$this->sut->verify_and_block( array( 'payment_method' => 'stripe' ), $errors );

		$this->assertEmpty( $errors->get_error_codes() );
		$this->assertLogged( 'warning', 'Payment data resolution failed: Compat layer exploded' );
	}

	/**
	 * @testdox verify_and_block() passes resolved PaymentMethodData to SessionVerifier.
	 */
	public function test_verify_passes_resolved_payment_data(): void {
		$_POST['wc_fraud_protection_session_id'] = 'test-session-600';

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
				'shortcode_checkout',
				$this->isType( 'array' ),
				$this->identicalTo( $resolved )
			)
			->willReturn( ApiClient::DECISION_ALLOW );

		$errors = new \WP_Error();
		$this->sut->verify_and_block(
			array( 'payment_method' => 'woocommerce_payments' ),
			$errors
		);

		$this->assertEmpty( $errors->get_error_codes() );
	}

	/*
	|--------------------------------------------------------------------------
	| build_request_data() Tests
	|--------------------------------------------------------------------------
	*/

	/**
	 * @testdox build_request_data structures billing/shipping addresses from flat POST keys.
	 */
	public function test_build_request_data_structures_addresses(): void {
		$posted_data = array(
			'billing_first_name'  => 'John',
			'billing_last_name'   => 'Doe',
			'billing_email'       => 'john@example.com',
			'billing_country'     => 'US',
			'shipping_first_name' => 'John',
			'shipping_last_name'  => 'Doe',
			'shipping_country'    => 'US',
			'payment_method'      => 'stripe',
		);

		$captured_request_data = null;

		$this->session_verifier
			->expects( $this->once() )
			->method( 'verify_session' )
			->willReturnCallback( function ( $session_id, $order_id, $source, $request_data ) use ( &$captured_request_data ) {
				$captured_request_data = $request_data;
				return ApiClient::DECISION_ALLOW;
			} );

		$errors = new \WP_Error();
		$this->sut->verify_and_block( $posted_data, $errors );

		$this->assertSame( 'John', $captured_request_data['billing_address']['first_name'] );
		$this->assertSame( 'Doe', $captured_request_data['billing_address']['last_name'] );
		$this->assertSame( 'john@example.com', $captured_request_data['billing_address']['email'] );
		$this->assertSame( 'US', $captured_request_data['billing_address']['country'] );

		$this->assertSame( 'John', $captured_request_data['shipping_address']['first_name'] );
		$this->assertSame( 'US', $captured_request_data['shipping_address']['country'] );

		$this->assertSame( 'stripe', $captured_request_data['payment_method'] );
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
			'wc_fraud_protection_session_id'  => 'sess-123',
			'billing_first_name'              => 'John',
			'shipping_first_name'             => 'John',
			'order_comments'                  => 'Leave at door',
			'account_username'                => 'john',
			'woocommerce_checkout_nonce'      => 'abc123',
			'_wpnonce'                        => 'xyz789',
			'payment_method'                  => 'stripe',
			'terms'                           => '1',
			'terms-field'                     => '1',
			'ship_to_different_address'       => '1',
			'wc_order_attribution_source_type' => 'typein',
			'wc_order_attribution_utm_source' => '(direct)',
			'wc-stripe-payment-method'        => 'pm_123',
			'wc-stripe-payment-token'         => 'new',
			'some_gateway_data'               => array( 'token' => 'tok_789' ),
		);

		$captured_request_data = null;

		$this->session_verifier
			->expects( $this->once() )
			->method( 'verify_session' )
			->willReturnCallback( function ( $session_id, $order_id, $source, $request_data ) use ( &$captured_request_data ) {
				$captured_request_data = $request_data;
				return ApiClient::DECISION_ALLOW;
			} );

		$errors = new \WP_Error();
		$this->sut->verify_and_block( array( 'payment_method' => 'stripe' ), $errors );

		$payment_data = $captured_request_data['payment_data'];

		// Should include gateway-specific keys (strings and arrays).
		$this->assertArrayHasKey( 'wc-stripe-payment-method', $payment_data );
		$this->assertSame( 'pm_123', $payment_data['wc-stripe-payment-method'] );
		$this->assertArrayHasKey( 'wc-stripe-payment-token', $payment_data );
		$this->assertSame( array( 'token' => 'tok_789' ), $payment_data['some_gateway_data'] );

		// Should exclude non-payment keys.
		$this->assertArrayNotHasKey( 'billing_first_name', $payment_data );
		$this->assertArrayNotHasKey( 'shipping_first_name', $payment_data );
		$this->assertArrayNotHasKey( 'order_comments', $payment_data );
		$this->assertArrayNotHasKey( 'account_username', $payment_data );
		$this->assertArrayNotHasKey( 'woocommerce_checkout_nonce', $payment_data );
		$this->assertArrayNotHasKey( '_wpnonce', $payment_data );
		$this->assertArrayNotHasKey( 'terms', $payment_data );
		$this->assertArrayNotHasKey( 'terms-field', $payment_data );
		$this->assertArrayNotHasKey( 'ship_to_different_address', $payment_data );
		$this->assertArrayNotHasKey( 'wc_fraud_protection_session_id', $payment_data );
		$this->assertArrayNotHasKey( 'wc_order_attribution_source_type', $payment_data );
		$this->assertArrayNotHasKey( 'wc_order_attribution_utm_source', $payment_data );
	}

}
