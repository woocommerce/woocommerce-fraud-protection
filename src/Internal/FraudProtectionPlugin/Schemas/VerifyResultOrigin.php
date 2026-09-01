<?php
/**
 * VerifyResultOrigin enum file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\Schemas;

defined( 'ABSPATH' ) || exit;

/**
 * Origin of a verification result.
 */
enum VerifyResultOrigin {

	/** The result came from a response. */
	case Response;

	/** Verification failed and produced a synthetic allow. */
	case FailOpen;

	/** The verification request received a confirmed rejection response. */
	case RequestRejected;
}
