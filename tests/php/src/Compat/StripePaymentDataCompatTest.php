<?php
/**
 * StripePaymentDataCompatTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Compat;

use Automattic\WooCommerce\FraudProtection\Compat\StripePaymentDataCompat;
use Automattic\WooCommerce\FraudProtection\Schemas\PaymentMethodData;
use WC_Unit_Test_Case;

// Stub WC_Stripe_API if the real class isn't loaded.
if ( ! class_exists( '\WC_Stripe_API', false ) ) {
	// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
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
			return self::$mock_response;
		}
	}

	class_alias( __NAMESPACE__ . '\WC_Stripe_API_Stub', 'WC_Stripe_API' );
}

/**
 * Tests for the StripePaymentDataCompat class.
 *
 * @covers \Automattic\WooCommerce\FraudProtection\Compat\StripePaymentDataCompat
 */
class StripePaymentDataCompatTest extends WC_Unit_Test_Case {

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
		parent::tearDown();
	}

	/**
	 * @testdox Returns resolved for non-Stripe payment methods.
	 */
	public function test_returns_resolved_for_non_stripe(): void {
		$resolved = new PaymentMethodData( 'woocommerce_payments', 'test' );

		$result = $this->sut->resolve(
			$resolved,
			'woocommerce_payments',
			array( 'wc-stripe-payment-method' => 'pm_123' )
		);

		$this->assertSame( $resolved, $result );
	}

	/**
	 * @testdox Returns resolved when PM ID is missing from payment data.
	 */
	public function test_returns_resolved_for_missing_pm_id(): void {
		$result = $this->sut->resolve(
			null,
			'stripe',
			array()
		);

		$this->assertNull( $result );
	}

	/**
	 * @testdox Passes through previously resolved data.
	 */
	public function test_passes_through_resolved_data(): void {
		$existing = new PaymentMethodData( 'stripe', 'card', false );

		$result = $this->sut->resolve(
			$existing,
			'stripe',
			array( 'wc-stripe-payment-method' => 'pm_123' )
		);

		$this->assertSame( $existing, $result );
	}

	/**
	 * @testdox Handles 'stripe' gateway ID.
	 */
	public function test_handles_stripe_gateway_id(): void {
		\WC_Stripe_API::set_mock_response( $this->create_card_response() );

		$result = $this->sut->resolve(
			null,
			'stripe',
			array( 'wc-stripe-payment-method' => 'pm_123' )
		);

		$this->assertInstanceOf( PaymentMethodData::class, $result );
		$this->assertSame( 'card', $result->to_array()['payment_type'] );
	}

	/**
	 * @testdox Handles 'stripe_sepa_debit' gateway ID.
	 */
	public function test_handles_stripe_sepa_debit_gateway_id(): void {
		$response       = new \stdClass();
		$response->type = 'sepa_debit';

		\WC_Stripe_API::set_mock_response( $response );

		$result = $this->sut->resolve(
			null,
			'stripe_sepa_debit',
			array( 'wc-stripe-payment-method' => 'pm_sepa_123' )
		);

		$this->assertInstanceOf( PaymentMethodData::class, $result );
		$array = $result->to_array();
		$this->assertSame( 'sepa_debit', $array['payment_type'] );
		$this->assertNull( $array['card'] );
	}

	/**
	 * @testdox Handles 'stripe_ideal' gateway ID.
	 */
	public function test_handles_stripe_ideal_gateway_id(): void {
		$response       = new \stdClass();
		$response->type = 'ideal';

		\WC_Stripe_API::set_mock_response( $response );

		$result = $this->sut->resolve(
			null,
			'stripe_ideal',
			array( 'wc-stripe-payment-method' => 'pm_ideal_123' )
		);

		$this->assertInstanceOf( PaymentMethodData::class, $result );
		$this->assertSame( 'ideal', $result->to_array()['payment_type'] );
	}

	/**
	 * @testdox Resolves card details from mocked API response.
	 */
	public function test_resolves_card_via_api(): void {
		\WC_Stripe_API::set_mock_response( $this->create_card_response() );

		$result = $this->sut->resolve(
			null,
			'stripe',
			array( 'wc-stripe-payment-method' => 'pm_card_123' )
		);

		$this->assertInstanceOf( PaymentMethodData::class, $result );
		$this->assertSame(
			array(
				'gateway'                 => 'stripe',
				'payment_type'            => 'card',
				'is_saved_payment_method' => false,
				'card'                    => array(
					'brand'            => 'visa',
					'funding'          => 'credit',
					'last4'            => '4242',
					'fingerprint'      => 'fp_stripe123',
					'country'          => 'US',
					'exp_month'        => 12,
					'exp_year'         => 2025,
					'billing_postcode' => '10001',
				),
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
			null,
			'stripe',
			array( 'stripe_source' => 'src_legacy_123' )
		);

		$this->assertInstanceOf( PaymentMethodData::class, $result );
	}

	/**
	 * @testdox Detects saved payment method via payment token key.
	 */
	public function test_detects_saved_payment_method(): void {
		\WC_Stripe_API::set_mock_response( $this->create_card_response() );

		$result = $this->sut->resolve(
			null,
			'stripe',
			array(
				'wc-stripe-payment-method' => 'pm_saved_123',
				'wc-stripe-payment-token'  => 'token_abc',
			)
		);

		$this->assertInstanceOf( PaymentMethodData::class, $result );
		$this->assertTrue( $result->to_array()['is_saved_payment_method'] );
	}

	/**
	 * @testdox Returns resolved when API returns null.
	 */
	public function test_returns_resolved_when_api_returns_null(): void {
		// Mock response is null by default (set in setUp via reset).
		$result = $this->sut->resolve(
			null,
			'stripe',
			array( 'wc-stripe-payment-method' => 'pm_123' )
		);

		$this->assertNull( $result );
	}


	/**
	 * @testdox Returns resolved when API returns a WP_Error.
	 */
	public function test_returns_resolved_when_api_returns_wp_error(): void {
		\WC_Stripe_API::set_mock_response( new \WP_Error( 'stripe_error', 'Connection failed' ) );

		$result = $this->sut->resolve(
			null,
			'stripe',
			array( 'wc-stripe-payment-method' => 'pm_123' )
		);

		$this->assertNull( $result );
	}

	/**
	 * @testdox Does not match 'stripe_something' incorrectly as non-Stripe gateway.
	 */
	public function test_does_not_match_stripe_partial_prefix(): void {
		$result = $this->sut->resolve(
			null,
			'stripez_custom',
			array( 'wc-stripe-payment-method' => 'pm_123' )
		);

		$this->assertNull( $result );
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
