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
		$this->sut = new AutomaticProtectionEarlyAccessNote();
		$this->sut->register();
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		remove_all_filters( 'wp_doing_ajax' );
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
	}

	/**
	 * @testdox AJAX requests do not change the invitation.
	 */
	public function test_ajax_does_not_retire_note(): void {
		$note = $this->create_note( Note::E_WC_ADMIN_NOTE_UNACTIONED );
		add_filter( 'wp_doing_ajax', '__return_true' );

		$this->sut->retire_note();

		$this->assertSame( Note::E_WC_ADMIN_NOTE_UNACTIONED, Notes::get_note( $note->get_id() )->get_status() );
	}

	/**
	 * @testdox An unavailable note store does not interrupt the admin request.
	 */
	public function test_unavailable_note_store_is_logged(): void {
		$unavailable_store = static function () {
			throw new \RuntimeException( 'Note storage unavailable.' );
		};
		add_filter( 'woocommerce_admin-note_data_store', $unavailable_store );
		try {
			$this->sut->retire_note();
			$this->assertLogged( 'warning', 'Failed to retire automatic protection early-access note.' );
		} finally {
			remove_filter( 'woocommerce_admin-note_data_store', $unavailable_store );
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
