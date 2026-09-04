<?php
/**
 * MerchantFacingFeaturesGateTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin\Settings;

use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Settings\MerchantFacingFeaturesGate;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Settings\SettingStatus;

/**
 * Tests for MerchantFacingFeaturesGate.
 */
class MerchantFacingFeaturesGateTest extends FraudProtectionUnitTestCase {

	private const OPTION_NAME = 'woocommerce_fraud_protection_merchant_facing_features';

	/**
	 * Merchant-facing features gate.
	 *
	 * @var MerchantFacingFeaturesGate
	 */
	private $sut;

	public function setUp(): void {
		parent::setUp();
		$this->sut = new MerchantFacingFeaturesGate();
		$this->sut->reset();
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
		$this->assertSame( 'yes', get_option( self::OPTION_NAME ) );
		$this->assertSame( SettingStatus::Enabled, $this->sut->get_status() );
		$this->assertTrue( $this->sut->is_enabled() );

		$this->assertTrue( $this->sut->set_enabled( false ) );
		$this->assertSame( 'no', get_option( self::OPTION_NAME ) );
		$this->assertSame( SettingStatus::Disabled, $this->sut->get_status() );
		$this->assertFalse( $this->sut->is_enabled() );
	}

	/**
	 * @testdox Invalid stored values follow the code default.
	 */
	public function test_invalid_value_follows_code_default(): void {
		update_option( self::OPTION_NAME, array( 'invalid' ) );

		$this->assertSame( SettingStatus::DefaultDisabled, $this->sut->get_status() );
		$this->assertFalse( $this->sut->is_enabled() );

		$enabled_default = new class() extends MerchantFacingFeaturesGate {
			public function get_default(): SettingStatus {
				return SettingStatus::Enabled;
			}
		};
		$this->assertSame( SettingStatus::DefaultEnabled, $enabled_default->get_status() );
		$this->assertTrue( $enabled_default->is_enabled() );
	}

	/**
	 * @testdox A failed option write is reported to the caller.
	 */
	public function test_failed_write_is_reported(): void {
		add_filter( 'pre_update_option_' . self::OPTION_NAME, '__return_false' );

		$this->assertFalse( $this->sut->set_enabled( true ) );
		$this->assertNull( get_option( self::OPTION_NAME, null ) );
	}

	/**
	 * @testdox Reset removes an explicit override.
	 */
	public function test_reset_deletes_override(): void {
		$this->sut->set_enabled( false );

		$this->assertTrue( $this->sut->reset() );
		$this->assertNull( get_option( self::OPTION_NAME, null ) );
	}
}
