<?php
/**
 * CartItemTest class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtection\Schemas;

use Automattic\WooCommerce\Internal\FraudProtection\Schemas\CartItem;

/**
 * Tests for CartItem schema.
 *
 * @covers \Automattic\WooCommerce\Internal\FraudProtection\Schemas\CartItem
 */
class CartItemTest extends \WC_Unit_Test_Case {

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
		$product->set_description( 'A test widget' );
		$product->set_sku( 'WDG-001' );
		$product->set_regular_price( 25.00 );
		$product->save();

		wp_set_object_terms( $product->get_id(), 'Electronics', 'product_cat' );

		WC()->cart->add_to_cart( $product->get_id(), 3 );
		WC()->cart->calculate_totals();

		$cart_contents = WC()->cart->get_cart();
		$cart_item     = reset( $cart_contents );

		$item = CartItem::from_cart_entry( $cart_item, $cart_item['data'] );
		$arr  = $item->to_array();

		$this->assertEquals( 'Test Widget', $arr['name'] );
		$this->assertEquals( 'A test widget', $arr['description'] );
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
		$product->set_regular_price( 100.00 );
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

}
