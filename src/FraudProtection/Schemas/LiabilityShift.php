<?php
/**
 * LiabilityShift enum file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\FraudProtection\Schemas;

defined( 'ABSPATH' ) || exit;

/**
 * 3DS/SCA liability outcome, the `liability_shift` vocabulary of a report context.
 *
 * Callers reference these when building a context via `ReportContextData::from_array()`.
 * Optional context; omitted when undeterminable.
 */
enum LiabilityShift: string {

	/** Authenticated, liability moved to the issuer. */
	case Shifted = 'shifted';

	/** 3DS attempted, issuer did not fully authenticate. */
	case Attempted = 'attempted';

	/** No 3DS, or authentication failed. */
	case NotShifted = 'not_shifted';
}
