<?php
/**
 * LearningModeContext class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\FraudProtection;

use Automattic\WooCommerce\FraudProtection\Schemas\PaymentMode;

defined( 'ABSPATH' ) || exit;

/**
 * Deprecated context formerly passed to learning-mode callbacks.
 *
 * @deprecated 0.2.2 The learning-mode filter no longer runs. Use the automatic-protection setting.
 */
final class LearningModeContext {

	/**
	 * Create a learning-mode context.
	 *
	 * @param string      $gateway          Payment gateway identifier.
	 * @param string      $verify_source    Verification source identifier.
	 * @param PaymentMode $transaction_mode Transaction mode.
	 *
	 * @since 0.2.0
	 * @deprecated 0.2.2 The learning-mode filter no longer runs.
	 */
	public function __construct(
		public readonly string $gateway,
		public readonly string $verify_source,
		public readonly PaymentMode $transaction_mode
	) {}
}
