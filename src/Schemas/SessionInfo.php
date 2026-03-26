<?php
/**
 * SessionInfo schema class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\FraudProtection\Schemas;

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
	private ?string $wc_identity_id;

	/**
	 * WordPress user email (logged-in users only).
	 *
	 * @var ?string
	 */
	private ?string $email;

	/**
	 * Private constructor — use factory methods.
	 *
	 * @param ?string $wc_identity_id WooCommerce session ID.
	 * @param ?string $email         WordPress user email.
	 */
	private function __construct(
		?string $wc_identity_id = null,
		?string $email = null
	) {
		$this->wc_identity_id = $wc_identity_id;
		$this->email          = $email;
	}

	/**
	 * Build from the current request context.
	 *
	 * @param string $wc_identity_id WooCommerce session ID from SessionClearanceManager.
	 * @return self
	 */
	public static function from_request( string $wc_identity_id ): self {
		return new self(
			$wc_identity_id,
			self::get_email(),
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
	 * Public for use by ApiClient in the no-session case (visitor_ip).
	 *
	 * @return ?string IP address or null.
	 */
	public static function get_ip_address(): ?string {
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
	 * Serialize to array.
	 *
	 * @return array
	 */
	public function to_array(): array {
		return array(
			'wc_identity_id' => $this->wc_identity_id,
			'email'          => $this->email,
		);
	}
}
