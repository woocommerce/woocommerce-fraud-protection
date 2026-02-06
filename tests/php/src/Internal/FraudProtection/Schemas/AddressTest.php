<?php
/**
 * AddressTest class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtection\Schemas;

use Automattic\WooCommerce\Internal\FraudProtection\Schemas\Address;

/**
 * Tests for Address schema.
 *
 * @covers \Automattic\WooCommerce\Internal\FraudProtection\Schemas\Address
 */
class AddressTest extends \WC_Unit_Test_Case {

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
	 * @testdox from_wc_customer_billing() builds address from billing fields.
	 */
	public function test_from_wc_customer_billing(): void {
		WC()->customer->set_billing_first_name( 'John' );
		WC()->customer->set_billing_last_name( 'Doe' );
		WC()->customer->set_billing_address_1( '123 Main St' );
		WC()->customer->set_billing_address_2( 'Apt 4B' );
		WC()->customer->set_billing_city( 'New York' );
		WC()->customer->set_billing_state( 'NY' );
		WC()->customer->set_billing_postcode( '10001' );
		WC()->customer->set_billing_country( 'US' );
		WC()->customer->set_billing_phone( '555-1234' );

		$address = Address::from_wc_customer_billing( WC()->customer );
		$arr     = $address->to_array();

		$this->assertEquals( 'John', $arr['first_name'] );
		$this->assertEquals( 'Doe', $arr['last_name'] );
		$this->assertEquals( '123 Main St', $arr['address_1'] );
		$this->assertEquals( 'Apt 4B', $arr['address_2'] );
		$this->assertEquals( 'New York', $arr['city'] );
		$this->assertEquals( 'NY', $arr['state'] );
		$this->assertEquals( '10001', $arr['postcode'] );
		$this->assertEquals( 'US', $arr['country'] );
		$this->assertEquals( '555-1234', $arr['phone'] );
	}

	/**
	 * @testdox from_wc_customer_shipping() builds address from shipping fields with null phone.
	 */
	public function test_from_wc_customer_shipping(): void {
		WC()->customer->set_shipping_first_name( 'Jane' );
		WC()->customer->set_shipping_last_name( 'Smith' );
		WC()->customer->set_shipping_address_1( '456 Oak Ave' );
		WC()->customer->set_shipping_address_2( 'Suite 100' );
		WC()->customer->set_shipping_city( 'Los Angeles' );
		WC()->customer->set_shipping_state( 'CA' );
		WC()->customer->set_shipping_postcode( '90001' );
		WC()->customer->set_shipping_country( 'US' );

		$address = Address::from_wc_customer_shipping( WC()->customer );
		$arr     = $address->to_array();

		$this->assertEquals( 'Jane', $arr['first_name'] );
		$this->assertEquals( '456 Oak Ave', $arr['address_1'] );
		$this->assertEquals( 'US', $arr['country'] );
		$this->assertNull( $arr['phone'] );
	}

	/**
	 * @testdox empty() returns all nulls.
	 */
	public function test_empty_returns_all_nulls(): void {
		$address = Address::empty();
		$arr     = $address->to_array();

		$this->assertCount( 9, $arr );
		foreach ( $arr as $value ) {
			$this->assertNull( $value );
		}
		$this->assertNull( $address->get_country() );
	}

	/**
	 * @testdox to_array() excludes legacy 'address' key and includes only address_1/address_2.
	 */
	public function test_to_array_excludes_legacy_address_key(): void {
		$address = Address::from_wc_customer_billing( WC()->customer );
		$arr     = $address->to_array();

		$this->assertArrayNotHasKey( 'address', $arr );
		$this->assertArrayHasKey( 'address_1', $arr );
		$this->assertArrayHasKey( 'address_2', $arr );
	}

	/**
	 * @testdox get_country() returns the country code.
	 */
	public function test_get_country(): void {
		WC()->customer->set_billing_country( 'CA' );

		$address = Address::from_wc_customer_billing( WC()->customer );
		$this->assertEquals( 'CA', $address->get_country() );
	}

	/**
	 * @testdox to_array() has exactly 9 keys.
	 */
	public function test_to_array_has_nine_keys(): void {
		$address = Address::from_wc_customer_billing( WC()->customer );
		$arr     = $address->to_array();

		$expected_keys = array(
			'first_name',
			'last_name',
			'address_1',
			'address_2',
			'city',
			'state',
			'postcode',
			'country',
			'phone',
		);
		$this->assertEquals( $expected_keys, array_keys( $arr ) );
	}
}
