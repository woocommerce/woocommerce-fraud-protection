<?php
/**
 * IntroInboxNote class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\FraudProtection\Notes;

use Automattic\WooCommerce\Admin\Notes\Note;
use Automattic\WooCommerce\Admin\Notes\NoteTraits;
use Automattic\WooCommerce\FraudProtection\FraudProtectionController;

defined( 'ABSPATH' ) || exit;

/**
 * Creates a one-time WooCommerce Admin Inbox note announcing that fraud
 * protection is now running on the store.
 *
 * The note is informational, dismissible, and created directly by the plugin
 * (not via the remote notifications feed) so it only reaches stores where the
 * feature is actually running. `NoteTraits::note_exists()` looks up the row by
 * name without filtering on `is_deleted`, so once a merchant dismisses the
 * note it will never be recreated on subsequent `admin_init` runs.
 *
 * @internal This class is part of the internal API and is subject to change without notice.
 */
class IntroInboxNote {

	use NoteTraits;

	/**
	 * Unique name for the Inbox note row.
	 */
	public const NOTE_NAME = 'wc-fraud-protection-intro';

	/**
	 * Destination for the "Learn more" action.
	 */
	private const LEARN_MORE_URL = 'https://woocommerce.com/document/fraud-protection/';

	/**
	 * Register hooks for creating the Inbox note.
	 *
	 * Called from FraudProtectionController::on_init() which already checks
	 * if the feature is enabled.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_init', array( $this, 'maybe_create_note' ) );
	}

	/**
	 * Attempt to create the Inbox note once per store.
	 *
	 * Skips AJAX requests to avoid the DB lookup on the admin AJAX hot path.
	 * Wrapped in a fail-open try/catch so a broken Inbox data store can never
	 * fatal an admin request — the note is purely informational.
	 *
	 * @return void
	 */
	public function maybe_create_note(): void {
		if ( wp_doing_ajax() ) {
			return;
		}

		try {
			self::possibly_add_note();
		} catch ( \Throwable $e ) {
			FraudProtectionController::log(
				'warning',
				'Failed to create fraud protection intro inbox note',
				array( 'error' => $e->getMessage() )
			);
		}
	}

	/**
	 * Build the Inbox note.
	 *
	 * Required by NoteTraits::possibly_add_note().
	 *
	 * @return Note
	 */
	public static function get_note(): Note {
		$note = new Note();

		$note->set_title( __( "Fraud protection is learning your store's patterns.", 'woocommerce-fraud-protection' ) );
		$note->set_content(
			__(
				"It's running silently in the background to understand your typical order activity, building a smarter foundation for your store — no orders will be blocked at this point.",
				'woocommerce-fraud-protection'
			)
		);
		$note->set_content_data( (object) array() );
		$note->set_type( Note::E_WC_ADMIN_NOTE_INFORMATIONAL );
		$note->set_name( self::NOTE_NAME );
		$note->set_source( 'woocommerce-fraud-protection' );
		$note->add_action(
			'learn-more',
			__( 'Learn more', 'woocommerce-fraud-protection' ),
			self::LEARN_MORE_URL
		);

		return $note;
	}
}
