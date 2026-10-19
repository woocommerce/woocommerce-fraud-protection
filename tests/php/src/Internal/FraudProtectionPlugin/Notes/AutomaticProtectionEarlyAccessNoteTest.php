<?php
/**
 * AutomaticProtectionEarlyAccessNoteTest class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin\Notes;

use Automattic\WooCommerce\Admin\Notes\Note;
use Automattic\WooCommerce\Admin\Notes\Notes;
use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Notes\AutomaticProtectionEarlyAccessNote;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Settings\AutomaticProtectionSetting;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Settings\MerchantFacingFeaturesGate;

/**
 * Tests the retirement of the early-access Inbox invitation.
 */
class AutomaticProtectionEarlyAccessNoteTest extends FraudProtectionUnitTestCase {
	/**
	 * The System Under Test.
	 *
	 * @var AutomaticProtectionEarlyAccessNote
	 */
	private $sut;

	/**
	 * Set up the note retirement.
	 */
	public function setUp(): void {
		parent::setUp();
		delete_option( AutomaticProtectionEarlyAccessNote::RETIRED_OPTION_NAME );
		$this->sut = new AutomaticProtectionEarlyAccessNote();
		$this->sut->register();
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		remove_all_filters( 'wp_doing_ajax' );
		delete_option( AutomaticProtectionEarlyAccessNote::RETIRED_OPTION_NAME );
		parent::tearDown();
	}

	/**
	 * @testdox Note retirement runs on admin initialization.
	 */
	public function test_registers_admin_callback(): void {
		$this->assertSame( 10, has_action( 'admin_init', array( $this->sut, 'retire_note' ) ) );
	}

	/**
	 * @testdox An active invitation leaves the Inbox whatever the stored protection state.
	 * @dataProvider protection_states
	 *
	 * @param string|null $stored_value The stored automatic-protection value, or null for none.
	 */
	public function test_retires_active_note( ?string $stored_value ): void {
		$this->store_protection_state( $stored_value );
		$note = $this->create_note( Note::E_WC_ADMIN_NOTE_UNACTIONED );

		$this->sut->retire_note();

		$reloaded = Notes::get_note( $note->get_id() );
		$this->assertSame( Note::E_WC_ADMIN_NOTE_ACTIONED, $reloaded->get_status(), 'The invitation should leave the Inbox' );
		$this->assertFalse( $reloaded->get_is_deleted() );
		$this->assertRetirementRecorded();
	}

	/**
	 * Stored automatic-protection states.
	 *
	 * @return array<string, array{?string}>
	 */
	public function protection_states(): array {
		return array(
			'no stored value'     => array( null ),
			'explicitly enabled'  => array( 'yes' ),
			'explicitly disabled' => array( 'no' ),
		);
	}

	/**
	 * @testdox Invitations are retired while merchant-facing features are disabled.
	 */
	public function test_retires_note_with_disabled_merchant_features(): void {
		wc_get_container()->get( MerchantFacingFeaturesGate::class )->set_enabled( false );
		$note = $this->create_note( Note::E_WC_ADMIN_NOTE_UNACTIONED );

		$this->sut->retire_note();

		$this->assertSame( Note::E_WC_ADMIN_NOTE_ACTIONED, Notes::get_note( $note->get_id() )->get_status() );
	}

	/**
	 * @testdox Snoozed invitations are retired so they do not return to the Inbox.
	 */
	public function test_retires_snoozed_note(): void {
		$note = $this->create_note( Note::E_WC_ADMIN_NOTE_SNOOZED );

		$this->sut->retire_note();

		$this->assertSame( Note::E_WC_ADMIN_NOTE_ACTIONED, Notes::get_note( $note->get_id() )->get_status() );
	}

	/**
	 * @testdox Dismissed invitations remain dismissed.
	 */
	public function test_dismissed_note_remains_dismissed(): void {
		$note = $this->create_note( Note::E_WC_ADMIN_NOTE_UNACTIONED, true );

		$this->sut->retire_note();

		$reloaded = Notes::get_note( $note->get_id() );
		$this->assertTrue( $reloaded->get_is_deleted() );
		$this->assertSame( Note::E_WC_ADMIN_NOTE_UNACTIONED, $reloaded->get_status() );
		$this->assertRetirementRecorded();
	}

	/**
	 * @testdox A store without the invitation records the completed check.
	 */
	public function test_missing_note_records_retirement(): void {
		$this->sut->retire_note();

		$this->assertFalse( Notes::get_note_by_name( AutomaticProtectionEarlyAccessNote::NOTE_NAME ) );
		$this->assertRetirementRecorded();
	}

	/**
	 * @testdox Once the check is recorded, later admin requests leave the note store alone.
	 */
	public function test_recorded_retirement_skips_later_checks(): void {
		update_option( AutomaticProtectionEarlyAccessNote::RETIRED_OPTION_NAME, 'yes' );
		$note = $this->create_note( Note::E_WC_ADMIN_NOTE_UNACTIONED );

		$this->sut->retire_note();

		$this->assertSame( Note::E_WC_ADMIN_NOTE_UNACTIONED, Notes::get_note( $note->get_id() )->get_status() );
	}

	/**
	 * @testdox AJAX requests do not change the invitation.
	 */
	public function test_ajax_does_not_retire_note(): void {
		$note = $this->create_note( Note::E_WC_ADMIN_NOTE_UNACTIONED );
		add_filter( 'wp_doing_ajax', '__return_true' );

		$this->sut->retire_note();

		$this->assertSame( Note::E_WC_ADMIN_NOTE_UNACTIONED, Notes::get_note( $note->get_id() )->get_status() );
		$this->assertNull( get_option( AutomaticProtectionEarlyAccessNote::RETIRED_OPTION_NAME, null ) );
	}

	/**
	 * @testdox An unavailable note store does not interrupt the admin request and is checked again later.
	 */
	public function test_unavailable_note_store_is_logged(): void {
		$unavailable_store = static function () {
			throw new \RuntimeException( 'Note storage unavailable.' );
		};
		add_filter( 'woocommerce_admin-note_data_store', $unavailable_store );
		try {
			$this->sut->retire_note();
			$this->assertLogged( 'warning', 'Failed to retire automatic protection early-access note.' );
			$this->assertNull( get_option( AutomaticProtectionEarlyAccessNote::RETIRED_OPTION_NAME, null ), 'A failed check must run again' );
		} finally {
			remove_filter( 'woocommerce_admin-note_data_store', $unavailable_store );
		}
	}

	/**
	 * @testdox A note write that fails silently is logged and checked again later.
	 */
	public function test_unconfirmed_note_write_is_checked_again(): void {
		global $wpdb;
		$note        = $this->create_note( Note::E_WC_ADMIN_NOTE_UNACTIONED );
		$fail_update = static function ( $query ) use ( $wpdb ) {
			return is_string( $query ) && str_starts_with( $query, "UPDATE `{$wpdb->prefix}wc_admin_notes`" ) ? 'SELECT * FROM missing_table_for_failed_note_update' : $query;
		};
		add_filter( 'query', $fail_update );
		$suppressed = $wpdb->suppress_errors();
		try {
			$this->sut->retire_note();
		} finally {
			$wpdb->suppress_errors( $suppressed );
			remove_filter( 'query', $fail_update );
		}

		$this->assertSame( Note::E_WC_ADMIN_NOTE_UNACTIONED, Notes::get_note( $note->get_id() )->get_status() );
		$this->assertLogged( 'warning', 'Failed to retire automatic protection early-access note.' );
		$this->assertNull( get_option( AutomaticProtectionEarlyAccessNote::RETIRED_OPTION_NAME, null ), 'An unconfirmed write must run again' );

		$this->sut->retire_note();

		$this->assertSame( Note::E_WC_ADMIN_NOTE_ACTIONED, Notes::get_note( $note->get_id() )->get_status() );
		$this->assertRetirementRecorded();
	}

	/**
	 * @testdox A failed note lookup is not treated as a missing note, so the check runs again.
	 */
	public function test_failed_lookup_is_checked_again(): void {
		$note = $this->create_note( Note::E_WC_ADMIN_NOTE_UNACTIONED );

		$this->with_failed_note_lookup( fn() => $this->sut->retire_note() );

		$this->assertSame( Note::E_WC_ADMIN_NOTE_UNACTIONED, Notes::get_note( $note->get_id() )->get_status() );
		$this->assertLogged( 'warning', 'Failed to retire automatic protection early-access note.' );
		$this->assertNull( get_option( AutomaticProtectionEarlyAccessNote::RETIRED_OPTION_NAME, null ), 'A failed lookup must run again' );

		$this->sut->retire_note();

		$this->assertSame( Note::E_WC_ADMIN_NOTE_ACTIONED, Notes::get_note( $note->get_id() )->get_status() );
		$this->assertRetirementRecorded();
	}

	/**
	 * Assert that the completed check is stored as an autoloaded option.
	 */
	private function assertRetirementRecorded(): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- PHPUnit style.
		$this->assertSame( 'yes', get_option( AutomaticProtectionEarlyAccessNote::RETIRED_OPTION_NAME ) );
		$this->assertArrayHasKey( AutomaticProtectionEarlyAccessNote::RETIRED_OPTION_NAME, wp_load_alloptions(), 'The option must be autoloaded' );
	}

	/**
	 * Run a callback while the note lookup by name fails at the database.
	 *
	 * @param callable $callback Code to run.
	 */
	private function with_failed_note_lookup( callable $callback ): void {
		global $wpdb;
		$fail_lookup = static function ( $query ) use ( $wpdb ) {
			return is_string( $query ) && str_starts_with( $query, "SELECT note_id FROM {$wpdb->prefix}wc_admin_notes WHERE name = " ) ? 'SELECT * FROM missing_table_for_failed_note_lookup' : $query;
		};
		add_filter( 'query', $fail_lookup );
		$suppressed = $wpdb->suppress_errors();
		try {
			$callback();
		} finally {
			$wpdb->suppress_errors( $suppressed );
			remove_filter( 'query', $fail_lookup );
		}
	}

	/**
	 * Store an automatic-protection state.
	 *
	 * @param string|null $stored_value The stored value, or null to delete it.
	 */
	private function store_protection_state( ?string $stored_value ): void {
		$setting = wc_get_container()->get( AutomaticProtectionSetting::class );
		if ( null === $stored_value ) {
			$setting->reset();
			return;
		}

		$setting->set_enabled( 'yes' === $stored_value );
	}

	/**
	 * Save an invitation created during early access.
	 *
	 * @param string $status     The note status.
	 * @param bool   $is_deleted Whether the note was dismissed.
	 * @return Note The saved note.
	 */
	private function create_note( string $status, bool $is_deleted = false ): Note {
		$note = new Note();
		$note->set_name( AutomaticProtectionEarlyAccessNote::NOTE_NAME );
		$note->set_source( 'woocommerce-fraud-protection' );
		$note->set_type( Note::E_WC_ADMIN_NOTE_INFORMATIONAL );
		$note->set_title( 'Start blocking risky checkout attempts' );
		$note->set_content( 'Early-access invitation.' );
		$note->set_status( $status );
		$note->set_is_deleted( $is_deleted );
		$note->save();

		return $note;
	}
}
