<?php
/**
 * AutomaticProtectionSettingTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin\Settings;

use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Settings\AutomaticProtectionSource;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Settings\AutomaticProtectionSetting;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Settings\SettingStatus;

/**
 * Tests for AutomaticProtectionSetting.
 */
class AutomaticProtectionSettingTest extends FraudProtectionUnitTestCase {

	private const OPTION_NAME              = 'woocommerce_fraud_protection_automatic_protection';
	private const OPT_OUT_DATE_OPTION_NAME = 'woocommerce_fraud_protection_automatic_protection_opted_out_at';

	/**
	 * Automatic protection setting.
	 *
	 * @var AutomaticProtectionSetting
	 */
	private $sut;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->sut = new AutomaticProtectionSetting();
		$this->sut->reset();
		delete_option( self::OPT_OUT_DATE_OPTION_NAME );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		$this->sut->reset();
		delete_option( self::OPT_OUT_DATE_OPTION_NAME );
		parent::tearDown();
	}

	/**
	 * @testdox An absent setting is disabled without writing an opt-out.
	 */
	public function test_absent_setting_is_default_disabled(): void {
		$this->assertSame( SettingStatus::DefaultDisabled, $this->sut->get_status() );
		$this->assertSame( SettingStatus::Disabled, $this->sut->get_default() );
		$this->assertFalse( $this->sut->is_enabled() );
		$this->assertSame( AutomaticProtectionSource::None, $this->sut->get_source() );
		$this->assertFalse( $this->sut->is_opted_out() );
		$this->assertNull( get_option( self::OPTION_NAME, null ) );
		$this->assertNull( get_option( self::OPT_OUT_DATE_OPTION_NAME, null ) );
	}

	/**
	 * @testdox Enabled and disabled values persist as explicit choices.
	 */
	public function test_explicit_values_persist(): void {
		$this->assertTrue( $this->sut->set_enabled( true ) );
		$this->assertSame( 'yes', get_option( self::OPTION_NAME ) );
		$this->assertSame( SettingStatus::Enabled, $this->sut->get_status() );
		$this->assertTrue( $this->sut->is_enabled() );
		$this->assertSame( AutomaticProtectionSource::Manual, $this->sut->get_source() );

		$this->assertTrue( $this->sut->set_enabled( false ) );
		$this->assertSame( 'no', get_option( self::OPTION_NAME ) );
		$this->assertSame( SettingStatus::Disabled, $this->sut->get_status() );
		$this->assertFalse( $this->sut->is_enabled() );
		$this->assertSame( AutomaticProtectionSource::Manual, $this->sut->get_source() );
	}

	/**
	 * @testdox Invalid stored values follow the code default.
	 */
	public function test_invalid_value_follows_code_default(): void {
		update_option( self::OPTION_NAME, array( 'invalid' ) );

		$this->assertSame( SettingStatus::DefaultDisabled, $this->sut->get_status() );
		$this->assertFalse( $this->sut->is_enabled() );

		$enabled_default = new class() extends AutomaticProtectionSetting {
			/**
			 * Provide the get_default() test stub.
			 */
			public function get_default(): SettingStatus {
				return SettingStatus::Enabled;
			}
		};
		$this->assertSame( SettingStatus::DefaultEnabled, $enabled_default->get_status() );
		$this->assertTrue( $enabled_default->is_enabled() );
		$this->assertSame( AutomaticProtectionSource::None, $enabled_default->get_source() );
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
	 * @testdox An opt-out stores its first UTC date.
	 */
	public function test_opt_out_stores_first_utc_date(): void {
		$before = time();
		$this->assertTrue( $this->sut->set_opted_out() );
		$after = time();

		$stored_date = get_option( self::OPT_OUT_DATE_OPTION_NAME );
		$this->assertIsString( $stored_date );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $stored_date );
		$stored_timestamp = strtotime( $stored_date . ' UTC' );
		$this->assertGreaterThanOrEqual( $before, $stored_timestamp );
		$this->assertLessThanOrEqual( $after, $stored_timestamp );
		$this->assertTrue( $this->sut->is_opted_out() );

		update_option( self::OPT_OUT_DATE_OPTION_NAME, '2026-09-11 12:00:00' );
		$this->assertFalse( $this->sut->set_opted_out() );
		$this->assertSame( '2026-09-11 12:00:00', get_option( self::OPT_OUT_DATE_OPTION_NAME ) );
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
