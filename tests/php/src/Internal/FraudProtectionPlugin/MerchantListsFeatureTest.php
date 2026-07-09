<?php
/**
 * MerchantListsFeatureTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin;

use Automattic\WooCommerce\Internal\FraudProtectionPlugin\MerchantListsFeature;
use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;

/**
 * Tests for the MerchantListsFeature class.
 */
class MerchantListsFeatureTest extends FraudProtectionUnitTestCase {

	/**
	 * The System Under Test.
	 *
	 * @var MerchantListsFeature
	 */
	private $sut;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->sut = new MerchantListsFeature();
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		delete_option( MerchantListsFeature::OPTION_NAME );
		remove_all_filters( 'woocommerce_fraud_protection_merchant_lists_enabled' );
		parent::tearDown();
	}

	/**
	 * @testdox Should be disabled by default.
	 */
	public function test_disabled_by_default(): void {
		$this->assertFalse( $this->sut->is_enabled(), 'The feature must be off unless explicitly enabled' );
	}

	/**
	 * @testdox Should be enabled when the option is set to yes.
	 */
	public function test_enabled_via_option(): void {
		update_option( MerchantListsFeature::OPTION_NAME, 'yes' );

		$this->assertTrue( $this->sut->is_enabled() );
	}

	/**
	 * @testdox Should let the filter override the option value in both directions.
	 */
	public function test_filter_overrides_option(): void {
		add_filter( 'woocommerce_fraud_protection_merchant_lists_enabled', '__return_true' );
		$this->assertTrue( $this->sut->is_enabled(), 'The filter should be able to enable the feature' );

		remove_all_filters( 'woocommerce_fraud_protection_merchant_lists_enabled' );
		update_option( MerchantListsFeature::OPTION_NAME, 'yes' );
		add_filter( 'woocommerce_fraud_protection_merchant_lists_enabled', '__return_false' );
		$this->assertFalse( $this->sut->is_enabled(), 'The filter should be able to disable the feature' );
	}
}
