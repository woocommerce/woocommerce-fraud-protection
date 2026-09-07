<?php
/**
 * PayPalPaymentDataCompatTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin\Compat;

require_once dirname( __DIR__, 4 ) . '/Support/PayPalPPCPStubs.php';

use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Compat\PayPalPaymentDataCompat;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\PaymentDataResolver;
use Automattic\WooCommerce\FraudProtection\Schemas\PaymentInstrumentData;
use Automattic\WooCommerce\FraudProtection\Schemas\PaymentMethodData;
use Automattic\WooCommerce\FraudProtection\Schemas\PaymentMode;
use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;
use Automattic\WooCommerce\FraudProtection\Tests\Support\ApplePayPaymentTokenStub;
use Automattic\WooCommerce\FraudProtection\Tests\Support\PayPalConnectionStateStub;
use Automattic\WooCommerce\FraudProtection\Tests\Support\PayPalContainerStub;
use Automattic\WooCommerce\FraudProtection\Tests\Support\PayPalPaymentTokenStub;
use Automattic\WooCommerce\FraudProtection\Tests\Support\PayPalPPCPStub;
use Automattic\WooCommerce\FraudProtection\Tests\Support\VenmoPaymentTokenStub;

if ( ! class_exists( '\WooCommerce\PayPalCommerce\PPCP', false ) ) {
	class_alias( PayPalPPCPStub::class, 'WooCommerce\PayPalCommerce\PPCP' );
}

/**
 * Tests for the PayPalPaymentDataCompat class.
 *
 * @covers \Automattic\WooCommerce\Internal\FraudProtectionPlugin\Compat\PayPalPaymentDataCompat
 */
class PayPalPaymentDataCompatTest extends FraudProtectionUnitTestCase {

	/**
	 * The System Under Test.
	 *
	 * @var PayPalPaymentDataCompat
	 */
	private PayPalPaymentDataCompat $sut;

	/**
	 * The logged-in test customer ID.
	 *
	 * @var int
	 */
	private int $customer_id;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->customer_id = $this->factory->user->create( array( 'role' => 'customer' ) );
		wp_set_current_user( $this->customer_id );
		add_filter( 'woocommerce_payment_token_class', array( $this, 'map_paypal_token_class' ), 10, 2 );
		$this->sut = new PayPalPaymentDataCompat();
	}

	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		PayPalPaymentTokenStub::set_email_throws( false );
		PayPalConnectionStateStub::set_sandbox( null );
		PayPalContainerStub::reset();
		parent::tearDown();
	}

	/**
	 * @testdox Returns resolved for non-PayPal payment methods.
	 */
	public function test_returns_resolved_for_non_paypal(): void {
		$resolved = new PaymentMethodData( 'stripe', 'card' );

		$result = $this->sut->resolve( $resolved );

		$this->assertSame( $resolved, $result );
	}

	/**
	 * @testdox Includes test mode when PayPal is in sandbox.
	 */
	public function test_includes_test_mode(): void {
		PayPalConnectionStateStub::set_sandbox( true );

		$result = $this->sut->resolve(
			new PaymentMethodData( 'ppcp-gateway' )
		);

		$this->assertSame( PaymentMode::Test->value, $result->to_array()['transaction_mode'] );
	}

	/**
	 * @testdox Includes live mode when PayPal is in production.
	 */
	public function test_includes_live_mode(): void {
		PayPalConnectionStateStub::set_sandbox( false );

		$result = $this->sut->resolve(
			new PaymentMethodData( 'ppcp-gateway' )
		);

		$this->assertSame( PaymentMode::Live->value, $result->to_array()['transaction_mode'] );
	}

	/**
	 * @testdox Transaction mode is unknown when PayPal merchant is not connected.
	 */
	public function test_transaction_mode_unknown_when_not_connected(): void {
		// Default state: not connected (null).
		$result = $this->sut->resolve(
			new PaymentMethodData( 'ppcp-gateway' )
		);

		$this->assertSame( PaymentMode::Unknown->value, $result->to_array()['transaction_mode'] );
	}

	/**
	 * @testdox Matches ppcp-card-button-gateway as a PayPal gateway.
	 */
	public function test_matches_ppcp_card_button_gateway(): void {
		PayPalConnectionStateStub::set_sandbox( true );

		$result = $this->sut->resolve(
			new PaymentMethodData( 'ppcp-card-button-gateway' )
		);

		$this->assertSame( PaymentMode::Test->value, $result->to_array()['transaction_mode'] );
	}

	/**
	 * @testdox Includes the merchant account identifier for a prefixed PayPal gateway.
	 */
	public function test_includes_merchant_identifier(): void {
		PayPalConnectionStateStub::set_sandbox( true );
		PayPalContainerStub::set_merchant_id( ' merchant_123 ' );

		$result = $this->sut->resolve( new PaymentMethodData( 'ppcp-card-button-gateway' ) );
		$array  = $result->to_array();

		$this->assertSame( 'merchant_123', $array['merchant_identifier'] );
		$this->assertSame( 'account', $array['merchant_identifier_type'] );
	}

	/**
	 * @testdox Resolves valid saved PayPal Payments wallet tokens.
	 *
	 * @dataProvider saved_wallet_token_provider
	 *
	 * @param string      $token_type    PayPal Payments token type.
	 * @param string      $payment_type  Expected payment type.
	 * @param ?string     $email         Saved payer email.
	 * @param ?string     $wallet        Expected wallet type.
	 */
	public function test_resolves_valid_saved_paypal_payments_wallet_token( string $token_type, string $payment_type, ?string $email, ?string $wallet ): void {
		PayPalConnectionStateStub::set_sandbox( true );
		PayPalContainerStub::set_merchant_id( 'merchant_123' );
		$this->sut->register();
		$token = $this->create_saved_token( $token_type, 'ppcp-gateway', null, $email );

		$result = ( new PaymentDataResolver() )->resolve(
			'ppcp-gateway',
			array( 'wc-ppcp-gateway-payment-token' => (string) $token->get_id() )
		);
		$array = $result->to_array();

		$this->assertTrue( $array['is_saved_payment_method'] );
		$this->assertSame( $payment_type, $array['payment_type'] );
		$expected_instrument = PaymentInstrumentData::empty()->to_array();
		$expected_instrument['payer_email'] = $email;
		$expected_instrument['wallet'] = $wallet;
		$this->assertSame( $expected_instrument, $array['instrument'] );
		$this->assertSame( PaymentMode::Test->value, $array['transaction_mode'] );
		$this->assertSame( 'merchant_123', $array['merchant_identifier'] );
		$this->assertSame( 'account', $array['merchant_identifier_type'] );
	}

	/**
	 * @return array<string, array{string, string, ?string, ?string}>
	 */
	public function saved_wallet_token_provider(): array {
		return array(
			'PayPal'    => array( 'PayPal', 'paypal', 'payer@example.com', null ),
			'Venmo'     => array( 'Venmo', 'venmo', 'payer@example.com', null ),
			'Apple Pay' => array( 'ApplePay', 'card', null, 'apple_pay' ),
		);
	}

	/**
	 * @testdox Preserves a saved PayPal Payments card token and enriches it with merchant and mode data.
	 */
	public function test_preserves_saved_paypal_payments_card_token(): void {
		PayPalConnectionStateStub::set_sandbox( true );
		PayPalContainerStub::set_merchant_id( 'merchant_123' );
		$this->sut->register();
		$token = $this->create_saved_token( 'CC', 'ppcp-credit-card-gateway' );

		$array = ( new PaymentDataResolver() )->resolve(
			'ppcp-credit-card-gateway',
			array( 'wc-ppcp-credit-card-gateway-payment-token' => (string) $token->get_id() )
		)->to_array();

		$this->assertSame( 'card', $array['payment_type'] );
		$this->assertTrue( $array['is_saved_payment_method'] );
		$this->assertSame( 'visa', $array['instrument']['brand'] );
		$this->assertSame( '4242', $array['instrument']['last4'] );
		$this->assertSame( 12, $array['instrument']['exp_month'] );
		$this->assertSame( 2028, $array['instrument']['exp_year'] );
		$this->assertSame( PaymentMode::Test->value, $array['transaction_mode'] );
		$this->assertSame( 'merchant_123', $array['merchant_identifier'] );
		$this->assertSame( 'account', $array['merchant_identifier_type'] );
	}

	/**
	 * @testdox Preserves saved state with empty instrument data when the PayPal email is unavailable.
	 *
	 * @dataProvider unavailable_email_provider
	 *
	 * @param ?string $email   Saved email value.
	 * @param bool    $throws  Whether reading the email throws.
	 */
	public function test_saved_paypal_token_without_valid_email_remains_saved( ?string $email, bool $throws ): void {
		$token = $this->create_saved_token( 'PayPal', 'ppcp-gateway', null, $email );
		PayPalPaymentTokenStub::set_email_throws( $throws );

		$array = $this->sut->resolve(
			new PaymentMethodData( 'ppcp-gateway' ),
			array( 'wc-ppcp-gateway-payment-token' => (string) $token->get_id() )
		)->to_array();

		$this->assertTrue( $array['is_saved_payment_method'] );
		$this->assertSame( PaymentInstrumentData::empty()->to_array(), $array['instrument'] );
	}

	/**
	 * @return array<string, array{?string, bool}>
	 */
	public function unavailable_email_provider(): array {
		return array(
			'missing'  => array( null, false ),
			'invalid'  => array( 'not-an-email', false ),
			'throwing' => array( null, true ),
		);
	}

	/**
	 * @testdox Does not mark a missing or invalid PayPal token as a saved payment method.
	 *
	 * @dataProvider invalid_saved_token_provider
	 *
	 * @param mixed $token_value Submitted token value.
	 */
	public function test_does_not_mark_missing_or_invalid_token_as_saved( $token_value ): void {
		$array = $this->sut->resolve(
			new PaymentMethodData( 'ppcp-gateway' ),
			array( 'wc-ppcp-gateway-payment-token' => $token_value )
		)->to_array();

		$this->assertFalse( $array['is_saved_payment_method'] );
	}

	/**
	 * @return array<string, array{mixed}>
	 */
	public function invalid_saved_token_provider(): array {
		return array(
			'missing'   => array( null ),
			'new'       => array( 'new' ),
			'empty'     => array( '' ),
			'malformed' => array( 'not-a-token' ),
			'unknown'   => array( '999999' ),
			'array'     => array( array( 'id' => 1 ) ),
		);
	}

	/**
	 * @testdox Does not mark a PayPal token owned by another customer as saved.
	 */
	public function test_does_not_mark_another_customers_token_as_saved(): void {
		$other_customer_id = $this->factory->user->create( array( 'role' => 'customer' ) );
		$token             = $this->create_saved_token( 'PayPal', 'ppcp-gateway', $other_customer_id );

		$array = $this->sut->resolve(
			new PaymentMethodData( 'ppcp-gateway' ),
			array( 'wc-ppcp-gateway-payment-token' => (string) $token->get_id() )
		)->to_array();

		$this->assertFalse( $array['is_saved_payment_method'] );
	}

	/**
	 * @testdox Does not mark a PayPal token from another PayPal gateway as saved.
	 */
	public function test_does_not_mark_token_from_another_gateway_as_saved(): void {
		$token = $this->create_saved_token( 'PayPal', 'ppcp-credit-card-gateway' );

		$array = $this->sut->resolve(
			new PaymentMethodData( 'ppcp-gateway' ),
			array( 'wc-ppcp-gateway-payment-token' => (string) $token->get_id() )
		)->to_array();

		$this->assertFalse( $array['is_saved_payment_method'] );
	}

	/**
	 * @testdox Omits the merchant account identifier when the PayPal source is invalid or throws.
	 *
	 * @dataProvider invalid_merchant_identifier_provider
	 *
	 * @param mixed $merchant_id Merchant identifier source.
	 * @param bool  $throws Whether the source throws.
	 */
	public function test_omits_invalid_merchant_identifier( $merchant_id, bool $throws ): void {
		PayPalConnectionStateStub::set_sandbox( true );
		PayPalContainerStub::set_merchant_id( $merchant_id );
		PayPalContainerStub::set_merchant_id_throws( $throws );

		$array = $this->sut->resolve( new PaymentMethodData( 'ppcp-gateway' ) )->to_array();

		$this->assertNull( $array['merchant_identifier'] );
		$this->assertSame( 'account', $array['merchant_identifier_type'] );
		$this->assertSame( PaymentMode::Test->value, $array['transaction_mode'] );
	}

	/**
	 * @return array<string, array{mixed, bool}>
	 */
	public function invalid_merchant_identifier_provider(): array {
		return array(
			'empty'     => array( '', false ),
			'malformed' => array( array( 'merchant_id' ), false ),
			'throwing'  => array( null, true ),
		);
	}

	/**
	 * @testdox Augments pre-resolved data with transaction mode.
	 */
	public function test_augments_preresolved_with_mode(): void {
		PayPalConnectionStateStub::set_sandbox( true );

		$resolved = new PaymentMethodData( 'ppcp-gateway', 'paypal', true );

		$result = $this->sut->resolve( $resolved );

		$this->assertNotSame( $resolved, $result );
		$array = $result->to_array();
		$this->assertSame( PaymentMode::Test->value, $array['transaction_mode'] );
		$this->assertSame( 'paypal', $array['payment_type'] );
		$this->assertTrue( $array['is_saved_payment_method'] );
	}

	/**
	 * Create a saved PayPal Payments token.
	 *
	 * @param string $token_type Token type.
	 * @param string $gateway_id Gateway ID.
	 * @param ?int   $user_id    Token owner.
	 * @param ?string $email     Saved payer email.
	 * @return \WC_Payment_Token
	 */
	private function create_saved_token( string $token_type, string $gateway_id = 'ppcp-gateway', ?int $user_id = null, ?string $email = null ): \WC_Payment_Token {
		switch ( $token_type ) {
			case 'CC':
				$token = new \WC_Payment_Token_CC();
				$token->set_card_type( 'visa' );
				$token->set_last4( '4242' );
				$token->set_expiry_month( '12' );
				$token->set_expiry_year( '2028' );
				break;
			case 'PayPal':
				$token = new PayPalPaymentTokenStub();
				break;
			case 'Venmo':
				$token = new VenmoPaymentTokenStub();
				break;
			case 'ApplePay':
				$token = new ApplePayPaymentTokenStub();
				break;
			default:
				throw new \InvalidArgumentException( 'Unsupported test token type.' );
		}

		$token->set_gateway_id( $gateway_id );
		$token->set_token( 'paypal_' . wp_unique_id() );
		$token->set_user_id( null === $user_id ? $this->customer_id : $user_id );
		if ( null !== $email && method_exists( $token, 'set_email' ) ) {
			$token->set_email( $email );
		}
		$token->save();

		return $token;
	}

	/**
	 * Map the PayPal token type to the test token class.
	 *
	 * @param string $class Token class name.
	 * @param string $type Token type.
	 * @return string
	 */
	public function map_paypal_token_class( string $class, string $type ): string {
		return array(
			'PayPal'   => PayPalPaymentTokenStub::class,
			'Venmo'    => VenmoPaymentTokenStub::class,
			'ApplePay' => ApplePayPaymentTokenStub::class,
		)[ $type ] ?? $class;
	}
}
