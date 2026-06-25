<?php
/**
 * SquarePaymentDataCompatTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\FraudProtection\Compat;

use Automattic\WooCommerce\FraudProtection\Compat\SquarePaymentDataCompat;
use Automattic\WooCommerce\FraudProtection\Schemas\PaymentInstrumentData;
use Automattic\WooCommerce\FraudProtection\Schemas\PaymentMethodData;
use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;

// Stub wc_square() in the global namespace if not loaded.
require_once __DIR__ . '/../../stubs/wc-square.php';

/**
 * Tests for the SquarePaymentDataCompat class.
 *
 * @covers \Automattic\WooCommerce\FraudProtection\Compat\SquarePaymentDataCompat
 */
class SquarePaymentDataCompatTest extends FraudProtectionUnitTestCase {

	/**
	 * The System Under Test.
	 *
	 * @var SquarePaymentDataCompat
	 */
	private SquarePaymentDataCompat $sut;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->sut = new SquarePaymentDataCompat();
	}

	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		\WC_Square_Settings_Stub::set_sandbox( false );
		parent::tearDown();
	}

	/**
	 * @testdox Returns resolved for non-Square payment methods.
	 */
	public function test_returns_resolved_for_non_square(): void {
		$resolved = new PaymentMethodData( 'stripe', 'card' );

		$result = $this->sut->resolve(
			$resolved,
			array( 'wc-square-credit-card-card-type' => 'visa' )
		);

		$this->assertSame( $resolved, $result );
	}

	/**
	 * @testdox Extracts card details from payment_data keys.
	 */
	public function test_extracts_card_details(): void {
		$result = $this->sut->resolve(
			new PaymentMethodData( 'square_credit_card' ),
			array(
				'wc-square-credit-card-card-type'         => 'visa',
				'wc-square-credit-card-last-four'         => '1234',
				'wc-square-credit-card-exp-month'         => '6',
				'wc-square-credit-card-exp-year'          => '2028',
				'wc-square-credit-card-payment-postcode'  => '90210',
			)
		);

		$this->assertSame(
			array(
				'gateway'                 => 'square_credit_card',
				'payment_type'            => 'card',
				'is_saved_payment_method' => false,
				'instrument'              => array(
					'brand'            => 'visa',
					'funding'          => null,
					'last4'            => '1234',
					'fingerprint'      => null,
					'country'          => null,
					'exp_month'        => 6,
					'exp_year'         => 2028,
					'billing_postcode' => '90210',
					'wallet'           => null,
					'bank_code'        => null,
					'bin'                => null,
					'cvc_check'          => null,
					'avs_address_check'  => null,
					'avs_postcode_check' => null,
				),
				'transaction_mode'        => PaymentMethodData::MODE_LIVE,
			),
			$result->to_array()
		);
	}

	/**
	 * @testdox Detects saved card via payment token presence.
	 */
	public function test_detects_saved_card(): void {
		$result = $this->sut->resolve(
			new PaymentMethodData( 'square_credit_card' ),
			array(
				'wc-square-credit-card-payment-token' => 'token_abc123',
				'wc-square-credit-card-card-type'     => 'mastercard',
				'wc-square-credit-card-last-four'     => '9999',
			)
		);

		$this->assertTrue( $result->to_array()['is_saved_payment_method'] );
	}

	/**
	 * @testdox Marks as not saved when payment token is "new".
	 */
	public function test_not_saved_when_payment_token_is_new(): void {
		$result = $this->sut->resolve(
			new PaymentMethodData( 'square_credit_card' ),
			array(
				'wc-square-credit-card-payment-token' => 'new',
				'wc-square-credit-card-card-type'     => 'visa',
				'wc-square-credit-card-last-four'     => '4242',
			)
		);

		$this->assertFalse( $result->to_array()['is_saved_payment_method'] );
	}

	/**
	 * @testdox Saved card with empty card keys preserves token data.
	 */
	public function test_saved_card_with_empty_keys_preserves_token_data(): void {
		$token_data = new PaymentMethodData(
			'square_credit_card',
			'card',
			true,
			PaymentInstrumentData::from_array( array( 'brand' => 'visa', 'last4' => '4242', 'exp_month' => 12, 'exp_year' => 2028 ) )
		);

		$result = $this->sut->resolve(
			$token_data,
			array(
				'wc-square-credit-card-payment-token' => 'token_abc123',
			)
		);

		$this->assertNotSame( $token_data, $result );
		$array = $result->to_array();
		$this->assertSame( 'card', $array['payment_type'] );
		$this->assertTrue( $array['is_saved_payment_method'] );
		$this->assertSame( 'visa', $array['instrument']['brand'] );
		$this->assertSame( '4242', $array['instrument']['last4'] );
	}

	/**
	 * @testdox Saved card with empty card keys and sandbox mode sets test mode.
	 */
	public function test_saved_card_with_empty_keys_sets_mode(): void {
		\WC_Square_Settings_Stub::set_sandbox( true );

		$token_data = new PaymentMethodData(
			'square_credit_card',
			'card',
			true,
			PaymentInstrumentData::from_array( array( 'brand' => 'visa', 'last4' => '4242', 'exp_month' => 12, 'exp_year' => 2028 ) )
		);

		$result = $this->sut->resolve(
			$token_data,
			array(
				'wc-square-credit-card-payment-token' => 'token_abc123',
			)
		);

		$this->assertNotSame( $token_data, $result );
		$array = $result->to_array();
		$this->assertSame( PaymentMethodData::MODE_TEST, $array['transaction_mode'] );
		$this->assertSame( 'visa', $array['instrument']['brand'] );
		$this->assertSame( '4242', $array['instrument']['last4'] );
	}

	/**
	 * @testdox Includes test mode when Square is in sandbox.
	 */
	public function test_includes_test_mode(): void {
		\WC_Square_Settings_Stub::set_sandbox( true );

		$result = $this->sut->resolve(
			new PaymentMethodData( 'square_credit_card' ),
			array(
				'wc-square-credit-card-card-type' => 'visa',
				'wc-square-credit-card-last-four' => '4242',
			)
		);

		$this->assertSame( PaymentMethodData::MODE_TEST, $result->to_array()['transaction_mode'] );
	}

	/**
	 * @testdox Includes live mode when Square is in production.
	 */
	public function test_includes_live_mode(): void {
		\WC_Square_Settings_Stub::set_sandbox( false );

		$result = $this->sut->resolve(
			new PaymentMethodData( 'square_credit_card' ),
			array(
				'wc-square-credit-card-card-type' => 'visa',
				'wc-square-credit-card-last-four' => '4242',
			)
		);

		$this->assertSame( PaymentMethodData::MODE_LIVE, $result->to_array()['transaction_mode'] );
	}
}
