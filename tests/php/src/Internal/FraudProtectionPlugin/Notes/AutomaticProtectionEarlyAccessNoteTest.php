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
	 * Set up the note and its eligibility settings.
	 */
	public function setUp(): void {
		parent::setUp();
		wc_get_container()->get( MerchantFacingFeaturesGate::class )->set_enabled( true );
		$this->sut = new AutomaticProtectionEarlyAccessNote();
		$this->sut->register();
	}

	/**
	 * @testdox Note creation runs on admin initialization without a REST initialization callback.
	 */
	public function test_registers_admin_callback(): void {
		$this->assertSame( 10, has_action( 'admin_init', array( $this->sut, 'maybe_add_note' ) ) );
		$this->assertFalse( has_action( 'rest_api_init', array( $this->sut, 'update_note' ) ) );
	}

	/**
	 * @testdox Resetting the merchant gate removes an active invitation immediately.
	 */
	public function test_resetting_merchant_gate_removes_note(): void {
		$this->sut->maybe_add_note();
		wc_get_container()->get( MerchantFacingFeaturesGate::class )->reset();
		$this->assertFalse( Notes::get_note_by_name( AutomaticProtectionEarlyAccessNote::NOTE_NAME ) );
	}

	/**
	 * @testdox The invitation uses the approved copy, support link, and standard settings action.
	 */
	public function test_note_content_and_actions(): void {
		$this->sut->maybe_add_note();
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
		$this->sut->maybe_add_note();
		$this->assertFalse( Notes::get_note_by_name( AutomaticProtectionEarlyAccessNote::NOTE_NAME ) );
	}

	/**
	 * @testdox Eligibility changes delete active invitations and allow one new invitation.
	 */
	public function test_eligibility_controls_stored_note(): void {
		$this->sut->maybe_add_note();
		$gate = wc_get_container()->get( MerchantFacingFeaturesGate::class );
		$gate->set_enabled( false );
		$this->assertFalse( Notes::get_note_by_name( AutomaticProtectionEarlyAccessNote::NOTE_NAME ) );
		$gate->set_enabled( true );
		$this->sut->maybe_add_note();
		$this->sut->maybe_add_note();
		$this->assertCount( 1, Notes::load_data_store()->get_notes_with_name( AutomaticProtectionEarlyAccessNote::NOTE_NAME ) );
	}

	/**
	 * @testdox Enabling protection completes the invitation immediately and disabling it does not restore it.
	 * @dataProvider protection_settings
	 * @param bool $existing_setting Whether the setting already exists.
	 */
	public function test_enabling_protection_completes_note( bool $existing_setting ): void {
		$setting = wc_get_container()->get( AutomaticProtectionSetting::class );
		if ( $existing_setting ) {
			$setting->set_enabled( false );
		}
		$this->sut->maybe_add_note();
		$note = Notes::get_note_by_name( AutomaticProtectionEarlyAccessNote::NOTE_NAME );
		$setting->set_enabled( true );
		$this->assertSame( Note::E_WC_ADMIN_NOTE_ACTIONED, Notes::get_note( $note->get_id() )->get_status() );
		$setting->set_enabled( false );
		$this->sut->maybe_add_note();
		$reloaded = Notes::get_note_by_name( AutomaticProtectionEarlyAccessNote::NOTE_NAME );
		$this->assertSame( $note->get_id(), $reloaded->get_id() );
		$this->assertSame( Note::E_WC_ADMIN_NOTE_ACTIONED, $reloaded->get_status() );
		$this->assertCount( 1, Notes::load_data_store()->get_notes_with_name( AutomaticProtectionEarlyAccessNote::NOTE_NAME ) );
	}

	/**
	 * Setting storage states.
	 *
	 * @return array<string, array{bool}>
	 */
	public function protection_settings(): array {
		return array(
			'new setting'      => array( false ),
			'existing setting' => array( true ),
		);
	}

	/**
	 * @testdox Completed invitations survive eligibility changes without being recreated.
	 */
	public function test_actioned_note_is_not_recreated(): void {
		$this->sut->maybe_add_note();
		$note = Notes::get_note_by_name( AutomaticProtectionEarlyAccessNote::NOTE_NAME );
		$note->set_status( Note::E_WC_ADMIN_NOTE_ACTIONED );
		$note->save();
		$gate = wc_get_container()->get( MerchantFacingFeaturesGate::class );
		$gate->set_enabled( false );
		$this->sut->maybe_add_note();
		$gate->set_enabled( true );
		$this->sut->maybe_add_note();
		$reloaded = Notes::get_note_by_name( AutomaticProtectionEarlyAccessNote::NOTE_NAME );
		$this->assertSame( $note->get_id(), $reloaded->get_id() );
		$this->assertSame( Note::E_WC_ADMIN_NOTE_ACTIONED, $reloaded->get_status() );
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
			$this->sut->maybe_add_note();
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
		$this->sut->maybe_add_note();
		$this->assertFalse( Notes::get_note_by_name( AutomaticProtectionEarlyAccessNote::NOTE_NAME ) );
	}

	/**
	 * @testdox Repeated admin requests preserve the same note and its dismissal.
	 */
	public function test_dismissed_note_is_not_recreated(): void {
		$this->sut->maybe_add_note();
		$note = Notes::get_note_by_name( AutomaticProtectionEarlyAccessNote::NOTE_NAME );
		$this->assertInstanceOf( Note::class, $note );
		$id = $note->get_id();
		$this->sut->maybe_add_note();
		$this->assertSame( $id, Notes::get_note_by_name( AutomaticProtectionEarlyAccessNote::NOTE_NAME )->get_id() );
		$note->set_is_deleted( true );
		$note->save();
		$gate = wc_get_container()->get( MerchantFacingFeaturesGate::class );
		$gate->set_enabled( false );
		$this->sut->maybe_add_note();
		$gate->set_enabled( true );
		$this->sut->maybe_add_note();
		$reloaded = Notes::get_note( $id );
		$this->assertTrue( $reloaded->get_is_deleted() );
		$this->assertCount( 1, Notes::load_data_store()->get_notes_with_name( AutomaticProtectionEarlyAccessNote::NOTE_NAME ) );
		$this->assertFalse( wc_get_container()->get( AutomaticProtectionSetting::class )->is_enabled() );
	}
}
