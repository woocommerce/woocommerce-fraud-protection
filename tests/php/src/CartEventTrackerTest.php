<?php
/**
 * CartEventTrackerTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal;

use Automattic\WooCommerce\FraudProtection\CartEventTracker;
use Automattic\WooCommerce\FraudProtection\SessionDataCollector;

/**
 * Tests for CartEventTracker.
 *
 * @covers \Automattic\WooCommerce\FraudProtection\CartEventTracker
 */
class CartEventTrackerTest extends \WC_Unit_Test_Case {

	/**
	 * The system under test.
	 *
	 * @var CartEventTracker
	 */
	private $sut;

	/**
	 * Mock session data collector.
	 *
	 * @var SessionDataCollector|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $mock_collector;

	/**
	 * Test product.
	 *
	 * @var \WC_Product
	 */
	private $test_product;

	/**
	 * Runs before each test.
	 */
	public function setUp(): void {
		parent::setUp();

		// Ensure WooCommerce cart and session are available.
		if ( ! did_action( 'woocommerce_load_cart_from_session' ) && function_exists( 'wc_load_cart' ) ) {
			wc_load_cart();
		}

		// Create mock.
		$this->mock_collector = $this->createMock( SessionDataCollector::class );

		// Create system under test.
		$this->sut = new CartEventTracker();
		$this->sut->init( $this->mock_collector );

		// Create a test product.
		$this->test_product = \WC_Helper_Product::create_simple_product();

		// Empty cart before each test.
		WC()->cart->empty_cart();
	}

	/**
	 * @testdox register() registers all cart event tracking hooks.
	 */
	public function test_register_registers_hooks(): void {
		$this->sut->register();

		$this->assertNotFalse(
			has_action( 'woocommerce_cart_item_added_from_user_request', array( $this->sut, 'track_cart_item_added' ) ),
			'woocommerce_cart_item_added_from_user_request hook should be registered'
		);
		$this->assertNotFalse(
			has_action( 'woocommerce_cart_item_removed_from_user_request', array( $this->sut, 'track_cart_item_removed' ) ),
			'woocommerce_cart_item_removed_from_user_request hook should be registered'
		);
		$this->assertNotFalse(
			has_action( 'woocommerce_cart_item_restored', array( $this->sut, 'track_cart_item_restored' ) ),
			'woocommerce_cart_item_restored hook should be registered'
		);
		$this->assertNotFalse(
			has_action( 'woocommerce_cart_item_updated_from_user_request', array( $this->sut, 'track_cart_item_updated' ) ),
			'woocommerce_cart_item_updated_from_user_request hook should be registered'
		);
		$this->assertNotFalse(
			has_action( 'template_redirect', array( $this->sut, 'track_cart_page_loaded' ) ),
			'template_redirect hook should be registered'
		);
	}

	/**
	 * @testdox track_cart_page_loaded() collects session data when on cart page.
	 */
	public function test_track_cart_page_loaded_collects_data_on_cart(): void {
		add_filter( 'woocommerce_is_cart', '__return_true' );

		$this->mock_collector
			->expects( $this->once() )
			->method( 'collect' )
			->with(
				$this->equalTo( 'cart_page_loaded' ),
				$this->equalTo( array() )
			);

		$this->sut->track_cart_page_loaded();

		remove_filter( 'woocommerce_is_cart', '__return_true' );
	}

	/**
	 * @testdox track_cart_page_loaded() does not collect session data when not on cart page.
	 */
	public function test_track_cart_page_loaded_does_not_collect_when_not_cart(): void {
		$this->mock_collector
			->expects( $this->never() )
			->method( 'collect' );

		$this->sut->track_cart_page_loaded();
	}

	/**
	 * @testdox track_cart_item_added() collects session data with event details.
	 */
	public function test_track_cart_item_added_collects_data(): void {
		$this->mock_collector
			->expects( $this->once() )
			->method( 'collect' )
			->with(
				$this->equalTo( 'cart_item_added' ),
				$this->callback(
					function ( $event_data ) {
						$this->assertArrayHasKey( 'action', $event_data );
						$this->assertEquals( 'item_added', $event_data['action'] );
						$this->assertArrayHasKey( 'product_id', $event_data );
						$this->assertEquals( $this->test_product->get_id(), $event_data['product_id'] );
						$this->assertArrayHasKey( 'quantity', $event_data );
						$this->assertEquals( 2, $event_data['quantity'] );
						return true;
					}
				)
			);

		$this->sut->track_cart_item_added( $this->test_product->get_id(), 2 );
	}

	/**
	 * @testdox track_cart_item_added() does not collect data for invalid product ID.
	 */
	public function test_track_cart_item_added_skips_invalid_product(): void {
		$this->mock_collector
			->expects( $this->never() )
			->method( 'collect' );

		$this->sut->track_cart_item_added( 999999, 1 );
	}

	/**
	 * Test cart item updated collects data.
	 *
	 * @testdox track_cart_item_updated() collects session data with quantity change.
	 */
	public function test_track_cart_item_updated_collects_data(): void {
		$cart_item_key = WC()->cart->add_to_cart( $this->test_product->get_id(), 1 );
		$this->assertIsString( $cart_item_key );

		$this->mock_collector
			->expects( $this->once() )
			->method( 'collect' )
			->with(
				$this->equalTo( 'cart_item_updated' ),
				$this->callback(
					function ( $event_data ) {
						$this->assertArrayHasKey( 'action', $event_data );
						$this->assertEquals( 'item_updated', $event_data['action'] );
						$this->assertArrayHasKey( 'quantity', $event_data );
						$this->assertEquals( 5, $event_data['quantity'] );
						$this->assertArrayHasKey( 'old_quantity', $event_data );
						$this->assertEquals( 1, $event_data['old_quantity'] );
						return true;
					}
				)
			);

		$this->sut->track_cart_item_updated(
			$cart_item_key,
			5,
			1,
			WC()->cart
		);
	}

	/**
	 * @testdox track_cart_item_updated() does not collect data when quantity is unchanged.
	 */
	public function test_track_cart_item_updated_skips_unchanged_quantity(): void {
		$cart_item_key = WC()->cart->add_to_cart( $this->test_product->get_id(), 3 );
		$this->assertIsString( $cart_item_key );

		$this->mock_collector
			->expects( $this->never() )
			->method( 'collect' );

		$this->sut->track_cart_item_updated(
			$cart_item_key,
			3,
			3,
			WC()->cart
		);
	}

	/**
	 * Test cart item removed collects data.
	 *
	 * @testdox track_cart_item_removed() collects session data.
	 */
	public function test_track_cart_item_removed_collects_data(): void {
		$cart_item_key = WC()->cart->add_to_cart( $this->test_product->get_id(), 1 );
		$this->assertIsString( $cart_item_key );

		$this->mock_collector
			->expects( $this->once() )
			->method( 'collect' )
			->with(
				$this->equalTo( 'cart_item_removed' ),
				$this->callback(
					function ( $event_data ) {
						$this->assertArrayHasKey( 'action', $event_data );
						$this->assertEquals( 'item_removed', $event_data['action'] );
						return true;
					}
				)
			);

		WC()->cart->remove_cart_item( $cart_item_key );

		$this->sut->track_cart_item_removed( $cart_item_key, WC()->cart );
	}

	/**
	 * Test cart item restored collects data.
	 *
	 * @testdox track_cart_item_restored() collects session data.
	 */
	public function test_track_cart_item_restored_collects_data(): void {
		$cart_item_key = WC()->cart->add_to_cart( $this->test_product->get_id(), 1 );
		$this->assertIsString( $cart_item_key );

		$this->mock_collector
			->expects( $this->once() )
			->method( 'collect' )
			->with(
				$this->equalTo( 'cart_item_restored' ),
				$this->callback(
					function ( $event_data ) {
						$this->assertArrayHasKey( 'action', $event_data );
						$this->assertEquals( 'item_restored', $event_data['action'] );
						return true;
					}
				)
			);

		$this->sut->track_cart_item_restored(
			$cart_item_key,
			WC()->cart
		);
	}

	/**
	 * @testdox track_cart_item_added() resolves variable product and sets variation_id.
	 */
	public function test_track_cart_item_added_resolves_variable_product(): void {
		$variable_product = \WC_Helper_Product::create_variation_product();
		$variations       = $variable_product->get_available_variations( 'array' );
		$this->assertIsArray( $variations[0] );
		$variation_id = $variations[0]['variation_id'];

		$this->mock_collector
			->expects( $this->once() )
			->method( 'collect' )
			->with(
				$this->equalTo( 'cart_item_added' ),
				$this->callback(
					function ( $event_data ) use ( $variable_product, $variation_id ) {
						$this->assertEquals( 'item_added', $event_data['action'] );
						$this->assertEquals( $variation_id, $event_data['variation_id'] );
						$this->assertEquals( $variable_product->get_id(), $event_data['product_id'] );
						return true;
					}
				)
			);

		$this->sut->track_cart_item_added( $variation_id, 1 );

		$variable_product->delete( true );
	}

	/**
	 * Cleanup after test.
	 */
	public function tearDown(): void {
		parent::tearDown();

		if ( $this->test_product ) {
			$this->test_product->delete( true );
		}

		WC()->cart->empty_cart();
		remove_all_filters( 'woocommerce_is_cart' );
		remove_all_actions( 'woocommerce_cart_item_added_from_user_request' );
		remove_all_actions( 'woocommerce_cart_item_updated_from_user_request' );
		remove_all_actions( 'woocommerce_cart_item_removed_from_user_request' );
		remove_all_actions( 'woocommerce_cart_item_restored' );
		remove_all_actions( 'template_redirect' );
	}
}
