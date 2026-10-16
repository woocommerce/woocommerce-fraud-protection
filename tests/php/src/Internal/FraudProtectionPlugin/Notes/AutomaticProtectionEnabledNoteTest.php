<?php
/**
 * AutomaticProtectionEnabledNoteTest class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin\Notes;

use Automattic\WooCommerce\Admin\Notes\Note;
use Automattic\WooCommerce\Admin\Notes\Notes;
use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Notes\AutomaticProtectionEnabledNote;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Settings\AutomaticProtectionSetting;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Settings\MerchantFacingFeaturesGate;

/**
 * Tests the Inbox note for stores that follow the enabled default.
 */
class AutomaticProtectionEnabledNoteTest extends FraudProtectionUnitTestCase {
	private const OPT_OUT_DATE_OPTION_NAME = 'woocommerce_fraud_protection_automatic_protection_opted_out_at';

	/**
	 * The System Under Test.
	 *
	 * @var AutomaticProtectionEnabledNote
	 */
	private $sut;

	/**
	 * Automatic-protection setting.
	 *
	 * @var AutomaticProtectionSetting
	 */
	private $setting;

	/**
	 * Merchant-facing features gate.
	 *
	 * @var MerchantFacingFeaturesGate
	 */
	private $gate;

	/**
	 * Set up a store that follows the enabled default.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->setting = wc_get_container()->get( AutomaticProtectionSetting::class );
		$this->gate    = wc_get_container()->get( MerchantFacingFeaturesGate::class );
		$this->reset_state();
		$this->sut = new AutomaticProtectionEnabledNote();
		$this->sut->register();
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		remove_all_filters( 'wp_doing_ajax' );
		$this->reset_state();
		parent::tearDown();
	}

	/**
	 * @testdox The note is added on admin initialization and follows setting changes.
	 */
	public function test_registers_callbacks(): void {
		$this->assertSame( 10, has_action( 'admin_init', array( $this->sut, 'maybe_add_note' ) ) );
		foreach ( array( 'woocommerce_fraud_protection_automatic_protection', 'woocommerce_fraud_protection_merchant_facing_features' ) as $option ) {
			foreach ( array( 'add_option_', 'update_option_', 'delete_option_' ) as $hook ) {
				$this->assertSame( 10, has_action( $hook . $option, array( $this->sut, 'update_note' ) ), $hook . $option );
			}
		}
	}

	/**
	 * @testdox A store that follows the enabled default gets the note with the approved copy and a settings link.
	 */
	public function test_adds_note_for_enabled_default(): void {
		$this->sut->maybe_add_note();

		$note = Notes::get_note_by_name( AutomaticProtectionEnabledNote::NOTE_NAME );
		$this->assertInstanceOf( Note::class, $note );
		$this->assertSame( Note::E_WC_ADMIN_NOTE_UNACTIONED, $note->get_status() );
		$this->assertSame( Note::E_WC_ADMIN_NOTE_INFORMATIONAL, $note->get_type() );
		$this->assertSame( 'woocommerce-fraud-protection', $note->get_source() );
		$this->assertSame( 'Fraud prevention is now blocking suspicious payments', $note->get_title() );
		$this->assertSame(
			'As of October 20, automatic blocking is on by default. It stops payment attempts flagged as suspicious before they go through. You can review your payment activity or turn off automatic blocking at any time in your fraud prevention settings. <a href="https://woocommerce.com/document/fraud-protection/" target="_blank" rel="noopener noreferrer">Learn more</a>',
			$note->get_content()
		);

		$actions = $note->get_actions();
		$this->assertCount( 1, $actions );
		$this->assertSame( 'manage-automatic-protection', $actions[0]->name );
		$this->assertSame( 'Manage in settings', $actions[0]->label );
		$this->assertSame( admin_url( 'admin.php?page=wc-settings&tab=woocommerce_fraud_protection&source=inbox' ), $actions[0]->query );
		$this->assertSame( Note::E_WC_ADMIN_NOTE_UNACTIONED, $actions[0]->status );

		$this->assertAdditionRecorded();
	}

	/**
	 * @testdox Stores with a stored choice or without the settings page do not get the note.
	 * @dataProvider not_applicable_states
	 *
	 * @param string|null $stored_value  The stored automatic-protection value, or null for none.
	 * @param string|null $opted_out_at  The stored opt-out date, or null for none.
	 * @param bool        $gate_enabled  Whether merchant-facing features are enabled.
	 */
	public function test_skips_stores_that_do_not_follow_the_default( ?string $stored_value, ?string $opted_out_at, bool $gate_enabled ): void {
		if ( null !== $stored_value ) {
			update_option( 'woocommerce_fraud_protection_automatic_protection', $stored_value );
		}
		if ( null !== $opted_out_at ) {
			update_option( self::OPT_OUT_DATE_OPTION_NAME, $opted_out_at );
		}
		if ( ! $gate_enabled ) {
			$this->gate->set_enabled( false );
		}

		$this->sut->maybe_add_note();

		$this->assertFalse( Notes::get_note_by_name( AutomaticProtectionEnabledNote::NOTE_NAME ) );
		$this->assertNull( get_option( AutomaticProtectionEnabledNote::ADDED_OPTION_NAME, null ), 'The note can still be added if the store follows the default later' );
	}

	/**
	 * States in which the note is not added.
	 *
	 * @return array<string, array{?string, ?string, bool}>
	 */
	public function not_applicable_states(): array {
		return array(
			'explicitly enabled'                => array( 'yes', null, true ),
			'explicitly disabled'               => array( 'no', null, true ),
			'opted out'                         => array( 'no', '2026-09-22 10:00:00', true ),
			'merchant-facing features disabled' => array( null, null, false ),
		);
	}

	/**
	 * @testdox A dismissed note is not added again.
	 */
	public function test_dismissed_note_is_not_added_again(): void {
		$this->sut->maybe_add_note();
		$note = Notes::get_note_by_name( AutomaticProtectionEnabledNote::NOTE_NAME );
		$note->set_is_deleted( true );
		$note->save();
		delete_option( AutomaticProtectionEnabledNote::ADDED_OPTION_NAME );

		$this->sut->maybe_add_note();

		$this->assertCount( 1, Notes::load_data_store()->get_notes_with_name( AutomaticProtectionEnabledNote::NOTE_NAME ) );
		$this->assertTrue( Notes::get_note( $note->get_id() )->get_is_deleted() );
		$this->assertAdditionRecorded();
	}

	/**
	 * @testdox Once the note is recorded, later admin requests leave the note store alone.
	 */
	public function test_recorded_addition_skips_later_checks(): void {
		update_option( AutomaticProtectionEnabledNote::ADDED_OPTION_NAME, 'yes' );

		$this->sut->maybe_add_note();

		$this->assertFalse( Notes::get_note_by_name( AutomaticProtectionEnabledNote::NOTE_NAME ) );
	}

	/**
	 * @testdox AJAX requests do not add the note.
	 */
	public function test_ajax_does_not_add_note(): void {
		add_filter( 'wp_doing_ajax', '__return_true' );

		$this->sut->maybe_add_note();

		$this->assertFalse( Notes::get_note_by_name( AutomaticProtectionEnabledNote::NOTE_NAME ) );
		$this->assertNull( get_option( AutomaticProtectionEnabledNote::ADDED_OPTION_NAME, null ) );
	}

	/**
	 * @testdox A note write that fails silently is logged and tried again later.
	 */
	public function test_unconfirmed_note_write_is_tried_again(): void {
		global $wpdb;
		$fail_insert = static function ( $query ) use ( $wpdb ) {
			return is_string( $query ) && str_starts_with( $query, "INSERT INTO `{$wpdb->prefix}wc_admin_notes`" ) ? 'SELECT * FROM missing_table_for_failed_note_insert' : $query;
		};
		add_filter( 'query', $fail_insert );
		$suppressed = $wpdb->suppress_errors();
		try {
			$this->sut->maybe_add_note();
		} finally {
			$wpdb->suppress_errors( $suppressed );
			remove_filter( 'query', $fail_insert );
		}

		$this->assertFalse( Notes::get_note_by_name( AutomaticProtectionEnabledNote::NOTE_NAME ) );
		$this->assertLogged( 'warning', 'Failed to add automatic protection enabled note.' );
		$this->assertNull( get_option( AutomaticProtectionEnabledNote::ADDED_OPTION_NAME, null ), 'An unconfirmed write must run again' );

		$this->sut->maybe_add_note();

		$this->assertInstanceOf( Note::class, Notes::get_note_by_name( AutomaticProtectionEnabledNote::NOTE_NAME ) );
		$this->assertAdditionRecorded();
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
			$this->sut->maybe_add_note();
			$this->assertLogged( 'warning', 'Failed to add automatic protection enabled note.' );
			$this->assertNull( get_option( AutomaticProtectionEnabledNote::ADDED_OPTION_NAME, null ) );
		} finally {
			remove_filter( 'woocommerce_admin-note_data_store', $unavailable_store );
		}
	}

	/**
	 * @testdox Turning automatic protection off retires the note.
	 */
	public function test_turning_protection_off_retires_note(): void {
		$this->sut->maybe_add_note();

		$this->setting->set_enabled( false );

		$this->assertSame( Note::E_WC_ADMIN_NOTE_ACTIONED, Notes::get_note_by_name( AutomaticProtectionEnabledNote::NOTE_NAME )->get_status() );
	}

	/**
	 * @testdox Hiding the settings page retires the note.
	 */
	public function test_disabling_merchant_features_retires_note(): void {
		$this->sut->maybe_add_note();

		$this->gate->set_enabled( false );

		$this->assertSame( Note::E_WC_ADMIN_NOTE_ACTIONED, Notes::get_note_by_name( AutomaticProtectionEnabledNote::NOTE_NAME )->get_status() );
	}

	/**
	 * @testdox Turning automatic protection on explicitly keeps the note, which is still accurate.
	 */
	public function test_explicit_enable_keeps_note(): void {
		$this->sut->maybe_add_note();

		$this->setting->set_enabled( true );

		$this->assertSame( Note::E_WC_ADMIN_NOTE_UNACTIONED, Notes::get_note_by_name( AutomaticProtectionEnabledNote::NOTE_NAME )->get_status() );
	}

	/**
	 * @testdox A dismissed note stays dismissed when protection is turned off.
	 */
	public function test_dismissed_note_stays_dismissed(): void {
		$this->sut->maybe_add_note();
		$note = Notes::get_note_by_name( AutomaticProtectionEnabledNote::NOTE_NAME );
		$note->set_is_deleted( true );
		$note->save();

		$this->setting->set_enabled( false );

		$reloaded = Notes::get_note( $note->get_id() );
		$this->assertTrue( $reloaded->get_is_deleted() );
		$this->assertSame( Note::E_WC_ADMIN_NOTE_UNACTIONED, $reloaded->get_status() );
	}

	/**
	 * @testdox A note retirement that fails silently is logged and tried again on the next admin request.
	 */
	public function test_unconfirmed_retirement_is_tried_again(): void {
		global $wpdb;
		$this->sut->maybe_add_note();
		$fail_update = static function ( $query ) use ( $wpdb ) {
			return is_string( $query ) && str_starts_with( $query, "UPDATE `{$wpdb->prefix}wc_admin_notes`" ) ? 'SELECT * FROM missing_table_for_failed_note_update' : $query;
		};
		add_filter( 'query', $fail_update );
		$suppressed = $wpdb->suppress_errors();
		try {
			$this->setting->set_enabled( false );
		} finally {
			$wpdb->suppress_errors( $suppressed );
			remove_filter( 'query', $fail_update );
		}

		$this->assertSame( Note::E_WC_ADMIN_NOTE_UNACTIONED, Notes::get_note_by_name( AutomaticProtectionEnabledNote::NOTE_NAME )->get_status() );
		$this->assertLogged( 'warning', 'Failed to update automatic protection enabled note.' );
		$this->assertSame( AutomaticProtectionEnabledNote::RETIREMENT_PENDING, get_option( AutomaticProtectionEnabledNote::ADDED_OPTION_NAME ) );
		$this->assertArrayHasKey( AutomaticProtectionEnabledNote::ADDED_OPTION_NAME, wp_load_alloptions(), 'The pending retry must be autoloaded' );

		$this->sut->maybe_add_note();

		$this->assertSame( Note::E_WC_ADMIN_NOTE_ACTIONED, Notes::get_note_by_name( AutomaticProtectionEnabledNote::NOTE_NAME )->get_status() );
		$this->assertAdditionRecorded();
	}

	/**
	 * @testdox A retirement that fails with an error is tried again on the next admin request.
	 */
	public function test_failed_retirement_is_tried_again(): void {
		$this->sut->maybe_add_note();
		$unavailable_store = static function () {
			throw new \RuntimeException( 'Note storage unavailable.' );
		};
		add_filter( 'woocommerce_admin-note_data_store', $unavailable_store );
		try {
			$this->setting->set_enabled( false );
		} finally {
			remove_filter( 'woocommerce_admin-note_data_store', $unavailable_store );
		}

		$this->assertLogged( 'warning', 'Failed to update automatic protection enabled note.' );
		$this->assertSame( AutomaticProtectionEnabledNote::RETIREMENT_PENDING, get_option( AutomaticProtectionEnabledNote::ADDED_OPTION_NAME ) );

		$this->sut->maybe_add_note();

		$this->assertSame( Note::E_WC_ADMIN_NOTE_ACTIONED, Notes::get_note_by_name( AutomaticProtectionEnabledNote::NOTE_NAME )->get_status() );
		$this->assertAdditionRecorded();
	}

	/**
	 * @testdox A pending retirement is dropped when protection is on again, and the note stays.
	 */
	public function test_pending_retirement_is_dropped_when_protection_is_on_again(): void {
		$this->sut->maybe_add_note();
		update_option( AutomaticProtectionEnabledNote::ADDED_OPTION_NAME, AutomaticProtectionEnabledNote::RETIREMENT_PENDING );

		$this->sut->maybe_add_note();

		$this->assertSame( Note::E_WC_ADMIN_NOTE_UNACTIONED, Notes::get_note_by_name( AutomaticProtectionEnabledNote::NOTE_NAME )->get_status() );
		$this->assertAdditionRecorded();
	}

	/**
	 * @testdox A pending retirement without a note does not prevent adding the note later.
	 */
	public function test_pending_retirement_without_note_allows_adding_later(): void {
		update_option( AutomaticProtectionEnabledNote::ADDED_OPTION_NAME, AutomaticProtectionEnabledNote::RETIREMENT_PENDING );

		$this->sut->maybe_add_note();

		$this->assertNull( get_option( AutomaticProtectionEnabledNote::ADDED_OPTION_NAME, null ) );
		$this->assertFalse( Notes::get_note_by_name( AutomaticProtectionEnabledNote::NOTE_NAME ) );

		$this->sut->maybe_add_note();

		$this->assertInstanceOf( Note::class, Notes::get_note_by_name( AutomaticProtectionEnabledNote::NOTE_NAME ) );
		$this->assertAdditionRecorded();
	}

	/**
	 * @testdox A failed note lookup is not treated as a missing note when adding it.
	 */
	public function test_failed_lookup_does_not_add_note(): void {
		$this->with_failed_note_lookup( fn() => $this->sut->maybe_add_note() );

		$this->assertSame( array(), Notes::load_data_store()->get_notes_with_name( AutomaticProtectionEnabledNote::NOTE_NAME ) );
		$this->assertLogged( 'warning', 'Failed to add automatic protection enabled note.' );
		$this->assertNull( get_option( AutomaticProtectionEnabledNote::ADDED_OPTION_NAME, null ) );

		$this->sut->maybe_add_note();

		$this->assertCount( 1, Notes::load_data_store()->get_notes_with_name( AutomaticProtectionEnabledNote::NOTE_NAME ) );
		$this->assertAdditionRecorded();
	}

	/**
	 * @testdox A failed note lookup keeps the retirement pending until the note can be read.
	 */
	public function test_failed_lookup_keeps_retirement_pending(): void {
		$this->sut->maybe_add_note();

		$this->with_failed_note_lookup( fn() => $this->setting->set_enabled( false ) );

		$this->assertLogged( 'warning', 'Failed to update automatic protection enabled note.' );
		$this->assertSame( AutomaticProtectionEnabledNote::RETIREMENT_PENDING, get_option( AutomaticProtectionEnabledNote::ADDED_OPTION_NAME ) );

		$this->with_failed_note_lookup( fn() => $this->sut->maybe_add_note() );

		$this->assertSame( AutomaticProtectionEnabledNote::RETIREMENT_PENDING, get_option( AutomaticProtectionEnabledNote::ADDED_OPTION_NAME ), 'A retry that cannot read the note must stay pending' );
		$this->assertSame( Note::E_WC_ADMIN_NOTE_UNACTIONED, Notes::get_note_by_name( AutomaticProtectionEnabledNote::NOTE_NAME )->get_status() );

		$this->sut->maybe_add_note();

		$this->assertSame( Note::E_WC_ADMIN_NOTE_ACTIONED, Notes::get_note_by_name( AutomaticProtectionEnabledNote::NOTE_NAME )->get_status() );
		$this->assertAdditionRecorded();
	}

	/**
	 * Assert that the added note is recorded as an autoloaded option.
	 */
	private function assertAdditionRecorded(): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- PHPUnit style.
		$this->assertSame( 'yes', get_option( AutomaticProtectionEnabledNote::ADDED_OPTION_NAME ) );
		$this->assertArrayHasKey( AutomaticProtectionEnabledNote::ADDED_OPTION_NAME, wp_load_alloptions(), 'The option must be autoloaded' );
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
	 * Return the store to the enabled default with no note.
	 */
	private function reset_state(): void {
		Notes::delete_notes_with_name( AutomaticProtectionEnabledNote::NOTE_NAME );
		delete_option( AutomaticProtectionEnabledNote::ADDED_OPTION_NAME );
		$this->setting->reset();
		$this->gate->reset();
	}
}
