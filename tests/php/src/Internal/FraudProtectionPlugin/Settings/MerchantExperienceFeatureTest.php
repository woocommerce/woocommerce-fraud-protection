<?php
/**
 * MerchantExperienceFeatureTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin\Settings;

use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Settings\MerchantExperienceFeature;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Settings\SettingStatus;

/**
 * Tests for MerchantExperienceFeature.
 */
class MerchantExperienceFeatureTest extends FraudProtectionUnitTestCase {

	private const OPTION_NAME = 'woocommerce_fraud_protection_merchant_experience';

	/**
	 * Merchant experience feature.
	 *
	 * @var MerchantExperienceFeature
	 */
	private $sut;

	public function setUp(): void {
		parent::setUp();
		$this->sut = new MerchantExperienceFeature();
		$this->sut->reset();
	}

	public function tearDown(): void {
		$this->sut->reset();
		parent::tearDown();
	}

	/**
	 * @testdox An absent override follows the disabled code default.
	 */
	public function test_absent_override_is_disabled_by_default(): void {
		$this->assertSame( SettingStatus::DefaultDisabled, $this->sut->get_status() );
		$this->assertSame( SettingStatus::Disabled, $this->sut->get_default() );
		$this->assertFalse( $this->sut->is_enabled() );
		$this->assertNull( get_option( self::OPTION_NAME, null ) );
	}

	/**
	 * @testdox Explicit enabled and disabled overrides take precedence.
	 */
	public function test_explicit_overrides_take_precedence(): void {
		$this->assertTrue( $this->sut->set_enabled( true ) );
		$this->assertSame( SettingStatus::Enabled, $this->sut->get_status() );
		$this->assertTrue( $this->sut->is_enabled() );

		$this->assertTrue( $this->sut->set_enabled( false ) );
		$this->assertSame( SettingStatus::Disabled, $this->sut->get_status() );
		$this->assertFalse( $this->sut->is_enabled() );
	}

	/**
	 * @testdox Invalid stored values follow the code default.
	 */
	public function test_invalid_value_follows_code_default(): void {
		update_option( self::OPTION_NAME, 'invalid' );

		$this->assertSame( SettingStatus::DefaultDisabled, $this->sut->get_status() );
		$this->assertFalse( $this->sut->is_enabled() );

		$enabled_default = new class() extends MerchantExperienceFeature {
			public function get_default(): SettingStatus {
				return SettingStatus::Enabled;
			}
		};
		$this->assertSame( SettingStatus::DefaultEnabled, $enabled_default->get_status() );
		$this->assertTrue( $enabled_default->is_enabled() );
	}

	/**
	 * @testdox Reset removes an explicit override.
	 */
	public function test_reset_deletes_override(): void {
		$this->sut->set_enabled( true );

		$this->assertTrue( $this->sut->reset() );
		$this->assertNull( get_option( self::OPTION_NAME, null ) );
	}
}
