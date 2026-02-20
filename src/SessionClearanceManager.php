<?php
/**
 * SessionClearanceManager class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\FraudProtection;

defined( 'ABSPATH' ) || exit;

/**
 * Manages session clearance state for fraud protection.
 *
 * This class handles the session status tracking for fraud protection decisions,
 * managing three possible states: pending, allowed, and blocked. It integrates
 * with WooCommerce sessions and uses the FraudProtectionController logging helper
 * to maintain consistent audit logs.
 *
 * @internal This class is part of the internal API and is subject to change without notice.
 */
class SessionClearanceManager {

	/**
	 * Session key for storing clearance status.
	 */
	private const SESSION_KEY = '_fraud_protection_clearance_status';

	/**
	 * Session key for storing customer session ID.
	 */
	private const CUSTOMER_SESSION_ID_KEY = '_fraud_protection_customer_session_id';

	/**
	 * Session status: pending clearance.
	 */
	public const STATUS_PENDING = 'pending';

	/**
	 * Session status: allowed.
	 */
	public const STATUS_ALLOWED = 'allowed';

	/**
	 * Session status: blocked.
	 */
	public const STATUS_BLOCKED = 'blocked';

	/**
	 * Default session status.
	 */
	public const DEFAULT_STATUS = self::STATUS_ALLOWED;

	/**
	 * Check if the current session is allowed.
	 *
	 * @return bool True if session is allowed, false otherwise.
	 */
	public function is_session_allowed(): bool {
		$status = $this->get_session_status();
		return self::STATUS_ALLOWED === $status;
	}

	/**
	 * Check if the current session is blocked.
	 *
	 * @return bool True if session is blocked, false otherwise.
	 */
	public function is_session_blocked(): bool {
		$status = $this->get_session_status();
		return self::STATUS_BLOCKED === $status;
	}

	/**
	 * Mark the current session as allowed.
	 *
	 * @return void
	 */
	public function allow_session(): void {
		$this->set_session_status( self::STATUS_ALLOWED );
		$this->log_session_update_event( 'allowed' );
	}

	/**
	 * Mark the current session as pending (challenge required).
	 *
	 * @return void
	 */
	public function challenge_session(): void {
		$this->set_session_status( self::STATUS_PENDING );
		$this->log_session_update_event( 'challenged' );
	}

	/**
	 * Mark the current session as blocked and empty the cart.
	 *
	 * Emptying the cart prevents express payment methods (e.g., PayPal) from
	 * rendering on cart pages, as they are loaded via third-party SDKs that
	 * don't respect WooCommerce's payment method filtering.
	 *
	 * @return void
	 */
	public function block_session(): void {
		$this->set_session_status( self::STATUS_BLOCKED );
		$this->log_session_update_event( 'blocked' );
		$this->empty_cart();
	}

	/**
	 * Get the current session clearance status.
	 *
	 * @return string One of: pending, allowed, blocked.
	 */
	public function get_session_status(): string {
		if ( ! $this->is_session_available() ) {
			return self::DEFAULT_STATUS;
		}

		$status = WC()->session->get( self::SESSION_KEY, self::DEFAULT_STATUS );

		// Validate status value - return default for invalid values.
		if ( ! in_array( $status, array( self::STATUS_PENDING, self::STATUS_ALLOWED, self::STATUS_BLOCKED ), true ) ) {
			return self::DEFAULT_STATUS;
		}

		return $status;
	}

	/**
	 * Set the session clearance status.
	 *
	 * @param string $status One of: pending, allowed, blocked.
	 * @return void
	 */
	private function set_session_status( string $status ): void {
		if ( ! $this->is_session_available() ) {
			return;
		}

		WC()->session->set( self::SESSION_KEY, $status );
	}

	/**
	 * Reset the session clearance status to default (allowed).
	 *
	 * @return void
	 */
	public function reset_session(): void {
		$this->set_session_status( self::DEFAULT_STATUS );
	}

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
	public function get_session_id(): string {
		if ( ! $this->is_session_available() ) {
			WC()->session = new \WC_Session_Handler();
			WC()->session->init();
		}

		// Checks if the identity ID is already in the session.
		$identity_id = WC()->session->get( self::CUSTOMER_SESSION_ID_KEY );

		if ( is_string( $identity_id ) && $identity_id ) {
			return $identity_id;
		}

		// If no identity ID is found in the session, the Tracks Client will get it from the tk_ai cookie or generate a new one.
		$identity    = \WC_Tracks_Client::get_identity( get_current_user_id() );
		$identity_id = $identity['_ui'] ?? '';

		if ( ! $identity_id ) {
			// Only used as a fallback. Should rarely happen.
			$identity_id = WC()->call_function( 'wc_rand_hash', 'customer_', 30 );
			FraudProtectionController::log(
				'warning',
				'FraudProtection: Created new fallback session identity ID for customer. This should rarely happen. User ID: ' . get_current_user_id()
			);
		}

		// Persists the identity ID in the session for future use.
		WC()->session->set( self::CUSTOMER_SESSION_ID_KEY, $identity_id );

		return $identity_id;
	}

	/**
	 * Empty the cart.
	 *
	 * @return void
	 */
	private function empty_cart(): void {
		if ( function_exists( 'WC' ) && WC()->cart ) {
			WC()->cart->empty_cart();
		}
	}

	/**
	 * Log a session update event using FraudProtectionController's logging helper.
	 *
	 * @param string $action The action taken (allowed, challenged, or blocked).
	 * @return void
	 */
	private function log_session_update_event( string $action ): void {
		$session_id = $this->get_session_id();
		$user_id    = get_current_user_id();
		$user_info  = $user_id ? "User: {$user_id}" : 'User: guest';
		$timestamp  = current_time( 'mysql' );

		$message = sprintf(
			'Session updated: %s | %s | Action: %s | Timestamp: %s',
			$session_id,
			$user_info,
			$action,
			$timestamp
		);

		FraudProtectionController::log( 'info', $message );
	}
}
