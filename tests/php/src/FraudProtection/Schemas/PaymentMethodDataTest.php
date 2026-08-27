<?php
/**
 * PaymentMethodDataTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\FraudProtection\Schemas;

use Automattic\WooCommerce\FraudProtection\Schemas\MerchantIdentifierType;
use Automattic\WooCommerce\FraudProtection\Schemas\PaymentInstrumentData;
use Automattic\WooCommerce\FraudProtection\Schemas\PaymentMethodData;
use Automattic\WooCommerce\FraudProtection\Schemas\PaymentMode;
use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;

/**
 * Tests for the PaymentMethodData class.
 *
 * @covers \Automattic\WooCommerce\FraudProtection\Schemas\PaymentMethodData
 */
class PaymentMethodDataTest extends FraudProtectionUnitTestCase {

	/**
	 * @testdox Constructor sets all properties; to_array() returns correct values.
	 */
	public function test_constructor_and_to_array(): void {
		$instrument = PaymentInstrumentData::from_array( array( 'brand' => 'visa', 'funding' => 'credit', 'last4' => '4242' ) );
		$data       = new PaymentMethodData( 'stripe', 'card', true, $instrument );

		$result = $data->to_array();

		$this->assertSame( 'card', $result['payment_type'] );
		$this->assertTrue( $result['is_saved_payment_method'] );
		$this->assertIsArray( $result['instrument'] );
		$this->assertSame( 'visa', $result['instrument']['brand'] );
	}

	/**
	 * @testdox Default is_saved_payment_method is false.
	 */
	public function test_default_is_saved_payment_method_is_false(): void {
		$data = new PaymentMethodData( 'stripe', 'card' );

		$this->assertFalse( $data->to_array()['is_saved_payment_method'] );
	}

	/**
	 * @testdox to_array() produces empty instrument when no instrument data.
	 */
	public function test_to_array_empty_instrument(): void {
		$data = new PaymentMethodData( 'stripe_ideal', 'ideal', false );

		$result = $data->to_array();

		$this->assertSame( 'ideal', $result['payment_type'] );
		$this->assertFalse( $result['is_saved_payment_method'] );
		$this->assertSame( PaymentInstrumentData::empty()->to_array(), $result['instrument'] );
	}

	/**
	 * @testdox to_array() includes full instrument data when instrument is present.
	 */
	public function test_to_array_with_full_instrument(): void {
		$instrument = PaymentInstrumentData::from_array( array( 'brand' => 'mastercard', 'funding' => 'debit', 'last4' => '5678', 'fingerprint' => 'fp_abc', 'country' => 'GB', 'exp_month' => 11, 'exp_year' => 2026 ) );
		$data       = new PaymentMethodData( 'stripe', 'card', true, $instrument );

		$this->assertSame(
			array(
				'gateway'                 => 'stripe',
				'payment_type'            => 'card',
				'is_saved_payment_method' => true,
				'instrument'              => array(
					'brand'            => 'mastercard',
					'funding'          => 'debit',
					'last4'            => '5678',
					'fingerprint'      => 'fp_abc',
					'country'          => 'GB',
					'exp_month'        => 11,
					'exp_year'         => 2026,
					'billing_postcode' => null,
					'wallet'           => null,
					'bank_code'        => null,
					'bin'                => null,
					'cvc_check'          => null,
					'avs_address_check'  => null,
					'avs_postcode_check' => null,
				),
				'transaction_mode'        => PaymentMode::Unknown->value,
				'merchant_identifier'     => null,
				'merchant_identifier_type' => null,
			),
			$data->to_array()
		);
	}

	/**
	 * @testdox Valid transaction_mode values are preserved.
	 */
	public function test_valid_transaction_modes_preserved(): void {
		$test    = new PaymentMethodData( 'stripe', 'card', false, null, PaymentMode::Test );
		$live    = new PaymentMethodData( 'stripe', 'card', false, null, PaymentMode::Live );
		$unknown = new PaymentMethodData( 'stripe', 'card', false, null, PaymentMode::Unknown );

		$this->assertSame( PaymentMode::Test->value, $test->to_array()['transaction_mode'] );
		$this->assertSame( PaymentMode::Live->value, $live->to_array()['transaction_mode'] );
		$this->assertSame( PaymentMode::Unknown->value, $unknown->to_array()['transaction_mode'] );
	}

	/**
	 * @testdox to_array() includes a complete merchant identifier pair.
	 */
	public function test_to_array_includes_merchant_identifier_pair(): void {
		$data = new PaymentMethodData( 'stripe', 'card', false, null, PaymentMode::Live, 'acct_123', MerchantIdentifierType::Account );

		$this->assertSame( 'acct_123', $data->to_array()['merchant_identifier'] );
		$this->assertSame( MerchantIdentifierType::Account->value, $data->to_array()['merchant_identifier_type'] );
	}

	/**
	 * @testdox to_array() normalizes an unavailable merchant identifier pair to null.
	 *
	 * @dataProvider incomplete_merchant_identifier_provider
	 */
	public function test_to_array_normalizes_unavailable_merchant_identifier_pair( ?string $identifier ): void {
		$data  = new PaymentMethodData( 'stripe', 'card', false, null, PaymentMode::Unknown, $identifier, MerchantIdentifierType::Account );
		$array = $data->to_array();

		$this->assertNull( $array['merchant_identifier'] );
		$this->assertNull( $array['merchant_identifier_type'] );
	}

	/**
	 * @return array<string, array{?string}>
	 */
	public function incomplete_merchant_identifier_provider(): array {
		return array(
			'missing identifier' => array( null ),
			'empty identifier'   => array( '' ),
		);
	}

	/**
	 * @testdox with_merchant_identifier() and with_transaction_mode() preserve the identifier pair.
	 */
	public function test_copy_methods_preserve_merchant_identifier_pair(): void {
		$instrument = PaymentInstrumentData::from_array( array( 'brand' => 'visa', 'last4' => '4242' ) );
		$data       = new PaymentMethodData( 'stripe', 'card', true, $instrument, PaymentMode::Live );

		$with_identifier = $data->with_merchant_identifier( 'acct_123', MerchantIdentifierType::Account )->to_array();
		$result          = $data->with_merchant_identifier( 'acct_123', MerchantIdentifierType::Account )
			->with_transaction_mode( PaymentMode::Test )->to_array();

		foreach ( array( $with_identifier, $result ) as $array ) {
			$this->assertSame( 'card', $array['payment_type'] );
			$this->assertTrue( $array['is_saved_payment_method'] );
			$this->assertSame( 'visa', $array['instrument']['brand'] );
			$this->assertSame( '4242', $array['instrument']['last4'] );
			$this->assertSame( 'acct_123', $array['merchant_identifier'] );
			$this->assertSame( MerchantIdentifierType::Account->value, $array['merchant_identifier_type'] );
		}

		$this->assertSame( PaymentMode::Live->value, $with_identifier['transaction_mode'] );
		$this->assertSame( PaymentMode::Test->value, $result['transaction_mode'] );
	}

	/**
	 * @testdox with_transaction_mode() preserves all other fields.
	 */
	public function test_with_transaction_mode_preserves_fields(): void {
		$instrument = PaymentInstrumentData::from_array( array( 'brand' => 'visa', 'funding' => 'credit', 'last4' => '4242', 'fingerprint' => 'fp_abc', 'country' => 'US', 'exp_month' => 12, 'exp_year' => 2028, 'billing_postcode' => '10001' ) );
		$original   = new PaymentMethodData( 'stripe', 'card', true, $instrument );

		$result = $original->with_transaction_mode( PaymentMode::Test );

		$expected = array_merge(
			$original->to_array(),
			array( 'transaction_mode' => PaymentMode::Test->value )
		);

		$this->assertSame( $expected, $result->to_array() );
	}
}
