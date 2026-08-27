<?php
/**
 * PayPalPaymentDataCompatTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin\Compat;

use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Compat\PayPalPaymentDataCompat;
use Automattic\WooCommerce\FraudProtection\Schemas\PaymentMethodData;
use Automattic\WooCommerce\FraudProtection\Schemas\PaymentMode;
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
		 * PayPal merchant identifier.
		 *
		 * @var mixed
		 */
		private static $merchant_id;

		/**
		 * Whether the merchant identifier lookup should throw.
		 *
		 * @var bool
		 */
		private static bool $merchant_id_throws = false;

		public static function set_merchant_id( $merchant_id ): void {
			self::$merchant_id = $merchant_id;
		}

		public static function set_merchant_id_throws( bool $throws ): void {
			self::$merchant_id_throws = $throws;
		}

		public static function reset(): void {
			self::$merchant_id        = null;
			self::$merchant_id_throws = false;
		}

		/**
		 * Get a service by ID.
		 *
		 * @param string $id Service ID.
		 * @return PPCP_ConnectionState_Stub
		 */
		public function get( string $id ) {
			if ( 'api.merchant_id' === $id ) {
				if ( self::$merchant_id_throws ) {
					throw new \RuntimeException( 'Merchant ID lookup failed' );
				}

				return self::$merchant_id;
			}

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
		PPCP_Container_Stub::reset();
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
	 * @testdox Includes the merchant account identifier for a prefixed PayPal gateway.
	 */
	public function test_includes_merchant_identifier(): void {
		PPCP_ConnectionState_Stub::set_sandbox( true );
		PPCP_Container_Stub::set_merchant_id( ' merchant_123 ' );

		$result = $this->sut->resolve( new PaymentMethodData( 'ppcp-card-button-gateway' ) );
		$array  = $result->to_array();

		$this->assertSame( 'merchant_123', $array['merchant_identifier'] );
		$this->assertSame( 'account', $array['merchant_identifier_type'] );
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
		PPCP_ConnectionState_Stub::set_sandbox( true );
		PPCP_Container_Stub::set_merchant_id( $merchant_id );
		PPCP_Container_Stub::set_merchant_id_throws( $throws );

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
