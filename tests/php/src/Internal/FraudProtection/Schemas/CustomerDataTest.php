<?php
/**
 * CustomerDataTest class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtection\Schemas;

use Automattic\WooCommerce\Internal\FraudProtection\Schemas\Address;
use Automattic\WooCommerce\Internal\FraudProtection\Schemas\CustomerData;

/**
 * Tests for CustomerData schema.
 *
 * @covers \Automattic\WooCommerce\Internal\FraudProtection\Schemas\CustomerData
 */
class CustomerDataTest extends \WC_Unit_Test_Case {

	/**
	 * Runs before each test.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! did_action( 'woocommerce_load_cart_from_session' ) && function_exists( 'wc_load_cart' ) ) {
			wc_load_cart();
		}
	}

	/**
	 * @testdox from_wc_customer() builds CustomerData with all 4 keys.
	 */
	public function test_from_wc_customer_builds_data(): void {
		WC()->customer->set_billing_email( 'john@example.com' );

		$billing  = Address::from_wc_customer_billing( WC()->customer );
		$shipping = Address::from_wc_customer_shipping( WC()->customer );
		$data     = CustomerData::from_wc_customer( WC()->customer, $billing, $shipping );
		$arr      = $data->to_array();

		$this->assertCount( 4, $arr );
		$this->assertEquals( 'john@example.com', $arr['billing_email'] );
		$this->assertIsInt( $arr['lifetime_order_count'] );
		$this->assertIsArray( $arr['billing_address'] );
		$this->assertIsArray( $arr['shipping_address'] );
	}

	/**
	 * @testdox to_array() nests billing_address and shipping_address as sub-arrays.
	 */
	public function test_addresses_nested_as_sub_arrays(): void {
		WC()->customer->set_billing_address_1( '123 Main St' );
		WC()->customer->set_billing_country( 'US' );
		WC()->customer->set_shipping_address_1( '456 Oak Ave' );
		WC()->customer->set_shipping_country( 'CA' );

		$billing  = Address::from_wc_customer_billing( WC()->customer );
		$shipping = Address::from_wc_customer_shipping( WC()->customer );
		$data     = CustomerData::from_wc_customer( WC()->customer, $billing, $shipping );
		$arr      = $data->to_array();

		$this->assertEquals( '123 Main St', $arr['billing_address']['address_1'] );
		$this->assertEquals( 'US', $arr['billing_address']['country'] );
		$this->assertEquals( '456 Oak Ave', $arr['shipping_address']['address_1'] );
		$this->assertEquals( 'CA', $arr['shipping_address']['country'] );
	}

	/**
	 * @testdox from_wc_customer() reloads customer for accurate order count.
	 */
	public function test_lifetime_order_count_for_registered_customer(): void {
		$user_id = $this->factory->user->create(
			array( 'user_email' => 'count-test@example.com' )
		);
		wp_set_current_user( $user_id );
		WC()->customer = new \WC_Customer( $user_id, true );

		$billing  = Address::from_wc_customer_billing( WC()->customer );
		$shipping = Address::from_wc_customer_shipping( WC()->customer );
		$data     = CustomerData::from_wc_customer( WC()->customer, $billing, $shipping );
		$arr      = $data->to_array();

		$this->assertIsInt( $arr['lifetime_order_count'] );
		$this->assertGreaterThanOrEqual( 0, $arr['lifetime_order_count'] );
	}

	/**
	 * @testdox empty() returns null email, zero count, and empty addresses.
	 */
	public function test_empty_returns_defaults(): void {
		$data = CustomerData::empty();
		$arr  = $data->to_array();

		$this->assertNull( $arr['billing_email'] );
		$this->assertEquals( 0, $arr['lifetime_order_count'] );
		$this->assertIsArray( $arr['billing_address'] );
		$this->assertIsArray( $arr['shipping_address'] );

		// Verify addresses are empty (all null).
		foreach ( $arr['billing_address'] as $value ) {
			$this->assertNull( $value );
		}
		foreach ( $arr['shipping_address'] as $value ) {
			$this->assertNull( $value );
		}
	}

	/**
	 * @testdox to_array() has correct top-level keys.
	 */
	public function test_to_array_keys(): void {
		$data = CustomerData::empty();
		$arr  = $data->to_array();

		$expected_keys = array(
			'billing_email',
			'lifetime_order_count',
			'billing_address',
			'shipping_address',
		);
		$this->assertEquals( $expected_keys, array_keys( $arr ) );
	}
}
