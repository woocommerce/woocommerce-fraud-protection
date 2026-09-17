<?php
/**
 * PluginInitializerTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin;

use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\CLI\FraudProtectionCommands;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\FraudProtectionController;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\ManagedTranslationUpdater;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\PluginInitializer;

/**
 * Tests for the PluginInitializer class.
 *
 * @covers \Automattic\WooCommerce\Internal\FraudProtectionPlugin\PluginInitializer
 */
class PluginInitializerTest extends FraudProtectionUnitTestCase {

	/**
	 * Reasons used during a test, so their throttle transients can be cleaned up.
	 *
	 * @var string[]
	 */
	private $used_reasons = array();

	/**
	 * Runs after each test.
	 */
	public function tearDown(): void {
		foreach ( $this->used_reasons as $reason ) {
			delete_transient( 'wcfp_init_bail_notice_' . md5( $reason ) );
		}
		$this->used_reasons = array();

		parent::tearDown();
	}

	/**
	 * @testdox The first occurrence of a bail-out reason should be emitted.
	 */
	public function test_first_occurrence_is_emitted(): void {
		$this->assertTrue(
			$this->invoke_should_emit_bail_notice( 'requires WooCommerce 9.8.0 or later (found 9.7.1); initialization skipped.' ),
			'The first time a reason is seen it should be emitted.'
		);
	}

	/**
	 * @testdox A repeated bail-out reason should be throttled within the window.
	 */
	public function test_repeated_reason_is_throttled(): void {
		$reason = 'requires WooCommerce 9.8.0 or later (found 9.7.1); initialization skipped.';

		$first  = $this->invoke_should_emit_bail_notice( $reason );
		$second = $this->invoke_should_emit_bail_notice( $reason );

		$this->assertTrue( $first, 'The first occurrence should be emitted.' );
		$this->assertFalse( $second, 'A repeated reason within the window should be throttled.' );
	}

	/**
	 * @testdox Distinct bail-out reasons should be throttled independently.
	 */
	public function test_distinct_reasons_are_throttled_independently(): void {
		$version_reason    = 'requires WooCommerce 9.8.0 or later (found 9.7.1); initialization skipped.';
		$autoloader_reason = 'autoloader is not readable at /var/www/plugin/vendor/autoload.php';

		$this->assertTrue(
			$this->invoke_should_emit_bail_notice( $version_reason ),
			'The version reason should be emitted.'
		);
		$this->assertTrue(
			$this->invoke_should_emit_bail_notice( $autoloader_reason ),
			'A different reason should be emitted even while another is throttled.'
		);
	}

	/**
	 * @testdox A bail-out reason should be emitted again after its throttle window expires.
	 */
	public function test_reason_is_emitted_again_after_window_expires(): void {
		$reason = 'requires WooCommerce 9.8.0 or later (found 9.7.1); initialization skipped.';

		$this->assertTrue(
			$this->invoke_should_emit_bail_notice( $reason ),
			'The first occurrence should be emitted.'
		);

		// Simulate the throttle transient expiring.
		delete_transient( 'wcfp_init_bail_notice_' . md5( $reason ) );

		$this->assertTrue(
			$this->invoke_should_emit_bail_notice( $reason ),
			'The reason should be emitted again once the throttle window has expired.'
		);
	}

	/**
	 * @testdox CLI should register commands and the feature controller.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_cli_registers_commands_and_feature_controller(): void {
		define( 'WP_CLI', true );
		$container  = wc_get_container();
		$commands   = $this->createMock( FraudProtectionCommands::class );
		$controller = $this->createMock( FraudProtectionController::class );
		$commands->expects( $this->once() )->method( 'register' );
		$controller->expects( $this->once() )->method( 'register' );
		$container->replace( FraudProtectionCommands::class, $commands );
		$container->replace( FraudProtectionController::class, $controller );

		try {
			PluginInitializer::handle_woocommerce_loaded();
		} finally {
			$container->reset_replacement( FraudProtectionCommands::class );
			$container->reset_replacement( FraudProtectionController::class );
		}
	}

	/**
	 * @testdox Standard installations keep the bundled script translation directory.
	 */
	public function test_standard_install_uses_bundled_script_translation_directory(): void {
		$this->assertSame( dirname( WC_FRAUD_PROTECTION_PLUGIN_FILE ) . '/languages', PluginInitializer::get_script_translation_dir() );
		$this->assertFalse( has_action( 'init', array( PluginInitializer::class, 'load_managed_textdomain' ) ) );
	}

	/**
	 * @testdox Managed bootstrap registers text-domain loading at the start of init.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_managed_bootstrap_registers_textdomain_loading_at_priority_zero(): void {
		define( 'WC_FRAUD_PROTECTION_MANAGED_INSTALL', true );
		set_error_handler(
			static fn( int $severity, string $message ): bool => E_WARNING === $severity && str_contains( $message, 'already defined' )
		);

		try {
			PluginInitializer::run( WC_FRAUD_PROTECTION_PLUGIN_FILE );
		} finally {
			restore_error_handler();
		}

		$this->assertSame( 0, has_action( 'init', array( PluginInitializer::class, 'load_managed_textdomain' ) ) );

		remove_action( 'init', array( PluginInitializer::class, 'load_managed_textdomain' ), 0 );
		remove_action( 'woocommerce_loaded', array( PluginInitializer::class, 'handle_woocommerce_loaded' ) );
		remove_filter( 'woocommerce_feature_fraud_protection_enabled', '__return_false', 999 );
	}

	/**
	 * @testdox Managed installations register the updater.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_managed_install_registers_updater(): void {
		define( 'WC_FRAUD_PROTECTION_MANAGED_INSTALL', true );
		$container  = wc_get_container();
		$updater    = $this->createMock( ManagedTranslationUpdater::class );
		$controller = $this->createMock( FraudProtectionController::class );
		$updater->expects( $this->once() )->method( 'register' );
		$controller->expects( $this->once() )->method( 'register' );
		$container->replace( ManagedTranslationUpdater::class, $updater );
		$container->replace( FraudProtectionController::class, $controller );

		try {
			PluginInitializer::handle_woocommerce_loaded();
		} finally {
			$container->reset_replacement( ManagedTranslationUpdater::class );
			$container->reset_replacement( FraudProtectionController::class );
		}
	}

	/**
	 * @testdox Managed installations load the explicit MU-plugin catalog path.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_managed_install_loads_mu_plugin_catalog(): void {
		define( 'WC_FRAUD_PROTECTION_MANAGED_INSTALL', true );
		$loaded = array();
		$filter = static function ( $override, $domain, $mofile, $locale ) use ( &$loaded ) {
			$loaded = array( $domain, $mofile, $locale );
			return true;
		};
		add_filter( 'override_load_textdomain', $filter, 10, 4 );

		PluginInitializer::load_managed_textdomain();
		remove_filter( 'override_load_textdomain', $filter, 10 );

		$locale = determine_locale();
		$this->assertSame(
			array( 'woocommerce-fraud-protection', WP_LANG_DIR . '/mu-plugins/woocommerce-fraud-protection-' . $locale . '.mo', $locale ),
			$loaded
		);
		$this->assertSame( WP_LANG_DIR . '/mu-plugins', PluginInitializer::get_script_translation_dir() );
	}

	/**
	 * Invoke the private static PluginInitializer::should_emit_bail_notice().
	 *
	 * @param string $reason Bail-out reason.
	 *
	 * @return bool Whether the notice should be emitted now.
	 */
	private function invoke_should_emit_bail_notice( string $reason ): bool {
		$this->used_reasons[] = $reason;

		$method = new \ReflectionMethod( PluginInitializer::class, 'should_emit_bail_notice' );
		$method->setAccessible( true );

		return (bool) $method->invoke( null, $reason );
	}
}
