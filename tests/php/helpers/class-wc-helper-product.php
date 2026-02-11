<?php
/**
 * Helper class for creating test products.
 *
 * @package WooCommerce_Fraud_Protection\Tests
 */

/**
 * WC_Helper_Product class for creating test products.
 */
class WC_Helper_Product {

	/**
	 * Create a simple product.
	 *
	 * @param bool  $save Whether to save the product.
	 * @param array $props Product properties.
	 * @return WC_Product_Simple
	 */
	public static function create_simple_product( $save = true, $props = array() ) {
		$defaults = array(
			'name'          => 'Test Product ' . wp_generate_password( 6, false ),
			'regular_price' => '10',
			'price'         => '10',
			'status'        => 'publish',
		);

		$props   = wp_parse_args( $props, $defaults );
		$product = new WC_Product_Simple();

		foreach ( $props as $key => $value ) {
			$setter = "set_{$key}";
			if ( method_exists( $product, $setter ) ) {
				$product->$setter( $value );
			}
		}

		if ( $save ) {
			$product->save();
		}

		return $product;
	}

	/**
	 * Create a variation product.
	 *
	 * @return WC_Product_Variable
	 */
	public static function create_variation_product() {
		$product = new WC_Product_Variable();
		$product->set_name( 'Test Variable Product ' . wp_generate_password( 6, false ) );
		$product->set_status( 'publish' );
		$product->save();

		// Create a size attribute.
		$attribute = new WC_Product_Attribute();
		$attribute->set_name( 'Size' );
		$attribute->set_options( array( 'small', 'large' ) );
		$attribute->set_position( 0 );
		$attribute->set_visible( true );
		$attribute->set_variation( true );

		$product->set_attributes( array( $attribute ) );
		$product->save();

		// Create variations.
		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $product->get_id() );
		$variation->set_attributes( array( 'size' => 'small' ) );
		$variation->set_regular_price( '10' );
		$variation->set_status( 'publish' );
		$variation->save();

		$variation2 = new WC_Product_Variation();
		$variation2->set_parent_id( $product->get_id() );
		$variation2->set_attributes( array( 'size' => 'large' ) );
		$variation2->set_regular_price( '15' );
		$variation2->set_status( 'publish' );
		$variation2->save();

		return wc_get_product( $product->get_id() );
	}
}
