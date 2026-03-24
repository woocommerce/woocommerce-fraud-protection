<?php
/**
 * WooPaymentsPaymentDataCompatTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\FraudProtection\Compat;

use Automattic\WooCommerce\FraudProtection\Compat\WooPaymentsPaymentDataCompat;
use Automattic\WooCommerce\FraudProtection\Schemas\PaymentMethodData;
use WC_Unit_Test_Case;

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
	}

	class_alias( __NAMESPACE__ . '\WC_Payments_Stub', 'WC_Payments' );
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
		WC_Payments_Stub::set_mode_available( true );
		parent::tearDown();
	}

	/**
	 * @testdox Returns resolved for non-WooPayments payment methods.
	 */
	public function test_returns_resolved_for_non_woopayments(): void {
		$resolved = new PaymentMethodData( 'stripe', 'card' );

		$result = $this->sut->resolve( $resolved );

		$this->assertSame( $resolved, $result );
	}

	/**
	 * @testdox Includes test mode when WooPayments is not in live mode.
	 */
	public function test_includes_test_mode(): void {
		WCPay_Mode_Stub::set_live( false );

		$result = $this->sut->resolve(
			new PaymentMethodData( 'woocommerce_payments' )
		);

		$this->assertSame( PaymentMethodData::MODE_TEST, $result->to_array()['transaction_mode'] );
	}

	/**
	 * @testdox Includes live mode when WooPayments is in live mode.
	 */
	public function test_includes_live_mode(): void {
		WCPay_Mode_Stub::set_live( true );

		$result = $this->sut->resolve(
			new PaymentMethodData( 'woocommerce_payments' )
		);

		$this->assertSame( PaymentMethodData::MODE_LIVE, $result->to_array()['transaction_mode'] );
	}

	/**
	 * @testdox Matches APM gateways like woocommerce_payments_bancontact.
	 */
	public function test_matches_apm_gateway(): void {
		WCPay_Mode_Stub::set_live( false );

		$result = $this->sut->resolve(
			new PaymentMethodData( 'woocommerce_payments_bancontact' )
		);

		$this->assertSame( PaymentMethodData::MODE_TEST, $result->to_array()['transaction_mode'] );
	}

	/**
	 * @testdox Does not match unrelated gateways with similar prefix.
	 */
	public function test_does_not_match_unrelated_gateway(): void {
		$resolved = new PaymentMethodData( 'woocommerce_paymentsx' );

		$result = $this->sut->resolve( $resolved );

		$this->assertSame( $resolved, $result );
	}

	/**
	 * @testdox Transaction mode is unknown when WooPayments Mode is unavailable.
	 */
	public function test_transaction_mode_unknown_when_mode_unavailable(): void {
		WC_Payments_Stub::set_mode_available( false );

		$result = $this->sut->resolve(
			new PaymentMethodData( 'woocommerce_payments' )
		);

		$this->assertSame( PaymentMethodData::MODE_UNKNOWN, $result->to_array()['transaction_mode'] );
	}

	/**
	 * @testdox Augments pre-resolved data with transaction mode.
	 */
	public function test_augments_preresolved_with_mode(): void {
		WCPay_Mode_Stub::set_live( true );

		$resolved = new PaymentMethodData( 'woocommerce_payments', 'card', true );

		$result = $this->sut->resolve( $resolved );

		$this->assertNotSame( $resolved, $result );
		$array = $result->to_array();
		$this->assertSame( PaymentMethodData::MODE_LIVE, $array['transaction_mode'] );
		$this->assertSame( 'card', $array['payment_type'] );
		$this->assertTrue( $array['is_saved_payment_method'] );
	}
}
