<?php
/**
 * CartEventTrackerTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin\Trackers;

use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Trackers\CartEventTracker;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Sessions\SessionDataCollector;

/**
 * Tests for CartEventTracker.
 *
 * @covers \Automattic\WooCommerce\Internal\FraudProtectionPlugin\Trackers\CartEventTracker
 */
class CartEventTrackerTest extends FraudProtectionUnitTestCase {

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
			has_action( 'internal_woocommerce_cart_item_added_from_user_request', array( $this->sut, 'track_cart_item_added' ) ),
			'internal_woocommerce_cart_item_added_from_user_request hook should be registered'
		);
		$this->assertNotFalse(
			has_action( 'internal_woocommerce_cart_item_removed_from_user_request', array( $this->sut, 'track_cart_item_removed' ) ),
			'internal_woocommerce_cart_item_removed_from_user_request hook should be registered'
		);
		$this->assertNotFalse(
			has_action( 'woocommerce_cart_item_restored', array( $this->sut, 'track_cart_item_restored' ) ),
			'woocommerce_cart_item_restored hook should be registered'
		);
		$this->assertNotFalse(
			has_action( 'internal_woocommerce_cart_item_updated_from_user_request', array( $this->sut, 'track_cart_item_updated' ) ),
			'internal_woocommerce_cart_item_updated_from_user_request hook should be registered'
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
	 * @testdox track_cart_item_added() preserves float quantities.
	 */
	public function test_track_cart_item_added_preserves_float_quantity(): void {
		$this->mock_collector
			->expects( $this->once() )
			->method( 'collect' )
			->with(
				$this->equalTo( 'cart_item_added' ),
				$this->callback(
					function ( $event_data ) {
						$this->assertSame( 2.5, $event_data['quantity'] );
						return true;
					}
				)
			);

		$this->sut->track_cart_item_added( $this->test_product->get_id(), 2.5 );
	}

	/**
	 * @testdox track_cart_item_updated() tracks fractional quantity changes with raw values.
	 */
	public function test_track_cart_item_updated_tracks_fractional_change(): void {
		$cart_item_key = WC()->cart->add_to_cart( $this->test_product->get_id(), 1 );
		$this->assertIsString( $cart_item_key );

		$this->mock_collector
			->expects( $this->once() )
			->method( 'collect' )
			->with(
				$this->equalTo( 'cart_item_updated' ),
				$this->callback(
					function ( $event_data ) {
						$this->assertSame( 2.5, $event_data['quantity'] );
						$this->assertSame( 2.0, $event_data['old_quantity'] );
						return true;
					}
				)
			);

		$this->sut->track_cart_item_updated( $cart_item_key, 2.5, 2.0, WC()->cart );
	}

	/**
	 * @testdox track_cart_item_updated() skips numerically equal int and float quantities.
	 */
	public function test_track_cart_item_updated_skips_equal_int_and_float(): void {
		$cart_item_key = WC()->cart->add_to_cart( $this->test_product->get_id(), 2 );
		$this->assertIsString( $cart_item_key );

		$this->mock_collector
			->expects( $this->never() )
			->method( 'collect' );

		$this->sut->track_cart_item_updated( $cart_item_key, 2.0, 2, WC()->cart );
	}

	/**
	 * @testdox track_cart_item_removed() preserves float quantities from cart contents.
	 */
	public function test_track_cart_item_removed_preserves_float_quantity(): void {
		$cart_item_key = WC()->cart->add_to_cart( $this->test_product->get_id(), 1 );
		$this->assertIsString( $cart_item_key );

		$this->mock_collector
			->expects( $this->once() )
			->method( 'collect' )
			->with(
				$this->equalTo( 'cart_item_removed' ),
				$this->callback(
					function ( $event_data ) {
						$this->assertSame( 2.5, $event_data['quantity'] );
						return true;
					}
				)
			);

		WC()->cart->cart_contents[ $cart_item_key ]['quantity'] = 2.5;
		WC()->cart->remove_cart_item( $cart_item_key );

		$this->sut->track_cart_item_removed( $cart_item_key, WC()->cart );
	}

	/**
	 * @testdox track_cart_item_restored() preserves float quantities from cart contents.
	 */
	public function test_track_cart_item_restored_preserves_float_quantity(): void {
		$cart_item_key = WC()->cart->add_to_cart( $this->test_product->get_id(), 1 );
		$this->assertIsString( $cart_item_key );

		$this->mock_collector
			->expects( $this->once() )
			->method( 'collect' )
			->with(
				$this->equalTo( 'cart_item_restored' ),
				$this->callback(
					function ( $event_data ) {
						$this->assertSame( 2.5, $event_data['quantity'] );
						return true;
					}
				)
			);

		WC()->cart->cart_contents[ $cart_item_key ]['quantity'] = 2.5;

		$this->sut->track_cart_item_restored( $cart_item_key, WC()->cart );
	}

	/**
	 * @testdox track_cart_item_added() does not throw on a non-numeric product ID.
	 */
	public function test_track_cart_item_added_ignores_non_numeric_product_id(): void {
		$this->mock_collector
			->expects( $this->never() )
			->method( 'collect' );

		$this->sut->track_cart_item_added( 'not-a-number', 1 );
	}

	/**
	 * Data provider: one invoker per tracker hook callback.
	 *
	 * @return array<string, array{string, \Closure}>
	 */
	public static function provider_tracker_callbacks(): array {
		return array(
			'page loaded' => array(
				'template_redirect',
				function ( CartEventTracker $sut, \WC_Product $product, string $cart_item_key ): void {
					add_filter( 'woocommerce_is_cart', '__return_true' );
					$sut->track_cart_page_loaded();
				},
			),
			'added'       => array(
				'internal_woocommerce_cart_item_added_from_user_request',
				function ( CartEventTracker $sut, \WC_Product $product, string $cart_item_key ): void {
					$sut->track_cart_item_added( $product->get_id(), 1 );
				},
			),
			'updated'     => array(
				'internal_woocommerce_cart_item_updated_from_user_request',
				function ( CartEventTracker $sut, \WC_Product $product, string $cart_item_key ): void {
					$sut->track_cart_item_updated( $cart_item_key, 5, 1, WC()->cart );
				},
			),
			'removed'     => array(
				'internal_woocommerce_cart_item_removed_from_user_request',
				function ( CartEventTracker $sut, \WC_Product $product, string $cart_item_key ): void {
					WC()->cart->remove_cart_item( $cart_item_key );
					$sut->track_cart_item_removed( $cart_item_key, WC()->cart );
				},
			),
			'restored'    => array(
				'woocommerce_cart_item_restored',
				function ( CartEventTracker $sut, \WC_Product $product, string $cart_item_key ): void {
					$sut->track_cart_item_restored( $cart_item_key, WC()->cart );
				},
			),
		);
	}

	/**
	 * @dataProvider provider_tracker_callbacks
	 * @testdox Each tracker callback catches a collector exception and logs a forwarded failure.
	 *
	 * @param string   $hook   The WordPress hook the callback is registered on.
	 * @param \Closure $invoke Invokes the callback under test.
	 */
	public function test_each_callback_catches_collector_exception_and_logs( string $hook, \Closure $invoke ): void {
		$cart_item_key = WC()->cart->add_to_cart( $this->test_product->get_id(), 1 );
		$this->assertIsString( $cart_item_key );

		$this->mock_collector
			->method( 'collect' )
			->willThrowException( new \RuntimeException( 'boom' ) );

		$invoke( $this->sut, $this->test_product, $cart_item_key );

		$this->assertLogged(
			'error',
			'Cart event tracker callback failed',
			array(
				'event_source'    => 'cart_event_tracker',
				'hook'            => $hook,
				'exception_class' => \RuntimeException::class,
			),
			true
		);
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
		remove_all_filters( 'woocommerce_cart_contents_count' );
		remove_all_actions( 'internal_woocommerce_cart_item_added_from_user_request' );
		remove_all_actions( 'internal_woocommerce_cart_item_updated_from_user_request' );
		remove_all_actions( 'internal_woocommerce_cart_item_removed_from_user_request' );
		remove_all_actions( 'woocommerce_cart_item_restored' );
		remove_all_actions( 'template_redirect' );
	}

	/**
	 * @testdox track_cart_item_added() relays the quantity WooCommerce supplied.
	 *
	 * @dataProvider provide_relayed_quantities
	 *
	 * @param mixed $quantity Quantity as WooCommerce supplies it to the callback.
	 * @param mixed $expected Quantity expected on the event.
	 */
	public function test_track_cart_item_added_relays_quantity( mixed $quantity, mixed $expected ): void {
		$this->expect_collected_quantity( 'cart_item_added', $expected );

		$this->sut->track_cart_item_added( $this->test_product->get_id(), $quantity );
	}

	/**
	 * @testdox track_cart_item_removed() relays the quantity held in the removed cart contents.
	 *
	 * @dataProvider provide_relayed_quantities
	 *
	 * @param mixed $quantity Quantity stored on the cart item before removal.
	 * @param mixed $expected Quantity expected on the event.
	 */
	public function test_track_cart_item_removed_relays_quantity( mixed $quantity, mixed $expected ): void {
		$cart_item_key = $this->add_test_product_to_cart();

		$this->expect_collected_quantity( 'cart_item_removed', $expected );

		WC()->cart->cart_contents[ $cart_item_key ]['quantity'] = $quantity;
		WC()->cart->remove_cart_item( $cart_item_key );

		$this->sut->track_cart_item_removed( $cart_item_key, WC()->cart );
	}

	/**
	 * @testdox track_cart_item_restored() relays the quantity held in the cart contents.
	 *
	 * Driven through a stub cart rather than WC()->cart. The callback reads the quantity from the
	 * live cart contents, and a non-numeric one there makes WooCommerce's own sum emit a warning
	 * on PHP 8.3+, which PHPUnit converts into an exception the callback's guard then catches.
	 * The matrix would fail on those versions against the harness rather than exercise the
	 * callback, so the stub keeps it testing the thing it names.
	 *
	 * @dataProvider provide_relayed_quantities
	 *
	 * @param mixed $quantity Quantity stored on the restored cart item.
	 * @param mixed $expected Quantity expected on the event.
	 */
	public function test_track_cart_item_restored_relays_quantity( mixed $quantity, mixed $expected ): void {
		$stub_cart                = new \stdClass();
		$stub_cart->cart_contents = array(
			'stub_key' => array(
				'product_id'   => $this->test_product->get_id(),
				'variation_id' => 0,
				'quantity'     => $quantity,
			),
		);

		$this->expect_collected_quantity( 'cart_item_restored', $expected );

		$this->sut->track_cart_item_restored( 'stub_key', $stub_cart );
	}

	/**
	 * Data provider for the three single-quantity relay tests.
	 *
	 * Shared deliberately: the contract is the same on every callback, and a value reported by
	 * one but not another would be a bug. Running the same matrix through each is what makes
	 * that assertion.
	 *
	 * @return array<string, array{0: mixed, 1: mixed}>
	 */
	public function provide_relayed_quantities(): array {
		return array(
			// Reported exactly as supplied — the reason this change exists.
			'numeric string'            => array( '2', '2' ),
			'fractional numeric string' => array( '1.5', '1.5' ),
			'non-numeric string'        => array( 'not-a-number', 'not-a-number' ),
			'exponent overflow string'  => array( '1e400', '1e400' ),

			// Reported as supplied, whatever the type. This layer does not rewrite the value.
			'non-finite float'          => array( INF, INF ),
			'array'                     => array( array( 1 ), array( 1 ) ),
		);
	}

	/**
	 * @testdox track_cart_item_updated() relays both the new and the old quantity.
	 *
	 * @dataProvider provide_updated_quantities
	 *
	 * @param mixed $quantity     New quantity as supplied.
	 * @param mixed $old_quantity Previous quantity as supplied.
	 * @param mixed $expected     New quantity expected on the event.
	 * @param mixed $expected_old Previous quantity expected on the event.
	 */
	public function test_track_cart_item_updated_relays_quantities( mixed $quantity, mixed $old_quantity, mixed $expected, mixed $expected_old ): void {
		$cart_item_key = $this->add_test_product_to_cart( 2 );

		$this->mock_collector
			->expects( $this->once() )
			->method( 'collect' )
			->with(
				$this->equalTo( 'cart_item_updated' ),
				$this->callback(
					function ( $event_data ) use ( $expected, $expected_old ) {
						$this->assertSame( $expected, $event_data['quantity'], 'quantity' );
						$this->assertSame( $expected_old, $event_data['old_quantity'], 'old_quantity' );
						return true;
					}
				)
			);

		$this->sut->track_cart_item_updated( $cart_item_key, $quantity, $old_quantity, WC()->cart );
	}

	/**
	 * Data provider for {@see test_track_cart_item_updated_relays_quantities()}.
	 *
	 * @return array<string, array{0: mixed, 1: mixed, 2: mixed, 3: mixed}>
	 */
	public function provide_updated_quantities(): array {
		return array(
			'numeric string quantity'     => array( '3.5', 2, '3.5', 2 ),
			'numeric string old quantity' => array( 3, '2', 3, '2' ),
			'non-numeric old quantity'    => array( 3, 'junk', 3, 'junk' ),
			'non-finite old quantity'     => array( 3, INF, 3, INF ),
		);
	}

	/**
	 * @testdox track_cart_item_updated() emits an event only when the quantity really changed.
	 *
	 * The comparison uses parsed numbers when both sides are numeric and strict raw equality
	 * otherwise, so that '2' and 2 are the same update while two different non-numeric values
	 * are not. Pinned as a matrix because every case is one call with a different pair.
	 *
	 * @dataProvider provide_quantity_transitions
	 *
	 * @param mixed $quantity     New quantity.
	 * @param mixed $old_quantity Previous quantity.
	 * @param bool  $should_emit  Whether an event is expected.
	 */
	public function test_track_cart_item_updated_emits_only_on_a_real_change( mixed $quantity, mixed $old_quantity, bool $should_emit ): void {
		$spy           = $this->spy_on_controller_logging();
		$cart_item_key = $this->add_test_product_to_cart( 2 );

		$this->mock_collector
			->expects( $should_emit ? $this->once() : $this->never() )
			->method( 'collect' );

		$this->sut->track_cart_item_updated( $cart_item_key, $quantity, $old_quantity, WC()->cart );

		// A skipped event and an aborted one are indistinguishable from the collector alone:
		// the callback catches its own failures, so a pair that started throwing would still
		// satisfy never(). The absence of a logged failure is what separates them.
		$this->assertSame(
			array(),
			array_values(
				array_filter(
					$spy->entries,
					static function ( array $entry ): bool {
						return false !== strpos( $entry['message'], 'Cart event tracker callback failed' );
					}
				)
			),
			'the callback must reach its decision, not abort on the way'
		);
	}

	/**
	 * Data provider for {@see test_track_cart_item_updated_emits_only_on_a_real_change()}.
	 *
	 * @return array<string, array{0: mixed, 1: mixed, 2: bool}>
	 */
	public function provide_quantity_transitions(): array {
		return array(
			'numerically equal string and int' => array( '2', 2, false ),
			'identical non-numeric values'     => array( 'junk', 'junk', false ),
			'two different non-numeric values' => array( 'junk-b', 'junk-a', true ),
			'boolean true to integer one'      => array( 1, true, true ),
			'a genuine numeric change'         => array( 3, 2, true ),
		);
	}

	/**
	 * Expect exactly one collected event carrying the given quantity.
	 *
	 * @param string $event_type Expected event type.
	 * @param mixed  $expected   Expected quantity on the event data.
	 */
	private function expect_collected_quantity( string $event_type, mixed $expected ): void {
		$this->mock_collector
			->expects( $this->once() )
			->method( 'collect' )
			->with(
				$this->equalTo( $event_type ),
				$this->callback(
					function ( $event_data ) use ( $expected ) {
						$this->assertSame( $expected, $event_data['quantity'] );
						return true;
					}
				)
			);
	}

	/**
	 * Put the shared test product in the cart and return its key.
	 *
	 * @param int $quantity Quantity to add.
	 * @return string The cart item key.
	 */
	private function add_test_product_to_cart( int $quantity = 1 ): string {
		$cart_item_key = WC()->cart->add_to_cart( $this->test_product->get_id(), $quantity );
		$this->assertIsString( $cart_item_key );

		return $cart_item_key;
	}

	/**
	 * @testdox track_cart_item_added() omits a cart_item_count WooCommerce cannot state.
	 *
	 * WooCommerce sums the count over the raw cart quantities and passes it through the
	 * `woocommerce_cart_contents_count` filter, so neither its type nor its finiteness is
	 * guaranteed. The count is the plugin's own number rather than a relayed one, so when it
	 * has no finite numeric form the field is omitted rather than filled in.
	 *
	 * @dataProvider provide_cart_item_counts
	 *
	 * @param mixed $filtered The value the cart count filter returns.
	 * @param mixed $expected The count expected in the event data.
	 */
	public function test_track_cart_item_added_guards_cart_item_count( mixed $filtered, mixed $expected ): void {
		add_filter(
			'woocommerce_cart_contents_count',
			static function () use ( $filtered ) {
				return $filtered;
			}
		);

		$this->mock_collector
			->expects( $this->once() )
			->method( 'collect' )
			->with(
				$this->equalTo( 'cart_item_added' ),
				$this->callback(
					function ( $event_data ) use ( $expected ) {
						$this->assertSame( $expected, $event_data['cart_item_count'] );
						return true;
					}
				)
			);

		$this->sut->track_cart_item_added( $this->test_product->get_id(), 1 );
	}

	/**
	 * Data provider for {@see test_track_cart_item_added_guards_cart_item_count()}.
	 *
	 * @return array<string, array{0: mixed, 1: mixed}>
	 */
	public function provide_cart_item_counts(): array {
		return array(
			'whole count'               => array( 3, 3 ),
			// An int is taken as given. Without that fast path this would come back as a float,
			// because (float) PHP_INT_MAX fails the deliberately strict upper bound below.
			'integer maximum'           => array( PHP_INT_MAX, PHP_INT_MAX ),
			'decimal count'             => array( 2.5, 2.5 ),
			'positive INF'              => array( INF, null ),
			'negative INF'              => array( -INF, null ),
			'NAN'                       => array( NAN, null ),
			// Read by numeric value rather than PHP type, matching how a money total is read,
			// and reported as a whole number when that is what it is.
			'numeric string'            => array( '3', 3 ),
			'fractional numeric string' => array( '2.5', 2.5 ),
			'sentinel string'           => array( 'INF', null ),
			'array'                     => array( array( 1 ), null ),

			// The integer boundary. Comparing in float rounds PHP_INT_MAX up to 2^63, so an
			// inclusive upper bound would admit this and cast it to a large negative — a count
			// reported with the wrong sign. It stays a float instead.
			'one past the integer maximum' => array( '9223372036854775808', 9223372036854775808.0 ),
			'largest lossless integer'     => array( 9223372036854774784.0, 9223372036854774784 ),
			// The bottom end matters for the same reason: without it, a large negative count
			// casts back to a large positive one.
			'below the integer minimum'    => array( -1.0e19, -1.0e19 ),
			'exactly the integer minimum'  => array( -9223372036854775808.0, PHP_INT_MIN ),
			'negative zero'                => array( -0.0, 0 ),
		);
	}
}
