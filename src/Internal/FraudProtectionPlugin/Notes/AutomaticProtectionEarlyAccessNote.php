<?php
/**
 * AutomaticProtectionEarlyAccessNote class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\Notes;

use Automattic\WooCommerce\Admin\Notes\Note;
use Automattic\WooCommerce\Admin\Notes\NoteTraits;
use Automattic\WooCommerce\Admin\Notes\Notes;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\FraudProtectionController;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Settings\AutomaticProtectionSetting;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Settings\MerchantFacingFeaturesGate;

defined( 'ABSPATH' ) || exit;

/**
 * Invites merchants to enable automatic protection during early access.
 */
class AutomaticProtectionEarlyAccessNote {

	use NoteTraits;

	public const NOTE_NAME = 'wc-fraud-protection-automatic-protection-early-access';

	/**
	 * Register note creation and cleanup.
	 */
	public function register(): void {
		add_action( 'admin_init', array( $this, 'maybe_add_note' ) );
		add_action( 'rest_api_init', array( $this, 'update_note' ) );
		add_action( 'add_option_woocommerce_fraud_protection_automatic_protection', array( $this, 'update_note' ) );
		add_action( 'update_option_woocommerce_fraud_protection_automatic_protection', array( $this, 'update_note' ) );
	}

	/**
	 * Add the note once for eligible stores.
	 *
	 * @internal
	 */
	public function maybe_add_note(): void {
		if ( wp_doing_ajax() ) {
			return;
		}

		$this->update_note();

		try {
			self::possibly_add_note();
		} catch ( \Throwable $e ) {
			FraudProtectionController::log( 'warning', 'Failed to create automatic protection early-access note.', array( 'error' => $e->getMessage() ) );
		}
	}

	/**
	 * Complete invitations when protection is enabled, or remove ineligible invitations.
	 *
	 * @internal
	 */
	public function update_note(): void {
		try {
			if ( wc_get_container()->get( AutomaticProtectionSetting::class )->is_enabled() ) {
				$note = Notes::get_note_by_name( self::NOTE_NAME );
				if ( $note instanceof Note && ! $note->get_is_deleted() && Note::E_WC_ADMIN_NOTE_ACTIONED !== $note->get_status() ) {
					$note->set_status( Note::E_WC_ADMIN_NOTE_ACTIONED );
					$note->save();
				}
				return;
			}

			self::delete_if_not_applicable();
		} catch ( \Throwable $e ) {
			FraudProtectionController::log( 'warning', 'Failed to update automatic protection early-access note.', array( 'error' => $e->getMessage() ) );
		}
	}

	/**
	 * Check the existing merchant gate and automatic-protection setting.
	 */
	public static function is_applicable(): bool {
		$container = wc_get_container();
		return $container->get( MerchantFacingFeaturesGate::class )->is_enabled()
			&& ! $container->get( AutomaticProtectionSetting::class )->is_enabled();
	}

	/**
	 * Build the early-access note for stores with automatic protection off.
	 *
	 * @return Note|null
	 */
	public static function get_note(): ?Note {
		if ( ! self::is_applicable() ) {
			return null;
		}

		$note = new Note();
		$note->set_name( self::NOTE_NAME );
		$note->set_source( 'woocommerce-fraud-protection' );
		$note->set_type( Note::E_WC_ADMIN_NOTE_INFORMATIONAL );
		$note->set_title( __( 'Start blocking risky checkout attempts', 'woocommerce-fraud-protection' ) );
		$note->set_content(
			sprintf(
				/* translators: 1: Opening documentation link tag, 2: Closing documentation link tag. */
				__( 'WooCommerce is introducing Fraud Prevention, a new feature that scans checkout attempts for signs of bot or automated behavior. You can turn it on early and try it now, or wait until October 20, when it will be enabled automatically. %1$sLearn more%2$s', 'woocommerce-fraud-protection' ),
				'<a href="' . esc_url( 'https://woocommerce.com/document/fraud-protection/' ) . '">',
				'</a>'
			)
		);
		$note->add_action(
			'review-automatic-protection',
			__( 'Manage in settings', 'woocommerce-fraud-protection' ),
			add_query_arg(
				array(
					'page'   => 'wc-settings',
					'tab'    => 'woocommerce_fraud_protection',
					'source' => 'inbox',
				),
				admin_url( 'admin.php' )
			),
			Note::E_WC_ADMIN_NOTE_UNACTIONED
		);

		return $note;
	}
}
