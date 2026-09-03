<?php
/**
 * SettingsChangeChannel enum file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Describes where a settings change originated.
 */
enum SettingsChangeChannel: string {

	case Settings = 'settings';

	case Cli = 'cli';
}
