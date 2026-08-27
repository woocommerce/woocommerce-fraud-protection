<?php
/**
 * MerchantIdentifierType enum file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\FraudProtection\Schemas;

defined( 'ABSPATH' ) || exit;

/**
 * Type of merchant identifier resolved by a payment gateway.
 */
enum MerchantIdentifierType: string {

	/** Merchant account identifier. */
	case Account = 'account';

	/** Merchant location identifier. */
	case Location = 'location';
}
