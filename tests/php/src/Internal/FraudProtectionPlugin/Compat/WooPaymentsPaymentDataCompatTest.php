<?php
/**
 * WooPaymentsPaymentDataCompatTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin\Compat;

use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Compat\WooPaymentsPaymentDataCompat;
use Automattic\WooCommerce\FraudProtection\Schemas\CheckResult;
use Automattic\WooCommerce\FraudProtection\Schemas\PaymentInstrumentData;
use Automattic\WooCommerce\FraudProtection\Schemas\PaymentMethodData;
use Automattic\WooCommerce\FraudProtection\Schemas\PaymentMode;
use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;

// Stub WooPayments classes if not loaded. Tests inject API mocks via \WC_Payments::set_api_client().
if ( ! class_exists( '\WC_Payments', false ) ) {
	// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
	class WC_Payments_Account_Service_Stub {
		/**
		 * Account identifier returned by the account service.
		 *
		 * @var mixed
		 */
		private static $stripe_account_id;

		/**
		 * Account data returned by refresh_account_data().
		 *
		 * @var mixed
		 */
		private static $account_data;
		private static int $stripe_account_id_calls = 0;
		private static int $refresh_account_data_calls = 0;

		/**
		 * Whether account methods should throw.
		 *
		 * @var bool
		 */
		private static bool $throws = false;
		private static bool $refresh_throws = false;

		public static function set_stripe_account_id( $account_id ): void {
			self::$stripe_account_id = $account_id;
		}

		public static function set_account_data( $account_data ): void {
			self::$account_data = $account_data;
		}

		public static function set_throws( bool $throws ): void {
			self::$throws = $throws;
		}

		public static function set_refresh_throws( bool $throws ): void {
			self::$refresh_throws = $throws;
		}

		public static function reset(): void {
			self::$stripe_account_id = null;
			self::$account_data      = null;
			self::$throws            = false;
			self::$refresh_throws    = false;
			self::$stripe_account_id_calls   = 0;
			self::$refresh_account_data_calls = 0;
		}

		public static function get_stripe_account_id_calls(): int {
			return self::$stripe_account_id_calls;
		}

		public static function get_refresh_account_data_calls(): int {
			return self::$refresh_account_data_calls;
		}

		public function get_stripe_account_id() {
			++self::$stripe_account_id_calls;
			if ( self::$throws ) {
				throw new \RuntimeException( 'Account lookup failed' );
			}

			return self::$stripe_account_id;
		}

		public function refresh_account_data() {
			++self::$refresh_account_data_calls;
			if ( self::$throws || self::$refresh_throws ) {
				throw new \RuntimeException( 'Account refresh failed' );
			}

			return self::$account_data;
		}
	}

	// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
	class WC_Payments_API_Client_Stub {
		public function get_payment_method( string $payment_method_id ): array {
			return array();
		}
	}

	// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
	class WC_Payments_Stub {
		private static bool $live                          = true;
		private static bool $mode_available                = true;
		private static ?\WC_Payments_API_Client $api_client = null;
		private static bool $api_client_set                = false;

		public static function set_live( bool $live ): void {
			self::$live = $live;
		}

		public static function set_mode_available( bool $available ): void {
			self::$mode_available = $available;
		}

		public static function mode(): ?object {
			if ( ! self::$mode_available ) {
				return null;
			}
			$live = self::$live;
			return new class( $live ) {
				private bool $live;
				public function __construct( bool $live ) { $this->live = $live; }
				public function is_live(): bool { return $this->live; }
			};
		}

		public static function set_api_client( ?\WC_Payments_API_Client $client ): void {
			self::$api_client     = $client;
			self::$api_client_set = true;
		}

		public static function get_account_service(): object {
			return new WC_Payments_Account_Service_Stub();
		}

		public static function get_payments_api_client(): ?\WC_Payments_API_Client {
			return self::$api_client_set ? self::$api_client : new \WC_Payments_API_Client();
		}

		public static function reset(): void {
			self::$live           = true;
			self::$mode_available = true;
			self::$api_client     = null;
			self::$api_client_set = false;
		}
	}

	// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
	class WC_Payments_Features_Stub {
		private static bool $woopay_enabled = false;

		public static function set_woopay_enabled( bool $enabled ): void {
			self::$woopay_enabled = $enabled;
		}

		public static function is_woopay_enabled(): bool {
			return self::$woopay_enabled;
		}

		public static function reset(): void {
			self::$woopay_enabled = false;
		}
	}

	class_alias( __NAMESPACE__ . '\WC_Payments_API_Client_Stub', 'WC_Payments_API_Client' );
	class_alias( __NAMESPACE__ . '\WC_Payments_Stub', 'WC_Payments' );
	class_alias( __NAMESPACE__ . '\WC_Payments_Features_Stub', 'WC_Payments_Features' );
}

/**
 * Tests for the WooPaymentsPaymentDataCompat class.
 *
 * @covers \Automattic\WooCommerce\Internal\FraudProtectionPlugin\Compat\WooPaymentsPaymentDataCompat
 */
class WooPaymentsPaymentDataCompatTest extends FraudProtectionUnitTestCase {

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
		\WC_Payments::reset();
		\WC_Payments_Features::reset();
		remove_filter( 'wp_doing_ajax', '__return_true' );
		WC_Payments_Account_Service_Stub::reset();
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
		\WC_Payments::set_live( false );

		$result = $this->sut->resolve(
			new PaymentMethodData( 'woocommerce_payments' ),
			array()
		);

		$this->assertSame( PaymentMode::Test->value, $result->to_array()['transaction_mode'] );
	}

	/**
	 * @testdox Includes live mode when WooPayments is in live mode.
	 */
	public function test_includes_live_mode(): void {
		\WC_Payments::set_live( true );

		$result = $this->sut->resolve(
			new PaymentMethodData( 'woocommerce_payments' ),
			array()
		);

		$this->assertSame( PaymentMode::Live->value, $result->to_array()['transaction_mode'] );
	}

	/**
	 * @testdox Includes the connected account identifier from the account service.
	 */
	public function test_includes_account_identifier(): void {
		WC_Payments_Account_Service_Stub::set_stripe_account_id( ' acct_123 ' );

		$array = $this->sut->resolve( new PaymentMethodData( 'woocommerce_payments' ), array() )->to_array();

		$this->assertSame( 'acct_123', $array['merchant_identifier'] );
		$this->assertSame( 'account', $array['merchant_identifier_type'] );
		$this->assertSame( 1, WC_Payments_Account_Service_Stub::get_stripe_account_id_calls() );
		$this->assertSame( 0, WC_Payments_Account_Service_Stub::get_refresh_account_data_calls() );
	}

	/**
	 * @testdox Does not refresh a null account result outside classic Ajax.
	 */
	public function test_does_not_refresh_null_account_result_outside_ajax(): void {
		WC_Payments_Account_Service_Stub::set_account_data( array( 'account_id' => 'acct_123' ) );

		$array = $this->sut->resolve( new PaymentMethodData( 'woocommerce_payments' ), array() )->to_array();

		$this->assertNull( $array['merchant_identifier'] );
		$this->assertSame( 'account', $array['merchant_identifier_type'] );
		$this->assertSame( 1, WC_Payments_Account_Service_Stub::get_stripe_account_id_calls() );
		$this->assertSame( 0, WC_Payments_Account_Service_Stub::get_refresh_account_data_calls() );
	}

	/**
	 * @testdox Refreshes once for a null account result during classic Ajax.
	 */
	public function test_refreshes_once_for_null_account_result_during_ajax(): void {
		WC_Payments_Account_Service_Stub::set_account_data( array( 'account_id' => ' acct_123 ' ) );
		add_filter( 'wp_doing_ajax', '__return_true' );

		$array = $this->sut->resolve( new PaymentMethodData( 'woocommerce_payments' ), array() )->to_array();

		$this->assertSame( 'acct_123', $array['merchant_identifier'] );
		$this->assertSame( 'account', $array['merchant_identifier_type'] );
		$this->assertSame( 1, WC_Payments_Account_Service_Stub::get_stripe_account_id_calls() );
		$this->assertSame( 1, WC_Payments_Account_Service_Stub::get_refresh_account_data_calls() );
	}

	/**
	 * @testdox Omits the account identifier when account lookup fails.
	 */
	public function test_omits_account_identifier_when_lookup_throws(): void {
		WC_Payments_Account_Service_Stub::set_throws( true );

		$array = $this->sut->resolve( new PaymentMethodData( 'woocommerce_payments' ), array() )->to_array();

		$this->assertNull( $array['merchant_identifier'] );
		$this->assertSame( 'account', $array['merchant_identifier_type'] );
		$this->assertSame( PaymentMode::Live->value, $array['transaction_mode'] );
	}

	/**
	 * @testdox Omits the account identifier when the classic Ajax refresh result is invalid.
	 *
	 * @dataProvider invalid_refresh_data_provider
	 *
	 * @param mixed $account_data Refreshed account data.
	 * @param bool  $throws Whether the refresh throws.
	 */
	public function test_omits_invalid_ajax_refresh_account_identifier( $account_data, bool $throws ): void {
		WC_Payments_Account_Service_Stub::set_account_data( $account_data );
		WC_Payments_Account_Service_Stub::set_refresh_throws( $throws );
		add_filter( 'wp_doing_ajax', '__return_true' );

		$array = $this->sut->resolve( new PaymentMethodData( 'woocommerce_payments' ), array() )->to_array();

		$this->assertNull( $array['merchant_identifier'] );
		$this->assertSame( 'account', $array['merchant_identifier_type'] );
		$this->assertSame( 1, WC_Payments_Account_Service_Stub::get_refresh_account_data_calls() );
	}

	/**
	 * @return array<string, array{mixed, bool}>
	 */
	public function invalid_refresh_data_provider(): array {
		return array(
			'false'     => array( false, false ),
			'malformed' => array( array( 'id' => 'acct_123' ), false ),
			'throwing'  => array( null, true ),
		);
	}

	/**
	 * @testdox Matches APM gateways like woocommerce_payments_bancontact.
	 */
	public function test_matches_apm_gateway(): void {
		\WC_Payments::set_live( false );
		WC_Payments_Account_Service_Stub::set_stripe_account_id( 'acct_123' );

		$result = $this->sut->resolve(
			new PaymentMethodData( 'woocommerce_payments_bancontact' ),
			array()
		);

		$array = $result->to_array();
		$this->assertSame( PaymentMode::Test->value, $array['transaction_mode'] );
		$this->assertSame( 'acct_123', $array['merchant_identifier'] );
		$this->assertSame( 'account', $array['merchant_identifier_type'] );
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

		$this->assertSame( PaymentMode::Unknown->value, $result->to_array()['transaction_mode'] );
	}

	/**
	 * @testdox Augments pre-resolved data with transaction mode.
	 */
	public function test_augments_preresolved_with_mode(): void {
		\WC_Payments::set_live( true );

		$resolved = new PaymentMethodData( 'woocommerce_payments', 'card', true );

		$result = $this->sut->resolve( $resolved, array() );

		$this->assertNotSame( $resolved, $result );
		$array = $result->to_array();
		$this->assertSame( PaymentMode::Live->value, $array['transaction_mode'] );
		$this->assertSame( 'card', $array['payment_type'] );
		$this->assertTrue( $array['is_saved_payment_method'] );
	}

	// --- Card resolution ---

	/**
	 * @testdox Resolves card details from mocked API response.
	 */
	public function test_resolves_card_via_api(): void {
		\WC_Payments::set_live( false );
		WC_Payments_Account_Service_Stub::set_stripe_account_id( 'acct_123' );

		$this->mock_api_response( $this->create_card_response() );

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
					'cvc_check'          => CheckResult::Pass->value,
					'avs_address_check'  => CheckResult::Fail->value,
					'avs_postcode_check' => CheckResult::Unchecked->value,
				),
				'transaction_mode'        => PaymentMode::Test->value,
				'merchant_identifier'     => 'acct_123',
				'merchant_identifier_type' => 'account',
			),
			$result->to_array()
		);
	}

	/**
	 * @testdox Handles missing card fields gracefully.
	 */
	public function test_handles_missing_card_fields(): void {
		$this->mock_api_response(
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

		$this->mock_api_response( $response );

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
		$this->mock_api_response(
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
		$this->mock_api_response(
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
	 * @testdox Returns empty instrument for non-bank type (link).
	 */
	public function test_returns_empty_instrument_for_non_bank_type(): void {
		$this->mock_api_response(
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
		$this->assertSame( PaymentInstrumentData::empty()->to_array(), $array['instrument'] );
	}

	/**
	 * @testdox Returns empty instrument when type_data is not an array.
	 */
	public function test_returns_empty_instrument_for_non_array_type_data(): void {
		$this->mock_api_response(
			array(
				'type' => 'card',
				'card' => 'not_an_array',
			)
		);

		$result = $this->sut->resolve(
			new PaymentMethodData( 'woocommerce_payments' ),
			array( 'wcpay-payment-method' => 'pm_123' )
		);

		$this->assertSame( PaymentInstrumentData::empty()->to_array(), $result->to_array()['instrument'] );
	}

	// --- Saved payments ---

	/**
	 * @testdox Detects saved payment method via payment token key.
	 */
	public function test_detects_saved_payment_method(): void {
		$this->mock_api_response( $this->create_card_response() );

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
		$this->mock_api_response( $this->create_card_response() );

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
		\WC_Payments::set_live( false );

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
		\WC_Payments::set_live( false );

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
		\WC_Payments::set_live( false );

		$result = $this->sut->resolve(
			new PaymentMethodData( 'woocommerce_payments' ),
			array(
				'wc-woocommerce_payments-payment-token' => '99999',
			)
		);

		$array = $result->to_array();
		$this->assertSame( PaymentMode::Test->value, $array['transaction_mode'] );
		$this->assertNull( $array['payment_type'] );
	}

	// --- WooPay guard ---

	/**
	 * @testdox Skips resolution when WooPay is enabled.
	 */
	public function test_skips_resolution_when_woopay_enabled(): void {
		\WC_Payments::set_live( false );
		\WC_Payments_Features::set_woopay_enabled( true );
		WC_Payments_Account_Service_Stub::set_stripe_account_id( 'acct_123' );

		$this->mock_api_response( $this->create_card_response() );

		$result = $this->sut->resolve(
			new PaymentMethodData( 'woocommerce_payments' ),
			array( 'wcpay-payment-method' => 'pm_123' )
		);

		$array = $result->to_array();
		$this->assertNull( $array['payment_type'] );
		$this->assertSame( PaymentInstrumentData::empty()->to_array(), $array['instrument'] );
		$this->assertSame( PaymentMode::Test->value, $array['transaction_mode'] );
		$this->assertSame( 'acct_123', $array['merchant_identifier'] );
		$this->assertSame( 'account', $array['merchant_identifier_type'] );
	}

	/**
	 * @testdox Augments pre-resolved data when WooPay is enabled.
	 */
	public function test_augments_preresolved_on_woopay_enabled(): void {
		\WC_Payments::set_live( true );
		\WC_Payments_Features::set_woopay_enabled( true );

		$resolved = new PaymentMethodData( 'woocommerce_payments', 'card', true );

		$result = $this->sut->resolve( $resolved, array( 'wcpay-payment-method' => 'pm_123' ) );

		$this->assertNotSame( $resolved, $result );
		$array = $result->to_array();
		$this->assertSame( PaymentMode::Live->value, $array['transaction_mode'] );
		$this->assertSame( 'card', $array['payment_type'] );
		$this->assertTrue( $array['is_saved_payment_method'] );
	}

	// --- Fail-open ---

	/**
	 * @testdox Returns mode only when PM ID is missing from payment data.
	 */
	public function test_returns_mode_only_when_pm_id_missing(): void {
		\WC_Payments::set_live( false );

		$result = $this->sut->resolve(
			new PaymentMethodData( 'woocommerce_payments' ),
			array()
		);

		$array = $result->to_array();
		$this->assertSame( PaymentMode::Test->value, $array['transaction_mode'] );
		$this->assertNull( $array['payment_type'] );
		$this->assertSame( PaymentInstrumentData::empty()->to_array(), $array['instrument'] );
	}

	/**
	 * @testdox Returns mode only when API client is null.
	 */
	public function test_returns_mode_only_when_api_client_null(): void {
		\WC_Payments::set_live( false );

		\WC_Payments::set_api_client( null );

		$result = $this->sut->resolve(
			new PaymentMethodData( 'woocommerce_payments' ),
			array( 'wcpay-payment-method' => 'pm_123' )
		);

		$array = $result->to_array();
		$this->assertSame( PaymentMode::Test->value, $array['transaction_mode'] );
		$this->assertNull( $array['payment_type'] );
		$this->assertSame( PaymentInstrumentData::empty()->to_array(), $array['instrument'] );
	}

	/**
	 * @testdox Returns mode only when API throws an exception.
	 */
	public function test_returns_mode_only_when_api_throws(): void {
		\WC_Payments::set_live( true );

		$this->mock_api_throws( new \RuntimeException( 'Connection failed' ) );

		$result = $this->sut->resolve(
			new PaymentMethodData( 'woocommerce_payments' ),
			array( 'wcpay-payment-method' => 'pm_123' )
		);

		$array = $result->to_array();
		$this->assertSame( PaymentMode::Live->value, $array['transaction_mode'] );
		$this->assertNull( $array['payment_type'] );
		$this->assertSame( PaymentInstrumentData::empty()->to_array(), $array['instrument'] );
	}

	/**
	 * @testdox Returns mode only when API response is missing type key.
	 */
	public function test_returns_mode_only_when_response_invalid(): void {
		$this->mock_api_response(
			array( 'id' => 'pm_123' )
		);

		$result = $this->sut->resolve(
			new PaymentMethodData( 'woocommerce_payments' ),
			array( 'wcpay-payment-method' => 'pm_123' )
		);

		$array = $result->to_array();
		$this->assertNull( $array['payment_type'] );
		$this->assertSame( PaymentInstrumentData::empty()->to_array(), $array['instrument'] );
	}

	/**
	 * @testdox Augments pre-resolved data on API error.
	 */
	public function test_augments_preresolved_on_api_error(): void {
		\WC_Payments::set_live( false );

		$this->mock_api_throws( new \RuntimeException( 'API error' ) );

		$instrument = PaymentInstrumentData::from_array( array( 'brand' => 'visa', 'last4' => '4242' ) );
		$resolved   = new PaymentMethodData( 'woocommerce_payments', 'card', true, $instrument );

		$result = $this->sut->resolve(
			$resolved,
			array( 'wcpay-payment-method' => 'pm_123' )
		);

		$this->assertNotSame( $resolved, $result );
		$array = $result->to_array();
		$this->assertSame( PaymentMode::Test->value, $array['transaction_mode'] );
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
		$this->mock_api_response(
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

		$this->mock_api_response( $response );

		$result = $this->sut->resolve(
			new PaymentMethodData( 'woocommerce_payments' ),
			array( 'wcpay-payment-method' => 'pm_card_123' )
		);

		$instrument = $result->to_array()['instrument'];
		$this->assertSame( CheckResult::Pass->value, $instrument['cvc_check'] );
		$this->assertSame( CheckResult::Fail->value, $instrument['avs_address_check'] );
		$this->assertSame( CheckResult::Unavailable->value, $instrument['avs_postcode_check'] );
	}

	/**
	 * @testdox Treats null checks hash as empty (no PHP warning, all checks null).
	 */
	public function test_handles_null_checks_hash(): void {
		$response         = $this->create_card_response();
		$response['card']['checks'] = null;

		$this->mock_api_response( $response );

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
		$this->mock_api_response(
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
		$this->mock_api_response(
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

	private function mock_api_response( array $response ): void {
		$mock = $this->createMock( \WC_Payments_API_Client::class );
		$mock->method( 'get_payment_method' )->willReturn( $response );
		\WC_Payments::set_api_client( $mock );
	}

	private function mock_api_throws( \Throwable $exception ): void {
		$mock = $this->createMock( \WC_Payments_API_Client::class );
		$mock->method( 'get_payment_method' )->willThrowException( $exception );
		\WC_Payments::set_api_client( $mock );
	}

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
				'checks'      => array(
					'cvc_check'                  => 'pass',
					'address_line1_check'        => 'fail',
					'address_postal_code_check'  => 'unchecked',
				),
			),
			'billing_details' => array(
				'address' => array( 'postal_code' => '10001' ),
			),
		);
	}
}
