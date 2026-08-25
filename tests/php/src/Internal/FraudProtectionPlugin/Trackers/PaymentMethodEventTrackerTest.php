<?php
/**
 * PaymentMethodEventTrackerTest class file.
 *
 * @package WooCommerce\Tests
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin\Trackers;

use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Sessions\SessionDataCollector;
use Automattic\WooCommerce\FraudProtection\Tests\Support\FraudProtectionLoggerForTests;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Trackers\PaymentMethodEventTracker;

/**
 * Tests for the PaymentMethodEventTracker class.
 *
 * @covers \Automattic\WooCommerce\Internal\FraudProtectionPlugin\Trackers\PaymentMethodEventTracker
 */
class PaymentMethodEventTrackerTest extends FraudProtectionUnitTestCase {

	/**
	 * The System Under Test.
	 *
	 * @var PaymentMethodEventTracker
	 */
	private $sut;

	/**
	 * Mock session data collector.
	 *
	 * @var SessionDataCollector|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $mock_collector;

	/**
	 * In-memory logger injected into the system under test.
	 *
	 * @var FraudProtectionLoggerForTests
	 */
	private FraudProtectionLoggerForTests $logger;

	/**
	 * Setup test.
	 */
	public function setUp(): void {
		parent::setUp();

		// Create mock.
		$this->mock_collector = $this->createMock( SessionDataCollector::class );
		$this->logger         = new FraudProtectionLoggerForTests();

		// Create system under test with mock.
		$this->sut = new PaymentMethodEventTracker();
		$this->sut->init( $this->mock_collector, $this->logger );
	}

	/**
	 * Do not install the controller logging spy. The tracker receives its logger directly.
	 *
	 * @return bool
	 */
	protected function uses_logging_spy(): bool {
		return false;
	}

	/**
	 * @testdox register() registers all payment method event tracking hooks.
	 */
	public function test_register_registers_hooks(): void {
		$this->sut->register();

		$this->assertNotFalse(
			has_action( 'woocommerce_new_payment_token', array( $this->sut, 'track_payment_method_added' ) ),
			'woocommerce_new_payment_token hook should be registered'
		);
		$this->assertNotFalse(
			has_action( 'before_woocommerce_add_payment_method', array( $this->sut, 'track_add_payment_method_page_loaded' ) ),
			'before_woocommerce_add_payment_method hook should be registered'
		);
	}

	/**
	 * Test add payment method page loaded collects data.
	 *
	 * @testdox track_add_payment_method_page_loaded() collects session data with empty event data.
	 */
	public function test_track_add_payment_method_page_loaded_collects_data(): void {
		$this->mock_collector
			->expects( $this->once() )
			->method( 'collect' )
			->with(
				$this->equalTo( 'add_payment_method_page_loaded' ),
				$this->equalTo( array() )
			);

		$this->sut->track_add_payment_method_page_loaded();
	}

	/**
	 * Payment method callbacks and their registered hooks.
	 *
	 * @return array<string, array{string, string}>
	 */
	public function payment_method_tracker_callbacks(): array {
		return array(
			'add payment method page loaded' => array( 'track_add_payment_method_page_loaded', 'before_woocommerce_add_payment_method' ),
			'payment method added'          => array( 'track_payment_method_added', 'woocommerce_new_payment_token' ),
		);
	}

	/**
	 * @testdox Every payment method tracker callback contains a collector failure.
	 * @dataProvider payment_method_tracker_callbacks
	 *
	 * @param string $callback Callback method name.
	 * @param string $hook     Registered hook name.
	 */
	public function test_tracker_callbacks_contain_failures( string $callback, string $hook ): void {
		$this->mock_collector
			->method( 'collect' )
			->willThrowException( new \RuntimeException( 'collector failed' ) );

		if ( 'track_add_payment_method_page_loaded' === $callback ) {
			$this->sut->track_add_payment_method_page_loaded();
		} else {
			$token = new \WC_Payment_Token_CC();
			$token->set_token( 'test_token_failure' );
			$token->set_gateway_id( 'stripe' );
			$token->set_card_type( 'visa' );
			$token->set_last4( '4242' );
			$this->sut->track_payment_method_added( 1, $token );
		}

		$this->assertCount( 1, $this->logger->entries );
		$entry = $this->logger->entries[0];
		$this->assertSame( 'error', $entry['level'] );
		$this->assertTrue( $entry['forwarded'] );
		$this->assertSame( 'payment_method_event_tracker', $entry['context']['event_source'] );
		$this->assertSame( $hook, $entry['context']['hook'] );
		$this->assertSame( \RuntimeException::class, $entry['context']['exception_class'] );
	}

	/**
	 * @testdox A payment method tracker failure before collection is contained and logged.
	 */
	public function test_tracker_pre_collection_failure_is_contained(): void {
		$token = $this->createMock( \WC_Payment_Token::class );
		$token
			->method( 'get_id' )
			->willThrowException( new \RuntimeException( 'token read failed' ) );

		$this->sut->track_payment_method_added( 1, $token );

		$this->assertCount( 1, $this->logger->entries );
		$this->assertSame( 'woocommerce_new_payment_token', $this->logger->entries[0]['context']['hook'] );
		$this->assertSame( \RuntimeException::class, $this->logger->entries[0]['context']['exception_class'] );
		$this->assertTrue( $this->logger->entries[0]['forwarded'] );
	}

	/**
	 * Test payment method added collects data.
	 *
	 * @testdox track_payment_method_added() collects session data with token details.
	 */
	public function test_track_payment_method_added_collects_data(): void {
		$user_id = $this->factory->user->create();
		$this->assertIsInt( $user_id );

		$token = new \WC_Payment_Token_CC();
		$token->set_token( 'test_token_123' );
		$token->set_gateway_id( 'stripe' );
		$token->set_card_type( 'visa' );
		$token->set_last4( '4242' );
		$token->set_expiry_month( '12' );
		$token->set_expiry_year( '2025' );
		$token->set_user_id( $user_id );
		$token->save();

		$this->mock_collector
			->expects( $this->once() )
			->method( 'collect' )
			->with(
				$this->equalTo( 'payment_method_added' ),
				$this->callback(
					function ( $event_data ) use ( $token ) {
						$this->assertArrayHasKey( 'action', $event_data );
						$this->assertEquals( 'added', $event_data['action'] );
						$this->assertArrayHasKey( 'token_id', $event_data );
						$this->assertEquals( $token->get_id(), $event_data['token_id'] );
						$this->assertArrayHasKey( 'token_type', $event_data );
						$this->assertArrayHasKey( 'gateway_id', $event_data );
						$this->assertEquals( 'stripe', $event_data['gateway_id'] );
						$this->assertArrayHasKey( 'card_type', $event_data );
						$this->assertEquals( 'visa', $event_data['card_type'] );
						$this->assertArrayHasKey( 'card_last4', $event_data );
						$this->assertEquals( '4242', $event_data['card_last4'] );
						return true;
					}
				)
			);

		$this->sut->track_payment_method_added( $token->get_id(), $token );

		$token->delete();
	}

	/**
	 * Test payment method added includes expiry for CC tokens.
	 *
	 * @testdox track_payment_method_added() includes expiry info for CC tokens.
	 */
	public function test_track_payment_method_added_includes_expiry_for_cc_tokens(): void {
		$user_id = $this->factory->user->create();
		$this->assertIsInt( $user_id );

		$token = new \WC_Payment_Token_CC();
		$token->set_token( 'test_token_456' );
		$token->set_gateway_id( 'stripe' );
		$token->set_card_type( 'mastercard' );
		$token->set_last4( '5555' );
		$token->set_expiry_month( '06' );
		$token->set_expiry_year( '2028' );
		$token->set_user_id( $user_id );
		$token->save();

		$this->mock_collector
			->expects( $this->once() )
			->method( 'collect' )
			->with(
				$this->equalTo( 'payment_method_added' ),
				$this->callback(
					function ( $event_data ) {
						$this->assertArrayHasKey( 'expiry_month', $event_data );
						$this->assertEquals( '06', $event_data['expiry_month'] );
						$this->assertArrayHasKey( 'expiry_year', $event_data );
						$this->assertEquals( '2028', $event_data['expiry_year'] );
						return true;
					}
				)
			);

		$this->sut->track_payment_method_added( $token->get_id(), $token );

		$token->delete();
	}

	/**
	 * @testdox Saving a payment token succeeds when its tracker fails.
	 */
	public function test_payment_token_save_continues_when_tracker_fails(): void {
		$user_id = $this->factory->user->create();
		$this->assertIsInt( $user_id );

		$this->mock_collector
			->method( 'collect' )
			->willThrowException( new \RuntimeException( 'collector failed' ) );
		$this->sut->register();

		$token = new \WC_Payment_Token_CC();
		$token->set_token( 'test_token_persistence' );
		$token->set_gateway_id( 'stripe' );
		$token->set_card_type( 'visa' );
		$token->set_last4( '4242' );
		$token->set_user_id( $user_id );
		$token_id = $token->save();

		$this->assertIsInt( $token_id );
		$this->assertGreaterThan( 0, $token_id );
		$this->assertInstanceOf( \WC_Payment_Token_CC::class, \WC_Payment_Tokens::get( $token_id ) );
		$this->assertSame( 'woocommerce_new_payment_token', $this->logger->entries[0]['context']['hook'] );
		$this->assertTrue( $this->logger->entries[0]['forwarded'] );

		$token->delete();
	}
}
