<?php
/**
 * SettingStatus enum file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Describes an explicit setting value or its code default.
 */
enum SettingStatus: string {

	case DefaultEnabled = 'default_enabled';

	case DefaultDisabled = 'default_disabled';

	case Enabled = 'enabled';

	case Disabled = 'disabled';
}
