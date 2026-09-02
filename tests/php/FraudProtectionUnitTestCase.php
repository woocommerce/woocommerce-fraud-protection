<?php
/**
 * FraudProtectionUnitTestCase file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\FraudProtection\Tests;

use Automattic\WooCommerce\FraudProtection\Tests\Support\FraudProtectionControllerForTests;
use Automattic\WooCommerce\FraudProtection\Tests\Support\FraudProtectionLoggerForTests;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Logging\FraudProtectionLogger;
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
	 * The in-memory logger installed by {@see spy_on_controller_logging()}, if any.
	 *
	 * @var ?FraudProtectionLoggerForTests
	 */
	private $logging_spy = null;

	/**
	 * Original values of server variables changed by a test.
	 *
	 * @var array<string, array{exists: bool, value: mixed}>
	 */
	private array $original_server_variables = array();

	/**
	 * Jetpack option filters added by mock_jetpack_blog_id().
	 *
	 * @var callable[]
	 */
	private array $jetpack_blog_id_filters = array();

	/**
	 * WooCommerce's session object at the start of the test.
	 *
	 * @var ?\WC_Session
	 */
	private ?\WC_Session $original_woocommerce_session = null;

	/**
	 * WooCommerce's cart object at the start of the test.
	 *
	 * @var ?\WC_Cart
	 */
	private ?\WC_Cart $original_woocommerce_cart = null;

	/**
	 * Runs before each test.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->forwarded_platform_logs = array();
		$this->original_server_variables = array();
		$this->jetpack_blog_id_filters = array();
		$this->original_woocommerce_session = WC()->session;
		$this->original_woocommerce_cart = WC()->cart;

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
		foreach ( $this->jetpack_blog_id_filters as $filter ) {
			remove_filter( 'pre_option_jetpack_options', $filter );
		}
		$this->jetpack_blog_id_filters = array();

		$this->restore_server_variables();
		$this->remove_controller_logging_spy();

		$this->reset_woocommerce_checkout_page_cache();
		$this->reset_legacy_proxy_mocks();
		WC()->session = $this->original_woocommerce_session;
		WC()->cart = $this->original_woocommerce_cart;
		$this->forwarded_platform_logs = array();

		parent::tearDown();
	}

	/**
	 * Get the original WooCommerce session before a test replaced it.
	 *
	 * @return ?\WC_Session
	 */
	protected function get_original_woocommerce_session(): ?\WC_Session {
		return $this->original_woocommerce_session;
	}

	/**
	 * Set server variables and restore their original state after the test.
	 *
	 * @param array<string, mixed> $variables Server variables keyed by name.
	 */
	protected function set_server_variables( array $variables ): void {
		foreach ( $variables as $key => $value ) {
			$this->remember_server_variable( $key );
			$_SERVER[ $key ] = $value;
		}
	}

	/**
	 * Unset server variables and restore their original state after the test.
	 *
	 * @param string[] $keys Server variable names.
	 */
	protected function unset_server_variables( array $keys ): void {
		foreach ( $keys as $key ) {
			$this->remember_server_variable( $key );
			unset( $_SERVER[ $key ] );
		}
	}

	/**
	 * Remember a server variable before its first change in a test.
	 *
	 * @param string $key Server variable name.
	 */
	private function remember_server_variable( string $key ): void {
		if ( array_key_exists( $key, $this->original_server_variables ) ) {
			return;
		}

		$this->original_server_variables[ $key ] = array(
			'exists' => array_key_exists( $key, $_SERVER ),
			'value'  => $_SERVER[ $key ] ?? null,
		);
	}

	/**
	 * Restore server variables changed through the test helpers.
	 */
	private function restore_server_variables(): void {
		foreach ( $this->original_server_variables as $key => $original ) {
			if ( $original['exists'] ) {
				$_SERVER[ $key ] = $original['value'];
			} else {
				unset( $_SERVER[ $key ] );
			}
		}

		$this->original_server_variables = array();
	}

	/**
	 * Every script handle the plugin registers on a purchase surface.
	 *
	 * @var string[]
	 */
	protected const FRAUD_PROTECTION_SCRIPT_HANDLES = array(
		'wc-fraud-protection-blackbox',
		'wc-fraud-protection-blackbox-init',
		'wc-fraud-protection-blocks-checkout',
		'wc-fraud-protection-shortcode-checkout',
		'wc-fraud-protection-pay-for-order',
		'wc-fraud-protection-add-payment-method',
		'wc-fraud-protection-paypal-express',
		'wc-fraud-protection-extension-flow',
	);

	/**
	 * Mock the Jetpack blog ID via the pre_option_jetpack_options filter.
	 *
	 * @param int $blog_id The blog ID to return.
	 * @return void
	 */
	protected function mock_jetpack_blog_id( int $blog_id ): void {
		$filter = function () use ( $blog_id ) {
			return array( 'id' => $blog_id );
		};

		add_filter( 'pre_option_jetpack_options', $filter );
		$this->jetpack_blog_id_filters[] = $filter;
	}

	/**
	 * Build a real BlackboxScriptHandler wired with a mocked session identity manager.
	 *
	 * Consumers of the pull API (protectors, compat layers) receive the handler
	 * through init(); tests use this factory so every consumer asks a working
	 * handler, whose outcome is then steered per test via the Jetpack option
	 * filter and the handler's public return value.
	 *
	 * @return \Automattic\WooCommerce\FraudProtection\BlackboxScriptHandler
	 */
	protected function make_blackbox_script_handler(): \Automattic\WooCommerce\FraudProtection\BlackboxScriptHandler {
		$session_manager = $this->createMock( \Automattic\WooCommerce\Internal\FraudProtectionPlugin\Sessions\SessionIdentityManager::class );
		$session_manager->method( 'get_identity_id' )->willReturn( 'mock-session-id' );

		$handler = new \Automattic\WooCommerce\FraudProtection\BlackboxScriptHandler();
		$handler->init( $session_manager );

		return $handler;
	}

	/**
	 * Clear WooCommerce's request-lifetime memo of whether this request is the checkout page.
	 *
	 * WooCommerce never resets it, so without this a go_to() in one test class leaves a stale
	 * verdict that silently changes is_checkout() for every later test in the process.
	 *
	 * @return void
	 */
	protected function reset_woocommerce_checkout_page_cache(): void {
		$reflection = new \ReflectionClass( \Automattic\WooCommerce\Blocks\Utils\CartCheckoutUtils::class );

		if ( ! $reflection->hasProperty( 'is_checkout_page' ) ) {
			return;
		}

		$property = $reflection->getProperty( 'is_checkout_page' );
		$property->setAccessible( true );
		$property->setValue( null, null );
	}

	/**
	 * Dequeue and deregister every plugin script so queue state cannot leak between tests.
	 *
	 * WordPress reports an unregistered handle as enqueued when a queued script declares it
	 * as a dependency, so a handle left behind by one test can silently satisfy the next.
	 *
	 * @return void
	 */
	protected function reset_fraud_protection_scripts(): void {
		foreach ( self::FRAUD_PROTECTION_SCRIPT_HANDLES as $handle ) {
			wp_dequeue_script( $handle );
			wp_deregister_script( $handle );
		}

		$scripts = wp_scripts();

		$scripts->to_do = array_values( array_diff( $scripts->to_do, self::FRAUD_PROTECTION_SCRIPT_HANDLES ) );
		$scripts->done  = array_values( array_diff( $scripts->done, self::FRAUD_PROTECTION_SCRIPT_HANDLES ) );

		foreach ( self::FRAUD_PROTECTION_SCRIPT_HANDLES as $handle ) {
			unset( $scripts->groups[ $handle ] );
		}

		$dependencies_class = new \ReflectionClass( \WP_Dependencies::class );

		if ( $dependencies_class->hasProperty( 'dependencies_with_missing_dependencies' ) ) {
			$missing = $dependencies_class->getProperty( 'dependencies_with_missing_dependencies' );
			$missing->setAccessible( true );
			$missing_dependencies = (array) $missing->getValue( $scripts );

			foreach ( self::FRAUD_PROTECTION_SCRIPT_HANDLES as $handle ) {
				unset( $missing_dependencies[ $handle ] );
			}

			$missing->setValue( $scripts, array_values( array_diff( $missing_dependencies, self::FRAUD_PROTECTION_SCRIPT_HANDLES ) ) );
		}
	}

	/**
	 * Point the static logging facade at an in-memory logger.
	 *
	 * Makes the spy the target of the static `FraudProtectionController::log()`
	 * facade, so every log call made during the test (by the system under test or
	 * anything else) is captured by the spy and can be asserted via
	 * {@see assertLogged()} without touching the real WooCommerce logger or the PHP
	 * error log. The canonical logger is restored automatically in
	 * {@see tearDown()}.
	 *
	 * @return FraudProtectionLoggerForTests The installed test double.
	 */
	protected function spy_on_controller_logging(): FraudProtectionLoggerForTests {
		$this->logging_spy = new FraudProtectionLoggerForTests();
		FraudProtectionControllerForTests::set_facade_logger( $this->logging_spy );

		return $this->logging_spy;
	}

	/**
	 * Restore the canonical logger after a spy was installed.
	 *
	 * @return void
	 */
	private function remove_controller_logging_spy(): void {
		if ( null === $this->logging_spy ) {
			return;
		}

		FraudProtectionControllerForTests::set_facade_logger( wc_get_container()->get( FraudProtectionLogger::class ) );

		$this->logging_spy = null;
	}

	/**
	 * Assert that the installed logger captured a matching log entry.
	 *
	 * @param string                    $level            Expected log level (e.g. 'info', 'warning', 'error').
	 * @param string                    $substring        Substring expected in the log message.
	 * @param ?array<string, mixed>     $expected_context Optional context subset that must be present in the entry's context.
	 * @param ?bool                     $forwarded        Optional expected platform-log forwarding flag.
	 * @return void
	 */
	protected function assertLogged( string $level, string $substring, ?array $expected_context = null, ?bool $forwarded = null ): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- PHPUnit style.
		$this->assertNotNull( $this->logging_spy, 'spy_on_controller_logging() must be called before asserting on logged entries.' );

		foreach ( $this->logging_spy->entries as $entry ) {
			if ( $entry['level'] !== $level || false === strpos( $entry['message'], $substring ) ) {
				continue;
			}
			if ( null !== $expected_context && ! $this->context_is_subset( $expected_context, $entry['context'] ) ) {
				continue;
			}
			if ( null !== $forwarded && $entry['forwarded'] !== $forwarded ) {
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
