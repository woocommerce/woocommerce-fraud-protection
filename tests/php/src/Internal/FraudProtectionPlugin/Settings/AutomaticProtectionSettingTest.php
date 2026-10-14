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
	 * @testdox An absent setting follows the enabled default without writing a value.
	 */
	public function test_absent_setting_is_default_enabled(): void {
		$this->assertSame( SettingStatus::DefaultEnabled, $this->sut->get_status() );
		$this->assertSame( SettingStatus::Enabled, $this->sut->get_default() );
		$this->assertTrue( $this->sut->is_enabled() );
		$this->assertSame( AutomaticProtectionSource::AutoEnroll, $this->sut->get_source() );
		$this->assertNull( $this->sut->get_enabled_at(), 'The enabled default has no enable date' );
		$this->assertFalse( $this->sut->is_opted_out() );
		$this->assertNull( $this->sut->get_opted_out_at() );
		$this->assertNull( get_option( self::OPTION_NAME, null ) );
		$this->assertNull( get_option( self::OPT_OUT_DATE_OPTION_NAME, null ) );
		$this->assertNull( get_option( self::ENABLED_AT_OPTION_NAME, null ) );
	}

	/**
	 * @testdox Stored states keep the merchant's choice under the enabled default.
	 * @dataProvider stored_states
	 *
	 * @param string|null   $stored_value    The stored option value, or null for none.
	 * @param string|null   $opted_out_at    The stored opt-out date, or null for none.
	 * @param SettingStatus $expected_status The expected status.
	 * @param bool          $expected_on     Whether protection is expected to be enabled.
	 * @param string        $expected_source The expected source value.
	 */
	public function test_stored_states_keep_merchant_choices( ?string $stored_value, ?string $opted_out_at, SettingStatus $expected_status, bool $expected_on, string $expected_source ): void {
		if ( null !== $stored_value ) {
			update_option( self::OPTION_NAME, $stored_value );
		}
		if ( null !== $opted_out_at ) {
			update_option( self::OPT_OUT_DATE_OPTION_NAME, $opted_out_at );
		}

		$this->assertSame( $expected_status, $this->sut->get_status() );
		$this->assertSame( $expected_on, $this->sut->is_enabled() );
		$this->assertSame( $expected_source, $this->sut->get_source()->value );
		$this->assertSame( $opted_out_at, $this->sut->get_opted_out_at(), 'The opt-out date must be kept' );
	}

	/**
	 * Stored setting states, including those created during early access.
	 *
	 * @return array<string, array{?string, ?string, SettingStatus, bool, string}>
	 */
	public function stored_states(): array {
		return array(
			'never chose'         => array( null, null, SettingStatus::DefaultEnabled, true, 'auto_enroll' ),
			'explicitly enabled'  => array( 'yes', null, SettingStatus::Enabled, true, 'manual' ),
			'opted out'           => array( 'no', '2026-09-22 10:00:00', SettingStatus::Disabled, false, 'manual' ),
			'explicitly disabled' => array( 'no', null, SettingStatus::Disabled, false, 'manual' ),
		);
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

		$this->assertSame( SettingStatus::DefaultEnabled, $this->sut->get_status() );
		$this->assertTrue( $this->sut->is_enabled() );
		$this->assertSame( AutomaticProtectionSource::AutoEnroll, $this->sut->get_source() );

		$disabled_default = new class() extends AutomaticProtectionSetting {
			/**
			 * Provide the get_default() test stub.
			 */
			public function get_default(): SettingStatus {
				return SettingStatus::Disabled;
			}
		};
		$this->assertSame( SettingStatus::DefaultDisabled, $disabled_default->get_status() );
		$this->assertFalse( $disabled_default->is_enabled() );
		$this->assertSame( AutomaticProtectionSource::None, $disabled_default->get_source() );
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
		$this->assertSame( $stored_date, $this->sut->get_opted_out_at() );
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
	 * @testdox Turning protection on records the enable date and off clears it.
	 */
	public function test_enabled_at_is_recorded_on_enable_and_cleared_on_disable(): void {
		$this->sut->set_enabled( false );
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
	 * @testdox Explicitly enabling a store that follows the enabled default records no enable date.
	 */
	public function test_enabling_from_enabled_default_records_no_date(): void {
		$this->assertTrue( $this->sut->set_enabled( true ) );

		$this->assertSame( SettingStatus::Enabled, $this->sut->get_status() );
		$this->assertNull( $this->sut->get_enabled_at(), 'Protection was already on, so there is no off-to-on transition' );
	}

	/**
	 * @testdox Reset removes the explicit value, opt-out date, and enable date.
	 */
	public function test_reset_deletes_value(): void {
		$this->sut->set_enabled( false );
		$this->sut->set_enabled( true );
		$this->sut->set_opted_out();
		$this->assertNotNull( get_option( self::ENABLED_AT_OPTION_NAME, null ) );

		$this->assertTrue( $this->sut->reset() );
		$this->assertNull( get_option( self::OPTION_NAME, null ) );
		$this->assertNull( get_option( self::OPT_OUT_DATE_OPTION_NAME, null ) );
		$this->assertNull( get_option( self::ENABLED_AT_OPTION_NAME, null ) );
		$this->assertFalse( $this->sut->is_opted_out() );
		$this->assertSame( SettingStatus::DefaultEnabled, $this->sut->get_status(), 'Reset returns the store to the enabled default' );
	}
}
