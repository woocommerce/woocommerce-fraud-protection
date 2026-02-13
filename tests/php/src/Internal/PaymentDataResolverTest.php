<?php
/**
 * PaymentDataResolverTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtection;

use Automattic\WooCommerce\Internal\FraudProtection\PaymentDataResolver;
use Automattic\WooCommerce\Internal\FraudProtection\Schemas\CardPaymentMethodData;
use Automattic\WooCommerce\Internal\FraudProtection\Schemas\PaymentMethodData;
use WC_Unit_Test_Case;

/**
 * Tests for the PaymentDataResolver class.
 *
 * @covers \Automattic\WooCommerce\Internal\FraudProtection\PaymentDataResolver
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
	 * @testdox Returns null when no filter callback resolves the data.
	 */
	public function test_returns_null_when_no_filter(): void {
		$result = $this->sut->resolve(
			'woocommerce_payments',
			array(
				array(
					'key'   => 'wcpay-payment-method',
					'value' => 'pm_123',
				),
			)
		);

		$this->assertNull( $result );
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
	 * @testdox Discards non-PaymentMethodData filter returns (type safety).
	 */
	public function test_discards_invalid_filter_return(): void {
		add_filter(
			'woocommerce_fraud_protection_resolved_payment_data',
			function () {
				return 'not a PaymentMethodData object';
			}
		);

		$result = $this->sut->resolve( 'test_gateway', array() );

		$this->assertNull( $result );
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
			array(
				array(
					'key'   => 'token',
					'value' => (string) $token->get_id(),
				),
			)
		);

		// Falls back to token pre-resolution, not null.
		$this->assertInstanceOf( PaymentMethodData::class, $result );
		$this->assertSame( 'visa', $result->to_array()['card']['brand'] );
	}

	/**
	 * @testdox Returns null when filter throws and no pre-resolved data exists.
	 */
	public function test_returns_null_when_filter_throws_without_preresolved(): void {
		add_filter( // @phpstan-ignore return.missing
			'woocommerce_fraud_protection_resolved_payment_data',
			function () {
				throw new \RuntimeException( 'Compat layer crashed' );
			}
		);

		$result = $this->sut->resolve( 'stripe', array() ); // @phpstan-ignore deadCode.unreachable

		$this->assertNull( $result );
	}

	/**
	 * @testdox Discards array filter returns (type safety).
	 */
	public function test_discards_array_filter_return(): void {
		add_filter(
			'woocommerce_fraud_protection_resolved_payment_data',
			function () {
				return array( 'payment_type' => 'card' );
			}
		);

		$result = $this->sut->resolve( 'test_gateway', array() );

		$this->assertNull( $result );
	}

	/**
	 * @testdox Normalizes raw [{key, value}] to key-value map before passing to filter.
	 */
	public function test_normalizes_payment_data(): void {
		$captured_data = null;

		add_filter(
			'woocommerce_fraud_protection_resolved_payment_data',
			function ( $resolved, $payment_method, $normalized ) use ( &$captured_data ) {
				$captured_data = $normalized;
				return null;
			},
			10,
			3
		);

		$this->sut->resolve(
			'test_gateway',
			array(
				array(
					'key'   => 'foo',
					'value' => 'bar',
				),
				array(
					'key'   => 'baz',
					'value' => 'qux',
				),
			)
		);

		$this->assertSame(
			array(
				'foo' => 'bar',
				'baz' => 'qux',
			),
			$captured_data
		);
	}

	/**
	 * @testdox Passes the payment_method string to the filter.
	 */
	public function test_passes_payment_method_to_filter(): void {
		$captured_method = null;

		add_filter(
			'woocommerce_fraud_protection_resolved_payment_data',
			function ( $resolved, $payment_method ) use ( &$captured_method ) {
				$captured_method = $payment_method;
				return null;
			},
			10,
			2
		);

		$this->sut->resolve( 'woocommerce_payments', array() );

		$this->assertSame( 'woocommerce_payments', $captured_method );
	}

	/**
	 * @testdox Skips malformed items in raw payment data during normalization.
	 */
	public function test_skips_malformed_items(): void {
		$captured_data = null;

		add_filter(
			'woocommerce_fraud_protection_resolved_payment_data',
			function ( $resolved, $payment_method, $normalized ) use ( &$captured_data ) {
				$captured_data = $normalized;
				return null;
			},
			10,
			3
		);

		$this->sut->resolve(
			'test_gateway',
			array(
				array(
					'key'   => 'valid',
					'value' => 'yes',
				),
				'not an array',
				array( 'missing_value_key' => true ),
				array(
					'key'   => 'also_valid',
					'value' => 'yep',
				),
			)
		);

		$this->assertSame(
			array(
				'valid'      => 'yes',
				'also_valid' => 'yep',
			),
			$captured_data
		);
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
			array(
				array(
					'key'   => 'token',
					'value' => (string) $token->get_id(),
				),
			)
		);

		$this->assertInstanceOf( PaymentMethodData::class, $result );
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
	 * @testdox Pre-resolution returns null when token belongs to a different user.
	 */
	public function test_token_preresolution_returns_null_for_other_users_token(): void {
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
			array(
				array(
					'key'   => 'token',
					'value' => (string) $token->get_id(),
				),
			)
		);

		$this->assertNull( $result );
	}

	/**
	 * @testdox Pre-resolution returns null for invalid or missing token IDs.
	 */
	public function test_token_preresolution_returns_null_for_invalid_token(): void {
		$result = $this->sut->resolve(
			'stripe',
			array(
				array(
					'key'   => 'token',
					'value' => '999999',
				),
			)
		);

		$this->assertNull( $result );
	}

	/**
	 * @testdox Pre-resolution returns null when token key is absent.
	 */
	public function test_token_preresolution_returns_null_when_no_token_key(): void {
		$result = $this->sut->resolve(
			'stripe',
			array(
				array(
					'key'   => 'wc-stripe-payment-method',
					'value' => 'pm_123',
				),
			)
		);

		$this->assertNull( $result );
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
			array(
				array(
					'key'   => 'token',
					'value' => (string) $token->get_id(),
				),
			)
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
			array(
				array(
					'key'   => 'token',
					'value' => (string) $token->get_id(),
				),
			)
		);

		$this->assertInstanceOf( PaymentMethodData::class, $captured_initial );
		$this->assertSame( $captured_initial, $result );
		$this->assertSame( 'mastercard', $result->to_array()['card']['brand'] );
	}
}
