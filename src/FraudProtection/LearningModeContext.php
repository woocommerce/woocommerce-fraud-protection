<?php
/**
 * LearningModeContext class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\FraudProtection;

use Automattic\WooCommerce\FraudProtection\Schemas\PaymentMode;

defined( 'ABSPATH' ) || exit;

/**
 * Context passed to learning-mode callbacks for a verification attempt.
 */
final class LearningModeContext {

	/**
	 * Create a learning-mode context.
	 *
	 * @since 0.1.10
	 *
	 * @param string      $gateway          Payment gateway identifier.
	 * @param string      $verify_source    Verification source identifier.
	 * @param PaymentMode $transaction_mode Transaction mode.
	 */
	public function __construct(
		public readonly string $gateway,
		public readonly string $verify_source,
		public readonly PaymentMode $transaction_mode
	) {}
}
