<?php
/**
 * BlocksCheckoutProtectorTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal;

use Automattic\WooCommerce\Internal\ApiClient;
use Automattic\WooCommerce\Internal\BlockedSessionNotice;
use Automattic\WooCommerce\Internal\BlocksCheckoutProtector;
use Automattic\WooCommerce\Internal\PaymentDataResolver;
use Automattic\WooCommerce\Internal\Schemas\CardPaymentMethodData;
use Automattic\WooCommerce\Internal\Schemas\PaymentMethodData;
use Automattic\WooCommerce\Internal\SessionVerifier;
use Automattic\WooCommerce\RestApi\UnitTests\LoggerSpyTrait;
use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;
use WC_Unit_Test_Case;

/**
 * Tests for the BlocksCheckoutProtector class.
 *
 * @covers \Automattic\WooCommerce\Internal\BlocksCheckoutProtector
 */
class BlocksCheckoutProtectorTest extends WC_Unit_Test_Case {

	use LoggerSpyTrait;

	/**
	 * The System Under Test.
	 *
	 * @var BlocksCheckoutProtector
	 */
	private BlocksCheckoutProtector $sut;

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

		$this->sut = new BlocksCheckoutProtector();
		$this->sut->init(
			$this->session_verifier,
			$this->blocked_session_notice,
			$this->payment_data_resolver
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
		$request_data = $this->create_mock_request(
			'test-session-123',
			array(
				'billing_address' => array( 'first_name' => 'Bob' ),
				'payment_method'  => 'stripe',
			)
		)->get_params();
		$this->set_request_data( $request_data );

		$order = $this->create_mock_order( 123 );

		$this->session_verifier
			->expects( $this->once() )
			->method( 'verify_session' )
			->with( 'test-session-123', 123, 'blocks_checkout', $request_data, null )
			->willReturn( ApiClient::DECISION_ALLOW );

		// Should not throw.
		$this->sut->verify_and_block( $order );
	}

	/**
	 * @testdox verify_and_block() throws RouteException when SessionVerifier returns BLOCK decision.
	 */
	public function test_verify_throws_on_block_decision(): void {
		$request_data = $this->create_mock_request(
			'test-session-456',
			array(
				'billing_address' => array( 'first_name' => 'Jane' ),
				'payment_method'  => 'woocommerce_payments',
			)
		)->get_params();
		$this->set_request_data( $request_data );

		$this->session_verifier
			->expects( $this->once() )
			->method( 'verify_session' )
			->with( 'test-session-456', 456, 'blocks_checkout', $request_data, null )
			->willReturn( ApiClient::DECISION_BLOCK );

		$order = $this->create_mock_order( 456 );

		$this->expectException( RouteException::class );
		$this->expectExceptionMessage( 'We are unable to process this request online. Please contact support (test@example.com) to complete your purchase.' );

		$this->sut->verify_and_block( $order );
	}

	/**
	 * @testdox verify_and_block() fails open when verify_session() throws a Throwable.
	 */
	public function test_verify_fails_open_when_verify_session_throws(): void {
		$order = $this->create_mock_order( 789 );

		$this->session_verifier
			->expects( $this->once() )
			->method( 'verify_session' )
			->willThrowException( new \TypeError( 'Unexpected type in collected data' ) );

		// Should not throw — fail-open allows checkout to proceed.
		$this->sut->verify_and_block( $order );

		$this->assertLogged( 'error', 'verify_and_block failed, allowing checkout: Unexpected type in collected data' );
	}

	/**
	 * @testdox verify_and_block() fails open with empty session_id (still calls verify).
	 */
	public function test_verify_fails_open_with_empty_session_id(): void {
		$order = $this->create_mock_order( 101 );

		// No extract_request_data called, so session_id is an empty string.
		$this->session_verifier
			->expects( $this->once() )
			->method( 'verify_session' )
			->with( '', 101, 'blocks_checkout', array(), null )
			->willReturn( ApiClient::DECISION_ALLOW );

		// Should not throw.
		$this->sut->verify_and_block( $order );
	}

	/*
	|--------------------------------------------------------------------------
	| extract_request_data() Tests
	|--------------------------------------------------------------------------
	*/

	/**
	 * @testdox extract_request_data() correctly extracts blackbox_session_id from request extensions.
	 */
	public function test_extract_request_data_extracts_session_id(): void {
		$request = $this->create_mock_request( 'test-session-303' );
		$order   = $this->create_mock_order( 303 );

		$this->sut->extract_request_data( $order, $request );

		$request_data = $this->get_request_data();
		$session_id   = $request_data['extensions']['woocommerce/fraud-protection']['blackbox_session_id'] ?? '';

		$this->assertSame( 'test-session-303', $session_id );
	}

	/**
	 * @testdox extract_request_data() handles missing extensions gracefully.
	 */
	public function test_extract_handles_missing_extensions(): void {
		$request = $this->create_mock_request( null );
		$order   = $this->create_mock_order( 404 );

		$this->sut->extract_request_data( $order, $request );

		$request_data = $this->get_request_data();

		// Extensions should be null when not provided in the request.
		$this->assertNull( $request_data['extensions'] );
	}

	/**
	 * @testdox extract_request_data() populates all fields including extensions.
	 */
	public function test_extract_request_data_populates_all_fields(): void {
		$request = $this->create_mock_request(
			'test-session-500',
			array(
				'billing_address'   => array( 'first_name' => 'John' ),
				'shipping_address'  => array( 'first_name' => 'John' ),
				'payment_method'    => 'woocommerce_payments',
				'payment_data'      => array( array( 'key' => 'wcpay-fingerprint', 'value' => 'abc123' ) ),
				'create_account'    => true,
				'additional_fields' => array( 'custom_field' => 'value' ),
				'extensions'        => array(
					'woocommerce/order-attribution' => array(
						'source_type' => 'typein',
						'referrer'    => '(none)',
						'utm_source'  => '(direct)',
					),
				),
			)
		);
		$order = $this->create_mock_order( 500 );

		$this->sut->extract_request_data( $order, $request );

		$request_data = $this->get_request_data();

		$this->assertSame( array( 'first_name' => 'John' ), $request_data['billing_address'] );
		$this->assertSame( array( 'first_name' => 'John' ), $request_data['shipping_address'] );
		$this->assertSame( 'woocommerce_payments', $request_data['payment_method'] );
		$this->assertSame( array( array( 'key' => 'wcpay-fingerprint', 'value' => 'abc123' ) ), $request_data['payment_data'] );
		$this->assertTrue( $request_data['create_account'] );
		$this->assertSame( array( 'custom_field' => 'value' ), $request_data['additional_fields'] );
		$this->assertSame( 'typein', $request_data['extensions']['woocommerce/order-attribution']['source_type'] );
	}

	/**
	 * @testdox extract_request_data() excludes customer_password from request_data.
	 */
	public function test_extract_request_data_excludes_customer_password(): void {
		$request = $this->create_mock_request(
			'test-session-501',
			array(
				'billing_address'    => array( 'first_name' => 'Jane' ),
				'customer_password'  => 'super_secret_password_123',
				'create_account'     => true,
			)
		);
		$order = $this->create_mock_order( 501 );

		$this->sut->extract_request_data( $order, $request );

		$request_data = $this->get_request_data();

		$this->assertArrayNotHasKey( 'customer_password', $request_data );
		$this->assertSame( array( 'first_name' => 'Jane' ), $request_data['billing_address'] );
		$this->assertTrue( $request_data['create_account'] );
	}

	/*
	|--------------------------------------------------------------------------
	| Payment Data Resolution Tests
	|--------------------------------------------------------------------------
	*/

	/**
	 * @testdox verify_and_block() passes resolved PaymentMethodData to SessionVerifier.
	 */
	public function test_verify_passes_resolved_payment_data(): void {
		$resolved = new PaymentMethodData(
			'woocommerce_payments',
			'card',
			false,
			new CardPaymentMethodData( 'visa', 'credit', '4242' )
		);

		$resolver = $this->createMock( PaymentDataResolver::class );
		$resolver
			->expects( $this->once() )
			->method( 'resolve' )
			->willReturn( $resolved );

		$this->session_verifier
			->expects( $this->once() )
			->method( 'verify_session' )
			->with(
				'test-session-600',
				600,
				'blocks_checkout',
				$this->isType( 'array' ),
				$this->identicalTo( $resolved )
			)
			->willReturn( ApiClient::DECISION_ALLOW );

		$sut = new BlocksCheckoutProtector();
		$sut->init( $this->session_verifier, $this->blocked_session_notice, $resolver );

		$request = $this->create_mock_request(
			'test-session-600',
			array(
				'payment_method' => 'woocommerce_payments',
				'payment_data'   => array(
					array(
						'key'   => 'wcpay-payment-method',
						'value' => 'pm_123',
					),
				),
			)
		);
		$order = $this->create_mock_order( 600 );

		$sut->extract_request_data( $order, $request );
		$sut->verify_and_block( $order );
	}

	/**
	 * @testdox verify_and_block() normalizes [{key, value}, ...] payment_data to flat map before calling resolver.
	 */
	public function test_verify_normalizes_payment_data_before_resolve(): void {
		$this->payment_data_resolver
			->expects( $this->once() )
			->method( 'resolve' )
			->with(
				'stripe',
				$this->equalTo( array( 'wc-stripe-payment-method' => 'pm_123' ) )
			);

		$this->session_verifier
			->method( 'verify_session' )
			->willReturn( ApiClient::DECISION_ALLOW );

		$request = $this->create_mock_request(
			'test-session-normalize',
			array(
				'payment_method' => 'stripe',
				'payment_data'   => array(
					array(
						'key'   => 'wc-stripe-payment-method',
						'value' => 'pm_123',
					),
				),
			)
		);
		$order = $this->create_mock_order( 800 );

		$this->sut->extract_request_data( $order, $request );
		$this->sut->verify_and_block( $order );
	}

	/**
	 * @testdox verify_and_block() passes null payment when resolver returns null.
	 */
	public function test_verify_passes_null_payment_when_resolver_returns_null(): void {
		$this->session_verifier
			->expects( $this->once() )
			->method( 'verify_session' )
			->with( 'test-session-601', 601, 'blocks_checkout', $this->isType( 'array' ), null )
			->willReturn( ApiClient::DECISION_ALLOW );

		$request = $this->create_mock_request(
			'test-session-601',
			array(
				'payment_method' => 'unknown_gateway',
				'payment_data'   => array(),
			)
		);
		$order = $this->create_mock_order( 601 );

		$this->sut->extract_request_data( $order, $request );
		$this->sut->verify_and_block( $order );
	}

	/**
	 * @testdox verify_and_block() fails open when payment data resolution throws, still calls verify.
	 */
	public function test_verify_fails_open_when_resolver_throws(): void {
		$resolver = $this->createMock( PaymentDataResolver::class );
		$resolver
			->expects( $this->once() )
			->method( 'resolve' )
			->willThrowException( new \RuntimeException( 'Compat layer exploded' ) );

		$this->session_verifier
			->expects( $this->once() )
			->method( 'verify_session' )
			->with( 'test-session-700', 700, 'blocks_checkout', $this->isType( 'array' ), null )
			->willReturn( ApiClient::DECISION_ALLOW );

		$sut = new BlocksCheckoutProtector();
		$sut->init( $this->session_verifier, $this->blocked_session_notice, $resolver );

		$request = $this->create_mock_request(
			'test-session-700',
			array(
				'payment_method' => 'stripe',
				'payment_data'   => array(
					array(
						'key'   => 'wc-stripe-payment-method',
						'value' => 'pm_123',
					),
				),
			)
		);
		$order = $this->create_mock_order( 700 );

		$sut->extract_request_data( $order, $request );
		$sut->verify_and_block( $order );

		$this->assertLogged( 'warning', 'Payment data resolution failed: Compat layer exploded' );
	}

	/*
	|--------------------------------------------------------------------------
	| Helpers
	|--------------------------------------------------------------------------
	*/

	/**
	 * Create a mock WC_Order.
	 *
	 * @param int $order_id The order ID.
	 * @return \WC_Order
	 */
	private function create_mock_order( int $order_id ): \WC_Order {
		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_id' )->willReturn( $order_id );
		return $order;
	}

	/**
	 * Create a WP_REST_Request with checkout data.
	 *
	 * @param string|null $session_id The blackbox session ID, or null for no session_id.
	 * @param array       $params     Additional request parameters.
	 * @return \WP_REST_Request
	 */
	private function create_mock_request( ?string $session_id, array $params = array() ): \WP_REST_Request {
		$request = new \WP_REST_Request();

		if ( null !== $session_id ) {
			$extensions = $params['extensions'] ?? array();
			$extensions['woocommerce/fraud-protection'] = array(
				'blackbox_session_id' => $session_id,
			);
			$request->set_param( 'extensions', $extensions );
		}

		foreach ( $params as $key => $value ) {
			if ( 'extensions' !== $key ) {
				$request->set_param( $key, $value );
			}
		}

		return $request;
	}

	/**
	 * Get the request_data from the SUT via reflection.
	 *
	 * @return array
	 */
	private function get_request_data(): array {
		$property = new \ReflectionProperty( BlocksCheckoutProtector::class, 'request_data' );
		$property->setAccessible( true );
		return $property->getValue( $this->sut );
	}

	/**
	 * Set the request_data on the SUT via reflection.
	 *
	 * @param array $request_data The request data to set.
	 */
	private function set_request_data( array $request_data ): void {
		$property = new \ReflectionProperty( BlocksCheckoutProtector::class, 'request_data' );
		$property->setAccessible( true );
		$property->setValue( $this->sut, $request_data );
	}
}
