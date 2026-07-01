<?php
/**
 * PaymentMode enum file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\Schemas;

defined( 'ABSPATH' ) || exit;

/**
 * Transaction mode of a payment method, resolved by gateway compat layers.
 *
 * Used by `PaymentMethodData` for its `transaction_mode` field.
 */
enum PaymentMode: string {

	/** Test/sandbox transactions. */
	case Test = 'test';

	/** Live/production transactions. */
	case Live = 'live';

	/** No gateway-specific resolver was available. */
	case Unknown = 'unknown';
}
