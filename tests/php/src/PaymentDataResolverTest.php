<?php
/**
 * PaymentDataResolverTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal;

use Automattic\WooCommerce\FraudProtection\PaymentDataResolver;
use Automattic\WooCommerce\FraudProtection\Schemas\CardPaymentMethodData;
use Automattic\WooCommerce\FraudProtection\Schemas\PaymentMethodData;
use WC_Unit_Test_Case;

/**
 * Tests for the PaymentDataResolver class.
 *
 * @covers \Automattic\WooCommerce\FraudProtection\PaymentDataResolver
 */
class PaymentDataResolverTest extends WC_Unit_Test_Case {

	/**
	 * The System Under Test.
	 *
	 * @var PaymentDataResolver
	 */
	private PaymentDataResolver $sut;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->sut = new PaymentDataResolver();
	}

	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		remove_all_filters( 'woocommerce_fraud_protection_resolved_payment_data' );

		parent::tearDown();
	}

	/**
	 * @testdox Returns baseline when no filter callback resolves the data.
	 */
	public function test_returns_baseline_when_no_filter(): void {
		$result = $this->sut->resolve(
			'woocommerce_payments',
			array( 'wcpay-payment-method' => 'pm_123' )
		);

		$this->assertSame( 'woocommerce_payments', $result->to_array()['gateway'] );
		$this->assertNull( $result->to_array()['payment_type'] );
	}

	/**
	 * @testdox Returns PaymentMethodData when filter returns valid instance.
	 */
	public function test_returns_payment_method_data_from_filter(): void {
		$expected = new PaymentMethodData(
			'test_gateway',
			'card',
			false,
			new CardPaymentMethodData( 'visa', 'credit', '4242' )
		);

		add_filter(
			'woocommerce_fraud_protection_resolved_payment_data',
			function () use ( $expected ) {
				return $expected;
			}
		);

		$result = $this->sut->resolve( 'test_gateway', array() );

		$this->assertSame( $expected, $result );
	}

	/**
	 * @testdox Discards non-PaymentMethodData filter returns and falls back to baseline.
	 */
	public function test_discards_invalid_filter_return(): void {
		add_filter(
			'woocommerce_fraud_protection_resolved_payment_data',
			function () {
				return 'not a PaymentMethodData object';
			}
		);

		$result = $this->sut->resolve( 'test_gateway', array() );

		$this->assertSame( 'test_gateway', $result->to_array()['gateway'] );
		$this->assertNull( $result->to_array()['payment_type'] );
	}

	/**
	 * @testdox Falls back to pre-resolved data when a filter callback throws an exception.
	 */
	public function test_falls_back_when_filter_throws(): void {
		$token = new \WC_Payment_Token_CC();
		$token->set_gateway_id( 'stripe' );
		$token->set_card_type( 'visa' );
		$token->set_last4( '4242' );
		$token->set_expiry_month( '12' );
		$token->set_expiry_year( '2028' );
		$token->set_token( 'tok_throw_test' );
		$token->set_user_id( get_current_user_id() );
		$token->save();

		add_filter( // @phpstan-ignore return.missing
			'woocommerce_fraud_protection_resolved_payment_data',
			function () {
				throw new \RuntimeException( 'Compat layer crashed' );
			}
		);

		$result = $this->sut->resolve( // @phpstan-ignore deadCode.unreachable
			'stripe',
			array( 'token' => (string) $token->get_id() )
		);

		// Falls back to token pre-resolution, not baseline.
		$this->assertSame( 'visa', $result->to_array()['card']['brand'] );
	}

	/**
	 * @testdox Returns baseline when filter throws and no pre-resolved token data exists.
	 */
	public function test_returns_baseline_when_filter_throws_without_preresolved(): void {
		add_filter( // @phpstan-ignore return.missing
			'woocommerce_fraud_protection_resolved_payment_data',
			function () {
				throw new \RuntimeException( 'Compat layer crashed' );
			}
		);

		$result = $this->sut->resolve( 'stripe', array() ); // @phpstan-ignore deadCode.unreachable

		$this->assertSame( 'stripe', $result->to_array()['gateway'] );
		$this->assertNull( $result->to_array()['payment_type'] );
	}

	/**
	 * @testdox Discards array filter returns and falls back to baseline.
	 */
	public function test_discards_array_filter_return(): void {
		add_filter(
			'woocommerce_fraud_protection_resolved_payment_data',
			function () {
				return array( 'payment_type' => 'card' );
			}
		);

		$result = $this->sut->resolve( 'test_gateway', array() );

		$this->assertSame( 'test_gateway', $result->to_array()['gateway'] );
		$this->assertNull( $result->to_array()['payment_type'] );
	}

	/**
	 * @testdox Passes payment_method and flat key-value payment_data to the filter.
	 */
	public function test_passes_payment_method_and_data_to_filter(): void {
		$captured_method = null;
		$captured_data   = null;

		add_filter(
			'woocommerce_fraud_protection_resolved_payment_data',
			function ( $resolved, $payment_method, $payment_data ) use ( &$captured_method, &$captured_data ) {
				$captured_method = $payment_method;
				$captured_data   = $payment_data;
				return null; // Filter returns null — resolver falls back to baseline.
			},
			10,
			3
		);

		$input = array(
			'foo' => 'bar',
			'baz' => 'qux',
		);

		$result = $this->sut->resolve( 'woocommerce_payments', $input );

		$this->assertSame( 'woocommerce_payments', $captured_method );
		$this->assertSame( $input, $captured_data );
		$this->assertSame( 'woocommerce_payments', $result->to_array()['gateway'] );
	}

	/**
	 * @testdox Pre-resolves PaymentMethodData from a WC payment token when token key is present.
	 */
	public function test_token_preresolution_provides_payment_method_data(): void {
		$token = new \WC_Payment_Token_CC();
		$token->set_gateway_id( 'stripe' );
		$token->set_card_type( 'visa' );
		$token->set_last4( '4242' );
		$token->set_expiry_month( '12' );
		$token->set_expiry_year( '2028' );
		$token->set_token( 'tok_test_123' );
		$token->set_user_id( get_current_user_id() );
		$token->save();

		$result = $this->sut->resolve(
			'stripe',
			array( 'token' => (string) $token->get_id() )
		);

		$array = $result->to_array();
		$this->assertSame( 'card', $array['payment_type'] );
		$this->assertTrue( $array['is_saved_payment_method'] );
		$this->assertSame( 'stripe', $array['gateway'] );
		$this->assertSame( 'visa', $array['card']['brand'] );
		$this->assertSame( '4242', $array['card']['last4'] );
		$this->assertSame( 12, $array['card']['exp_month'] );
		$this->assertSame( 2028, $array['card']['exp_year'] );
	}

	/**
	 * @testdox Pre-resolution returns baseline when token belongs to a different user.
	 */
	public function test_token_preresolution_returns_baseline_for_other_users_token(): void {
		$token = new \WC_Payment_Token_CC();
		$token->set_gateway_id( 'stripe' );
		$token->set_card_type( 'visa' );
		$token->set_last4( '4242' );
		$token->set_expiry_month( '12' );
		$token->set_expiry_year( '2028' );
		$token->set_token( 'tok_other_user' );
		$token->set_user_id( 99999 );
		$token->save();

		$result = $this->sut->resolve(
			'stripe',
			array( 'token' => (string) $token->get_id() )
		);

		$this->assertSame( 'stripe', $result->to_array()['gateway'] );
		$this->assertNull( $result->to_array()['payment_type'] );
	}

	/**
	 * @testdox Pre-resolution returns baseline for invalid or missing token IDs.
	 */
	public function test_token_preresolution_returns_baseline_for_invalid_token(): void {
		$result = $this->sut->resolve(
			'stripe',
			array( 'token' => '999999' )
		);

		$this->assertSame( 'stripe', $result->to_array()['gateway'] );
		$this->assertNull( $result->to_array()['payment_type'] );
	}

	/**
	 * @testdox Pre-resolution returns baseline when token key is absent.
	 */
	public function test_token_preresolution_returns_baseline_when_no_token_key(): void {
		$result = $this->sut->resolve(
			'stripe',
			array( 'wc-stripe-payment-method' => 'pm_123' )
		);

		$this->assertSame( 'stripe', $result->to_array()['gateway'] );
		$this->assertNull( $result->to_array()['payment_type'] );
	}

	/**
	 * @testdox Pre-resolves from wc-{gateway}-payment-token key (classic checkout).
	 */
	public function test_token_preresolution_from_classic_checkout_key(): void {
		$token = new \WC_Payment_Token_CC();
		$token->set_gateway_id( 'stripe' );
		$token->set_card_type( 'visa' );
		$token->set_last4( '4242' );
		$token->set_expiry_month( '12' );
		$token->set_expiry_year( '2028' );
		$token->set_token( 'tok_classic_test' );
		$token->set_user_id( get_current_user_id() );
		$token->save();

		$result = $this->sut->resolve(
			'stripe',
			array( 'wc-stripe-payment-token' => (string) $token->get_id() )
		);

		$array = $result->to_array();
		$this->assertSame( 'stripe', $array['gateway'] );
		$this->assertSame( 'visa', $array['card']['brand'] );
		$this->assertSame( '4242', $array['card']['last4'] );
		$this->assertTrue( $array['is_saved_payment_method'] );
	}

	/**
	 * @testdox Pre-resolves from dasherized gateway key (e.g. Square's SkyVerge framework).
	 */
	public function test_token_preresolution_from_dasherized_gateway_key(): void {
		$token = new \WC_Payment_Token_CC();
		$token->set_gateway_id( 'square_credit_card' );
		$token->set_card_type( 'visa' );
		$token->set_last4( '1111' );
		$token->set_expiry_month( '03' );
		$token->set_expiry_year( '2029' );
		$token->set_token( 'tok_square_dasherized' );
		$token->set_user_id( get_current_user_id() );
		$token->save();

		$result = $this->sut->resolve(
			'square_credit_card',
			array( 'wc-square-credit-card-payment-token' => (string) $token->get_id() )
		);

		$array = $result->to_array();
		$this->assertSame( 'square_credit_card', $array['gateway'] );
		$this->assertSame( 'visa', $array['card']['brand'] );
		$this->assertSame( '1111', $array['card']['last4'] );
		$this->assertTrue( $array['is_saved_payment_method'] );
	}

	/**
	 * @testdox Pre-resolution falls back to bare token key when gateway key is absent.
	 */
	public function test_token_preresolution_falls_back_to_bare_token_key(): void {
		$token = new \WC_Payment_Token_CC();
		$token->set_gateway_id( 'stripe' );
		$token->set_card_type( 'mastercard' );
		$token->set_last4( '5678' );
		$token->set_expiry_month( '06' );
		$token->set_expiry_year( '2027' );
		$token->set_token( 'tok_bare_fallback' );
		$token->set_user_id( get_current_user_id() );
		$token->save();

		$result = $this->sut->resolve(
			'stripe',
			array( 'token' => (string) $token->get_id() )
		);

		$this->assertSame( 'mastercard', $result->to_array()['card']['brand'] );
	}

	/**
	 * @testdox Pre-resolution returns baseline when classic key value is "new".
	 */
	public function test_token_preresolution_returns_baseline_for_new_payment_method(): void {
		$result = $this->sut->resolve(
			'stripe',
			array( 'wc-stripe-payment-token' => 'new' )
		);

		$this->assertSame( 'stripe', $result->to_array()['gateway'] );
		$this->assertNull( $result->to_array()['payment_type'] );
	}

	/**
	 * @testdox Filter can override token-based pre-resolved data.
	 */
	public function test_filter_can_override_token_data(): void {
		$token = new \WC_Payment_Token_CC();
		$token->set_gateway_id( 'stripe' );
		$token->set_card_type( 'visa' );
		$token->set_last4( '4242' );
		$token->set_expiry_month( '12' );
		$token->set_expiry_year( '2028' );
		$token->set_token( 'tok_test_456' );
		$token->set_user_id( get_current_user_id() );
		$token->save();

		$override = new PaymentMethodData(
			'stripe',
			'card',
			true,
			new CardPaymentMethodData( 'visa', 'credit', '4242', 'fp_abc', 'US', 12, 2028 )
		);

		add_filter(
			'woocommerce_fraud_protection_resolved_payment_data',
			function () use ( $override ) {
				return $override;
			}
		);

		$result = $this->sut->resolve(
			'stripe',
			array( 'token' => (string) $token->get_id() )
		);

		$this->assertSame( $override, $result );
	}

	/**
	 * @testdox Filter receives token-based data as initial value and can pass it through.
	 */
	public function test_filter_passes_through_token_data(): void {
		$token = new \WC_Payment_Token_CC();
		$token->set_gateway_id( 'square_credit_card' );
		$token->set_card_type( 'mastercard' );
		$token->set_last4( '5678' );
		$token->set_expiry_month( '06' );
		$token->set_expiry_year( '2027' );
		$token->set_token( 'tok_test_789' );
		$token->set_user_id( get_current_user_id() );
		$token->save();

		$captured_initial = null;

		add_filter(
			'woocommerce_fraud_protection_resolved_payment_data',
			function ( $resolved ) use ( &$captured_initial ) {
				$captured_initial = $resolved;
				return $resolved; // Pass through.
			}
		);

		$result = $this->sut->resolve(
			'square_credit_card',
			array( 'token' => (string) $token->get_id() )
		);

		$this->assertSame( $captured_initial, $result );
		$this->assertSame( 'mastercard', $result->to_array()['card']['brand'] );
	}
}
