<?php
/**
 * FraudProtectionSettingsPageTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin\Settings;

use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Settings\AutomaticProtectionSetting;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Settings\FraudProtectionSettingsPage;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Settings\SettingStatus;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Settings\SettingsRestController;

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
	 * Automatic-protection setting.
	 *
	 * @var AutomaticProtectionSetting
	 */
	private $automatic_protection;

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

	/**
	 * Original values of globals changed by these tests.
	 *
	 * @var array<string, array{exists: bool, value: mixed}>
	 */
	private array $original_globals;

	public function setUp(): void {
		parent::setUp();
		$this->original_globals = array();
		foreach ( array( 'hide_save_button', 'current_section', 'current_tab' ) as $global_name ) {
			$this->original_globals[ $global_name ] = array(
				'exists' => array_key_exists( $global_name, $GLOBALS ),
				'value'  => $GLOBALS[ $global_name ] ?? null,
			);
		}
		$this->asset_file         = dirname( WC_FRAUD_PROTECTION_PLUGIN_FILE ) . '/build/admin-settings.asset.php';
		$this->asset_file_existed = is_file( $this->asset_file );
		if ( $this->asset_file_existed ) {
			$this->asset_file_contents = (string) file_get_contents( $this->asset_file );
		}
		$this->reset_asset_registrations();
		$this->automatic_protection = wc_get_container()->get( AutomaticProtectionSetting::class );
		$this->sut                  = new FraudProtectionSettingsPage();
		$this->sut->init( $this->spy_on_controller_logging() );
		$this->automatic_protection->reset();
	}

	public function tearDown(): void {
		foreach ( $this->original_globals as $global_name => $original ) {
			if ( $original['exists'] ) {
				$GLOBALS[ $global_name ] = $original['value'];
			} else {
				unset( $GLOBALS[ $global_name ] );
			}
		}
		$this->restore_asset_fixture();
		$this->reset_asset_registrations();
		$this->automatic_protection->reset();
		remove_action( 'admin_enqueue_scripts', array( $this->sut, 'enqueue_assets' ) );
		parent::tearDown();
	}

	/**
	 * @testdox The page uses a unique top-level tab ID and label.
	 */
	public function test_registers_unique_tab(): void {
		$tabs = $this->sut->add_settings_page( array( 'general' => 'General' ) );

		$this->assertSame( 'Fraud prevention', $tabs[ FraudProtectionSettingsPage::PAGE_ID ] );
	}

	/**
	 * @testdox Register connects the settings assets to the admin enqueue hook.
	 */
	public function test_registers_asset_hook(): void {
		$this->sut->register();

		$this->assertSame( 10, has_action( 'admin_enqueue_scripts', array( $this->sut, 'enqueue_assets' ) ) );
	}

	/**
	 * @testdox The page renders the React mount and hides the classic save button.
	 */
	public function test_output_renders_mount_and_hides_classic_save(): void {
		$GLOBALS['hide_save_button'] = false;

		ob_start();
		$this->sut->output();
		$output = (string) ob_get_clean();

		$this->assertSame( '<div id="wc-fraud-protection-settings" class="wc-settings-prevent-change-event"></div>', $output );
		$this->assertTrue( $GLOBALS['hide_save_button'] );
	}

	/**
	 * @testdox A classic page save has no fields and preserves an absent automatic-protection option.
	 */
	public function test_classic_save_preserves_absent_setting(): void {
		global $current_section;
		$current_section = '';

		$this->sut->save();

		$this->assertSame( SettingStatus::DefaultDisabled, wc_get_container()->get( AutomaticProtectionSetting::class )->get_status() );
	}

	/**
	 * @testdox Settings assets are not enqueued outside the Fraud prevention tab.
	 *
	 * @dataProvider non_matching_settings_routes
	 *
	 * @param mixed  $hook_suffix Admin page hook suffix.
	 * @param string $tab         Settings tab.
	 */
	public function test_assets_are_not_enqueued_for_other_routes( $hook_suffix, string $tab ): void {
		$GLOBALS['current_tab'] = $tab;

		$this->sut->enqueue_assets( $hook_suffix );

		$this->assertFalse( wp_script_is( self::ASSET_HANDLE, 'enqueued' ) );
		$this->assertFalse( wp_style_is( self::ASSET_HANDLE, 'enqueued' ) );
	}

	/**
	 * Settings routes that must not load plugin assets.
	 *
	 * @return array<string, array{mixed, string}>
	 */
	public function non_matching_settings_routes(): array {
		return array(
			'other admin page'    => array( 'plugins', FraudProtectionSettingsPage::PAGE_ID ),
			'other settings tab'  => array( 'woocommerce_page_wc-settings', 'general' ),
			'invalid hook suffix' => array( array(), FraudProtectionSettingsPage::PAGE_ID ),
		);
	}

	/**
	 * @testdox The Fraud prevention tab uses generated metadata to enqueue its runtime assets.
	 */
	public function test_matching_tab_enqueues_generated_assets(): void {
		$dependencies = array( 'react-jsx-runtime', 'wp-api-fetch', 'wp-components', 'wp-element', 'wp-i18n' );
		$version      = 'settings-test-version';
		$this->write_asset_fixture( $dependencies, $version );
		$GLOBALS['current_tab'] = FraudProtectionSettingsPage::PAGE_ID;
		wp_set_current_user( 1 );
		$rest_controller = wc_get_container()->get( SettingsRestController::class );
		$rest_controller->register();
		do_action( 'rest_api_init', rest_get_server() );
		remove_action( 'rest_api_init', array( $rest_controller, 'register_routes' ) );

		$this->sut->enqueue_assets( 'woocommerce_page_wc-settings' );

		$this->assertFalse( wp_style_is( 'wp-components', 'enqueued' ) );
		$this->assertTrue( wp_style_is( self::ASSET_HANDLE, 'enqueued' ) );
		$this->assertTrue( wp_script_is( self::ASSET_HANDLE, 'enqueued' ) );

		$style  = wp_styles()->registered[ self::ASSET_HANDLE ];
		$script = wp_scripts()->registered[ self::ASSET_HANDLE ];
		$this->assertSame( plugins_url( 'build/admin-settings.css', WC_FRAUD_PROTECTION_PLUGIN_FILE ), $style->src );
		$this->assertSame( array(), $style->deps );
		$this->assertSame( $version, $style->ver );
		$this->assertSame( plugins_url( 'build/admin-settings.js', WC_FRAUD_PROTECTION_PLUGIN_FILE ), $script->src );
		$this->assertSame( $dependencies, $script->deps );
		$this->assertSame( $version, $script->ver );
		$this->assertSame( 'woocommerce-fraud-protection', $script->textdomain );
		$this->assertSame( dirname( WC_FRAUD_PROTECTION_PLUGIN_FILE ) . '/languages', $script->translations_path );

		$before = wp_scripts()->get_data( self::ASSET_HANDLE, 'before' );
		$this->assertIsArray( $before );
		$before_script = implode( "\n", $before );
		$this->assertStringContainsString( 'wp.apiFetch.createPreloadingMiddleware', $before_script );
		$this->assertStringContainsString( '"/wc-fraud-protection/v1/settings"', $before_script );
	}

	/**
	 * @testdox Missing or invalid asset metadata prevents settings assets from loading and records the error.
	 *
	 * @dataProvider asset_metadata_failures
	 *
	 * @param string $fixture       Fixture type.
	 * @param string $expected_log  Expected error log.
	 */
	public function test_asset_metadata_failures_are_logged( string $fixture, string $expected_log ): void {
		if ( 'missing' === $fixture ) {
			if ( is_file( $this->asset_file ) ) {
				unlink( $this->asset_file );
			}
		} else {
			$this->write_raw_asset_fixture( array( 'version' => 'settings-test-version' ) );
		}
		$GLOBALS['current_tab'] = FraudProtectionSettingsPage::PAGE_ID;

		$this->sut->enqueue_assets( 'woocommerce_page_wc-settings' );

		$this->assertLogged( 'error', $expected_log, array(), true );
		$this->assertFalse( wp_script_is( self::ASSET_HANDLE, 'enqueued' ) );
		$this->assertFalse( wp_style_is( self::ASSET_HANDLE, 'enqueued' ) );
	}

	/**
	 * Asset metadata failure fixtures.
	 *
	 * @return array<string, array{string, string}>
	 */
	public function asset_metadata_failures(): array {
		return array(
			'missing metadata' => array( 'missing', 'Fraud Protection settings asset metadata is unavailable.' ),
			'invalid metadata' => array( 'invalid', 'Fraud Protection settings asset metadata is invalid.' ),
		);
	}

	/**
	 * Write controlled generated asset metadata.
	 *
	 * @param string[] $dependencies Script dependencies.
	 * @param string   $version      Asset version.
	 */
	private function write_asset_fixture( array $dependencies, string $version ): void {
		$this->write_raw_asset_fixture(
			array(
				'dependencies' => $dependencies,
				'version'      => $version,
			)
		);
	}

	/**
	 * Write controlled generated asset metadata.
	 *
	 * @param mixed $asset Asset metadata value.
	 */
	private function write_raw_asset_fixture( mixed $asset ): void {
		$build_directory = dirname( $this->asset_file );
		if ( ! is_dir( $build_directory ) ) {
			$this->assertTrue( wp_mkdir_p( $build_directory ) );
			$this->build_directory_created = true;
		}

		$contents = '<?php return ' . var_export( $asset, true ) . ';';
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
