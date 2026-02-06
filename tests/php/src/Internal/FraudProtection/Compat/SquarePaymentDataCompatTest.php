<?php
/**
 * SquarePaymentDataCompatTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtection\Compat;

use Automattic\WooCommerce\Internal\FraudProtection\Compat\SquarePaymentDataCompat;
use Automattic\WooCommerce\Internal\FraudProtection\Schemas\CardPaymentMethodData;
use Automattic\WooCommerce\Internal\FraudProtection\Schemas\PaymentMethodData;
use WC_Unit_Test_Case;

/**
 * Tests for the SquarePaymentDataCompat class.
 *
 * @covers \Automattic\WooCommerce\Internal\FraudProtection\Compat\SquarePaymentDataCompat
 */
class SquarePaymentDataCompatTest extends WC_Unit_Test_Case {

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
	 * @testdox Returns resolved for non-Square payment methods.
	 */
	public function test_returns_resolved_for_non_square(): void {
		$resolved = new PaymentMethodData( 'stripe', 'test' );

		$result = $this->sut->resolve(
			$resolved,
			'stripe',
			array( 'wc-square-credit-card-card-type' => 'visa' )
		);

		$this->assertSame( $resolved, $result );
	}

	/**
	 * @testdox Extracts card details from payment_data keys.
	 */
	public function test_extracts_card_details(): void {
		$result = $this->sut->resolve(
			null,
			'square_credit_card',
			array(
				'wc-square-credit-card-card-type'  => 'visa',
				'wc-square-credit-card-last-four'  => '1234',
				'wc-square-credit-card-exp-month'  => '6',
				'wc-square-credit-card-exp-year'   => '2028',
			)
		);

		$this->assertInstanceOf( PaymentMethodData::class, $result );
		$this->assertSame(
			array(
				'gateway'                 => 'square_credit_card',
				'payment_type'            => 'card',
				'is_saved_payment_method' => false,
				'card'                    => array(
					'brand'            => 'visa',
					'funding'          => null,
					'last4'            => '1234',
					'fingerprint'      => null,
					'country'          => null,
					'exp_month'        => 6,
					'exp_year'         => 2028,
					'billing_postcode' => null,
				),
			),
			$result->to_array()
		);
	}

	/**
	 * @testdox Extracts billing postcode from payment_data keys.
	 */
	public function test_extracts_billing_postcode(): void {
		$result = $this->sut->resolve(
			null,
			'square_credit_card',
			array(
				'wc-square-credit-card-card-type'         => 'visa',
				'wc-square-credit-card-last-four'         => '1234',
				'wc-square-credit-card-exp-month'         => '6',
				'wc-square-credit-card-exp-year'          => '2028',
				'wc-square-credit-card-payment-postcode'  => '90210',
			)
		);

		$this->assertInstanceOf( PaymentMethodData::class, $result );
		$this->assertSame( '90210', $result->to_array()['card']['billing_postcode'] );
	}

	/**
	 * @testdox Detects saved card via payment token presence.
	 */
	public function test_detects_saved_card(): void {
		$result = $this->sut->resolve(
			null,
			'square_credit_card',
			array(
				'wc-square-credit-card-payment-token' => 'token_abc123',
				'wc-square-credit-card-card-type'     => 'mastercard',
				'wc-square-credit-card-last-four'     => '9999',
			)
		);

		$this->assertInstanceOf( PaymentMethodData::class, $result );
		$this->assertTrue( $result->to_array()['is_saved_payment_method'] );
	}

	/**
	 * @testdox Saved card with empty card keys returns pre-resolved token data.
	 */
	public function test_saved_card_with_empty_keys_returns_resolved(): void {
		$token_data = new PaymentMethodData(
			'square_credit_card',
			'card',
			true,
			new CardPaymentMethodData( 'visa', null, '4242', null, null, 12, 2028 )
		);

		$result = $this->sut->resolve(
			$token_data,
			'square_credit_card',
			array(
				'wc-square-credit-card-payment-token' => 'token_abc123',
			)
		);

		$this->assertSame( $token_data, $result );
	}
}
