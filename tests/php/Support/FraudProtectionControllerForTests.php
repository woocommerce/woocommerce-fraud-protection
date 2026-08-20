<?php
/**
 * FraudProtectionControllerForTests file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\FraudProtection\Tests\Support;

use Automattic\WooCommerce\Internal\FraudProtectionPlugin\FraudProtectionController;

/**
 * Test double for {@see FraudProtectionController} that records behaviour in memory.
 *
 * Installed as the controller facade target (see
 * {@see \Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase::spy_on_controller_logging()}),
 * so calls routed through the static `FraudProtectionController::log()` facade —
 * and through the instance `write_log()` — are captured in memory instead of
 * reaching the real WooCommerce logger or the PHP error log. This lets a test
 * assert what a component intended to log (level, message, context and the
 * platform-forward flag) without depending on the logging infrastructure.
 *
 * Any further controller behaviour that needs to be faked or recorded for tests
 * should be added here.
 */
class FraudProtectionControllerForTests extends FraudProtectionController {

	/**
	 * Captured log entries, in the order they were recorded.
	 *
	 * @var array<int, array{level: string, message: string, context: array<string, mixed>, forwarded: bool}>
	 */
	public array $entries = array();

	/**
	 * Point the static FraudProtectionController::log() facade at a controller.
	 *
	 * The facade target is a `protected static` property the parent assigns in
	 * init() when the container creates the controller. A replacement (this
	 * double) or a cached instance skips init(), so tests set the target through
	 * this subclass — no reflection and no container reset required. Pass the spy
	 * to install it, or the canonical controller to restore it.
	 *
	 * @param FraudProtectionController $controller The instance to make the facade target.
	 * @return void
	 */
	public static function set_facade_target( FraudProtectionController $controller ): void {
		self::$instance = $controller;
	}

	/**
	 * Record a log entry in memory instead of writing it anywhere.
	 *
	 * @param string               $level                   Log level.
	 * @param string               $message                 Log message.
	 * @param array<string, mixed> $context                 Optional context data.
	 * @param bool                 $forward_to_platform_log Whether the caller requested platform-log forwarding.
	 * @return void
	 */
	protected function write_log( string $level, string $message, array $context = array(), bool $forward_to_platform_log = false ): void {
		$this->entries[] = array(
			'level'     => $level,
			'message'   => $message,
			'context'   => $context,
			'forwarded' => $forward_to_platform_log,
		);
	}
}
