<?php
/**
 * PaymentMethodTitleResolverTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin\Sessions;

use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Sessions\PaymentMethodTitleResolver;

/**
 * Tests for the PaymentMethodTitleResolver class.
 */
class PaymentMethodTitleResolverTest extends FraudProtectionUnitTestCase {

	/**
	 * The System Under Test.
	 *
	 * @var PaymentMethodTitleResolver
	 */
	private $sut;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		add_filter( 'woocommerce_payment_gateways', array( $this, 'add_mock_gateway' ) );
		WC()->payment_gateways()->init();

		$this->sut = new PaymentMethodTitleResolver();
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		remove_filter( 'woocommerce_payment_gateways', array( $this, 'add_mock_gateway' ) );
		WC()->payment_gateways()->init();

		parent::tearDown();
	}

	/**
	 * Register the mock gateway.
	 *
	 * @param array<int, mixed> $gateways Registered gateways.
	 * @return array<int, mixed>
	 */
	public function add_mock_gateway( array $gateways ): array {
		$gateways[] = \WC_Mock_Payment_Gateway::class;

		return $gateways;
	}

	/**
	 * @testdox Should resolve a registered gateway id to its method title.
	 */
	public function test_resolves_registered_gateway_title(): void {
		$this->assertSame( 'Mock Gateway', $this->sut->resolve( 'mock' ) );
	}

	/**
	 * @testdox Should fall back to the raw id for an unregistered gateway.
	 */
	public function test_falls_back_to_id_for_unregistered_gateway(): void {
		$this->assertSame( 'legacy_gateway', $this->sut->resolve( 'legacy_gateway' ) );
	}

	/**
	 * @testdox Should return an empty string for an empty id.
	 */
	public function test_returns_empty_string_for_empty_id(): void {
		$this->assertSame( '', $this->sut->resolve( '' ) );
	}

	/**
	 * @testdox Should resolve the gateway icon URL from its icon property.
	 */
	public function test_resolves_gateway_icon_url(): void {
		WC()->payment_gateways()->payment_gateways()['mock']->icon = 'https://example.test/mock-icon.svg';

		$this->assertSame( 'https://example.test/mock-icon.svg', $this->sut->resolve_icon( 'mock' ) );
	}

	/**
	 * @testdox Should fall back to the first image the gateway markup renders.
	 */
	public function test_resolves_gateway_icon_from_markup(): void {
		WC()->payment_gateways()->payment_gateways()['mock']->icon = '';
		$callback = static fn(): string => '<img src="https://example.test/from-markup.png" alt="Mock" />';
		add_filter( 'woocommerce_gateway_icon', $callback );

		try {
			$this->assertSame( 'https://example.test/from-markup.png', $this->sut->resolve_icon( 'mock' ) );
		} finally {
			remove_filter( 'woocommerce_gateway_icon', $callback );
		}
	}

	/**
	 * @testdox Should return null when the gateway has no icon.
	 */
	public function test_returns_null_icon_when_gateway_has_none(): void {
		WC()->payment_gateways()->payment_gateways()['mock']->icon = '';

		$this->assertNull( $this->sut->resolve_icon( 'mock' ) );
	}

	/**
	 * @testdox Should return null icon for an unregistered gateway.
	 */
	public function test_returns_null_icon_for_unregistered_gateway(): void {
		$this->assertNull( $this->sut->resolve_icon( 'legacy_gateway' ) );
	}
}
