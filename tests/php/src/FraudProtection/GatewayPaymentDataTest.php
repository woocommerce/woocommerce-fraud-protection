<?php
/**
 * GatewayPaymentDataTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\FraudProtection;

use Automattic\WooCommerce\FraudProtection\GatewayPaymentData;
use Automattic\WooCommerce\FraudProtection\Schemas\PaymentInstrumentData;
use Automattic\WooCommerce\FraudProtection\Schemas\PaymentMethodData;
use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\PaymentDataResolver;

/**
 * Tests for the GatewayPaymentData class.
 *
 * @covers \Automattic\WooCommerce\FraudProtection\GatewayPaymentData
 */
class GatewayPaymentDataTest extends FraudProtectionUnitTestCase {

	private const GATEWAY_ID = 'my_gateway';

	/**
	 * The System Under Test.
	 *
	 * @var GatewayPaymentData
	 */
	private GatewayPaymentData $sut;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->sut = new GatewayPaymentData();
		$this->sut->register();
	}

	/**
	 * Tear down after each test.
	 */
	public function tearDown(): void {
		remove_filter( 'woocommerce_fraud_protection_resolved_payment_data', array( $this->sut, 'apply_resolver' ), 10 );

		parent::tearDown();
	}

	/**
	 * @testdox The class is a shared WooCommerce container service.
	 */
	public function test_is_resolvable_from_container(): void {
		$this->assertInstanceOf( GatewayPaymentData::class, wc_get_container()->get( GatewayPaymentData::class ) );
		$this->assertSame( wc_get_container()->get( GatewayPaymentData::class ), wc_get_container()->get( GatewayPaymentData::class ) );
	}

	/**
	 * @testdox The resolver describes its gateway's attempts from the payment fields and the data resolved so far.
	 */
	public function test_resolver_describes_its_gateway_attempts(): void {
		$received = null;
		$this->sut->register_resolver(
			self::GATEWAY_ID,
			static function ( array $payment_fields, PaymentMethodData $resolved ) use ( &$received ): PaymentMethodData {
				$received = array( $payment_fields, $resolved->get_gateway() );

				return self::card( $payment_fields['brand'] ?? null );
			}
		);

		$resolved = ( new PaymentDataResolver() )->resolve( self::GATEWAY_ID, array( 'brand' => 'visa' ) )->to_array();

		$this->assertSame( array( array( 'brand' => 'visa' ), self::GATEWAY_ID ), $received );
		$this->assertSame( 'card', $resolved['payment_type'] );
		$this->assertSame( 'visa', $resolved['instrument']['brand'] );
	}

	/**
	 * @testdox Attempts paid with another gateway never reach the resolver.
	 */
	public function test_other_gateways_are_left_alone(): void {
		$this->sut->register_resolver(
			self::GATEWAY_ID,
			function () {
				$this->fail( 'The resolver must not run for another gateway' );
			}
		);

		$resolved = ( new PaymentDataResolver() )->resolve( 'other_gateway', array( 'brand' => 'visa' ) );

		$this->assertSame( 'other_gateway', $resolved->get_gateway() );
		$this->assertNull( $resolved->to_array()['payment_type'] );
	}

	/**
	 * @testdox A resolver that returns null keeps the data resolved so far.
	 */
	public function test_null_keeps_the_resolved_data(): void {
		$this->sut->register_resolver( self::GATEWAY_ID, static fn() => null );
		$incoming = new PaymentMethodData( self::GATEWAY_ID );

		$this->assertSame( $incoming, $this->apply( $incoming, array() ) );
	}

	/**
	 * @testdox An unusable resolver result keeps the data resolved so far and logs a forwarded warning.
	 *
	 * @dataProvider unusable_result_provider
	 *
	 * @param callable $resolver The resolver.
	 * @param string   $message  Expected log message substring.
	 */
	public function test_unusable_result_keeps_the_resolved_data( callable $resolver, string $message ): void {
		$this->spy_on_controller_logging();
		$this->sut->register_resolver( self::GATEWAY_ID, $resolver );
		$incoming = new PaymentMethodData( self::GATEWAY_ID );

		$this->assertSame( $incoming, $this->apply( $incoming, array() ) );
		$this->assertLogged( 'warning', $message, array( 'payment_type' => self::GATEWAY_ID ), true );
	}

	/**
	 * Resolvers whose result cannot be used.
	 *
	 * @return array<string, array{callable, string}>
	 */
	public function unusable_result_provider(): array {
		return array(
			'throws'                => array(
				static function () {
					throw new \RuntimeException( 'Resolver failed' );
				},
				'Payment data resolver threw',
			),
			'returns an array'      => array( static fn() => array( 'payment_type' => 'card' ), 'returned an unusable value' ),
			'returns other gateway' => array( static fn() => new PaymentMethodData( 'other_gateway' ), 'returned an unusable value' ),
		);
	}

	/**
	 * @testdox The resolver receives an empty array when the payment fields are not an array.
	 */
	public function test_non_array_payment_fields_become_an_empty_array(): void {
		$received = null;
		$this->sut->register_resolver(
			self::GATEWAY_ID,
			static function ( array $payment_fields ) use ( &$received ) {
				$received = $payment_fields;

				return null;
			}
		);

		$this->sut->apply_resolver( new PaymentMethodData( self::GATEWAY_ID ), 'not-an-array' );

		$this->assertSame( array(), $received );
	}

	/**
	 * @testdox A value that is not payment data passes through without running the resolver.
	 */
	public function test_non_payment_data_passes_through(): void {
		$this->sut->register_resolver(
			self::GATEWAY_ID,
			function () {
				$this->fail( 'The resolver must not run without payment data' );
			}
		);

		$this->assertSame( 'unexpected', $this->sut->apply_resolver( 'unexpected', array() ) );
	}

	/**
	 * @testdox A later registration for the same gateway replaces the resolver.
	 */
	public function test_later_registration_replaces_the_resolver(): void {
		$this->sut->register_resolver( self::GATEWAY_ID, static fn() => self::card( 'visa' ) );
		$this->sut->register_resolver( self::GATEWAY_ID, static fn() => self::card( 'amex' ) );

		$resolved = $this->apply( new PaymentMethodData( self::GATEWAY_ID ), array() );

		$this->assertSame( 'amex', $resolved->to_array()['instrument']['brand'] );
	}

	/**
	 * Run the payment data filter.
	 *
	 * @param mixed $resolved       The payment data resolved so far.
	 * @param mixed $payment_fields The payment fields.
	 * @return mixed
	 */
	private function apply( $resolved, $payment_fields ) {
		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Test invokes the hook.
		return apply_filters( 'woocommerce_fraud_protection_resolved_payment_data', $resolved, $payment_fields );
	}

	/**
	 * Build card payment data for the test gateway.
	 *
	 * @param ?string $brand Card brand.
	 * @return PaymentMethodData
	 */
	private static function card( ?string $brand ): PaymentMethodData {
		return new PaymentMethodData( self::GATEWAY_ID, 'card', false, PaymentInstrumentData::from_array( array( 'brand' => $brand ) ) );
	}
}
