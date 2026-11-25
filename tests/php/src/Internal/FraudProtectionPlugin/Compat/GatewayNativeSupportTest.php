<?php
/**
 * GatewayNativeSupportTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin\Compat;

use Automattic\WooCommerce\FraudProtection\FraudProtectionFeature;
use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;
use Automattic\WooCommerce\FraudProtection\Tests\Support\FakeNativeGateway;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Compat\GatewayNativeSupport;

/**
 * Tests for the GatewayNativeSupport class.
 *
 * @covers \Automattic\WooCommerce\Internal\FraudProtectionPlugin\Compat\GatewayNativeSupport
 */
class GatewayNativeSupportTest extends FraudProtectionUnitTestCase {

	/**
	 * The System Under Test.
	 *
	 * @var GatewayNativeSupport
	 */
	private $sut;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->sut = new GatewayNativeSupport();
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		remove_all_filters( 'woocommerce_payment_gateway_supports' );

		parent::tearDown();
	}

	/**
	 * @testdox A registered gateway that lists the feature in its supports declares it.
	 */
	public function test_registered_gateway_declares_listed_feature(): void {
		$this->register_payment_gateways( new FakeNativeGateway( 'native_gateway', array( 'products', FraudProtectionFeature::PAYMENT_DATA ) ) );

		$this->assertTrue( $this->sut->declared( FraudProtectionFeature::PAYMENT_DATA, 'native_gateway' ) );
		$this->assertFalse( $this->sut->declared( FraudProtectionFeature::CHECKOUT, 'native_gateway' ), 'Only the listed feature is declared' );
	}

	/**
	 * @testdox The built-in WooCommerce gateways declare nothing.
	 */
	public function test_core_gateways_declare_nothing(): void {
		foreach ( FraudProtectionFeature::ALL as $feature ) {
			$this->assertFalse( $this->sut->declared( $feature, 'bacs', 'cheque', 'cod' ) );
		}
		$this->assertSame( array(), $this->sut->get_declarations() );
	}

	/**
	 * @testdox An unregistered gateway ID is skipped.
	 */
	public function test_unregistered_gateway_is_skipped(): void {
		$this->assertFalse( $this->sut->declared( FraudProtectionFeature::PAYMENT_DATA, 'missing_gateway', '' ) );
	}

	/**
	 * @testdox A declaration on the family's main gateway covers a payment-method instance that stays silent.
	 */
	public function test_main_gateway_declaration_covers_silent_family_instance(): void {
		$this->register_payment_gateways(
			new FakeNativeGateway( 'stripe', array( FraudProtectionFeature::PAYMENT_DATA ) ),
			new FakeNativeGateway( 'stripe_sepa', array( 'products' ) )
		);

		$this->assertTrue( $this->sut->declared( FraudProtectionFeature::PAYMENT_DATA, 'stripe_sepa', 'stripe' ) );
		$this->assertFalse( $this->sut->declared( FraudProtectionFeature::PAYMENT_DATA, 'stripe_sepa' ), 'Asked alone, the instance does not declare' );
	}

	/**
	 * @testdox A payment-method instance can declare on its own.
	 */
	public function test_family_instance_declares_on_its_own(): void {
		$this->register_payment_gateways(
			new FakeNativeGateway( 'stripe', array( 'products' ) ),
			new FakeNativeGateway( 'stripe_sepa', array( FraudProtectionFeature::PAYMENT_DATA ) )
		);

		$this->assertTrue( $this->sut->declared( FraudProtectionFeature::PAYMENT_DATA, 'stripe_sepa', 'stripe' ) );
		$this->assertFalse( $this->sut->declared( FraudProtectionFeature::PAYMENT_DATA, 'stripe' ) );
	}

	/**
	 * @testdox WooCommerce's gateway-supports filter can force the answer for a gateway.
	 *
	 * @dataProvider forced_answer_provider
	 *
	 * @param bool $declared Whether the gateway lists the feature.
	 * @param bool $forced   The answer the filter forces.
	 */
	public function test_gateway_supports_filter_forces_the_answer( bool $declared, bool $forced ): void {
		$this->register_payment_gateways( new FakeNativeGateway( 'native_gateway', $declared ? array( FraudProtectionFeature::CHECKOUT ) : array() ) );
		add_filter(
			'woocommerce_payment_gateway_supports',
			static function ( $supports, $feature, $gateway ) use ( $forced ) {
				return FraudProtectionFeature::CHECKOUT === $feature && 'native_gateway' === $gateway->id ? $forced : $supports;
			},
			PHP_INT_MAX,
			3
		);

		$this->assertSame( $forced, $this->sut->declared( FraudProtectionFeature::CHECKOUT, 'native_gateway' ) );
	}

	/**
	 * Forced answers.
	 *
	 * @return array<string, array{bool, bool}>
	 */
	public function forced_answer_provider(): array {
		return array(
			'forced back to compat' => array( true, false ),
			'forced to native'      => array( false, true ),
		);
	}

	/**
	 * @testdox A non-boolean answer from the gateway-supports filter keeps the compatibility layer on.
	 */
	public function test_non_boolean_supports_answer_is_not_a_declaration(): void {
		$this->register_payment_gateways( new FakeNativeGateway( 'native_gateway', array( FraudProtectionFeature::CHECKOUT ) ) );
		add_filter( 'woocommerce_payment_gateway_supports', static fn() => 'yes', PHP_INT_MAX );

		$this->assertFalse( $this->sut->declared( FraudProtectionFeature::CHECKOUT, 'native_gateway' ) );
	}

	/**
	 * @testdox A throwing gateway-supports filter keeps the compatibility layer on and is logged.
	 */
	public function test_throwing_supports_filter_keeps_compat_on(): void {
		$this->register_payment_gateways( new FakeNativeGateway( 'native_gateway', array( FraudProtectionFeature::CHECKOUT ) ) );
		add_filter(
			'woocommerce_payment_gateway_supports',
			static function () {
				throw new \RuntimeException( 'broken gateway' );
			},
			PHP_INT_MAX
		);

		$this->assertFalse( $this->sut->declared( FraudProtectionFeature::CHECKOUT, 'native_gateway' ) );
		$this->assertSame( array(), $this->sut->get_declarations() );
		$this->assertLogged( 'warning', 'native Fraud Protection support failed', array( 'exception_message' => 'broken gateway' ) );
	}

	/**
	 * @testdox A declaration is logged once per feature and gateway per request.
	 */
	public function test_declaration_is_logged_once(): void {
		$spy = $this->spy_on_controller_logging();
		$this->register_payment_gateways( new FakeNativeGateway( 'native_gateway', array( FraudProtectionFeature::PAYMENT_DATA, FraudProtectionFeature::CHECKOUT ) ) );

		$this->sut->declared( FraudProtectionFeature::PAYMENT_DATA, 'native_gateway' );
		$this->sut->declared( FraudProtectionFeature::PAYMENT_DATA, 'native_gateway' );
		$this->sut->declared( FraudProtectionFeature::CHECKOUT, 'native_gateway' );

		$declaration_logs = array_filter(
			$spy->entries,
			static fn( array $entry ): bool => str_contains( $entry['message'], 'declares native Fraud Protection support' )
		);
		$this->assertCount( 2, $declaration_logs, 'One entry per declared feature' );
		$this->assertLogged(
			'info',
			'declares native Fraud Protection support',
			array(
				'payment_type' => 'native_gateway',
				'feature'      => FraudProtectionFeature::PAYMENT_DATA,
			)
		);
	}

	/**
	 * @testdox The declarations list names each declaring gateway with its features.
	 */
	public function test_get_declarations_lists_declaring_gateways(): void {
		$this->register_payment_gateways(
			new FakeNativeGateway( 'native_gateway', array( 'refunds', FraudProtectionFeature::CHECKOUT, FraudProtectionFeature::PAYMENT_DATA ) ),
			new FakeNativeGateway( 'silent_gateway', array( 'refunds' ) )
		);

		$this->assertSame(
			array( 'native_gateway' => array( FraudProtectionFeature::PAYMENT_DATA, FraudProtectionFeature::CHECKOUT ) ),
			$this->sut->get_declarations()
		);
	}
}
