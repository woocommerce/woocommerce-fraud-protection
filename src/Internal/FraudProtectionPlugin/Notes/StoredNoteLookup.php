<?php
/**
 * StoredNoteLookup trait file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\Notes;

use Automattic\WooCommerce\Admin\Notes\Note;
use Automattic\WooCommerce\Admin\Notes\Notes;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the stored note named by the class's NOTE_NAME constant.
 *
 * WooCommerce returns false both when the note does not exist and when the read fails. A failed
 * read must not be treated as a missing note, so it is reported as an exception.
 */
trait StoredNoteLookup {

	/**
	 * Get the stored note, including a dismissed one.
	 *
	 * @return Note|null The note, or null when none is stored.
	 * @throws \RuntimeException When the note could not be read.
	 */
	private static function get_stored_note(): ?Note {
		global $wpdb;

		$note = Notes::get_note_by_name( self::NOTE_NAME );
		// Each query resets the last error, so it reflects only the note lookup.
		if ( '' !== $wpdb->last_error ) {
			throw new \RuntimeException( 'The note could not be read.' );
		}

		return $note instanceof Note ? $note : null;
	}
}
