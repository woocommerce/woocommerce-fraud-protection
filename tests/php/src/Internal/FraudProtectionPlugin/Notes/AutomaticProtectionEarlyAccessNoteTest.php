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
 * Tests the early-access Inbox invitation.
 */
class AutomaticProtectionEarlyAccessNoteTest extends FraudProtectionUnitTestCase {

	/**
	 * The System Under Test.
	 *
	 * @var AutomaticProtectionEarlyAccessNote
	 */
	private $sut;

	/**
	 * Original admin-header callback priority.
	 *
	 * @var int|false
	 */
	private $admin_headers_priority;

	/**
	 * Set up the note through its registered hook.
	 */
	public function setUp(): void {
		parent::setUp();
		set_current_screen( 'dashboard' );
		$this->admin_headers_priority = has_action( 'admin_init', 'wp_admin_headers' );
		if ( false !== $this->admin_headers_priority ) {
			remove_action( 'admin_init', 'wp_admin_headers', $this->admin_headers_priority );
		}
		AutomaticProtectionEarlyAccessNote::possibly_delete_note();
		wc_get_container()->get( AutomaticProtectionSetting::class )->reset();
		wc_get_container()->get( MerchantFacingFeaturesGate::class )->set_enabled( true );
		$this->sut = new AutomaticProtectionEarlyAccessNote();
		$this->sut->register();
	}

	/**
	 * Remove test notes, options, and hooks.
	 */
	public function tearDown(): void {
		remove_action( 'admin_init', array( $this->sut, 'handle_admin_init' ) );
		remove_filter( 'wp_doing_ajax', '__return_true' );
		remove_filter( 'woocommerce_note_where_clauses', array( $this->sut, 'filter_visible_notes' ) );
		wc_get_container()->get( MerchantFacingFeaturesGate::class )->reset();
		AutomaticProtectionEarlyAccessNote::possibly_delete_note();
		wc_get_container()->get( AutomaticProtectionSetting::class )->reset();
		if ( false !== $this->admin_headers_priority ) {
			add_action( 'admin_init', 'wp_admin_headers', $this->admin_headers_priority );
		}
		set_current_screen( 'front' );
		parent::tearDown();
	}

	/**
	 * @testdox The invitation uses the approved copy, support link, and standard settings action.
	 */
	public function test_note_content_and_actions(): void {
		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Exercise the WordPress admin hook.
		do_action( 'admin_init' );
		$note = Notes::get_note_by_name( AutomaticProtectionEarlyAccessNote::NOTE_NAME );
		$this->assertInstanceOf( Note::class, $note );
		$this->assertSame( 'wc-fraud-protection-automatic-protection-early-access', $note->get_name() );
		$this->assertSame( 'woocommerce-fraud-protection', $note->get_source() );
		$this->assertSame( Note::E_WC_ADMIN_NOTE_INFORMATIONAL, $note->get_type() );
		$this->assertSame( 'Start blocking risky checkout attempts', $note->get_title() );
		$this->assertSame( 'WooCommerce is introducing Fraud Prevention, a new feature that scans checkout attempts for signs of bot or automated behavior. You can turn it on early and try it now, or wait until October 20, when it will be enabled automatically. <a href="https://woocommerce.com/document/fraud-protection/">Learn more</a>', $note->get_content() );
		$this->assertLessThanOrEqual( 320, mb_strlen( wp_strip_all_tags( $note->get_content() ) ) );
		$actions = $note->get_actions();
		$this->assertCount( 1, $actions );
		$this->assertSame( 'review-automatic-protection', $actions[0]->name );
		$this->assertSame( 'Manage in settings', $actions[0]->label );
		$this->assertSame( admin_url( 'admin.php?page=wc-settings&tab=woocommerce_fraud_protection&source=inbox' ), $actions[0]->query );
		Notes::trigger_note_action( $note, $actions[0] );
		$reloaded = Notes::get_note( $note->get_id() );
		$this->assertSame( Note::E_WC_ADMIN_NOTE_UNACTIONED, $reloaded->get_status() );
		$this->assertFalse( $reloaded->get_is_deleted() );
	}

	/**
	 * @testdox Stores that already enabled automatic protection do not receive an invitation.
	 */
	public function test_enabled_stores_do_not_receive_note(): void {
		wc_get_container()->get( AutomaticProtectionSetting::class )->set_enabled( true );
		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Exercise the WordPress admin hook.
		do_action( 'admin_init' );
		$this->assertFalse( Notes::get_note_by_name( AutomaticProtectionEarlyAccessNote::NOTE_NAME ) );
	}

	/**
	 * @testdox Gate changes hide an actioned invitation without deleting it.
	 */
	public function test_gate_controls_stored_note_visibility(): void {
		$this->sut->handle_admin_init();
		$note = Notes::get_note_by_name( AutomaticProtectionEarlyAccessNote::NOTE_NAME );
		$this->assertInstanceOf( Note::class, $note );
		$note->set_status( Note::E_WC_ADMIN_NOTE_ACTIONED );
		$note->save();
		$gate = wc_get_container()->get( MerchantFacingFeaturesGate::class );
		$gate->set_enabled( false );
		$this->assertNull( AutomaticProtectionEarlyAccessNote::get_note() );
		$this->assertEmpty( Notes::get_notes( 'view', array( 'source' => array( 'woocommerce-fraud-protection' ) ) ) );
		$gate->set_enabled( true );
		$this->assertCount( 1, Notes::get_notes( 'view', array( 'source' => array( 'woocommerce-fraud-protection' ) ) ) );
		wc_get_container()->get( AutomaticProtectionSetting::class )->set_enabled( true );
		$this->assertEmpty( Notes::get_notes( 'view', array( 'source' => array( 'woocommerce-fraud-protection' ) ) ) );
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
			$this->sut->handle_admin_init();
			$this->assertLogged( 'warning', 'Failed to create automatic protection early-access note.' );
		} finally {
			remove_filter( 'woocommerce_admin-note_data_store', $unavailable_store );
		}
	}

	/**
	 * @testdox AJAX requests do not create the note.
	 */
	public function test_ajax_does_not_create_note(): void {
		add_filter( 'wp_doing_ajax', '__return_true' );
		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Exercise the WordPress admin hook.
		do_action( 'admin_init' );
		$this->assertFalse( Notes::get_note_by_name( AutomaticProtectionEarlyAccessNote::NOTE_NAME ) );
	}

	/**
	 * @testdox Repeated admin requests preserve the same note and its dismissal.
	 */
	public function test_dismissed_note_is_not_recreated(): void {
		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Exercise the WordPress admin hook.
		do_action( 'admin_init' );
		$note = Notes::get_note_by_name( AutomaticProtectionEarlyAccessNote::NOTE_NAME );
		$this->assertInstanceOf( Note::class, $note );
		$id = $note->get_id();
		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Exercise the WordPress admin hook.
		do_action( 'admin_init' );
		$this->assertSame( $id, Notes::get_note_by_name( AutomaticProtectionEarlyAccessNote::NOTE_NAME )->get_id() );
		$note->set_is_deleted( true );
		$note->save();
		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Exercise the WordPress admin hook.
		do_action( 'admin_init' );
		$reloaded = Notes::get_note( $id );
		$this->assertTrue( $reloaded->get_is_deleted() );
		$this->assertCount( 1, Notes::load_data_store()->get_notes_with_name( AutomaticProtectionEarlyAccessNote::NOTE_NAME ) );
		$this->assertFalse( wc_get_container()->get( AutomaticProtectionSetting::class )->is_enabled() );
	}
}
