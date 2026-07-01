<?php
/**
 * FraudProtectionUnitTestCase file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\FraudProtection\Tests;

use Automattic\WooCommerce\FraudProtection\Tests\Support\FraudProtectionControllerForTests;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\FraudProtectionController;
use WC_Unit_Test_Case;

/**
 * Base test case for WooCommerce Fraud Protection unit tests.
 *
 * It inherits from WooCommerce core's `WC_Unit_Test_Case`, so all the
 * testing infrastructure provided by that class is available.
 */
abstract class FraudProtectionUnitTestCase extends WC_Unit_Test_Case {

	/**
	 * Lines captured from `error_log()` calls forwarded through the proxy during the test.
	 *
	 * @var string[]
	 */
	protected $forwarded_platform_logs = array();

	/**
	 * The in-memory controller test double installed by {@see spy_on_controller_logging()}, if any.
	 *
	 * @var ?FraudProtectionControllerForTests
	 */
	private $logging_spy = null;

	/**
	 * Runs before each test.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->forwarded_platform_logs = array();

		$this->register_legacy_proxy_function_mocks(
			array(
				'error_log' => function ( $message ) {
					$this->forwarded_platform_logs[] = (string) $message;
					return true;
				},
			)
		);

		if ( $this->uses_logging_spy() ) {
			$this->spy_on_controller_logging();
		}
	}

	/**
	 * Whether to install the in-memory controller logging spy in {@see setUp()}.
	 *
	 * Defaults to true: logging is captured in memory so components' log calls
	 * can be asserted via {@see assertLogged()} without touching the real
	 * WooCommerce logger. Tests that exercise the real logging path (e.g. the
	 * controller's own logging tests) override this to return false.
	 *
	 * @return bool
	 */
	protected function uses_logging_spy(): bool {
		return true;
	}

	/**
	 * Runs after each test.
	 */
	public function tearDown(): void {
		$this->remove_controller_logging_spy();

		$this->reset_legacy_proxy_mocks();
		$this->forwarded_platform_logs = array();

		parent::tearDown();
	}

	/**
	 * Point the static logging facade at an in-memory spy controller.
	 *
	 * Makes the spy the target of the static `FraudProtectionController::log()`
	 * facade, so every log call made during the test (by the system under test or
	 * anything else) is captured by the spy and can be asserted via
	 * {@see assertLogged()} without touching the real WooCommerce logger or the PHP
	 * error log. The canonical controller is restored automatically in
	 * {@see tearDown()}.
	 *
	 * @return FraudProtectionControllerForTests The installed test double.
	 */
	protected function spy_on_controller_logging(): FraudProtectionControllerForTests {
		$this->logging_spy = new FraudProtectionControllerForTests();
		FraudProtectionControllerForTests::set_facade_target( $this->logging_spy );

		return $this->logging_spy;
	}

	/**
	 * Restore the canonical controller as the facade target after a spy was installed.
	 *
	 * @return void
	 */
	private function remove_controller_logging_spy(): void {
		if ( null === $this->logging_spy ) {
			return;
		}

		// The container singleton was wired as the facade target by its init();
		// point the facade back at it.
		FraudProtectionControllerForTests::set_facade_target( wc_get_container()->get( FraudProtectionController::class ) );

		$this->logging_spy = null;
	}

	/**
	 * Assert that the installed controller spy captured a matching log entry.
	 *
	 * @param string                    $level            Expected log level (e.g. 'info', 'warning', 'error').
	 * @param string                    $substring        Substring expected in the log message.
	 * @param ?array<string, mixed>     $expected_context Optional context subset that must be present in the entry's context.
	 * @return void
	 */
	protected function assertLogged( string $level, string $substring, ?array $expected_context = null ): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- PHPUnit style.
		$this->assertNotNull( $this->logging_spy, 'spy_on_controller_logging() must be called before asserting on logged entries.' );

		foreach ( $this->logging_spy->entries as $entry ) {
			if ( $entry['level'] !== $level || false === strpos( $entry['message'], $substring ) ) {
				continue;
			}
			if ( null !== $expected_context && ! $this->context_is_subset( $expected_context, $entry['context'] ) ) {
				continue;
			}
			$this->addToAssertionCount( 1 );
			return;
		}

		$this->fail(
			sprintf(
				"Expected a %s log containing '%s'.\nCaptured entries: %s",
				$level,
				$substring,
				wp_json_encode( $this->logging_spy->entries, JSON_PRETTY_PRINT )
			)
		);
	}

	/**
	 * Recursively check that every key/value in $expected is present in $actual.
	 *
	 * @param array<string, mixed> $expected Expected context subset.
	 * @param array<string, mixed> $actual   Actual logged context.
	 * @return bool
	 */
	private function context_is_subset( array $expected, array $actual ): bool {
		foreach ( $expected as $key => $value ) {
			if ( ! array_key_exists( $key, $actual ) ) {
				return false;
			}
			if ( is_array( $value ) && is_array( $actual[ $key ] ) ) {
				if ( ! $this->context_is_subset( $value, $actual[ $key ] ) ) {
					return false;
				}
			} elseif ( $actual[ $key ] !== $value ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Get the lines forwarded to the platform log during the test.
	 *
	 * Each entry is the verbatim string passed to `error_log()`, i.e. a
	 * `PHP Warning: [woo-fraud-protection <level>] ...` line.
	 *
	 * @return string[]
	 */
	protected function get_forwarded_platform_logs(): array {
		return $this->forwarded_platform_logs;
	}
}
