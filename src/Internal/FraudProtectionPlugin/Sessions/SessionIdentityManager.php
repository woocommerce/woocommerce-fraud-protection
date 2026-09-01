<?php
/**
 * SessionIdentityManager class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\Sessions;

use Automattic\WooCommerce\Internal\FraudProtectionPlugin\FraudProtectionController;

defined( 'ABSPATH' ) || exit;

/**
 * Manages the customer identity ID persisted in the WooCommerce session.
 *
 * The identity ID correlates fraud protection events and log entries for a
 * customer across requests. It is sourced from the Tracks client (tk_ai
 * cookie) when available, with a random fallback, and cached in the WC
 * session for reuse.
 */
class SessionIdentityManager {

	/** Maximum stored identity length in bytes. */
	private const MAX_IDENTITY_LENGTH = 64;

	/**
	 * Session key for storing customer identity ID.
	 */
	public const CUSTOMER_IDENTITY_ID_KEY = '_fraud_protection_customer_identity_id';

	/**
	 * Ensure cart and session are available.
	 *
	 * Loads cart if not already loaded, which initializes session for both
	 * traditional (cookie) and Store API (token) flows.
	 *
	 * @return void
	 */
	public function ensure_cart_loaded(): void {
		if ( ! did_action( 'woocommerce_load_cart_from_session' ) && function_exists( 'wc_load_cart' ) ) {
			WC()->call_function( 'wc_load_cart' );
		}
	}

	/**
	 * Check if WooCommerce session is available.
	 *
	 * @return bool True if session is available.
	 */
	private function is_session_available(): bool {
		$this->ensure_cart_loaded();
		return WC()->session instanceof \WC_Session;
	}

	/**
	 * Get a unique identifier for the current session.
	 * Uses the Tracks Client to get the identity ID.
	 * If no identity ID is found in the session, the Tracks Client will get it from the tk_ai cookie or generate a new one.
	 * If no identity ID is found in the session or the cookie, a fallback identity ID will be generated.
	 * The fallback identity ID is used to ensure that a session ID is always generated.
	 *
	 * @return string Session identifier.
	 */
	public function get_identity_id(): string {
		if ( ! $this->is_session_available() ) {
			WC()->initialize_session();
		}

		// Checks if the identity ID is already in the session.
		$stored_identity_id = WC()->session->get( self::CUSTOMER_IDENTITY_ID_KEY );

		if ( null !== $stored_identity_id ) {
			$identity_id = self::normalize_identity_id( $stored_identity_id );
			if ( null === $identity_id ) {
				$identity_id = $this->generate_fallback_identity_id();
			}

			WC()->session->set( self::CUSTOMER_IDENTITY_ID_KEY, $identity_id );
			return $identity_id;
		}

		$identity_id = null;
		if ( class_exists( '\WC_Tracks_Client' ) ) {
			// If no identity ID is found in the session, the Tracks Client will get it from the tk_ai cookie or generate a new one.
			$identity    = \WC_Tracks_Client::get_identity( get_current_user_id() );
			$identity_id = $identity['_ui'] ?? '';
		}

		$identity_id = self::normalize_identity_id( $identity_id );
		if ( null === $identity_id ) {
			$identity_id = $this->generate_fallback_identity_id();
		}

		// Persists the identity ID in the session for future use.
		WC()->session->set( self::CUSTOMER_IDENTITY_ID_KEY, $identity_id );

		return $identity_id;
	}

	/**
	 * Normalize an identity value for storage and logging.
	 *
	 * @param mixed $identity_id Identity value.
	 * @return string|null Normalized identity, or null when rejected.
	 */
	public static function normalize_identity_id( mixed $identity_id ): ?string {
		if ( is_int( $identity_id ) ) {
			$identity_id = (string) $identity_id;
		}

		if ( ! is_string( $identity_id ) || '' === $identity_id || 1 !== preg_match( '/^[A-Za-z0-9:_+\/=\-]+$/', $identity_id ) ) {
			return null;
		}

		return substr( $identity_id, 0, self::MAX_IDENTITY_LENGTH );
	}

	/**
	 * Generate and log a fallback identity.
	 *
	 * @return string Fallback identity.
	 */
	private function generate_fallback_identity_id(): string {
		$identity_id = WC()->call_function( 'wc_rand_hash', 'customer_', 30 );
		FraudProtectionController::log(
			'warning',
			'Created new fallback session identity ID for customer. This should rarely happen.',
			array( 'user_id' => get_current_user_id() )
		);

		return $identity_id;
	}
}
