<?php
/**
 * CheckResult enum file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\FraudProtection\Schemas;

defined( 'ABSPATH' ) || exit;

/**
 * Verification-check outcome for a payment instrument (CVC and AVS checks).
 *
 * Used by `PaymentInstrumentData` for its `cvc_check`, `avs_address_check`, and
 * `avs_postcode_check` fields.
 */
enum CheckResult: string {

	/** The value matches what the issuer has on file. */
	case Pass = 'pass';

	/** The value does not match. */
	case Fail = 'fail';

	/** The issuer does not support this check. */
	case Unavailable = 'unavailable';

	/** The check was not run for this transaction. */
	case Unchecked = 'unchecked';
}
