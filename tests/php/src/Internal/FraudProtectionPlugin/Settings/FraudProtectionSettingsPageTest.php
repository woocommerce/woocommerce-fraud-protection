<?php
/**
 * FraudProtectionSettingsPageTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin\Settings;

use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Settings\AutomaticProtectionSetting;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Settings\FraudProtectionSettingsPage;

/**
 * Tests for FraudProtectionSettingsPage.
 */
class FraudProtectionSettingsPageTest extends FraudProtectionUnitTestCase {

	/**
	 * Settings page under test.
	 *
	 * @var FraudProtectionSettingsPage
	 */
	private $sut;

	public function setUp(): void {
		parent::setUp();
		$this->sut = new FraudProtectionSettingsPage();
		$this->sut->register();
		wc_get_container()->get( AutomaticProtectionSetting::class )->reset();
	}

	public function tearDown(): void {
		wc_get_container()->get( AutomaticProtectionSetting::class )->reset();
		remove_action( 'admin_enqueue_scripts', array( $this->sut, 'enqueue_assets' ) );
		parent::tearDown();
	}

	/**
	 * @testdox The page uses a unique top-level tab ID and label.
	 */
	public function test_registers_unique_tab(): void {
		$tabs = $this->sut->add_settings_page( array( 'general' => 'General' ) );

		$this->assertSame( 'Fraud prevention', $tabs[ FraudProtectionSettingsPage::PAGE_ID ] );
		$this->assertNotFalse( has_action( 'admin_enqueue_scripts', array( $this->sut, 'enqueue_assets' ) ) );
	}

	/**
	 * @testdox The page renders only the React mount and hides the classic save button.
	 */
	public function test_output_renders_mount_and_hides_classic_save(): void {
		$GLOBALS['hide_save_button'] = false;

		ob_start();
		$this->sut->output();
		$output = (string) ob_get_clean();

		$this->assertSame( '<div id="wc-fraud-protection-settings"></div>', $output );
		$this->assertTrue( $GLOBALS['hide_save_button'] );
	}

	/**
	 * @testdox A classic page save has no fields and preserves an absent automatic-protection option.
	 */
	public function test_classic_save_preserves_absent_setting(): void {
		global $current_section;
		$current_section = '';

		$this->sut->save();

		$this->assertSame( AutomaticProtectionSetting::STATUS_DEFAULT_DISABLED, wc_get_container()->get( AutomaticProtectionSetting::class )->get_stored_status() );
	}
}
