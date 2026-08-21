<?php
/**
 * PaymentDataResolverTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin;

use Automattic\WooCommerce\Internal\FraudProtectionPlugin\PaymentDataResolver;
use Automattic\WooCommerce\FraudProtection\Schemas\PaymentInstrumentData;
use Automattic\WooCommerce\FraudProtection\Schemas\PaymentMethodData;
use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;

/**
 * Tests for the PaymentDataResolver class.
 *
 * @covers \Automattic\WooCommerce\Internal\FraudProtectionPlugin\PaymentDataResolver
 */
class PaymentDataResolverTest extends FraudProtectionUnitTestCase {

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

		wp_set_current_user( $this->factory->user->create() );
		$this->sut = new PaymentDataResolver();
	}

	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		remove_all_filters( 'woocommerce_fraud_protection_resolved_payment_data' );
		wp_set_current_user( 0 );

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
			PaymentInstrumentData::from_array( array( 'brand' => 'visa', 'funding' => 'credit', 'last4' => '4242' ) )
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
		$spy     = $this->spy_on_controller_logging();
		$invalid = (object) array( 'marker' => 'invalid-filter-value' );

		add_filter(
			'woocommerce_fraud_protection_resolved_payment_data',
			function () use ( $invalid ) {
				return $invalid;
			}
		);

		$result = $this->sut->resolve( 'test_gateway', array( 'submitted' => 'payment-field-value' ) );

		$this->assertSame( 'test_gateway', $result->to_array()['gateway'] );
		$this->assertNull( $result->to_array()['payment_type'] );
		$this->assertLogged(
			'warning',
			'returned unexpected type',
			array(
				'argument_type'             => 'object',
				'argument_class'            => \stdClass::class,
				'pre_resolved_payment_data' => $result->to_array(),
			),
			true
		);
		$this->assertSame(
			array(
				'filter'                    => 'woocommerce_fraud_protection_resolved_payment_data',
				'payment_type'              => 'test_gateway',
				'argument_type'             => 'object',
				'pre_resolved_payment_data' => $result->to_array(),
				'argument_class'            => \stdClass::class,
			),
			$spy->entries[0]['context']
		);
	}

	/**
	 * @testdox Falls back to pre-resolved data when a filter callback throws an exception.
	 */
	public function test_falls_back_when_filter_throws(): void {
		$token = $this->create_card_token( 'stripe' );
		$spy   = $this->spy_on_controller_logging();
		$error = new \RuntimeException( 'Compat layer crashed with exception-value-marker' );

		add_filter( // @phpstan-ignore return.missing
			'woocommerce_fraud_protection_resolved_payment_data',
			function () use ( $error ) {
				throw $error;
			}
		);

		$result = $this->sut->resolve( // @phpstan-ignore deadCode.unreachable
			'stripe',
			array(
				'token'                => (string) $token->get_id(),
				'gateway-value-marker' => 'submitted-payment-value-marker',
			)
		);

		// Falls back to token pre-resolution, not baseline.
		$this->assertSame( 'visa', $result->to_array()['instrument']['brand'] );

		$this->assertLogged(
			'warning',
			'woocommerce_fraud_protection_resolved_payment_data` threw',
			null,
			true
		);
		$this->assertSame(
			array(
				'filter'                    => 'woocommerce_fraud_protection_resolved_payment_data',
				'payment_type'              => 'stripe',
				'exception_class'           => \RuntimeException::class,
				'exception_message'         => $error->getMessage(),
				'exception_file'            => $error->getFile(),
				'exception_line'            => $error->getLine(),
				'pre_resolved_payment_data' => $result->to_array(),
			),
			$spy->entries[0]['context']
		);
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
	 * @testdox Passes baseline PaymentMethodData and flat key-value payment_data to the filter.
	 */
	public function test_passes_baseline_and_data_to_filter(): void {
		$captured_resolved = null;
		$captured_data     = null;

		add_filter(
			'woocommerce_fraud_protection_resolved_payment_data',
			function ( $resolved, $payment_data ) use ( &$captured_resolved, &$captured_data ) {
				$captured_resolved = $resolved;
				$captured_data     = $payment_data;
				return null; // Filter returns null — resolver falls back to baseline.
			},
			10,
			2
		);

		$input = array(
			'foo' => 'bar',
			'baz' => 'qux',
		);

		$result = $this->sut->resolve( 'woocommerce_payments', $input );

		$this->assertInstanceOf( PaymentMethodData::class, $captured_resolved );
		$this->assertSame( 'woocommerce_payments', $captured_resolved->get_gateway() );
		$this->assertSame( $input, $captured_data );
		$this->assertSame( 'woocommerce_payments', $result->to_array()['gateway'] );
	}

	/**
	 * @testdox Pre-resolves PaymentMethodData from a WC payment token when token key is present.
	 */
	public function test_token_preresolution_provides_payment_method_data(): void {
		$token = $this->create_card_token( 'stripe' );

		$result = $this->sut->resolve(
			'stripe',
			array( 'token' => (string) $token->get_id() )
		);

		$array = $result->to_array();
		$this->assertSame( 'card', $array['payment_type'] );
		$this->assertTrue( $array['is_saved_payment_method'] );
		$this->assertSame( 'stripe', $array['gateway'] );
		$this->assertSame( 'visa', $array['instrument']['brand'] );
		$this->assertSame( '4242', $array['instrument']['last4'] );
		$this->assertSame( 12, $array['instrument']['exp_month'] );
		$this->assertSame( 2028, $array['instrument']['exp_year'] );
	}

	/**
	 * Saved-token fields that must enforce the gateway match.
	 *
	 * @return array<string, array{string, string}>
	 */
	public function mismatched_gateway_token_field_provider(): array {
		return array(
			'bare token field'               => array( 'stripe', 'token' ),
			'classic selected-gateway field' => array( 'stripe', 'wc-stripe-payment-token' ),
			'dasherized Square field'        => array( 'square_credit_card', 'wc-square-credit-card-payment-token' ),
		);
	}

	/**
	 * @testdox Pre-resolution returns the selected gateway baseline for a saved token from another gateway.
	 *
	 * @dataProvider mismatched_gateway_token_field_provider
	 *
	 * @param string $selected_gateway The selected gateway ID.
	 * @param string $token_field       The submitted token field.
	 */
	public function test_token_preresolution_returns_baseline_for_token_from_other_gateway( string $selected_gateway, string $token_field ): void {
		$token = $this->create_card_token( 'woocommerce_payments' );

		$actual = $this->sut->resolve(
			$selected_gateway,
			array( $token_field => (string) $token->get_id() )
		)->to_array();

		$this->assertSame( $selected_gateway, $actual['gateway'] );
		$this->assertNull( $actual['payment_type'] );
		$this->assertFalse( $actual['is_saved_payment_method'] );
		$this->assertNull( $actual['instrument']['brand'] );
		$this->assertNull( $actual['instrument']['last4'] );
		$this->assertNull( $actual['instrument']['exp_month'] );
		$this->assertNull( $actual['instrument']['exp_year'] );
	}

	/**
	 * @testdox Pre-resolution returns baseline when token belongs to a different user.
	 */
	public function test_token_preresolution_returns_baseline_for_other_users_token(): void {
		$token = $this->create_card_token( 'stripe', 99999 );

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
		$token = $this->create_card_token( 'stripe' );

		$result = $this->sut->resolve(
			'stripe',
			array( 'wc-stripe-payment-token' => (string) $token->get_id() )
		);

		$array = $result->to_array();
		$this->assertSame( 'stripe', $array['gateway'] );
		$this->assertSame( 'visa', $array['instrument']['brand'] );
		$this->assertSame( '4242', $array['instrument']['last4'] );
		$this->assertTrue( $array['is_saved_payment_method'] );
	}

	/**
	 * @testdox Pre-resolves from dasherized gateway key (e.g. Square's SkyVerge framework).
	 */
	public function test_token_preresolution_from_dasherized_gateway_key(): void {
		$token = $this->create_card_token( 'square_credit_card' );

		$result = $this->sut->resolve(
			'square_credit_card',
			array( 'wc-square-credit-card-payment-token' => (string) $token->get_id() )
		);

		$array = $result->to_array();
		$this->assertSame( 'square_credit_card', $array['gateway'] );
		$this->assertSame( 'visa', $array['instrument']['brand'] );
		$this->assertSame( '4242', $array['instrument']['last4'] );
		$this->assertTrue( $array['is_saved_payment_method'] );
	}

	/**
	 * @testdox Pre-resolution falls back to bare token key when gateway key is absent.
	 */
	public function test_token_preresolution_falls_back_to_bare_token_key(): void {
		$token = $this->create_card_token( 'stripe' );

		$result = $this->sut->resolve(
			'stripe',
			array( 'token' => (string) $token->get_id() )
		);

		$this->assertSame( 'visa', $result->to_array()['instrument']['brand'] );
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
		$token = $this->create_card_token( 'stripe' );

		$override = new PaymentMethodData(
			'stripe',
			'card',
			true,
			PaymentInstrumentData::from_array( array( 'brand' => 'visa', 'funding' => 'credit', 'last4' => '4242', 'fingerprint' => 'fp_abc', 'country' => 'US', 'exp_month' => 12, 'exp_year' => 2028 ) )
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
		$token = $this->create_card_token( 'square_credit_card' );

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
		$this->assertSame( 'visa', $result->to_array()['instrument']['brand'] );
	}

	/**
	 * Create a saved card token.
	 *
	 * @param string $gateway_id Gateway ID.
	 * @param ?int   $user_id    User ID. Defaults to the current user.
	 * @return \WC_Payment_Token_CC
	 */
	private function create_card_token( string $gateway_id, ?int $user_id = null ): \WC_Payment_Token_CC {
		$token = new \WC_Payment_Token_CC();
		$token->set_gateway_id( $gateway_id );
		$token->set_card_type( 'visa' );
		$token->set_last4( '4242' );
		$token->set_expiry_month( '12' );
		$token->set_expiry_year( '2028' );
		$token->set_token( 'tok_' . wp_unique_id() );
		$token->set_user_id( null === $user_id ? get_current_user_id() : $user_id );
		$token->save();

		return $token;
	}
}
