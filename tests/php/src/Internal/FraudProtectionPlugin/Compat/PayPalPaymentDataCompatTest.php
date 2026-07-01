<?php
/**
 * PayPalPaymentDataCompatTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin\Compat;

use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Compat\PayPalPaymentDataCompat;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Schemas\PaymentMethodData;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Schemas\PaymentMode;
use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;

// Stub PayPal Payments PPCP and ConnectionState if not loaded.
if ( ! class_exists( '\WooCommerce\PayPalCommerce\PPCP', false ) ) {

	// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
	class PPCP_ConnectionState_Stub {

		/**
		 * Whether the merchant is connected and in sandbox mode.
		 *
		 * @var ?bool True = sandbox, false = production, null = not connected.
		 */
		private static ?bool $sandbox = null;

		/**
		 * Set the sandbox state for testing.
		 *
		 * @param ?bool $sandbox True = sandbox, false = production, null = not connected.
		 * @return void
		 */
		public static function set_sandbox( ?bool $sandbox ): void {
			self::$sandbox = $sandbox;
		}

		/**
		 * Whether the merchant is connected and in sandbox mode.
		 *
		 * @return bool
		 */
		public function is_sandbox(): bool {
			return true === self::$sandbox;
		}

		/**
		 * Whether the merchant is connected and in production mode.
		 *
		 * @return bool
		 */
		public function is_production(): bool {
			return false === self::$sandbox;
		}
	}

	// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
	class PPCP_Container_Stub {

		/**
		 * Get a service by ID.
		 *
		 * @param string $id Service ID.
		 * @return PPCP_ConnectionState_Stub
		 */
		public function get( string $id ): PPCP_ConnectionState_Stub {
			return new PPCP_ConnectionState_Stub();
		}
	}

	// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
	class PPCP_Stub {

		/**
		 * Get the container.
		 *
		 * @return PPCP_Container_Stub
		 */
		public static function container(): PPCP_Container_Stub {
			return new PPCP_Container_Stub();
		}
	}

	class_alias( __NAMESPACE__ . '\PPCP_Stub', 'WooCommerce\PayPalCommerce\PPCP' );
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
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->sut = new PayPalPaymentDataCompat();
	}

	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		PPCP_ConnectionState_Stub::set_sandbox( null );
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
		PPCP_ConnectionState_Stub::set_sandbox( true );

		$result = $this->sut->resolve(
			new PaymentMethodData( 'ppcp-gateway' )
		);

		$this->assertSame( PaymentMode::Test->value, $result->to_array()['transaction_mode'] );
	}

	/**
	 * @testdox Includes live mode when PayPal is in production.
	 */
	public function test_includes_live_mode(): void {
		PPCP_ConnectionState_Stub::set_sandbox( false );

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
		PPCP_ConnectionState_Stub::set_sandbox( true );

		$result = $this->sut->resolve(
			new PaymentMethodData( 'ppcp-card-button-gateway' )
		);

		$this->assertSame( PaymentMode::Test->value, $result->to_array()['transaction_mode'] );
	}

	/**
	 * @testdox Augments pre-resolved data with transaction mode.
	 */
	public function test_augments_preresolved_with_mode(): void {
		PPCP_ConnectionState_Stub::set_sandbox( true );

		$resolved = new PaymentMethodData( 'ppcp-gateway', 'paypal', true );

		$result = $this->sut->resolve( $resolved );

		$this->assertNotSame( $resolved, $result );
		$array = $result->to_array();
		$this->assertSame( PaymentMode::Test->value, $array['transaction_mode'] );
		$this->assertSame( 'paypal', $array['payment_type'] );
		$this->assertTrue( $array['is_saved_payment_method'] );
	}
}
