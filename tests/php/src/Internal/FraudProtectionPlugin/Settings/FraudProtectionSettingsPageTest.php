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

	private const ASSET_HANDLE = 'wc-fraud-protection-admin-settings';

	/**
	 * Settings page under test.
	 *
	 * @var FraudProtectionSettingsPage
	 */
	private $sut;

	/**
	 * Original request parameters.
	 *
	 * @var array<string, mixed>
	 */
	private array $original_get;

	/**
	 * Generated asset metadata path.
	 */
	private string $asset_file;

	/**
	 * Whether asset metadata existed before the test.
	 */
	private bool $asset_file_existed;

	/**
	 * Original asset metadata contents.
	 */
	private string $asset_file_contents = '';

	/**
	 * Whether the test created the build directory.
	 */
	private bool $build_directory_created = false;

	public function setUp(): void {
		parent::setUp();
		$this->original_get       = $_GET;
		$this->asset_file         = dirname( WC_FRAUD_PROTECTION_PLUGIN_FILE ) . '/build/admin-settings.asset.php';
		$this->asset_file_existed = is_file( $this->asset_file );
		if ( $this->asset_file_existed ) {
			$this->asset_file_contents = (string) file_get_contents( $this->asset_file );
		}
		$this->reset_asset_registrations();
		$this->sut = new FraudProtectionSettingsPage();
		$this->sut->register();
		wc_get_container()->get( AutomaticProtectionSetting::class )->reset();
	}

	public function tearDown(): void {
		$_GET = $this->original_get;
		$this->restore_asset_fixture();
		$this->reset_asset_registrations();
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

	/**
	 * @testdox Settings assets are not enqueued outside the Fraud prevention tab.
	 *
	 * @dataProvider non_matching_settings_routes
	 *
	 * @param string $page Admin page.
	 * @param string $tab  Settings tab.
	 */
	public function test_assets_are_not_enqueued_for_other_routes( string $page, string $tab ): void {
		$_GET['page'] = $page;
		$_GET['tab']  = $tab;

		$this->sut->enqueue_assets();

		$this->assertFalse( wp_script_is( self::ASSET_HANDLE, 'enqueued' ) );
		$this->assertFalse( wp_style_is( self::ASSET_HANDLE, 'enqueued' ) );
	}

	/**
	 * Settings routes that must not load plugin assets.
	 *
	 * @return array<string, array{string, string}>
	 */
	public function non_matching_settings_routes(): array {
		return array(
			'other admin page'   => array( 'plugins', FraudProtectionSettingsPage::PAGE_ID ),
			'other settings tab' => array( 'wc-settings', 'general' ),
		);
	}

	/**
	 * @testdox The Fraud prevention tab uses generated metadata to enqueue its runtime assets.
	 */
	public function test_matching_tab_enqueues_generated_assets(): void {
		$dependencies = array( 'react-jsx-runtime', 'wp-api-fetch', 'wp-components', 'wp-element', 'wp-i18n' );
		$version      = 'settings-test-version';
		$this->write_asset_fixture( $dependencies, $version );
		$_GET['page'] = 'wc-settings';
		$_GET['tab']  = FraudProtectionSettingsPage::PAGE_ID;

		$this->sut->enqueue_assets();

		$this->assertTrue( wp_style_is( 'wp-components', 'enqueued' ) );
		$this->assertTrue( wp_style_is( self::ASSET_HANDLE, 'enqueued' ) );
		$this->assertTrue( wp_script_is( self::ASSET_HANDLE, 'enqueued' ) );

		$style  = wp_styles()->registered[ self::ASSET_HANDLE ];
		$script = wp_scripts()->registered[ self::ASSET_HANDLE ];
		$this->assertSame( array( 'wp-components' ), $style->deps );
		$this->assertSame( $version, $style->ver );
		$this->assertSame( $dependencies, $script->deps );
		$this->assertSame( $version, $script->ver );
	}

	/**
	 * Write controlled generated asset metadata.
	 *
	 * @param string[] $dependencies Script dependencies.
	 * @param string   $version      Asset version.
	 */
	private function write_asset_fixture( array $dependencies, string $version ): void {
		$build_directory = dirname( $this->asset_file );
		if ( ! is_dir( $build_directory ) ) {
			$this->assertTrue( wp_mkdir_p( $build_directory ) );
			$this->build_directory_created = true;
		}

		$contents = '<?php return ' . var_export(
			array(
				'dependencies' => $dependencies,
				'version'      => $version,
			),
			true
		) . ';';
		$this->assertNotFalse( file_put_contents( $this->asset_file, $contents ) );
	}

	/**
	 * Restore generated asset metadata to its state before the test.
	 */
	private function restore_asset_fixture(): void {
		if ( $this->asset_file_existed ) {
			file_put_contents( $this->asset_file, $this->asset_file_contents );
		} elseif ( is_file( $this->asset_file ) ) {
			unlink( $this->asset_file );
		}

		$build_directory = dirname( $this->asset_file );
		if ( $this->build_directory_created && array( '.', '..' ) === scandir( $build_directory ) ) {
			rmdir( $build_directory );
		}
	}

	/**
	 * Remove asset registrations created by a test.
	 */
	private function reset_asset_registrations(): void {
		wp_dequeue_script( self::ASSET_HANDLE );
		wp_deregister_script( self::ASSET_HANDLE );
		wp_dequeue_style( self::ASSET_HANDLE );
		wp_deregister_style( self::ASSET_HANDLE );
		wp_dequeue_style( 'wp-components' );
	}
}
