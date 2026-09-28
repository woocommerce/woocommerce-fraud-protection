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
	 * Constant that declares the trusted proxies and their client IP header.
	 */
	public const TRUSTED_PROXY_CONFIG_CONSTANT = 'WC_FRAUD_PROTECTION_TRUSTED_PROXY_CONFIG';

	/**
	 * Filter applied to the trusted-proxy configuration.
	 */
	public const TRUSTED_PROXY_CONFIG_FILTER = 'woocommerce_fraud_protection_trusted_proxy_config';

	/**
	 * Transient that limits the log for the same configuration problem to once per site per day.
	 */
	private const CONFIG_PROBLEM_LOG_TRANSIENT = 'wc_fraud_protection_trusted_proxy_config_log';

	/**
	 * Get the visitor IP address.
	 *
	 * Uses the direct peer address (`REMOTE_ADDR`). When the peer belongs to a trusted-proxy entry,
	 * the client address from the header of the first matching entry replaces it.
	 *
	 * @return ?string IP address or null.
	 */
	public function get_ip_address(): ?string {
		// The complete value must stay unchanged before validation.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		$peer_address = self::validate_ip( $_SERVER['REMOTE_ADDR'] ?? null );
		if ( null === $peer_address ) {
			return null;
		}

		$config = $this->get_trusted_proxy_config();
		$entry  = $config->find_entry_for_peer( $peer_address );
		if ( null === $entry ) {
			return $peer_address;
		}

		return self::get_forwarded_client_ip( $config, $entry ) ?? $peer_address;
	}

	/**
	 * Get the validated trusted-proxy configuration from the constant and its filter.
	 *
	 * @return TrustedProxyConfig
	 */
	public function get_trusted_proxy_config(): TrustedProxyConfig {
		$config = defined( self::TRUSTED_PROXY_CONFIG_CONSTANT ) ? constant( self::TRUSTED_PROXY_CONFIG_CONSTANT ) : array();

		try {
			/**
			 * Filters the trusted-proxy configuration used to select the visitor IP address.
			 *
			 * Expected shape: one entry, `array( 'addresses' => string[], 'header' => string )`, or a list
			 * of entries. `addresses` lists the IP addresses and CIDR ranges of proxies in front of the store,
			 * and `header` names the header those proxies set with the client address, such as
			 * `X-Forwarded-For` or `X-Real-IP`. The first entry that contains the direct peer decides the
			 * header. A non-array return keeps the unfiltered value.
			 *
			 * @since 0.2.9
			 *
			 * @param mixed $config The `WC_FRAUD_PROTECTION_TRUSTED_PROXY_CONFIG` value, or an empty array when it is not defined.
			 */
			$filtered = apply_filters( self::TRUSTED_PROXY_CONFIG_FILTER, $config );

			if ( is_array( $filtered ) ) {
				$config = $filtered;
			} else {
				FraudProtectionController::log(
					'warning',
					'Trusted proxy configuration filter returned a non-array value; using the unfiltered value',
					array(
						'filter'        => self::TRUSTED_PROXY_CONFIG_FILTER,
						'argument_type' => get_debug_type( $filtered ),
					)
				);
			}
		} catch ( \Throwable $e ) {
			FraudProtectionController::log(
				'warning',
				'Trusted proxy configuration filter threw; using the unfiltered value',
				array(
					'filter'            => self::TRUSTED_PROXY_CONFIG_FILTER,
					'exception_class'   => $e::class,
					'exception_message' => $e->getMessage(),
					'exception_file'    => $e->getFile(),
					'exception_line'    => $e->getLine(),
				),
				true
			);
		}

		$trusted_proxy_config = TrustedProxyConfig::from_mixed( $config );
		$this->log_config_problems( $trusted_proxy_config );

		return $trusted_proxy_config;
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

	/**
	 * Get the client address that a trusted proxy supplied in its entry's header.
	 *
	 * Every header is read as a comma-separated chain, from right to left: addresses trusted by any entry
	 * are skipped and the first untrusted one is the client. When every address is trusted, the leftmost
	 * one is the client. A single address is a one-element chain.
	 *
	 * @param TrustedProxyConfig $config Trusted-proxy configuration.
	 * @param TrustedProxyEntry  $entry  Usable entry that contains the direct peer.
	 * @return ?string Client address, or null when the header is missing or contains an invalid entry.
	 */
	private static function get_forwarded_client_ip( TrustedProxyConfig $config, TrustedProxyEntry $entry ): ?string {
		$server_key = $entry->get_header_server_key();
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		$header_value = null === $server_key ? null : ( $_SERVER[ $server_key ] ?? null );
		if ( ! is_string( $header_value ) ) {
			return null;
		}

		$client_address = null;
		foreach ( array_reverse( explode( ',', $header_value ) ) as $chain_entry ) {
			$client_address = self::validate_ip( trim( $chain_entry, " \t" ) );
			if ( null === $client_address ) {
				return null;
			}

			if ( ! $config->is_trusted( $client_address ) ) {
				return $client_address;
			}
		}

		return $client_address;
	}

	/**
	 * Accept only one complete IP literal.
	 *
	 * @param mixed $value Candidate value.
	 * @return ?string The value, or null when it is not a valid IP address.
	 */
	private static function validate_ip( mixed $value ): ?string {
		if ( ! is_string( $value ) ) {
			return null;
		}

		return false === filter_var( $value, FILTER_VALIDATE_IP ) ? null : $value;
	}

	/**
	 * Log configuration problems once per day, or immediately when the problem changes.
	 *
	 * @param TrustedProxyConfig $config Validated configuration.
	 * @return void
	 */
	private function log_config_problems( TrustedProxyConfig $config ): void {
		if ( ! $config->has_problems() ) {
			return;
		}

		$context = $config->to_diagnostic_array();

		$fingerprint = md5( (string) wp_json_encode( $context ) );
		if ( get_transient( self::CONFIG_PROBLEM_LOG_TRANSIENT ) === $fingerprint ) {
			return;
		}

		set_transient( self::CONFIG_PROBLEM_LOG_TRANSIENT, $fingerprint, DAY_IN_SECONDS );

		FraudProtectionController::log(
			'warning',
			$config->is_active()
				? 'Trusted proxy configuration contains invalid values that were ignored'
				: 'Trusted proxy configuration is incomplete or invalid; using the direct peer address',
			$context
		);
	}
}
