<?php
/**
 * StripePaymentDataCompatTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin\Compat;

use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Compat\StripePaymentDataCompat;
use Automattic\WooCommerce\FraudProtection\Schemas\PaymentInstrumentData;
use Automattic\WooCommerce\FraudProtection\Schemas\PaymentMethodData;
use Automattic\WooCommerce\FraudProtection\Schemas\PaymentMode;
use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;

// Stub WC_Stripe_API if the real class isn't loaded.
// The following classes keep the names of the external API that they model.
// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound, Squiz.Classes.ClassFileName.NoMatch, Squiz.Classes.ValidClassName.NotCamelCaps
if ( ! class_exists( '\WC_Stripe_API', false ) ) {
	// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
	/** Stripe API test stub. */
	class WC_Stripe_API_Stub {

		/**
		 * Mock response to return from get_payment_method().
		 *
		 * @var mixed
		 */
		private static $mock_response;

		/**
		 * Reset mock state.
		 *
		 * @return void
		 */
		public static function reset(): void {
			self::$mock_response = null;
		}

		/**
		 * Set the mock response.
		 *
		 * @param mixed $response The response to return.
		 */
		public static function set_mock_response( $response ): void {
			self::$mock_response = $response;
		}

		/**
		 * Retrieve a payment method by ID.
		 *
		 * Mirrors the real WC_Stripe_API static method signature.
		 *
		 * @param string $payment_method_id The payment method ID.
		 * @return mixed
		 */
		public static function get_payment_method( string $payment_method_id ) {
			unset( $payment_method_id );
			return self::$mock_response;
		}
	}

	class_alias( __NAMESPACE__ . '\WC_Stripe_API_Stub', 'WC_Stripe_API' );
}

// Stub WC_Stripe_Mode if the real class isn't loaded.
if ( ! class_exists( '\WC_Stripe_Mode', false ) ) {
	// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
	/** Stripe mode test stub. */
	class WC_Stripe_Mode_Stub {

		/**
		 * Whether Stripe is in live mode.
		 *
		 * @var bool
		 */
		private static bool $live = true;

		/**
		 * Set the live state for testing.
		 *
		 * @param bool $live True = live, false = test.
		 * @return void
		 */
		public static function set_live( bool $live ): void {
			self::$live = $live;
		}

		/**
		 * Whether Stripe is in live mode.
		 *
		 * @return bool
		 */
		public static function is_live(): bool {
			return self::$live;
		}
	}

	class_alias( __NAMESPACE__ . '\WC_Stripe_Mode_Stub', 'WC_Stripe_Mode' );
}

// Stub WC_Stripe and its account service if the real class isn't loaded.
if ( ! class_exists( '\WC_Stripe', false ) ) {
	// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
	/** Stripe account test stub. */
	class WC_Stripe_Account_Stub {

		/**
		 * Mock account response.
		 *
		 * @var mixed
		 */
		private static $account_data;

		/**
		 * Whether the account lookup should throw.
		 *
		 * @var bool
		 */
		private static bool $throws = false;

		/**
		 * Provide the set_account_data() test stub.
		 *
		 * @param mixed $account_data Test value.
		 */
		public static function set_account_data( $account_data ): void {
			self::$account_data = $account_data;
		}

		/**
		 * Provide the set_throws() test stub.
		 *
		 * @param bool $throws Test value.
		 */
		public static function set_throws( bool $throws ): void {
			self::$throws = $throws;
		}

		/**
		 * Provide the reset() test stub.
		 */
		public static function reset(): void {
			self::$account_data = null;
			self::$throws       = false;
		}

		/**
		 * Provide the get_cached_account_data() test stub.
		 */
		public function get_cached_account_data() {
			if ( self::$throws ) {
				throw new \RuntimeException( 'Account lookup failed' );
			}

			return self::$account_data;
		}
	}

	// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
	/** Stripe plugin test stub. */
	class WC_Stripe_Stub {
		/** @var WC_Stripe_Account_Stub Stripe account service. */
		public WC_Stripe_Account_Stub $account;

		/**
		 * Provide the __construct() test stub.
		 */
		public function __construct() {
			$this->account = new WC_Stripe_Account_Stub();
		}

		/**
		 * Provide the get_instance() test stub.
		 */
		public static function get_instance(): self {
			static $instance;

			if ( ! $instance ) {
				$instance = new self();
			}

			return $instance;
		}
	}

	class_alias( __NAMESPACE__ . '\WC_Stripe_Account_Stub', 'WC_Stripe_Account' );
	class_alias( __NAMESPACE__ . '\WC_Stripe_Stub', 'WC_Stripe' );
}

// phpcs:enable Squiz.Classes.ClassFileName.NoMatch, Squiz.Classes.ValidClassName.NotCamelCaps

/**
 * Tests for the StripePaymentDataCompat class.
 *
 * @covers \Automattic\WooCommerce\Internal\FraudProtectionPlugin\Compat\StripePaymentDataCompat
 */
class StripePaymentDataCompatTest extends FraudProtectionUnitTestCase {

	/**
	 * The System Under Test.
	 *
	 * @var StripePaymentDataCompat
	 */
	private StripePaymentDataCompat $sut;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->sut = new StripePaymentDataCompat();
	}

	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		\WC_Stripe_API::reset();
		if ( class_exists( __NAMESPACE__ . '\\WC_Stripe_Account_Stub', false ) ) {
			WC_Stripe_Account_Stub::reset();
		}
		WC_Stripe_Mode_Stub::set_live( true );
		parent::tearDown();
	}

	/**
	 * @testdox Returns resolved for non-Stripe payment methods.
	 */
	public function test_returns_resolved_for_non_stripe(): void {
		$resolved = new PaymentMethodData( 'woocommerce_payments', 'card' );

		$result = $this->sut->resolve(
			$resolved,
			array( 'wc-stripe-payment-method' => 'pm_123' )
		);

		$this->assertSame( $resolved, $result );
	}

	/**
	 * @testdox Returns data with mode applied when PM ID is missing from payment data.
	 */
	public function test_returns_resolved_for_missing_pm_id(): void {
		$resolved = new PaymentMethodData( 'stripe' );

		$result = $this->sut->resolve( $resolved, array() );

		$array = $result->to_array();
		$this->assertSame( 'stripe', $array['gateway'] );
		$this->assertNull( $array['payment_type'] );
	}

	/**
	 * @testdox Passes through previously resolved data with mode applied.
	 */
	public function test_passes_through_resolved_data(): void {
		$existing = new PaymentMethodData( 'stripe', 'card', false );

		$result = $this->sut->resolve(
			$existing,
			array( 'wc-stripe-payment-method' => 'pm_123' )
		);

		$array = $result->to_array();
		$this->assertSame( 'stripe', $array['gateway'] );
		$this->assertSame( 'card', $array['payment_type'] );
		$this->assertFalse( $array['is_saved_payment_method'] );
	}

	/**
	 * @testdox Handles 'stripe' gateway ID.
	 */
	public function test_handles_stripe_gateway_id(): void {
		\WC_Stripe_API::set_mock_response( $this->create_card_response() );

		$result = $this->sut->resolve(
			new PaymentMethodData( 'stripe' ),
			array( 'wc-stripe-payment-method' => 'pm_123' )
		);

		$this->assertSame( 'card', $result->to_array()['payment_type'] );
	}

	/**
	 * @testdox Includes the connected account identifier for a Stripe gateway.
	 */
	public function test_includes_account_identifier(): void {
		WC_Stripe_Account_Stub::set_account_data( array( 'id' => ' acct_123 ' ) );

		$array = $this->sut->resolve( new PaymentMethodData( 'stripe' ), array() )->to_array();

		$this->assertSame( 'acct_123', $array['merchant_identifier'] );
		$this->assertSame( 'account', $array['merchant_identifier_type'] );
	}

	/**
	 * @testdox Omits the account identifier when Stripe account data is invalid or throws.
	 *
	 * @dataProvider invalid_account_data_provider
	 *
	 * @param mixed $account_data Account data.
	 * @param bool  $throws Whether the lookup throws.
	 */
	public function test_omits_invalid_account_identifier( $account_data, bool $throws ): void {
		WC_Stripe_Account_Stub::set_account_data( $account_data );
		WC_Stripe_Account_Stub::set_throws( $throws );

		$array = $this->sut->resolve( new PaymentMethodData( 'stripe' ), array() )->to_array();

		$this->assertNull( $array['merchant_identifier'] );
		$this->assertSame( 'account', $array['merchant_identifier_type'] );
	}

	/**
	 * @return array<string, array{mixed, bool}>
	 */
	public function invalid_account_data_provider(): array {
		return array(
			'empty'     => array( array( 'id' => '' ), false ),
			'malformed' => array( array( 'id' => array() ), false ),
			'throwing'  => array( null, true ),
		);
	}

	/**
	 * @testdox Handles 'stripe_sepa_debit' gateway ID.
	 */
	public function test_handles_stripe_sepa_debit_gateway_id(): void {
		WC_Stripe_Account_Stub::set_account_data( array( 'id' => 'acct_123' ) );
		$response       = new \stdClass();
		$response->type = 'sepa_debit';

		\WC_Stripe_API::set_mock_response( $response );

		$result = $this->sut->resolve(
			new PaymentMethodData( 'stripe_sepa_debit' ),
			array( 'wc-stripe-payment-method' => 'pm_sepa_123' )
		);

		$array = $result->to_array();
		$this->assertSame( 'sepa_debit', $array['payment_type'] );
		$this->assertSame( PaymentInstrumentData::empty()->to_array(), $array['instrument'] );
		$this->assertSame( 'acct_123', $array['merchant_identifier'] );
		$this->assertSame( 'account', $array['merchant_identifier_type'] );
	}

	/**
	 * @testdox Handles 'stripe_ideal' gateway ID.
	 */
	public function test_handles_stripe_ideal_gateway_id(): void {
		$response       = new \stdClass();
		$response->type = 'ideal';

		\WC_Stripe_API::set_mock_response( $response );

		$result = $this->sut->resolve(
			new PaymentMethodData( 'stripe_ideal' ),
			array( 'wc-stripe-payment-method' => 'pm_ideal_123' )
		);

		$this->assertSame( 'ideal', $result->to_array()['payment_type'] );
	}

	/**
	 * @testdox Resolves card details from mocked API response.
	 */
	public function test_resolves_card_via_api(): void {
		WC_Stripe_Account_Stub::set_account_data( array( 'id' => 'acct_123' ) );
		\WC_Stripe_API::set_mock_response( $this->create_card_response() );

		$result = $this->sut->resolve(
			new PaymentMethodData( 'stripe' ),
			array( 'wc-stripe-payment-method' => 'pm_card_123' )
		);

		$this->assertSame(
			array(
				'gateway'                  => 'stripe',
				'payment_type'             => 'card',
				'is_saved_payment_method'  => false,
				'instrument'               => array(
					'brand'              => 'visa',
					'funding'            => 'credit',
					'last4'              => '4242',
					'fingerprint'        => 'fp_stripe123',
					'country'            => 'US',
					'exp_month'          => 12,
					'exp_year'           => 2025,
					'billing_postcode'   => '10001',
					'wallet'             => null,
					'payer_email'        => null,
					'bank_code'          => null,
					'bin'                => null,
					'cvc_check'          => null,
					'avs_address_check'  => null,
					'avs_postcode_check' => null,
				),
				'transaction_mode'         => PaymentMode::Live->value,
				'merchant_identifier'      => 'acct_123',
				'merchant_identifier_type' => 'account',
			),
			$result->to_array()
		);
	}

	/**
	 * @testdox Extracts PM ID from legacy stripe_source key.
	 */
	public function test_extracts_from_stripe_source(): void {
		\WC_Stripe_API::set_mock_response( $this->create_card_response() );

		$result = $this->sut->resolve(
			new PaymentMethodData( 'stripe' ),
			array( 'stripe_source' => 'src_legacy_123' )
		);

		$this->assertSame( 'card', $result->to_array()['payment_type'] );
	}

	/**
	 * @testdox Detects saved payment method via payment token key.
	 */
	public function test_detects_saved_payment_method(): void {
		\WC_Stripe_API::set_mock_response( $this->create_card_response() );

		$result = $this->sut->resolve(
			new PaymentMethodData( 'stripe' ),
			array(
				'wc-stripe-payment-method' => 'pm_saved_123',
				'wc-stripe-payment-token'  => 'token_abc',
			)
		);

		$this->assertTrue( $result->to_array()['is_saved_payment_method'] );
	}

	/**
	 * @testdox Marks as not saved when payment token is "new".
	 */
	public function test_not_saved_when_payment_token_is_new(): void {
		\WC_Stripe_API::set_mock_response( $this->create_card_response() );

		$result = $this->sut->resolve(
			new PaymentMethodData( 'stripe' ),
			array(
				'wc-stripe-payment-method' => 'pm_new_123',
				'wc-stripe-payment-token'  => 'new',
			)
		);

		$this->assertFalse( $result->to_array()['is_saved_payment_method'] );
	}

	/**
	 * @testdox Returns data with mode applied when API returns null.
	 */
	public function test_returns_resolved_when_api_returns_null(): void {
		// Mock response is null by default (set in setUp via reset).
		$resolved = new PaymentMethodData( 'stripe' );

		$result = $this->sut->resolve(
			$resolved,
			array( 'wc-stripe-payment-method' => 'pm_123' )
		);

		$array = $result->to_array();
		$this->assertSame( 'stripe', $array['gateway'] );
		$this->assertNull( $array['payment_type'] );
	}

	/**
	 * @testdox Returns data with mode applied when API returns a WP_Error.
	 */
	public function test_returns_resolved_when_api_returns_wp_error(): void {
		\WC_Stripe_API::set_mock_response( new \WP_Error( 'stripe_error', 'Connection failed' ) );

		$resolved = new PaymentMethodData( 'stripe' );

		$result = $this->sut->resolve(
			$resolved,
			array( 'wc-stripe-payment-method' => 'pm_123' )
		);

		$array = $result->to_array();
		$this->assertSame( 'stripe', $array['gateway'] );
		$this->assertNull( $array['payment_type'] );
	}

	/**
	 * @testdox Does not match 'stripe_something' incorrectly as non-Stripe gateway.
	 */
	public function test_does_not_match_stripe_partial_prefix(): void {
		$resolved = new PaymentMethodData( 'stripez_custom' );

		$result = $this->sut->resolve(
			$resolved,
			array( 'wc-stripe-payment-method' => 'pm_123' )
		);

		$this->assertSame( $resolved, $result );
	}

	/**
	 * @testdox Includes test mode when Stripe is not in live mode.
	 */
	public function test_includes_test_mode(): void {
		WC_Stripe_Mode_Stub::set_live( false );
		\WC_Stripe_API::set_mock_response( $this->create_card_response() );

		$result = $this->sut->resolve(
			new PaymentMethodData( 'stripe' ),
			array( 'wc-stripe-payment-method' => 'pm_123' )
		);

		$this->assertSame( PaymentMode::Test->value, $result->to_array()['transaction_mode'] );
	}

	/**
	 * @testdox Includes live mode when Stripe is in live mode.
	 */
	public function test_includes_live_mode(): void {
		WC_Stripe_Mode_Stub::set_live( true );
		\WC_Stripe_API::set_mock_response( $this->create_card_response() );

		$result = $this->sut->resolve(
			new PaymentMethodData( 'stripe' ),
			array( 'wc-stripe-payment-method' => 'pm_123' )
		);

		$this->assertSame( PaymentMode::Live->value, $result->to_array()['transaction_mode'] );
	}

	/**
	 * @testdox Augments pre-resolved token data with transaction mode when PM ID is missing.
	 */
	public function test_augments_preresolved_with_mode_on_missing_pm_id(): void {
		WC_Stripe_Mode_Stub::set_live( false );

		$resolved = new PaymentMethodData( 'stripe', 'card', true );

		$result = $this->sut->resolve( $resolved, array() );

		$this->assertNotSame( $resolved, $result );
		$array = $result->to_array();
		$this->assertSame( PaymentMode::Test->value, $array['transaction_mode'] );
		$this->assertSame( 'card', $array['payment_type'] );
		$this->assertTrue( $array['is_saved_payment_method'] );
	}

	/**
	 * @testdox Augments pre-resolved token data with transaction mode on API error.
	 */
	public function test_augments_preresolved_with_mode_on_api_error(): void {
		WC_Stripe_Mode_Stub::set_live( true );
		\WC_Stripe_API::set_mock_response( new \WP_Error( 'stripe_error', 'Fail' ) );

		$resolved = new PaymentMethodData( 'stripe', 'card', true );

		$result = $this->sut->resolve(
			$resolved,
			array( 'wc-stripe-payment-method' => 'pm_123' )
		);

		$this->assertNotSame( $resolved, $result );
		$array = $result->to_array();
		$this->assertSame( PaymentMode::Live->value, $array['transaction_mode'] );
		$this->assertSame( 'card', $array['payment_type'] );
		$this->assertTrue( $array['is_saved_payment_method'] );
	}

	/**
	 * @testdox Includes transaction mode for non-card payment types.
	 */
	public function test_includes_mode_for_non_card_types(): void {
		WC_Stripe_Mode_Stub::set_live( false );

		$response       = new \stdClass();
		$response->type = 'sepa_debit';

		\WC_Stripe_API::set_mock_response( $response );

		$result = $this->sut->resolve(
			new PaymentMethodData( 'stripe_sepa_debit' ),
			array( 'wc-stripe-payment-method' => 'pm_sepa_123' )
		);

		$array = $result->to_array();
		$this->assertSame( 'sepa_debit', $array['payment_type'] );
		$this->assertSame( PaymentMode::Test->value, $array['transaction_mode'] );
	}

	/**
	 * @testdox Normalizes supported express payment request values.
	 *
	 * @dataProvider express_payment_type_provider
	 *
	 * @param string $express_payment_type Submitted Stripe value.
	 * @param string $expected_wallet Expected wallet value.
	 */
	public function test_normalizes_express_payment_type( string $express_payment_type, string $expected_wallet ): void {
		$array = $this->sut->resolve(
			new PaymentMethodData( 'stripe' ),
			array( 'express_payment_type' => $express_payment_type )
		)->to_array();

		$this->assertSame( $expected_wallet, $array['instrument']['wallet'] );
	}

	/**
	 * Supported Stripe request values.
	 *
	 * @return array<string, array{string, string}>
	 */
	public function express_payment_type_provider(): array {
		return array(
			'Apple Pay'            => array( 'applePay', 'apple_pay' ),
			'Apple Pay canonical'  => array( 'apple_pay', 'apple_pay' ),
			'Google Pay'           => array( 'googlePay', 'google_pay' ),
			'Google Pay canonical' => array( 'google_pay', 'google_pay' ),
			'Amazon Pay'           => array( 'amazonPay', 'amazon_pay' ),
			'Amazon Pay canonical' => array( 'amazon_pay', 'amazon_pay' ),
			'PayPal'               => array( 'paypal', 'paypal' ),
			'Link'                 => array( 'link', 'link' ),
			'Cash App Pay'         => array( 'cashapp', 'cash_app_pay' ),
			'Cash App canonical'   => array( 'cash_app_pay', 'cash_app_pay' ),
		);
	}

	/**
	 * @testdox Ignores unsupported or malformed express payment request values.
	 *
	 * @dataProvider invalid_express_payment_type_provider
	 *
	 * @param mixed $express_payment_type Submitted Stripe value.
	 */
	public function test_ignores_invalid_express_payment_type( $express_payment_type ): void {
		$array = $this->sut->resolve(
			new PaymentMethodData( 'stripe' ),
			array( 'express_payment_type' => $express_payment_type )
		)->to_array();

		$this->assertNull( $array['instrument']['wallet'] );
	}

	/**
	 * Invalid Stripe request values.
	 *
	 * @return array<string, array{mixed}>
	 */
	public function invalid_express_payment_type_provider(): array {
		return array(
			'unknown' => array( 'unsupported_wallet' ),
			'empty'   => array( '' ),
			'array'   => array( array( 'applePay' ) ),
			'object'  => array( new \stdClass() ),
		);
	}

	/**
	 * @testdox Preserves a request wallet when the provider lookup fails.
	 */
	public function test_preserves_request_wallet_when_provider_lookup_fails(): void {
		\WC_Stripe_API::set_mock_response( new \WP_Error( 'stripe_error', 'Connection failed' ) );

		$array = $this->sut->resolve(
			new PaymentMethodData( 'stripe', 'card' ),
			array(
				'wc-stripe-payment-method' => 'pm_123',
				'express_payment_type'     => 'googlePay',
			)
		)->to_array();

		$this->assertSame( 'card', $array['payment_type'] );
		$this->assertSame( 'google_pay', $array['instrument']['wallet'] );
	}

	/**
	 * @testdox Provider card wallet takes priority over the request fallback.
	 */
	public function test_provider_card_wallet_takes_priority_over_request_fallback(): void {
		$response                     = $this->create_card_response();
		$response->card->wallet       = new \stdClass();
		$response->card->wallet->type = 'apple_pay';
		\WC_Stripe_API::set_mock_response( $response );

		$array = $this->sut->resolve(
			new PaymentMethodData( 'stripe' ),
			array(
				'wc-stripe-payment-method' => 'pm_123',
				'express_payment_type'     => 'googlePay',
			)
		)->to_array();

		$this->assertSame( 'apple_pay', $array['instrument']['wallet'] );
	}

	/**
	 * @testdox Normalizes supported provider payment types.
	 *
	 * @dataProvider provider_payment_type_provider
	 *
	 * @param string $payment_type Provider payment type.
	 * @param string $expected_wallet Expected wallet value.
	 */
	public function test_normalizes_provider_payment_type( string $payment_type, string $expected_wallet ): void {
		$response       = new \stdClass();
		$response->type = $payment_type;
		\WC_Stripe_API::set_mock_response( $response );

		$array = $this->sut->resolve(
			new PaymentMethodData( 'stripe' ),
			array( 'wc-stripe-payment-method' => 'pm_123' )
		)->to_array();

		$this->assertSame( $payment_type, $array['payment_type'] );
		$this->assertSame( $expected_wallet, $array['instrument']['wallet'] );
	}

	/**
	 * Supported Stripe provider payment types.
	 *
	 * @return array<string, array{string, string}>
	 */
	public function provider_payment_type_provider(): array {
		return array(
			'Amazon Pay'   => array( 'amazon_pay', 'amazon_pay' ),
			'PayPal'       => array( 'paypal', 'paypal' ),
			'Link'         => array( 'link', 'link' ),
			'Cash App Pay' => array( 'cashapp', 'cash_app_pay' ),
		);
	}

	/**
	 * @testdox Preserves an existing wallet and unrelated resolved data.
	 */
	public function test_preserves_existing_wallet_and_resolved_data(): void {
		WC_Stripe_Account_Stub::set_account_data( array( 'id' => 'acct_123' ) );
		$response                     = $this->create_card_response();
		$response->card->wallet       = new \stdClass();
		$response->card->wallet->type = 'apple_pay';
		\WC_Stripe_API::set_mock_response( $response );
		$resolved = new PaymentMethodData(
			'stripe',
			'card',
			true,
			PaymentInstrumentData::from_array( array( 'wallet' => 'existing_wallet' ) )
		);

		$array = $this->sut->resolve(
			$resolved,
			array(
				'wc-stripe-payment-method' => 'pm_123',
				'express_payment_type'     => 'googlePay',
			)
		)->to_array();

		$this->assertSame( 'existing_wallet', $array['instrument']['wallet'] );
		$this->assertSame( '4242', $array['instrument']['last4'] );
		$this->assertSame( 'acct_123', $array['merchant_identifier'] );
	}

	/**
	 * Create a mock card API response.
	 *
	 * @return \stdClass
	 */
	private function create_card_response(): \stdClass {
		$response       = new \stdClass();
		$response->type = 'card';

		$card_data              = new \stdClass();
		$card_data->brand       = 'visa';
		$card_data->funding     = 'credit';
		$card_data->last4       = '4242';
		$card_data->fingerprint = 'fp_stripe123';
		$card_data->country     = 'US';
		$card_data->exp_month   = 12;
		$card_data->exp_year    = 2025;

		$response->card = $card_data;

		$address              = new \stdClass();
		$address->postal_code = '10001';

		$billing_details          = new \stdClass();
		$billing_details->address = $address;

		$response->billing_details = $billing_details;

		return $response;
	}
}
