<?php
/**
 * VisitorIpResolver class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves the visitor IP address and its country from the current request.
 */
class VisitorIpResolver {

	/**
	 * Get the visitor IP address from the complete value supplied to PHP.
	 *
	 * @return ?string IP address or null.
	 */
	public function get_ip_address(): ?string {
		// The complete value must stay unchanged before validation.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		$ip_address = $_SERVER['REMOTE_ADDR'] ?? null;

		if ( ! is_string( $ip_address ) ) {
			return null;
		}

		return false === filter_var( $ip_address, FILTER_VALIDATE_IP ) ? null : $ip_address;
	}

	/**
	 * Get the country for a selected visitor IP address.
	 *
	 * @param ?string $ip_address Selected visitor IP address.
	 * @return string Country code or an empty string.
	 */
	public function get_ip_country( ?string $ip_address ): string {
		if ( empty( $ip_address ) || ! class_exists( 'WC_Geolocation' ) ) {
			return '';
		}

		$geolocation = \WC_Geolocation::geolocate_ip( $ip_address, false, false );

		return (string) ( $geolocation['country'] ?? '' );
	}
}
