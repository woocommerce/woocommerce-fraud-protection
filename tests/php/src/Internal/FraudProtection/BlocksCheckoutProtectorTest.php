<?php
/**
 * BlocksCheckoutProtectorTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtection;

use Automattic\WooCommerce\Internal\FraudProtection\ApiClient;
use Automattic\WooCommerce\Internal\FraudProtection\BlockedSessionNotice;
use Automattic\WooCommerce\Internal\FraudProtection\BlocksCheckoutProtector;
use Automattic\WooCommerce\Internal\FraudProtection\SessionVerifier;
use Automattic\WooCommerce\RestApi\UnitTests\LoggerSpyTrait;
use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;
use WC_Unit_Test_Case;

/**
 * Tests for the BlocksCheckoutProtector class.
 *
 * @covers \Automattic\WooCommerce\Internal\FraudProtection\BlocksCheckoutProtector
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
	 * @var SessionVerifier
	 */
	private $session_verifier;

	/**
	 * Mock blocked session notice.
	 *
	 * @var BlockedSessionNotice
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

		$this->sut = new BlocksCheckoutProtector();
		$this->sut->init(
			$this->session_verifier,
			$this->blocked_session_notice
		);
	}

	/*
	|--------------------------------------------------------------------------
	| verify_and_block() Tests
	|--------------------------------------------------------------------------
	*/

	/**
	 * @testdox verify_and_block() allows checkout when SessionVerifier returns ALLOW decision.
	 */
	public function test_verify_allows_on_allow_decision(): void {
		$this->set_blackbox_session_id( 'test-session-123' );

		$order = $this->create_mock_order( 123 );

		$this->session_verifier
			->expects( $this->once() )
			->method( 'verify_session' )
			->with( 'test-session-123', 123 )
			->willReturn( ApiClient::DECISION_ALLOW );

		// Should not throw.
		$this->sut->verify_and_block( $order );
	}

	/**
	 * @testdox verify_and_block() throws RouteException when SessionVerifier returns BLOCK decision.
	 */
	public function test_verify_throws_on_block_decision(): void {
		$this->set_blackbox_session_id( 'test-session-456' );

		$this->session_verifier
			->expects( $this->once() )
			->method( 'verify_session' )
			->with( 'test-session-456', 456 )
			->willReturn( ApiClient::DECISION_BLOCK );

		$order = $this->create_mock_order( 456 );

		$this->expectException( RouteException::class );
		$this->expectExceptionMessage( 'We are unable to process this request online. Please contact support (test@example.com) to complete your purchase.' );

		$this->sut->verify_and_block( $order );
	}

	/**
	 * @testdox verify_and_block() fails open with empty session_id (still calls verify).
	 */
	public function test_verify_fails_open_with_empty_session_id(): void {
		$order = $this->create_mock_order( 101 );

		// No extract_session_id called, so blackbox_session_id is empty string.
		$this->session_verifier
			->expects( $this->once() )
			->method( 'verify_session' )
			->with( '', 101 )
			->willReturn( ApiClient::DECISION_ALLOW );

		// Should not throw.
		$this->sut->verify_and_block( $order );
	}

	/*
	|--------------------------------------------------------------------------
	| extract_session_id() Tests
	|--------------------------------------------------------------------------
	*/

	/**
	 * @testdox extract_session_id() correctly extracts blackbox_session_id from request extensions.
	 */
	public function test_extract_session_id_from_extensions(): void {
		$request = $this->create_mock_request( 'test-session-303' );
		$order   = $this->create_mock_order( 303 );

		$this->sut->extract_session_id( $order, $request );

		$this->assertSame( 'test-session-303', $this->get_blackbox_session_id() );
	}

	/**
	 * @testdox extract_session_id() handles missing extensions gracefully.
	 */
	public function test_extract_handles_missing_extensions(): void {
		$request = $this->create_mock_request( null );
		$order   = $this->create_mock_order( 404 );

		$this->sut->extract_session_id( $order, $request );

		// Session ID remains at default empty string.
		$this->assertSame( '', $this->get_blackbox_session_id() );
	}

	/*
	|--------------------------------------------------------------------------
	| Helpers
	|--------------------------------------------------------------------------
	*/

	/**
	 * Set the blackbox_session_id on the SUT via reflection.
	 *
	 * @param string $session_id The session ID to set.
	 */
	private function set_blackbox_session_id( string $session_id ): void {
		$property = new \ReflectionProperty( BlocksCheckoutProtector::class, 'blackbox_session_id' );
		$property->setAccessible( true );
		$property->setValue( $this->sut, $session_id );
	}

	/**
	 * Get the blackbox_session_id from the SUT via reflection.
	 *
	 * @return string
	 */
	private function get_blackbox_session_id(): string {
		$property = new \ReflectionProperty( BlocksCheckoutProtector::class, 'blackbox_session_id' );
		$property->setAccessible( true );
		return $property->getValue( $this->sut );
	}

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
	 * Create a WP_REST_Request with extension data.
	 *
	 * @param string|null $session_id The blackbox session ID, or null for no extensions.
	 * @return \WP_REST_Request
	 */
	private function create_mock_request( ?string $session_id ): \WP_REST_Request {
		$request = new \WP_REST_Request();
		if ( null !== $session_id ) {
			$request->set_param(
				'extensions',
				array(
					'woocommerce/fraud-protection' => array(
						'blackbox_session_id' => $session_id,
					),
				)
			);
		}
		return $request;
	}
}
