<?php
/**
 * AutomaticProtectionEarlyAccessNote class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\Notes;

use Automattic\WooCommerce\Admin\Notes\Note;
use Automattic\WooCommerce\Admin\Notes\NoteTraits;
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
	 * Register note creation.
	 */
	public function register(): void {
		add_action( 'admin_init', array( $this, 'handle_admin_init' ) );
		add_filter( 'woocommerce_note_where_clauses', array( $this, 'filter_visible_notes' ) );
	}

	/**
	 * Add the note once for eligible stores.
	 *
	 * @internal
	 */
	public function handle_admin_init(): void {
		if ( wp_doing_ajax() ) {
			return;
		}

		try {
			self::possibly_add_note();
		} catch ( \Throwable $e ) {
			FraudProtectionController::log( 'warning', 'Failed to create automatic protection early-access note.', array( 'error' => $e->getMessage() ) );
		}
	}

	/**
	 * Keep stored invitations out of the Inbox when the store is no longer eligible.
	 *
	 * @internal
	 *
	 * @param mixed $where_clauses Existing note query conditions.
	 * @return mixed
	 */
	public function filter_visible_notes( $where_clauses ) {
		if ( ! is_string( $where_clauses ) || self::is_applicable() ) {
			return $where_clauses;
		}

		global $wpdb;
		return $where_clauses . $wpdb->prepare( ' AND name <> %s', self::NOTE_NAME );
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
		$content = __( "Fraud prevention scans checkout attempts on every supported payment gateway for signs of bot or automated behavior. Flagged attempts are only recorded. You can turn on blocking today, or do nothing and it will turn on automatically on October 20. If you'd rather keep recording only, opt out before then.", 'woocommerce-fraud-protection' );
		$note->set_content( $content . ' <a href="' . esc_url( 'https://woocommerce.com/document/fraud-protection/' ) . '">' . esc_html__( 'Learn more', 'woocommerce-fraud-protection' ) . '</a>' );
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
