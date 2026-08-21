<?php
/**
 * PluginInitializerTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin;

use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\CLI\FraudProtectionCommands;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\FraudProtectionController;
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
