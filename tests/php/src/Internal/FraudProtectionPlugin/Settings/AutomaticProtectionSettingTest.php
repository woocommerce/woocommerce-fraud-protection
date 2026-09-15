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
	private const OPT_OUT_INFO_OPTION_NAME = 'woocommerce_fraud_protection_automatic_protection_opted_out_info';
	private const ENABLED_AT_OPTION_NAME   = 'woocommerce_fraud_protection_automatic_protection_enabled_at';

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
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		$this->sut->reset();
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
		$this->assertNull( $this->sut->get_opted_out_at() );
		$this->assertNull( get_option( self::OPTION_NAME, null ) );
		$this->assertNull( get_option( self::OPT_OUT_INFO_OPTION_NAME, null ) );
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
	 * @testdox An opt-out stores its UTC date and the user who made it.
	 */
	public function test_opt_out_stores_date_and_user(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$before = time();
		$this->assertTrue( $this->sut->set_opted_out() );
		$after = time();

		// The option holds a JSON record of the date and the user.
		$record = json_decode( (string) get_option( self::OPT_OUT_INFO_OPTION_NAME ), true );
		$this->assertIsArray( $record );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $record['date'] );
		$this->assertSame( $user_id, $record['user_id'] );

		$this->assertTrue( $this->sut->is_opted_out() );
		$this->assertSame( $record['date'], $this->sut->get_opted_out_at() );
		$this->assertSame( $user_id, $this->sut->get_opted_out_by() );

		$stored_timestamp = strtotime( $record['date'] . ' UTC' );
		$this->assertGreaterThanOrEqual( $before, $stored_timestamp );
		$this->assertLessThanOrEqual( $after, $stored_timestamp );

		// A second call keeps the first record.
		$this->assertFalse( $this->sut->set_opted_out() );
	}

	/**
	 * @testdox A malformed opt-out record exposes no date or user.
	 */
	public function test_opt_out_reads_malformed_record(): void {
		update_option( self::OPT_OUT_INFO_OPTION_NAME, 'not-json' );

		$this->assertTrue( $this->sut->is_opted_out() );
		$this->assertNull( $this->sut->get_opted_out_at() );
		$this->assertNull( $this->sut->get_opted_out_by() );
	}

	/**
	 * @testdox Turning protection on records the enable date and off clears it.
	 */
	public function test_enabled_at_is_recorded_on_enable_and_cleared_on_disable(): void {
		$this->assertNull( $this->sut->get_enabled_at() );

		$before = time();
		$this->assertTrue( $this->sut->set_enabled( true ) );
		$after = time();

		$enabled_at = $this->sut->get_enabled_at();
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $enabled_at );
		$stored_timestamp = strtotime( $enabled_at . ' UTC' );
		$this->assertGreaterThanOrEqual( $before, $stored_timestamp );
		$this->assertLessThanOrEqual( $after, $stored_timestamp );

		// Re-saving while already on keeps the original enable date.
		$this->assertTrue( $this->sut->set_enabled( true ) );
		$this->assertSame( $enabled_at, $this->sut->get_enabled_at() );

		// Turning it off clears the date, and the getter returns null while off.
		$this->assertTrue( $this->sut->set_enabled( false ) );
		$this->assertNull( $this->sut->get_enabled_at() );
		$this->assertNull( get_option( self::ENABLED_AT_OPTION_NAME, null ) );
	}

	/**
	 * @testdox Reset removes the explicit value, opt-out date, and enable date.
	 */
	public function test_reset_deletes_value(): void {
		$this->sut->set_enabled( true );
		$this->sut->set_opted_out();
		$this->assertNotNull( get_option( self::ENABLED_AT_OPTION_NAME, null ) );

		$this->assertTrue( $this->sut->reset() );
		$this->assertNull( get_option( self::OPTION_NAME, null ) );
		$this->assertNull( get_option( self::OPT_OUT_INFO_OPTION_NAME, null ) );
		$this->assertNull( get_option( self::ENABLED_AT_OPTION_NAME, null ) );
		$this->assertFalse( $this->sut->is_opted_out() );
	}
}
