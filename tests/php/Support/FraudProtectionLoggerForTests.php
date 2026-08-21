<?php
/**
 * FraudProtectionLoggerForTests file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\FraudProtection\Tests\Support;

use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Logging\FraudProtectionLogger;

/**
 * In-memory Fraud Protection logger for tests.
 */
class FraudProtectionLoggerForTests extends FraudProtectionLogger {

	/**
	 * Captured log entries.
	 *
	 * @var array<int, array{level: string, message: string, context: array<string, mixed>, forwarded: bool}>
	 */
	public array $entries = array();

	/**
	 * Record a log entry in memory.
	 *
	 * @internal
	 *
	 * @param string               $level                   Log level.
	 * @param string               $message                 Log message.
	 * @param array<string, mixed> $context                 Optional context data.
	 * @param bool                 $forward_to_platform_log Whether the caller requested platform-log forwarding.
	 */
	public function log( string $level, string $message, array $context = array(), bool $forward_to_platform_log = false ): void {
		$this->entries[] = array(
			'level'     => $level,
			'message'   => $message,
			'context'   => $context,
			'forwarded' => $forward_to_platform_log,
		);
	}
}
