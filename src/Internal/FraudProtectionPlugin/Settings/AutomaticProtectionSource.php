<?php
/**
 * AutomaticProtectionSource enum file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Describes the source of the automatic-protection setting.
 */
enum AutomaticProtectionSource: string {

	case None = 'none';

	case Manual = 'manual';
}
