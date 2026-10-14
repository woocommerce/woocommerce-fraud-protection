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

	/**
	 * Register the note retirement.
	 */
	public function register(): void {
		add_action( 'admin_init', array( $this, 'retire_note' ) );
	}

	/**
	 * Mark an active invitation as actioned so it leaves the Inbox.
	 *
	 * @internal
	 */
	public function retire_note(): void {
		if ( wp_doing_ajax() ) {
			return;
		}

		try {
			$note = Notes::get_note_by_name( self::NOTE_NAME );
			if ( $note instanceof Note && ! $note->get_is_deleted() && Note::E_WC_ADMIN_NOTE_ACTIONED !== $note->get_status() ) {
				$note->set_status( Note::E_WC_ADMIN_NOTE_ACTIONED );
				$note->save();
			}
		} catch ( \Throwable $e ) {
			FraudProtectionController::log( 'warning', 'Failed to retire automatic protection early-access note.', array( 'error' => $e->getMessage() ) );
		}
	}
}
