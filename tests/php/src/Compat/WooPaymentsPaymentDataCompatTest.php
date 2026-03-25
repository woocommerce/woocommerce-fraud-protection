<?php
/**
 * WooPaymentsPaymentDataCompatTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\FraudProtection\Compat;

use Automattic\WooCommerce\FraudProtection\Compat\WooPaymentsPaymentDataCompat;
use Automattic\WooCommerce\FraudProtection\Schemas\PaymentInstrumentData;
use Automattic\WooCommerce\FraudProtection\Schemas\PaymentMethodData;
use WC_Unit_Test_Case;

// Stub WC_Payments_API_Client if the real class isn't loaded.
if ( ! class_exists( '\WC_Payments_API_Client', false ) ) {
	// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
	class WC_Payments_API_Client_Stub {

		/**
		 * Mock response to return from get_payment_method().
		 *
		 * @var mixed
		 */
		private static $mock_response;

		/**
		 * Exception to throw from get_payment_method().
		 *
		 * @var ?\Throwable
		 */
		private static ?\Throwable $throw_exception = null;

		/**
		 * Set the mock response.
		 *
		 * @param mixed $response The response to return.
		 */
		public static function set_mock_response( $response ): void {
			self::$mock_response = $response;
		}

		/**
		 * Set an exception to throw.
		 *
		 * @param ?\Throwable $throwable The exception to throw, or null.
		 */
		public static function set_throw( ?\Throwable $throwable ): void {
			self::$throw_exception = $throwable;
		}

		/**
		 * Reset mock state.
		 *
		 * @return void
		 */
		public static function reset(): void {
			self::$mock_response   = null;
			self::$throw_exception = null;
		}

		/**
		 * Retrieve a payment method by ID.
		 *
		 * @param string $payment_method_id The payment method ID.
		 * @return array
		 * @throws \Throwable When set_throw() has been called.
		 */
		public function get_payment_method( string $payment_method_id ): array {
			if ( null !== self::$throw_exception ) {
				throw self::$throw_exception;
			}

			return self::$mock_response;
		}
	}

	class_alias( __NAMESPACE__ . '\WC_Payments_API_Client_Stub', 'WC_Payments_API_Client' );
}

// Stub WC_Payments and its Mode class if not loaded.
if ( ! class_exists( '\WC_Payments', false ) ) {
	// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
	class WCPay_Mode_Stub {

		/**
		 * Whether WooPayments is in live mode.
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
		 * Whether WooPayments is in live mode.
		 *
		 * @return bool
		 */
		public function is_live(): bool {
			return self::$live;
		}
	}

	// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
	class WC_Payments_Stub {

		/**
		 * Whether mode() returns a Mode instance or null.
		 *
		 * @var bool
		 */
		private static bool $mode_available = true;

		/**
		 * The API client instance to return.
		 *
		 * @var ?\WC_Payments_API_Client
		 */
		private static ?\WC_Payments_API_Client $api_client = null;

		/**
		 * Whether set_api_client() has been called explicitly.
		 *
		 * @var bool
		 */
		private static bool $api_client_set = false;

		/**
		 * Set whether mode() returns a Mode instance.
		 *
		 * @param bool $available True = returns Mode, false = returns null.
		 * @return void
		 */
		public static function set_mode_available( bool $available ): void {
			self::$mode_available = $available;
		}

		/**
		 * Get the mode instance.
		 *
		 * @return ?WCPay_Mode_Stub
		 */
		public static function mode(): ?WCPay_Mode_Stub {
			return self::$mode_available ? new WCPay_Mode_Stub() : null;
		}

		/**
		 * Set the API client instance.
		 *
		 * @param ?\WC_Payments_API_Client $client The API client, or null.
		 */
		public static function set_api_client( ?\WC_Payments_API_Client $client ): void {
			self::$api_client     = $client;
			self::$api_client_set = true;
		}

		/**
		 * Get the payments API client.
		 *
		 * Returns a new WC_Payments_API_Client by default, or the explicitly
		 * set client if set_api_client() was called.
		 *
		 * @return ?\WC_Payments_API_Client
		 */
		public static function get_payments_api_client(): ?\WC_Payments_API_Client {
			if ( self::$api_client_set ) {
				return self::$api_client;
			}

			return new \WC_Payments_API_Client();
		}

		/**
		 * Reset mock state.
		 *
		 * @return void
		 */
		public static function reset(): void {
			self::$mode_available = true;
			self::$api_client     = null;
			self::$api_client_set = false;
		}
	}

	class_alias( __NAMESPACE__ . '\WC_Payments_Stub', 'WC_Payments' );
}

// Stub WC_Payments_Features if the real class isn't loaded.
if ( ! class_exists( '\WC_Payments_Features', false ) ) {
	// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
	class WC_Payments_Features_Stub {

		/**
		 * Whether WooPay is enabled.
		 *
		 * @var bool
		 */
		private static bool $woopay_enabled = false;

		/**
		 * Set whether WooPay is enabled.
		 *
		 * @param bool $enabled True = enabled, false = disabled.
		 * @return void
		 */
		public static function set_woopay_enabled( bool $enabled ): void {
			self::$woopay_enabled = $enabled;
		}

		/**
		 * Whether WooPay is enabled.
		 *
		 * @return bool
		 */
		public static function is_woopay_enabled(): bool {
			return self::$woopay_enabled;
		}

		/**
		 * Reset mock state.
		 *
		 * @return void
		 */
		public static function reset(): void {
			self::$woopay_enabled = false;
		}
	}

	class_alias( __NAMESPACE__ . '\WC_Payments_Features_Stub', 'WC_Payments_Features' );
}

/**
 * Tests for the WooPaymentsPaymentDataCompat class.
 *
 * @covers \Automattic\WooCommerce\FraudProtection\Compat\WooPaymentsPaymentDataCompat
 */
class WooPaymentsPaymentDataCompatTest extends WC_Unit_Test_Case {

	/**
	 * The System Under Test.
	 *
	 * @var WooPaymentsPaymentDataCompat
	 */
	private WooPaymentsPaymentDataCompat $sut;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->sut = new WooPaymentsPaymentDataCompat();
	}

	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		WCPay_Mode_Stub::set_live( true );
		\WC_Payments_API_Client::reset();
		\WC_Payments::reset();
		\WC_Payments_Features::reset();
		parent::tearDown();
	}

	/**
	 * @testdox Returns resolved for non-WooPayments payment methods.
	 */
	public function test_returns_resolved_for_non_woopayments(): void {
		$resolved = new PaymentMethodData( 'stripe', 'card' );

		$result = $this->sut->resolve( $resolved, array() );

		$this->assertSame( $resolved, $result );
	}

	/**
	 * @testdox Includes test mode when WooPayments is not in live mode.
	 */
	public function test_includes_test_mode(): void {
		WCPay_Mode_Stub::set_live( false );

		$result = $this->sut->resolve(
			new PaymentMethodData( 'woocommerce_payments' ),
			array()
		);

		$this->assertSame( PaymentMethodData::MODE_TEST, $result->to_array()['transaction_mode'] );
	}

	/**
	 * @testdox Includes live mode when WooPayments is in live mode.
	 */
	public function test_includes_live_mode(): void {
		WCPay_Mode_Stub::set_live( true );

		$result = $this->sut->resolve(
			new PaymentMethodData( 'woocommerce_payments' ),
			array()
		);

		$this->assertSame( PaymentMethodData::MODE_LIVE, $result->to_array()['transaction_mode'] );
	}

	/**
	 * @testdox Matches APM gateways like woocommerce_payments_bancontact.
	 */
	public function test_matches_apm_gateway(): void {
		WCPay_Mode_Stub::set_live( false );

		$result = $this->sut->resolve(
			new PaymentMethodData( 'woocommerce_payments_bancontact' ),
			array()
		);

		$this->assertSame( PaymentMethodData::MODE_TEST, $result->to_array()['transaction_mode'] );
	}

	/**
	 * @testdox Does not match unrelated gateways with similar prefix.
	 */
	public function test_does_not_match_unrelated_gateway(): void {
		$resolved = new PaymentMethodData( 'woocommerce_paymentsx' );

		$result = $this->sut->resolve( $resolved, array() );

		$this->assertSame( $resolved, $result );
	}

	/**
	 * @testdox Transaction mode is unknown when WooPayments Mode is unavailable.
	 */
	public function test_transaction_mode_unknown_when_mode_unavailable(): void {
		\WC_Payments::set_mode_available( false );

		$result = $this->sut->resolve(
			new PaymentMethodData( 'woocommerce_payments' ),
			array()
		);

		$this->assertSame( PaymentMethodData::MODE_UNKNOWN, $result->to_array()['transaction_mode'] );
	}

	/**
	 * @testdox Augments pre-resolved data with transaction mode.
	 */
	public function test_augments_preresolved_with_mode(): void {
		WCPay_Mode_Stub::set_live( true );

		$resolved = new PaymentMethodData( 'woocommerce_payments', 'card', true );

		$result = $this->sut->resolve( $resolved, array() );

		$this->assertNotSame( $resolved, $result );
		$array = $result->to_array();
		$this->assertSame( PaymentMethodData::MODE_LIVE, $array['transaction_mode'] );
		$this->assertSame( 'card', $array['payment_type'] );
		$this->assertTrue( $array['is_saved_payment_method'] );
	}

	// --- Card resolution ---

	/**
	 * @testdox Resolves card details from mocked API response.
	 */
	public function test_resolves_card_via_api(): void {
		WCPay_Mode_Stub::set_live( false );

		\WC_Payments_API_Client::set_mock_response( $this->create_card_response() );

		$result = $this->sut->resolve(
			new PaymentMethodData( 'woocommerce_payments' ),
			array( 'wcpay-payment-method' => 'pm_card_123' )
		);

		$this->assertSame(
			array(
				'gateway'                 => 'woocommerce_payments',
				'payment_type'            => 'card',
				'is_saved_payment_method' => false,
				'instrument'              => array(
					'brand'            => 'visa',
					'funding'          => 'credit',
					'last4'            => '4242',
					'fingerprint'      => 'fp_wcpay123',
					'country'          => 'US',
					'exp_month'        => 12,
					'exp_year'         => 2025,
					'billing_postcode' => '10001',
					'wallet'           => null,
					'bank_code'        => null,
					'bin'                => '424242',
					'cvc_check'          => null,
					'avs_address_check'  => null,
					'avs_postcode_check' => null,
				),
				'transaction_mode'        => PaymentMethodData::MODE_TEST,
			),
			$result->to_array()
		);
	}

	/**
	 * @testdox Handles missing card fields gracefully.
	 */
	public function test_handles_missing_card_fields(): void {
		\WC_Payments_API_Client::set_mock_response(
			array(
				'type' => 'card',
				'card' => array(
					'brand' => 'mastercard',
					'last4' => '1234',
				),
			)
		);

		$result = $this->sut->resolve(
			new PaymentMethodData( 'woocommerce_payments' ),
			array( 'wcpay-payment-method' => 'pm_partial_123' )
		);

		$array = $result->to_array();
		$this->assertSame( 'card', $array['payment_type'] );
		$this->assertSame( 'mastercard', $array['instrument']['brand'] );
		$this->assertSame( '1234', $array['instrument']['last4'] );
		$this->assertNull( $array['instrument']['funding'] );
		$this->assertNull( $array['instrument']['country'] );
	}

	// --- Wallet / Express ---

	/**
	 * @testdox Resolves wallet type for express payment method (Apple Pay).
	 */
	public function test_resolves_wallet_type_for_express_method(): void {
		$response = $this->create_card_response();
		$response['card']['wallet'] = array( 'type' => 'apple_pay' );

		\WC_Payments_API_Client::set_mock_response( $response );

		$result = $this->sut->resolve(
			new PaymentMethodData( 'woocommerce_payments' ),
			array( 'wcpay-payment-method' => 'pm_apple_123' )
		);

		$this->assertSame( 'apple_pay', $result->to_array()['instrument']['wallet'] );
	}

	// --- Bank types ---

	/**
	 * @testdox Resolves bank data for SEPA debit including bank_code.
	 */
	public function test_resolves_bank_data_for_sepa(): void {
		\WC_Payments_API_Client::set_mock_response(
			array(
				'type'       => 'sepa_debit',
				'sepa_debit' => array(
					'country'     => 'DE',
					'fingerprint' => 'fp_sepa_456',
					'last4'       => '3456',
					'bank_code'   => '19043',
				),
			)
		);

		$result = $this->sut->resolve(
			new PaymentMethodData( 'woocommerce_payments' ),
			array( 'wcpay-payment-method' => 'pm_sepa_123' )
		);

		$array = $result->to_array();
		$this->assertSame( 'sepa_debit', $array['payment_type'] );
		$this->assertSame( 'DE', $array['instrument']['country'] );
		$this->assertSame( 'fp_sepa_456', $array['instrument']['fingerprint'] );
		$this->assertSame( '3456', $array['instrument']['last4'] );
		$this->assertSame( '19043', $array['instrument']['bank_code'] );
		$this->assertNull( $array['instrument']['brand'] );
		$this->assertNull( $array['instrument']['exp_month'] );
	}

	/**
	 * @testdox Resolves BECS debit bank data.
	 */
	public function test_resolves_becs_debit(): void {
		\WC_Payments_API_Client::set_mock_response(
			array(
				'type'           => 'au_becs_debit',
				'au_becs_debit'  => array(
					'bsb_number'  => '000-000',
					'fingerprint' => 'fp_becs_789',
					'last4'       => '7890',
				),
			)
		);

		$result = $this->sut->resolve(
			new PaymentMethodData( 'woocommerce_payments' ),
			array( 'wcpay-payment-method' => 'pm_becs_123' )
		);

		$array = $result->to_array();
		$this->assertSame( 'au_becs_debit', $array['payment_type'] );
		$this->assertSame( 'fp_becs_789', $array['instrument']['fingerprint'] );
		$this->assertSame( '7890', $array['instrument']['last4'] );
		$this->assertSame( '000-000', $array['instrument']['bank_code'] );
	}

	/**
	 * @testdox Returns null instrument for non-bank type (link).
	 */
	public function test_returns_null_instrument_for_non_bank_type(): void {
		\WC_Payments_API_Client::set_mock_response(
			array(
				'type' => 'link',
				'link' => array(
					'email' => 'user@example.com',
				),
			)
		);

		$result = $this->sut->resolve(
			new PaymentMethodData( 'woocommerce_payments' ),
			array( 'wcpay-payment-method' => 'pm_link_123' )
		);

		$array = $result->to_array();
		$this->assertSame( 'link', $array['payment_type'] );
		$this->assertNull( $array['instrument'] );
	}

	/**
	 * @testdox Returns null instrument when type_data is not an array.
	 */
	public function test_returns_null_instrument_for_non_array_type_data(): void {
		\WC_Payments_API_Client::set_mock_response(
			array(
				'type' => 'card',
				'card' => 'not_an_array',
			)
		);

		$result = $this->sut->resolve(
			new PaymentMethodData( 'woocommerce_payments' ),
			array( 'wcpay-payment-method' => 'pm_123' )
		);

		$this->assertNull( $result->to_array()['instrument'] );
	}

	// --- Saved payments ---

	/**
	 * @testdox Detects saved payment method via payment token key.
	 */
	public function test_detects_saved_payment_method(): void {
		\WC_Payments_API_Client::set_mock_response( $this->create_card_response() );

		$result = $this->sut->resolve(
			new PaymentMethodData( 'woocommerce_payments' ),
			array(
				'wcpay-payment-method'                    => 'pm_saved_123',
				'wc-woocommerce_payments-payment-token'   => 'token_abc',
			)
		);

		$this->assertTrue( $result->to_array()['is_saved_payment_method'] );
	}

	/**
	 * @testdox Marks as not saved when payment token is "new".
	 */
	public function test_not_saved_when_token_is_new(): void {
		\WC_Payments_API_Client::set_mock_response( $this->create_card_response() );

		$result = $this->sut->resolve(
			new PaymentMethodData( 'woocommerce_payments' ),
			array(
				'wcpay-payment-method'                    => 'pm_new_123',
				'wc-woocommerce_payments-payment-token'   => 'new',
			)
		);

		$this->assertFalse( $result->to_array()['is_saved_payment_method'] );
	}

	/**
	 * @testdox Resolves full instrument data for saved card via WC token, passing the token's pm_ ID to the API.
	 */
	public function test_resolves_saved_card_via_wc_token(): void {
		$token = new \WC_Payment_Token_CC();
		$token->set_gateway_id( 'woocommerce_payments' );
		$token->set_token( 'pm_saved_xyz' );
		$token->set_card_type( 'visa' );
		$token->set_last4( '4242' );
		$token->set_expiry_month( '12' );
		$token->set_expiry_year( '2028' );
		$token->set_user_id( get_current_user_id() );
		$token->save();

		$api_mock = $this->createMock( \WC_Payments_API_Client::class );
		$api_mock->expects( $this->once() )
			->method( 'get_payment_method' )
			->with( 'pm_saved_xyz' )
			->willReturn( $this->create_card_response() );
		\WC_Payments::set_api_client( $api_mock );

		$result = $this->sut->resolve(
			new PaymentMethodData( 'woocommerce_payments' ),
			array(
				'wc-woocommerce_payments-payment-token' => (string) $token->get_id(),
			)
		);

		$array = $result->to_array();
		$this->assertTrue( $array['is_saved_payment_method'] );
		$this->assertSame( 'card', $array['payment_type'] );
		$this->assertSame( 'visa', $array['instrument']['brand'] );
		$this->assertSame( 'fp_wcpay123', $array['instrument']['fingerprint'] );
	}

	/**
	 * @testdox Falls back to mode-only when token belongs to another user.
	 */
	public function test_falls_back_when_token_belongs_to_another_user(): void {
		WCPay_Mode_Stub::set_live( false );

		$token = new \WC_Payment_Token_CC();
		$token->set_gateway_id( 'woocommerce_payments' );
		$token->set_token( 'pm_other_user' );
		$token->set_card_type( 'visa' );
		$token->set_last4( '1234' );
		$token->set_expiry_month( '01' );
		$token->set_expiry_year( '2030' );
		$token->set_user_id( 99999 ); // different user
		$token->save();

		$result = $this->sut->resolve(
			new PaymentMethodData( 'woocommerce_payments' ),
			array(
				'wc-woocommerce_payments-payment-token' => (string) $token->get_id(),
			)
		);

		$this->assertNull( $result->to_array()['payment_type'] );
	}

	/**
	 * @testdox Falls back to mode-only when token belongs to a different gateway.
	 */
	public function test_falls_back_when_token_belongs_to_different_gateway(): void {
		WCPay_Mode_Stub::set_live( false );

		$token = new \WC_Payment_Token_CC();
		$token->set_gateway_id( 'stripe' );
		$token->set_token( 'pm_stripe_token' );
		$token->set_card_type( 'visa' );
		$token->set_last4( '4242' );
		$token->set_expiry_month( '12' );
		$token->set_expiry_year( '2030' );
		$token->set_user_id( get_current_user_id() );
		$token->save();

		$result = $this->sut->resolve(
			new PaymentMethodData( 'woocommerce_payments' ),
			array(
				'wc-woocommerce_payments-payment-token' => (string) $token->get_id(),
			)
		);

		$this->assertNull( $result->to_array()['payment_type'] );
	}

	/**
	 * @testdox Falls back to mode-only when saved token ID does not exist.
	 */
	public function test_falls_back_when_saved_token_not_found(): void {
		WCPay_Mode_Stub::set_live( false );

		$result = $this->sut->resolve(
			new PaymentMethodData( 'woocommerce_payments' ),
			array(
				'wc-woocommerce_payments-payment-token' => '99999',
			)
		);

		$array = $result->to_array();
		$this->assertSame( PaymentMethodData::MODE_TEST, $array['transaction_mode'] );
		$this->assertNull( $array['payment_type'] );
	}

	// --- WooPay guard ---

	/**
	 * @testdox Skips resolution when WooPay is enabled.
	 */
	public function test_skips_resolution_when_woopay_enabled(): void {
		WCPay_Mode_Stub::set_live( false );
		\WC_Payments_Features::set_woopay_enabled( true );

		\WC_Payments_API_Client::set_mock_response( $this->create_card_response() );

		$result = $this->sut->resolve(
			new PaymentMethodData( 'woocommerce_payments' ),
			array( 'wcpay-payment-method' => 'pm_123' )
		);

		$array = $result->to_array();
		$this->assertNull( $array['payment_type'] );
		$this->assertNull( $array['instrument'] );
		$this->assertSame( PaymentMethodData::MODE_TEST, $array['transaction_mode'] );
	}

	/**
	 * @testdox Augments pre-resolved data when WooPay is enabled.
	 */
	public function test_augments_preresolved_on_woopay_enabled(): void {
		WCPay_Mode_Stub::set_live( true );
		\WC_Payments_Features::set_woopay_enabled( true );

		$resolved = new PaymentMethodData( 'woocommerce_payments', 'card', true );

		$result = $this->sut->resolve( $resolved, array( 'wcpay-payment-method' => 'pm_123' ) );

		$this->assertNotSame( $resolved, $result );
		$array = $result->to_array();
		$this->assertSame( PaymentMethodData::MODE_LIVE, $array['transaction_mode'] );
		$this->assertSame( 'card', $array['payment_type'] );
		$this->assertTrue( $array['is_saved_payment_method'] );
	}

	// --- Fail-open ---

	/**
	 * @testdox Returns mode only when PM ID is missing from payment data.
	 */
	public function test_returns_mode_only_when_pm_id_missing(): void {
		WCPay_Mode_Stub::set_live( false );

		$result = $this->sut->resolve(
			new PaymentMethodData( 'woocommerce_payments' ),
			array()
		);

		$array = $result->to_array();
		$this->assertSame( PaymentMethodData::MODE_TEST, $array['transaction_mode'] );
		$this->assertNull( $array['payment_type'] );
		$this->assertNull( $array['instrument'] );
	}

	/**
	 * @testdox Returns mode only when API client is null.
	 */
	public function test_returns_mode_only_when_api_client_null(): void {
		WCPay_Mode_Stub::set_live( false );

		\WC_Payments::set_api_client( null );

		$result = $this->sut->resolve(
			new PaymentMethodData( 'woocommerce_payments' ),
			array( 'wcpay-payment-method' => 'pm_123' )
		);

		$array = $result->to_array();
		$this->assertSame( PaymentMethodData::MODE_TEST, $array['transaction_mode'] );
		$this->assertNull( $array['payment_type'] );
		$this->assertNull( $array['instrument'] );
	}

	/**
	 * @testdox Returns mode only when API throws an exception.
	 */
	public function test_returns_mode_only_when_api_throws(): void {
		WCPay_Mode_Stub::set_live( true );

		\WC_Payments_API_Client::set_throw( new \RuntimeException( 'Connection failed' ) );

		$result = $this->sut->resolve(
			new PaymentMethodData( 'woocommerce_payments' ),
			array( 'wcpay-payment-method' => 'pm_123' )
		);

		$array = $result->to_array();
		$this->assertSame( PaymentMethodData::MODE_LIVE, $array['transaction_mode'] );
		$this->assertNull( $array['payment_type'] );
		$this->assertNull( $array['instrument'] );
	}

	/**
	 * @testdox Returns mode only when API response is missing type key.
	 */
	public function test_returns_mode_only_when_response_invalid(): void {
		\WC_Payments_API_Client::set_mock_response(
			array( 'id' => 'pm_123' )
		);

		$result = $this->sut->resolve(
			new PaymentMethodData( 'woocommerce_payments' ),
			array( 'wcpay-payment-method' => 'pm_123' )
		);

		$array = $result->to_array();
		$this->assertNull( $array['payment_type'] );
		$this->assertNull( $array['instrument'] );
	}

	/**
	 * @testdox Augments pre-resolved data on API error.
	 */
	public function test_augments_preresolved_on_api_error(): void {
		WCPay_Mode_Stub::set_live( false );

		\WC_Payments_API_Client::set_throw( new \RuntimeException( 'API error' ) );

		$instrument = new PaymentInstrumentData( 'visa', null, '4242' );
		$resolved   = new PaymentMethodData( 'woocommerce_payments', 'card', true, $instrument );

		$result = $this->sut->resolve(
			$resolved,
			array( 'wcpay-payment-method' => 'pm_123' )
		);

		$this->assertNotSame( $resolved, $result );
		$array = $result->to_array();
		$this->assertSame( PaymentMethodData::MODE_TEST, $array['transaction_mode'] );
		$this->assertSame( 'card', $array['payment_type'] );
		$this->assertTrue( $array['is_saved_payment_method'] );
		$this->assertSame( 'visa', $array['instrument']['brand'] );
		$this->assertSame( '4242', $array['instrument']['last4'] );
	}

	// --- PM ID extraction ---

	/**
	 * @testdox Extracts PM ID from SEPA-specific key.
	 */
	public function test_extracts_from_sepa_key(): void {
		\WC_Payments_API_Client::set_mock_response(
			array(
				'type'       => 'sepa_debit',
				'sepa_debit' => array(
					'country'     => 'NL',
					'fingerprint' => 'fp_sepa_nl',
					'last4'       => '9999',
				),
			)
		);

		$result = $this->sut->resolve(
			new PaymentMethodData( 'woocommerce_payments' ),
			array( 'wcpay-payment-method-sepa' => 'pm_sepa_alt_123' )
		);

		$array = $result->to_array();
		$this->assertSame( 'sepa_debit', $array['payment_type'] );
		$this->assertSame( '9999', $array['instrument']['last4'] );
	}

	// --- Verification checks ---

	/**
	 * @testdox Maps Stripe check values to normalized constants via CHECK_MAP.
	 */
	public function test_maps_check_values_via_check_map(): void {
		$response         = $this->create_card_response();
		$response['card']['checks'] = array(
			'cvc_check'                  => 'pass',
			'address_line1_check'        => 'fail',
			'address_postal_code_check'  => 'unavailable',
		);

		\WC_Payments_API_Client::set_mock_response( $response );

		$result = $this->sut->resolve(
			new PaymentMethodData( 'woocommerce_payments' ),
			array( 'wcpay-payment-method' => 'pm_card_123' )
		);

		$instrument = $result->to_array()['instrument'];
		$this->assertSame( PaymentInstrumentData::CHECK_PASS, $instrument['cvc_check'] );
		$this->assertSame( PaymentInstrumentData::CHECK_FAIL, $instrument['avs_address_check'] );
		$this->assertSame( PaymentInstrumentData::CHECK_UNAVAILABLE, $instrument['avs_postcode_check'] );
	}

	/**
	 * @testdox Treats null checks hash as empty (no PHP warning, all checks null).
	 */
	public function test_handles_null_checks_hash(): void {
		$response         = $this->create_card_response();
		$response['card']['checks'] = null;

		\WC_Payments_API_Client::set_mock_response( $response );

		$result = $this->sut->resolve(
			new PaymentMethodData( 'woocommerce_payments' ),
			array( 'wcpay-payment-method' => 'pm_card_123' )
		);

		$instrument = $result->to_array()['instrument'];
		$this->assertNull( $instrument['cvc_check'] );
		$this->assertNull( $instrument['avs_address_check'] );
		$this->assertNull( $instrument['avs_postcode_check'] );
	}

	// --- bank_code fallback chain ---

	/**
	 * @testdox Resolves bank_code from routing_number (US bank fallback).
	 */
	public function test_resolves_bank_code_from_routing_number(): void {
		\WC_Payments_API_Client::set_mock_response(
			array(
				'type'            => 'us_bank_account',
				'us_bank_account' => array(
					'routing_number' => '110000000',
					'fingerprint'    => 'fp_us_routing',
					'last4'          => '2222',
				),
			)
		);

		$result = $this->sut->resolve(
			new PaymentMethodData( 'woocommerce_payments' ),
			array( 'wcpay-payment-method' => 'pm_us_routing' )
		);

		$this->assertSame( '110000000', $result->to_array()['instrument']['bank_code'] );
	}

	/**
	 * @testdox Resolves bank_code from bic (iDEAL fallback).
	 */
	public function test_resolves_bank_code_from_bic(): void {
		\WC_Payments_API_Client::set_mock_response(
			array(
				'type'  => 'ideal',
				'ideal' => array(
					'bic'  => 'INGBNL2A',
					'bank' => 'ing',
				),
			)
		);

		$result = $this->sut->resolve(
			new PaymentMethodData( 'woocommerce_payments' ),
			array( 'wcpay-payment-method' => 'pm_ideal_bic' )
		);

		$this->assertSame( 'INGBNL2A', $result->to_array()['instrument']['bank_code'] );
	}

	// --- Helpers ---

	/**
	 * Create a mock card API response.
	 *
	 * @return array
	 */
	private function create_card_response(): array {
		return array(
			'type'            => 'card',
			'card'            => array(
				'brand'       => 'visa',
				'funding'     => 'credit',
				'last4'       => '4242',
				'fingerprint' => 'fp_wcpay123',
				'country'     => 'US',
				'exp_month'   => 12,
				'exp_year'    => 2025,
				'iin'         => '424242',
			),
			'billing_details' => array(
				'address' => array( 'postal_code' => '10001' ),
			),
		);
	}
}
