<?php
/**
 * ClassicFormDataExtractionTraitTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtection;

use Automattic\WooCommerce\Internal\FraudProtection\ClassicFormDataExtractionTrait;
use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;

/**
 * Test double that exposes the trait's private methods for testing.
 */
class ClassicFormDataExtractionTraitTestDouble {
	use ClassicFormDataExtractionTrait;

	/**
	 * Public proxy for the private build_request_data() method.
	 *
	 * @param array $form_data Form data array.
	 * @return array Structured request data.
	 */
	public function test_build_request_data( array $form_data ): array {
		return $this->build_request_data( $form_data );
	}

	/**
	 * Public proxy for the private extract_payment_data() method.
	 *
	 * @return array Flat key-value map of payment-related POST fields.
	 */
	public function test_extract_payment_data(): array {
		return $this->extract_payment_data();
	}
}

/**
 * Tests for the ClassicFormDataExtractionTrait.
 *
 * @covers \Automattic\WooCommerce\Internal\FraudProtection\ClassicFormDataExtractionTrait
 */
class ClassicFormDataExtractionTraitTest extends FraudProtectionUnitTestCase {

	/**
	 * The System Under Test.
	 *
	 * @var ClassicFormDataExtractionTraitTestDouble
	 */
	private ClassicFormDataExtractionTraitTestDouble $sut;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->sut = new ClassicFormDataExtractionTraitTestDouble();
	}

	/**
	 * Tear down after each test.
	 */
	public function tearDown(): void {
		$_POST = array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		parent::tearDown();
	}

	/*
	|--------------------------------------------------------------------------
	| build_request_data() Tests
	|--------------------------------------------------------------------------
	*/

	/**
	 * @testdox build_request_data() includes payment_method from form data.
	 */
	public function test_build_request_data_includes_payment_method(): void {
		$result = $this->sut->test_build_request_data( array( 'payment_method' => 'stripe' ) );

		$this->assertSame( 'stripe', $result['payment_method'] );
	}

	/**
	 * @testdox build_request_data() omits address keys when form data has no address fields.
	 */
	public function test_build_request_data_omits_empty_addresses(): void {
		$result = $this->sut->test_build_request_data( array( 'payment_method' => 'stripe' ) );

		$this->assertArrayNotHasKey( 'billing_address', $result );
		$this->assertArrayNotHasKey( 'shipping_address', $result );
	}

	/**
	 * @testdox build_request_data() structures billing/shipping addresses from flat prefixed keys.
	 */
	public function test_build_request_data_structures_addresses(): void {
		$form_data = array(
			'billing_first_name'  => 'John',
			'billing_last_name'   => 'Doe',
			'billing_email'       => 'john@example.com',
			'billing_phone'       => '555-0100',
			'billing_country'     => 'US',
			'billing_address_1'   => '123 Main St',
			'billing_address_2'   => 'Apt 4',
			'billing_city'        => 'Springfield',
			'billing_state'       => 'IL',
			'billing_postcode'    => '62701',
			'shipping_first_name' => 'Jane',
			'shipping_last_name'  => 'Doe',
			'shipping_country'    => 'US',
			'shipping_address_1'  => '456 Oak Ave',
			'shipping_address_2'  => '',
			'shipping_city'       => 'Springfield',
			'shipping_state'      => 'IL',
			'shipping_postcode'   => '62702',
			'payment_method'      => 'stripe',
		);

		$result = $this->sut->test_build_request_data( $form_data );

		$this->assertSame(
			array(
				'first_name' => 'John',
				'last_name'  => 'Doe',
				'email'      => 'john@example.com',
				'phone'      => '555-0100',
				'country'    => 'US',
				'address_1'  => '123 Main St',
				'address_2'  => 'Apt 4',
				'city'       => 'Springfield',
				'state'      => 'IL',
				'postcode'   => '62701',
			),
			$result['billing_address']
		);

		$this->assertSame(
			array(
				'first_name' => 'Jane',
				'last_name'  => 'Doe',
				'country'    => 'US',
				'address_1'  => '456 Oak Ave',
				'address_2'  => '',
				'city'       => 'Springfield',
				'state'      => 'IL',
				'postcode'   => '62702',
			),
			$result['shipping_address']
		);

		$this->assertSame( 'stripe', $result['payment_method'] );
	}

	/*
	|--------------------------------------------------------------------------
	| extract_payment_data() Tests
	|--------------------------------------------------------------------------
	*/

	/**
	 * @testdox extract_payment_data() excludes known non-payment keys and prefixes.
	 */
	public function test_extract_payment_data_excludes_non_payment_keys(): void {
		$_POST = array(
			'wc_fraud_protection_session_id'       => 'sess-123',
			'billing_first_name'                   => 'John',
			'shipping_first_name'                  => 'John',
			'order_comments'                       => 'Leave at door',
			'account_username'                     => 'john',
			'woocommerce_checkout_nonce'            => 'abc123',
			'woocommerce_add_payment_method'       => '1',
			'woocommerce-add-payment-method-nonce' => 'def456',
			'_wpnonce'                             => 'xyz789',
			'payment_method'                       => 'stripe',
			'terms'                                => '1',
			'terms-field'                          => '1',
			'ship_to_different_address'            => '1',
			'wc_order_attribution_source_type'     => 'typein',
			'wc_order_attribution_utm_source'      => '(direct)',
			'wc-stripe-payment-method'             => 'pm_123',
			'wc-stripe-payment-token'              => 'new',
			'some_gateway_data'                    => array( 'token' => 'tok_789' ),
		);

		$payment_data = $this->sut->test_extract_payment_data();

		// Should include gateway-specific keys (strings and arrays).
		$this->assertArrayHasKey( 'wc-stripe-payment-method', $payment_data );
		$this->assertSame( 'pm_123', $payment_data['wc-stripe-payment-method'] );
		$this->assertArrayHasKey( 'wc-stripe-payment-token', $payment_data );
		$this->assertSame( array( 'token' => 'tok_789' ), $payment_data['some_gateway_data'] );

		// Should exclude non-payment keys.
		$this->assertArrayNotHasKey( 'billing_first_name', $payment_data );
		$this->assertArrayNotHasKey( 'shipping_first_name', $payment_data );
		$this->assertArrayNotHasKey( 'order_comments', $payment_data );
		$this->assertArrayNotHasKey( 'account_username', $payment_data );
		$this->assertArrayNotHasKey( 'woocommerce_checkout_nonce', $payment_data );
		$this->assertArrayNotHasKey( 'woocommerce_add_payment_method', $payment_data );
		$this->assertArrayNotHasKey( 'woocommerce-add-payment-method-nonce', $payment_data );
		$this->assertArrayNotHasKey( '_wpnonce', $payment_data );
		$this->assertArrayNotHasKey( 'terms', $payment_data );
		$this->assertArrayNotHasKey( 'terms-field', $payment_data );
		$this->assertArrayNotHasKey( 'ship_to_different_address', $payment_data );
		$this->assertArrayNotHasKey( 'wc_fraud_protection_session_id', $payment_data );
		$this->assertArrayNotHasKey( 'wc_order_attribution_source_type', $payment_data );
		$this->assertArrayNotHasKey( 'wc_order_attribution_utm_source', $payment_data );
	}
}
