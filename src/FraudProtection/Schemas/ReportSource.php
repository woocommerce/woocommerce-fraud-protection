<?php
/**
 * ReportSource enum file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\FraudProtection\Schemas;

defined( 'ABSPATH' ) || exit;

/**
 * Origin of a fraud-protection report event.
 *
 * Passed to `FraudProtectionReporter::report()`.
 */
enum ReportSource: string {

	/** Chargeback event. */
	case Chargeback = 'chargeback';

	/** Manual review outcome. */
	case ManualReview = 'manual_review';

	/** API-driven event. */
	case Api = 'api';
}
