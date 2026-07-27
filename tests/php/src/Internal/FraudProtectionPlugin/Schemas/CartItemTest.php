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

			// Finite and positive, so the division happens and the result is whatever it
			// computes to. Only the divisor is this method's concern.
			'denormal divisor'           => array( '1e-320', '1e-320', INF ),

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
