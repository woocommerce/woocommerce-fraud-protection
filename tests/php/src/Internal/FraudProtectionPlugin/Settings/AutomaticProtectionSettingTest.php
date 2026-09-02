<?php
/**
 * AutomaticProtectionSettingTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin\Settings;

use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Settings\AutomaticProtectionSetting;

/**
 * Tests for AutomaticProtectionSetting.
 */
class AutomaticProtectionSettingTest extends FraudProtectionUnitTestCase {

	private const OPTION_NAME = 'woocommerce_fraud_protection_automatic_protection';

	/**
	 * Automatic protection setting.
	 *
	 * @var AutomaticProtectionSetting
	 */
	private $sut;

	public function setUp(): void {
		parent::setUp();
		$this->sut = new AutomaticProtectionSetting();
		$this->sut->reset();
	}

	public function tearDown(): void {
		$this->sut->reset();
		parent::tearDown();
	}

	/**
	 * @testdox An absent setting is disabled without writing an opt-out.
	 */
	public function test_absent_setting_is_default_disabled(): void {
		$this->assertSame( AutomaticProtectionSetting::STATUS_DEFAULT_DISABLED, $this->sut->get_stored_status() );
		$this->assertFalse( $this->sut->is_enabled() );
		$this->assertNull( get_option( self::OPTION_NAME, null ) );
	}

	/**
	 * @testdox Enabled and disabled values persist as explicit choices.
	 */
	public function test_explicit_values_persist(): void {
		$this->assertTrue( $this->sut->set_enabled( true ) );
		$this->assertSame( 'yes', get_option( self::OPTION_NAME ) );
		$this->assertSame( AutomaticProtectionSetting::STATUS_ENABLED, $this->sut->get_stored_status() );
		$this->assertTrue( $this->sut->is_enabled() );

		$this->assertTrue( $this->sut->set_enabled( false ) );
		$this->assertSame( 'no', get_option( self::OPTION_NAME ) );
		$this->assertSame( AutomaticProtectionSetting::STATUS_DISABLED, $this->sut->get_stored_status() );
		$this->assertFalse( $this->sut->is_enabled() );
	}

	/**
	 * @testdox Invalid stored values fail to disabled.
	 */
	public function test_invalid_value_fails_to_disabled(): void {
		update_option( self::OPTION_NAME, array( 'invalid' ) );

		$this->assertSame( AutomaticProtectionSetting::STATUS_DEFAULT_DISABLED, $this->sut->get_stored_status() );
		$this->assertFalse( $this->sut->is_enabled() );
	}

	/**
	 * @testdox A failed option write is reported to the caller.
	 */
	public function test_failed_write_is_reported(): void {
		$filter = static function () {
			return false;
		};
		add_filter( 'pre_update_option_' . self::OPTION_NAME, $filter );

		try {
			$this->assertFalse( $this->sut->set_enabled( true ) );
			$this->assertNull( get_option( self::OPTION_NAME, null ) );
		} finally {
			remove_filter( 'pre_update_option_' . self::OPTION_NAME, $filter );
		}
	}

	/**
	 * @testdox Reset removes an explicit value.
	 */
	public function test_reset_deletes_value(): void {
		$this->sut->set_enabled( false );

		$this->assertTrue( $this->sut->reset() );
		$this->assertNull( get_option( self::OPTION_NAME, null ) );
	}
}
