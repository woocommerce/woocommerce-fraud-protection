<?php
/**
 * AutomaticProtectionEnabledNote class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\Notes;

use Automattic\WooCommerce\Admin\Notes\Note;
use Automattic\WooCommerce\Admin\Notes\NoteTraits;
use Automattic\WooCommerce\Admin\Notes\Notes;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\FraudProtectionController;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Settings\AutomaticProtectionSetting;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Settings\MerchantFacingFeaturesGate;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Settings\SettingStatus;

defined( 'ABSPATH' ) || exit;

/**
 * Tells merchants that automatic protection was turned on by default.
 */
class AutomaticProtectionEnabledNote {

	use NoteTraits;

	public const NOTE_NAME = 'wc-fraud-protection-automatic-protection-enabled';

	public const ADDED_OPTION_NAME = 'woocommerce_fraud_protection_enabled_note_added';

	/**
	 * Register note creation and retirement.
	 */
	public function register(): void {
		add_action( 'admin_init', array( $this, 'maybe_add_note' ) );
		foreach ( array( 'woocommerce_fraud_protection_automatic_protection', 'woocommerce_fraud_protection_merchant_facing_features' ) as $option ) {
			foreach ( array( 'add_option_', 'update_option_', 'delete_option_' ) as $hook ) {
				add_action( $hook . $option, array( $this, 'update_note' ) );
			}
		}
	}

	/**
	 * Add the note once for stores that follow the enabled default.
	 *
	 * The note is added once per site. A failed or unconfirmed write runs again on the next admin request.
	 *
	 * @internal
	 */
	public function maybe_add_note(): void {
		if ( wp_doing_ajax() || 'yes' === get_option( self::ADDED_OPTION_NAME ) || ! self::is_applicable() ) {
			return;
		}

		try {
			self::possibly_add_note();

			// The note store does not report failed writes, so confirm the note exists
			// before recording it.
			if ( ! self::note_exists() ) {
				FraudProtectionController::log( 'warning', 'Failed to add automatic protection enabled note.', array( 'error' => 'The note was not stored.' ) );
				return;
			}

			// Autoloaded so later admin requests skip the check without a query.
			update_option( self::ADDED_OPTION_NAME, 'yes', true );
		} catch ( \Throwable $e ) {
			FraudProtectionController::log( 'warning', 'Failed to add automatic protection enabled note.', array( 'error' => $e->getMessage() ) );
		}
	}

	/**
	 * Retire the note when automatic protection is turned off or the settings page is hidden.
	 *
	 * @internal
	 */
	public function update_note(): void {
		try {
			$container = wc_get_container();
			if ( $container->get( MerchantFacingFeaturesGate::class )->is_enabled() && $container->get( AutomaticProtectionSetting::class )->is_enabled() ) {
				return;
			}

			$note = Notes::get_note_by_name( self::NOTE_NAME );
			if ( $note instanceof Note && ! $note->get_is_deleted() && Note::E_WC_ADMIN_NOTE_ACTIONED !== $note->get_status() ) {
				$note->set_status( Note::E_WC_ADMIN_NOTE_ACTIONED );
				$note->save();
			}
		} catch ( \Throwable $e ) {
			FraudProtectionController::log( 'warning', 'Failed to update automatic protection enabled note.', array( 'error' => $e->getMessage() ) );
		}
	}

	/**
	 * Check that the store follows the enabled default and can open the settings page.
	 */
	public static function is_applicable(): bool {
		$container = wc_get_container();
		return $container->get( MerchantFacingFeaturesGate::class )->is_enabled()
			&& SettingStatus::DefaultEnabled === $container->get( AutomaticProtectionSetting::class )->get_status();
	}

	/**
	 * Build the note.
	 */
	public static function get_note(): Note {
		$note = new Note();
		$note->set_name( self::NOTE_NAME );
		$note->set_source( 'woocommerce-fraud-protection' );
		$note->set_type( Note::E_WC_ADMIN_NOTE_INFORMATIONAL );
		$note->set_title( __( 'Fraud prevention is now blocking suspicious payments', 'woocommerce-fraud-protection' ) );
		$note->set_content(
			sprintf(
				/* translators: 1: Opening documentation link tag, 2: Closing documentation link tag. */
				__( 'As of October 20, automatic blocking is on by default. It stops payment attempts flagged as suspicious before they go through. You can review your payment activity or turn off automatic blocking at any time in your fraud prevention settings. %1$sLearn more%2$s', 'woocommerce-fraud-protection' ),
				'<a href="' . esc_url( 'https://woocommerce.com/document/fraud-protection/' ) . '" target="_blank" rel="noopener noreferrer">',
				'</a>'
			)
		);
		$note->add_action(
			'manage-automatic-protection',
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
