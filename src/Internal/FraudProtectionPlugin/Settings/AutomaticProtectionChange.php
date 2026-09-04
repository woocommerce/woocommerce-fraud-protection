<?php
/**
 * AutomaticProtectionChange enum file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Describes an automatic-protection setting change.
 */
enum AutomaticProtectionChange: string {

	case Enabled = 'enabled';

	case Disabled = 'disabled';

	case Reset = 'reset';
}
