<?php
/**
 * SessionInfo schema class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtection\Schemas;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable record object representing session identification data.
 *
 * @internal This class is part of the internal API and is subject to change without notice.
 */
class SessionInfo {

	/**
	 * WooCommerce session ID.
	 *
	 * @var ?string
	 */
	private ?string $wc_session_id;

	/**
	 * IP address.
	 *
	 * @var ?string
	 */
	private ?string $ip_address;

	/**
	 * WordPress user email (logged-in users only).
	 *
	 * @var ?string
	 */
	private ?string $email;

	/**
	 * User agent string.
	 *
	 * @var ?string
	 */
	private ?string $user_agent;

	/**
	 * Private constructor — use factory methods.
	 *
	 * @param ?string $wc_session_id WooCommerce session ID.
	 * @param ?string $ip_address    IP address.
	 * @param ?string $email         WordPress user email.
	 * @param ?string $user_agent    User agent string.
	 */
	private function __construct(
		?string $wc_session_id = null,
		?string $ip_address = null,
		?string $email = null,
		?string $user_agent = null
	) {
		$this->wc_session_id = $wc_session_id;
		$this->ip_address    = $ip_address;
		$this->email         = $email;
		$this->user_agent    = $user_agent;
	}

	/**
	 * Build from the current request context.
	 *
	 * @param string $wc_session_id WooCommerce session ID from SessionClearanceManager.
	 * @return self
	 */
	public static function from_request( string $wc_session_id ): self {
		return new self(
			$wc_session_id,
			self::get_ip_address(),
			self::get_email(),
			self::get_user_agent(),
		);
	}

	/**
	 * Build an empty SessionInfo for graceful degradation.
	 *
	 * @return self
	 */
	public static function empty(): self {
		return new self();
	}

	/**
	 * Get client IP address using WooCommerce geolocation utility.
	 *
	 * @return ?string IP address or null.
	 */
	private static function get_ip_address(): ?string {
		if ( class_exists( 'WC_Geolocation' ) ) {
			$ip = \WC_Geolocation::get_ip_address();
			return $ip ? $ip : null;
		}
		return null;
	}

	/**
	 * Get WordPress user email (logged-in users only).
	 *
	 * Billing email is already available in customer.billing_email,
	 * so this only captures the WordPress account email for logged-in users.
	 *
	 * @return ?string Email address or null.
	 */
	private static function get_email(): ?string {
		if ( \is_user_logged_in() ) {
			$user = \wp_get_current_user();
			if ( $user && $user->user_email ) {
				return \sanitize_email( $user->user_email );
			}
		}
		return null;
	}

	/**
	 * Get user agent string from HTTP headers.
	 *
	 * @return ?string User agent or null.
	 */
	private static function get_user_agent(): ?string {
		if ( isset( $_SERVER['HTTP_USER_AGENT'] ) ) {
			return \sanitize_text_field( \wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) );
		}
		return null;
	}

	/**
	 * Serialize to array.
	 *
	 * @return array
	 */
	public function to_array(): array {
		return array(
			'wc_session_id' => $this->wc_session_id,
			'ip_address'    => $this->ip_address,
			'email'         => $this->email,
			'user_agent'    => $this->user_agent,
		);
	}
}
