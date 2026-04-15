<?php
/**
 * IntroInboxNoteTest class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\FraudProtection\Notes;

use Automattic\WooCommerce\Admin\Notes\Note;
use Automattic\WooCommerce\Admin\Notes\Notes;
use WC_Unit_Test_Case;

/**
 * Tests for IntroInboxNote.
 *
 * @covers \Automattic\WooCommerce\FraudProtection\Notes\IntroInboxNote
 */
class IntroInboxNoteTest extends WC_Unit_Test_Case {

	/**
	 * The System Under Test.
	 *
	 * @var IntroInboxNote
	 */
	private IntroInboxNote $sut;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->delete_intro_notes();

		$this->sut = new IntroInboxNote();
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		remove_all_actions( 'admin_init' );
		remove_all_filters( 'wp_doing_ajax' );
		$this->delete_intro_notes();

		parent::tearDown();
	}

	/**
	 * @testdox register should hook maybe_create_note into admin_init.
	 */
	public function test_register_hooks_admin_init(): void {
		$this->sut->register();

		$this->assertNotFalse(
			has_action( 'admin_init', array( $this->sut, 'maybe_create_note' ) ),
			'Should register admin_init action'
		);
	}

	/**
	 * @testdox Creates the intro note on first run.
	 */
	public function test_creates_note_on_first_run(): void {
		$this->sut->maybe_create_note();

		$note = Notes::get_note_by_name( IntroInboxNote::NOTE_NAME );
		$this->assertInstanceOf( Note::class, $note );
		$this->assertSame( 'woocommerce-fraud-protection', $note->get_source() );
		$this->assertSame( Note::E_WC_ADMIN_NOTE_INFORMATIONAL, $note->get_type() );
		$this->assertStringContainsString( "learning your store's patterns", $note->get_title() );
		$this->assertStringContainsString( 'no orders will be blocked at this point', $note->get_content() );
	}

	/**
	 * @testdox Created note includes a Learn more action pointing at the WooCommerce.com support doc.
	 */
	public function test_note_has_learn_more_action(): void {
		$this->sut->maybe_create_note();

		$note = Notes::get_note_by_name( IntroInboxNote::NOTE_NAME );
		$this->assertInstanceOf( Note::class, $note );

		$actions = $note->get_actions();
		$this->assertCount( 1, $actions );

		$action = $actions[0];
		$this->assertSame( 'learn-more', $action->name );
		$this->assertSame( 'Learn more', $action->label );
		$this->assertSame( 'https://woocommerce.com/document/fraud-protection/', $action->query );
	}

	/**
	 * @testdox Running maybe_create_note twice only persists one note row.
	 */
	public function test_is_idempotent(): void {
		$this->sut->maybe_create_note();
		$this->sut->maybe_create_note();

		$this->assertCount( 1, $this->get_intro_note_ids() );
	}

	/**
	 * @testdox A dismissed note is not recreated on subsequent runs.
	 *
	 * This is the behavioural contract we rely on in place of a "new vs existing
	 * store" detection: NoteTraits::note_exists() looks up rows by name without
	 * filtering on is_deleted, so once a merchant dismisses the note it stays
	 * dismissed across admin reloads.
	 */
	public function test_does_not_recreate_after_dismissal(): void {
		$this->sut->maybe_create_note();

		$note = Notes::get_note_by_name( IntroInboxNote::NOTE_NAME );
		$this->assertInstanceOf( Note::class, $note );
		$note->set_is_deleted( true );
		$note->save();

		$this->sut->maybe_create_note();

		$note_ids = $this->get_intro_note_ids();
		$this->assertCount( 1, $note_ids );

		$reloaded = Notes::get_note( $note_ids[0] );
		$this->assertInstanceOf( Note::class, $reloaded );
		$this->assertTrue( (bool) $reloaded->get_is_deleted(), 'Dismissed note should remain dismissed' );
	}

	/**
	 * @testdox Skips note creation on AJAX requests to avoid the admin AJAX hot path.
	 */
	public function test_skips_on_ajax(): void {
		add_filter( 'wp_doing_ajax', '__return_true' );

		$this->sut->maybe_create_note();

		$this->assertSame( false, Notes::get_note_by_name( IntroInboxNote::NOTE_NAME ) );
	}

	/**
	 * Hard-delete any intro notes in the DB so tests start from a clean slate.
	 */
	private function delete_intro_notes(): void {
		$data_store = Notes::load_data_store();
		foreach ( $this->get_intro_note_ids() as $note_id ) {
			$note = Notes::get_note( $note_id );
			if ( $note instanceof Note ) {
				$data_store->delete( $note );
			}
		}
	}

	/**
	 * Get the raw note IDs for the intro note, including dismissed ones.
	 *
	 * @return array<int, int>
	 */
	private function get_intro_note_ids(): array {
		$data_store = Notes::load_data_store();
		return (array) $data_store->get_notes_with_name( IntroInboxNote::NOTE_NAME ); // @phpstan-ignore method.notFound
	}
}
