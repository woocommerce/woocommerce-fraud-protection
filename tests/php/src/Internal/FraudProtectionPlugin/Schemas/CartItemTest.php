<?php
/**
 * CartItemTest class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin\Schemas;

use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Schemas\CartItem;

/**
 * Tests for CartItem schema.
 *
 * @covers \Automattic\WooCommerce\Internal\FraudProtectionPlugin\Schemas\CartItem
 */
class CartItemTest extends FraudProtectionUnitTestCase {

	/**
	 * Runs before each test.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! did_action( 'woocommerce_load_cart_from_session' ) && function_exists( 'wc_load_cart' ) ) {
			wc_load_cart();
		}

		update_option( 'woocommerce_calc_taxes', 'no' );
	}

	/**
	 * @testdox from_cart_entry() builds a CartItem with all 12 fields.
	 */
	public function test_from_cart_entry_builds_item_with_all_fields(): void {
		WC()->cart->empty_cart();

		$product = \WC_Helper_Product::create_simple_product();
		$product->set_name( 'Test Widget' );
		$product->set_sku( 'WDG-001' );
		$product->set_regular_price( '25.00' );
		$product->save();

		wp_set_object_terms( $product->get_id(), 'Electronics', 'product_cat' );

		WC()->cart->add_to_cart( $product->get_id(), 3 );
		WC()->cart->calculate_totals();

		$cart_contents = WC()->cart->get_cart();
		$cart_item     = reset( $cart_contents );

		$item = CartItem::from_cart_entry( $cart_item, $cart_item['data'] );
		$arr  = $item->to_array();

		$this->assertEquals( $product->get_id(), $arr['product_id'] );
		$this->assertEquals( 'Test Widget', $arr['name'] );
		$this->assertArrayNotHasKey( 'description', $arr );
		$this->assertEquals( 'Electronics', $arr['category'] );
		$this->assertEquals( 'WDG-001', $arr['sku'] );
		$this->assertEquals( 3, $arr['quantity'] );
		$this->assertEquals( 25.00, $arr['unit_price'] );
		$this->assertIsFloat( $arr['unit_tax_amount'] );
		$this->assertIsFloat( $arr['unit_discount_amount'] );
		$this->assertEquals( 'simple', $arr['product_type'] );
		$this->assertFalse( $arr['is_virtual'] );
		$this->assertFalse( $arr['is_downloadable'] );
		$this->assertIsArray( $arr['attributes'] );
	}

	/**
	 * @testdox Unit tax and discount calculations are correct.
	 */
	public function test_unit_tax_and_discount_calculations(): void {
		WC()->cart->empty_cart();

		$product = \WC_Helper_Product::create_simple_product();
		$product->set_regular_price( '100.00' );
		$product->save();

		WC()->cart->add_to_cart( $product->get_id(), 2 );
		WC()->cart->calculate_totals();

		$cart_contents = WC()->cart->get_cart();
		$cart_item     = reset( $cart_contents );

		$item = CartItem::from_cart_entry( $cart_item, $cart_item['data'] );
		$arr  = $item->to_array();

		$this->assertIsFloat( $arr['unit_tax_amount'] );
		$this->assertIsFloat( $arr['unit_discount_amount'] );
	}

	/**
	 * @testdox from_cart_entry() preserves float quantities and divides unit amounts by them.
	 */
	public function test_from_cart_entry_preserves_float_quantity(): void {
		WC()->cart->empty_cart();

		$product = \WC_Helper_Product::create_simple_product();

		WC()->cart->add_to_cart( $product->get_id(), 1 );
		WC()->cart->calculate_totals();

		$cart_contents = WC()->cart->get_cart();
		$cart_item     = reset( $cart_contents );

		$cart_item['quantity']      = 2.5;
		$cart_item['line_tax']      = 5.0;
		$cart_item['line_subtotal'] = 25.0;
		$cart_item['line_total']    = 20.0;

		$arr = CartItem::from_cart_entry( $cart_item, $cart_item['data'] )->to_array();

		$this->assertSame( 2.5, $arr['quantity'] );
		$this->assertSame( 2.0, $arr['unit_tax_amount'] );
		$this->assertSame( 2.0, $arr['unit_discount_amount'] );
	}

	/**
	 * @testdox from_cart_entry() relays the cart quantity verbatim and derives unit amounts only from a numeric one.
	 *
	 * One matrix over the whole quantity contract: what WooCommerce left in the cart entry, what
	 * is reported for it, and whether a per-unit amount could be derived from it. The entry
	 * always carries line tax 5.0 and a line discount of 5.0 (subtotal 25.0 - total 20.0), so
	 * every expected unit amount below is simply 5.0 divided by the quantity — or null when
	 * there is no number to divide by. The keys are always present; only the value is null.
	 *
	 * @dataProvider provide_cart_quantities
	 *
	 * @param mixed      $quantity      Quantity as stored in the cart entry.
	 * @param mixed      $expected      Quantity expected on the payload.
	 * @param float|null $expected_unit Expected unit tax and unit discount, or null when underivable.
	 */
	public function test_from_cart_entry_relays_quantity_and_derives_unit_amounts( mixed $quantity, mixed $expected, ?float $expected_unit ): void {
		$cart_item = $this->cart_entry();

		$cart_item['quantity'] = $quantity;

		$arr = CartItem::from_cart_entry( $cart_item, $cart_item['data'] )->to_array();

		$this->assertSame( $expected, $arr['quantity'], 'the quantity is reported as supplied' );
		$this->assertSame( $expected_unit, $arr['unit_tax_amount'], 'unit_tax_amount' );
		$this->assertSame( $expected_unit, $arr['unit_discount_amount'], 'unit_discount_amount' );
	}

	/**
	 * Data provider for {@see test_from_cart_entry_relays_quantity_and_derives_unit_amounts()}.
	 *
	 * @return array<string, array{0: mixed, 1: mixed, 2: float|null}>
	 */
	public function provide_cart_quantities(): array {
		return array(
			// Numbers WooCommerce normally supplies.
			'integer'                    => array( 2, 2, 2.5 ),
			'float'                      => array( 2.5, 2.5, 2.0 ),

			// Strings: the reason this change exists. Reported as-is, still usable for division.
			'numeric string'             => array( '2', '2', 2.5 ),
			'fractional numeric string'  => array( '2.5', '2.5', 2.0 ),

			// Reported as supplied, but with no number to divide by, so the unit amounts are null.
			'non-numeric string'         => array( 'not-a-number', 'not-a-number', null ),
			'boolean true'               => array( true, true, null ),
			'exponent overflow string'   => array( '1e400', '1e400', null ),
			'negative exponent overflow' => array( '-1e400', '-1e400', null ),

			// Finite and positive, so the division happens — but a divisor this small overflows
			// the quotient, and a derived amount the payload cannot state is reported as no
			// amount rather than as a number the arithmetic never produced.
			'denormal divisor'           => array( '1e-320', '1e-320', null ),

			// Historical behaviour: a non-positive quantity yields zero unit amounts rather than
			// a division. Retained so the per_unit_amount() extraction cannot silently change it.
			'zero'                       => array( 0, 0, 0.0 ),
			'negative'                   => array( -1, -1, 0.0 ),

			// Reported as supplied, whatever the type. This layer does not rewrite the value.
			'non-finite float'           => array( INF, INF, null ),
			'array'                      => array( array( 2 ), array( 2 ), null ),
		);
	}

	/**
	 * @testdox from_cart_entry() keeps the historical default of 1 for an absent quantity.
	 */
	public function test_from_cart_entry_defaults_absent_quantity_to_one(): void {
		$cart_item = $this->cart_entry();

		unset( $cart_item['quantity'] );

		$arr = CartItem::from_cart_entry( $cart_item, $cart_item['data'] )->to_array();

		$this->assertSame( 1, $arr['quantity'] );
		$this->assertSame( 5.0, $arr['unit_tax_amount'] );
	}

	/**
	 * @testdox from_cart_entry() reports no unit amount when the line amounts overflow, though the quantity is finite.
	 *
	 * The quantity here is a valid finite divisor, so the divisor checks do not fire. WooCommerce
	 * derives the line amounts from that same quantity, and at this magnitude they overflow: the
	 * subtotal and total are both infinite, and the discount between them is NAN. This is the
	 * shape a guard on the divisor alone does not catch.
	 */
	public function test_from_cart_entry_reports_no_unit_amount_when_line_amounts_overflow(): void {
		$cart_item                  = $this->cart_entry();
		$cart_item['quantity']      = 1.0e308;
		$cart_item['line_tax']      = INF;
		$cart_item['line_subtotal'] = INF;
		$cart_item['line_total']    = INF;

		$arr = CartItem::from_cart_entry( $cart_item, $cart_item['data'] )->to_array();

		$this->assertSame( 1.0e308, $arr['quantity'], 'the quantity is still reported as supplied' );
		$this->assertNull( $arr['unit_tax_amount'], 'an infinite quotient is reported as no amount' );
		$this->assertNull( $arr['unit_discount_amount'], 'a NAN quotient is reported as no amount' );
	}

	/**
	 * @testdox from_cart_entry() omits the unit amounts for an unreadable line amount even at zero quantity.
	 *
	 * The zero-quantity branch returns the historical zero amounts, which stands for "nothing to
	 * divide". An amount nobody can read is not nothing, so it must not borrow that answer — the
	 * dividend has to be judged before the quantity's sign, not after.
	 */
	public function test_from_cart_entry_omits_unit_amounts_for_an_unreadable_amount_at_zero_quantity(): void {
		$cart_item                  = $this->cart_entry();
		$cart_item['quantity']      = 0;
		$cart_item['line_tax']      = 'INF';
		$cart_item['line_subtotal'] = 'INF';
		$cart_item['line_total']    = 'INF';

		$arr = CartItem::from_cart_entry( $cart_item, $cart_item['data'] )->to_array();

		$this->assertNull( $arr['unit_tax_amount'], 'unit_tax_amount must not fall back to the zero-quantity amount' );
		$this->assertNull( $arr['unit_discount_amount'], 'unit_discount_amount must not fall back to the zero-quantity amount' );
	}

	/**
	 * @testdox from_cart_entry() omits a unit price that is not a finite number.
	 *
	 * The price reaches this record through get_price(), whose return passes through the
	 * woocommerce_product_get_price filter, so it is no more guaranteed to be a usable number
	 * than the line amounts beside it. Casting 'INF' would report a free product.
	 *
	 * The stubbed reads beside get_price() are the ones from_cart_entry() feeds into typed
	 * constructor parameters, where the mock's default null would be a TypeError rather than
	 * a case under test.
	 *
	 * @dataProvider provide_unreadable_prices
	 *
	 * @param mixed $price Whatever get_price() returns.
	 */
	public function test_from_cart_entry_omits_an_unreadable_unit_price( mixed $price ): void {
		$cart_item = $this->cart_entry();

		$product = $this->createMock( \WC_Product::class );
		$product->method( 'get_price' )->willReturn( $price );
		$product->method( 'get_id' )->willReturn( $cart_item['product_id'] );
		$product->method( 'is_virtual' )->willReturn( false );
		$product->method( 'is_downloadable' )->willReturn( false );

		$arr = CartItem::from_cart_entry( $cart_item, $product )->to_array();

		$this->assertNull( $arr['unit_price'], 'an unreadable price must be omitted, not reported as free' );
		$this->assertSame( 1, $arr['quantity'], 'the item itself must survive' );
	}

	/**
	 * Data provider for {@see test_from_cart_entry_omits_an_unreadable_unit_price()}.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public function provide_unreadable_prices(): array {
		return array(
			'sentinel string'  => array( 'INF' ),
			'non-finite float' => array( INF ),
			'not a number'     => array( 'free' ),
			// WooCommerce's own "no price set" value, which is not a number either. Reported as
			// absent rather than as a free product.
			'no price set'     => array( '' ),
		);
	}

	/**
	 * @testdox from_cart_entry() keeps the item and omits the amounts when a line amount is not a number.
	 *
	 * Core keeps cart line amounts as int|float, but they pass through cart filters before they
	 * reach this record, so a string is possible here in a way it is not inside core. Two
	 * different failures follow from one, which is why both are pinned in a single matrix:
	 * casting a string that means nothing reports a confident 0.0, and subtracting one raises a
	 * TypeError that the caller's per-item guard turns into a lost line. The item must survive,
	 * with only the derived amounts absent.
	 *
	 * @dataProvider provide_unreadable_line_amounts
	 *
	 * @param array<string, mixed> $amounts Line amounts to place on the cart entry.
	 */
	public function test_from_cart_entry_omits_unit_amounts_for_an_unreadable_line_amount( array $amounts ): void {
		$cart_item = array_merge( $this->cart_entry(), $amounts );

		$arr = CartItem::from_cart_entry( $cart_item, $cart_item['data'] )->to_array();

		$this->assertSame( 1, $arr['quantity'], 'the item itself must survive' );
		$this->assertNull( $arr['unit_tax_amount'], 'unit_tax_amount' );
		$this->assertNull( $arr['unit_discount_amount'], 'unit_discount_amount' );
	}

	/**
	 * Data provider for {@see test_from_cart_entry_omits_unit_amounts_for_an_unreadable_line_amount()}.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public function provide_unreadable_line_amounts(): array {
		return array(
			// Reaches per_unit_amount() directly, where a cast would produce 0.0.
			'tax sentinel'      => array( array( 'line_tax' => 'INF', 'line_subtotal' => 'INF', 'line_total' => 'INF' ) ),
			// Reaches the subtraction first, where a cast is not even attempted.
			'subtotal sentinel' => array( array( 'line_tax' => 'INF', 'line_subtotal' => 'INF', 'line_total' => 20.0 ) ),
			'total sentinel'    => array( array( 'line_tax' => 'INF', 'line_subtotal' => 25.0, 'line_total' => 'INF' ) ),
		);
	}

	/**
	 * A real cart entry carrying fixed line amounts.
	 *
	 * Line tax is 5.0 and the line discount is 5.0 (subtotal 25.0 - total 20.0), so an expected
	 * unit amount is always 5.0 divided by the quantity under test.
	 *
	 * @return array<string, mixed> The cart entry, including its `data` product object.
	 */
	private function cart_entry(): array {
		WC()->cart->empty_cart();

		$product = \WC_Helper_Product::create_simple_product();

		WC()->cart->add_to_cart( $product->get_id(), 1 );
		WC()->cart->calculate_totals();

		$cart_contents = WC()->cart->get_cart();
		$cart_item     = reset( $cart_contents );

		$cart_item['line_tax']      = 5.0;
		$cart_item['line_subtotal'] = 25.0;
		$cart_item['line_total']    = 20.0;

		return $cart_item;
	}
}
