<?php
/**
 * AutomaticProtectionEarlyAccessNote class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\Notes;

use Automattic\WooCommerce\Admin\Notes\Note;
use Automattic\WooCommerce\Admin\Notes\Notes;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\FraudProtectionController;

defined( 'ABSPATH' ) || exit;

/**
 * Retires the early-access invitation to enable automatic protection.
 *
 * Early access ended when automatic protection became enabled by default. The
 * stored invitation keeps its early-access copy, so it is removed from the Inbox
 * instead of being updated.
 */
class AutomaticProtectionEarlyAccessNote {

	public const NOTE_NAME = 'wc-fraud-protection-automatic-protection-early-access';

	public const RETIRED_OPTION_NAME = 'woocommerce_fraud_protection_early_access_note_retired';

	/**
	 * Register the note retirement.
	 */
	public function register(): void {
		add_action( 'admin_init', array( $this, 'retire_note' ) );
	}

	/**
	 * Mark an active invitation as actioned so it leaves the Inbox.
	 *
	 * The check runs once per site. A failed check runs again on the next admin request.
	 *
	 * @internal
	 */
	public function retire_note(): void {
		if ( wp_doing_ajax() || 'yes' === get_option( self::RETIRED_OPTION_NAME ) ) {
			return;
		}

		try {
			$note = Notes::get_note_by_name( self::NOTE_NAME );
			if ( $note instanceof Note && ! $note->get_is_deleted() && Note::E_WC_ADMIN_NOTE_ACTIONED !== $note->get_status() ) {
				$note->set_status( Note::E_WC_ADMIN_NOTE_ACTIONED );
				$note->save();

				// The note store does not report failed writes, so confirm the stored status
				// before recording the check. An unconfirmed write runs again on the next request.
				$stored_note = Notes::get_note( $note->get_id() );
				if ( ! $stored_note instanceof Note || Note::E_WC_ADMIN_NOTE_ACTIONED !== $stored_note->get_status() ) {
					FraudProtectionController::log( 'warning', 'Failed to retire automatic protection early-access note.', array( 'error' => 'The note status was not stored.' ) );
					return;
				}
			}

			// Autoloaded so later admin requests skip the check without a query.
			update_option( self::RETIRED_OPTION_NAME, 'yes', true );
		} catch ( \Throwable $e ) {
			FraudProtectionController::log( 'warning', 'Failed to retire automatic protection early-access note.', array( 'error' => $e->getMessage() ) );
		}
	}
}
