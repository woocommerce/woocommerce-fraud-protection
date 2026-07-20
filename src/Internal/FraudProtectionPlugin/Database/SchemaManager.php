<?php
/**
 * SchemaManager class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\Database;

use Automattic\WooCommerce\Internal\FraudProtectionPlugin\FraudProtectionController;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\MerchantListsFeature;
use Automattic\WooCommerce\Proxies\LegacyProxy;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and upgrades the plugin's database tables.
 *
 * The plugin is deployed as an MU-plugin, so there is no activation hook:
 * schema installation is version-gated and runs from `register()` (WordPress
 * `init`), and only while the merchant lists feature is enabled — sites with
 * the feature off get no tables at all.
 *
 * Migrations must be forward-safe: rollbacks of the plugin are not self-serve
 * on WoA, so a newer schema must keep working under an older plugin version.
 */
class SchemaManager {

	/**
	 * Option holding the currently installed schema version.
	 */
	public const DB_VERSION_OPTION = 'woocommerce_fraud_protection_db_version';

	/**
	 * Schema version written by this build. Bump when the schema changes.
	 */
	public const SCHEMA_VERSION = 1;

	/**
	 * Merchant lists feature gate instance.
	 *
	 * @var MerchantListsFeature
	 */
	private MerchantListsFeature $merchant_lists_feature;

	/**
	 * Legacy proxy instance, used to reach `$wpdb` and `dbDelta()`.
	 *
	 * @var LegacyProxy
	 */
	private LegacyProxy $legacy_proxy;

	/**
	 * Initialize with dependencies.
	 *
	 * @internal
	 *
	 * @param MerchantListsFeature $merchant_lists_feature The merchant lists feature gate instance.
	 * @param LegacyProxy          $legacy_proxy           The legacy proxy instance.
	 */
	final public function init( MerchantListsFeature $merchant_lists_feature, LegacyProxy $legacy_proxy ): void {
		$this->merchant_lists_feature = $merchant_lists_feature;
		$this->legacy_proxy           = $legacy_proxy;
	}

	/**
	 * Install or upgrade the schema if needed. To be run at WordPress `init`.
	 */
	public function register(): void {
		if ( ! $this->merchant_lists_feature->is_enabled() ) {
			return;
		}

		$this->maybe_install_schema();
	}

	/**
	 * Get the name of the sessions table, including the site prefix.
	 *
	 * @return string
	 */
	public function get_sessions_table_name(): string {
		return $this->legacy_proxy->get_global( 'wpdb' )->prefix . 'wc_fraud_protection_sessions';
	}

	/**
	 * Run dbDelta when the stored schema version is older than the current one.
	 *
	 * Fail-open: any failure is logged and the version option is left
	 * untouched, so the next request retries; nothing is thrown.
	 */
	private function maybe_install_schema(): void {
		if ( self::SCHEMA_VERSION === (int) get_option( self::DB_VERSION_OPTION, 0 ) ) {
			return;
		}

		try {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';

			$this->legacy_proxy->call_function( 'dbDelta', $this->get_sessions_table_schema() );

			$wpdb  = $this->legacy_proxy->get_global( 'wpdb' );
			$table = $this->get_sessions_table_name();

			// dbDelta swallows individual query errors, so confirm the table exists
			// before recording the schema version as installed.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
				FraudProtectionController::log(
					'error',
					'Sessions table creation failed: table does not exist after dbDelta.',
					array(
						'event_source' => 'schema_manager',
						'db_error'     => $wpdb->last_error,
					),
					true
				);
				return;
			}

			update_option( self::DB_VERSION_OPTION, self::SCHEMA_VERSION );

			FraudProtectionController::log(
				'info',
				sprintf( 'Database schema installed (version %d).', self::SCHEMA_VERSION )
			);
		} catch ( \Throwable $e ) {
			FraudProtectionController::log(
				'error',
				'Database schema installation failed',
				array(
					'event_source'      => 'schema_manager',
					'exception'         => $e,
					'exception_class'   => $e::class,
					'exception_message' => $e->getMessage(),
					'exception_file'    => $e->getFile(),
					'exception_line'    => $e->getLine(),
				),
				true
			);
		}
	}

	/**
	 * Get the dbDelta schema for the sessions table.
	 *
	 * One row per recorded verify event, plain-inserted: `session_id` is
	 * indexed but not unique (repeated session IDs keep separate rows, and it
	 * is nullable for the rare no-session verify). Enum-valued columns hold
	 * the backing values of string-backed PHP enums (`FraudDecision`,
	 * `SessionFinalStatus`, `SessionTrigger`). The trigger column is named
	 * `trigger_type` because `trigger` is a MySQL reserved word.
	 *
	 * `metadata` is reserved for gateway-supplied per-session data (JSON
	 * object, `LONGTEXT` per WooCommerce core's convention for JSON blobs).
	 * Nothing writes it yet: the collection mechanism will be added later.
	 *
	 * @return string
	 */
	public function get_sessions_table_schema(): string {
		$table   = $this->get_sessions_table_name();
		$collate = $this->legacy_proxy->get_global( 'wpdb' )->get_charset_collate();

		return "CREATE TABLE {$table} (
	id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	session_id VARCHAR(64) NULL,
	recorded_at DATETIME NOT NULL,
	source VARCHAR(32) NOT NULL DEFAULT '',
	decision VARCHAR(16) NOT NULL,
	final_status VARCHAR(32) NOT NULL,
	trigger_type VARCHAR(16) NOT NULL,
	risk_score DOUBLE NULL,
	email VARCHAR(254) NOT NULL DEFAULT '',
	ip VARCHAR(45) NOT NULL DEFAULT '',
	ip_country VARCHAR(2) NOT NULL DEFAULT '',
	billing_country VARCHAR(2) NOT NULL DEFAULT '',
	billing_state VARCHAR(100) NOT NULL DEFAULT '',
	billing_city VARCHAR(100) NOT NULL DEFAULT '',
	billing_postcode VARCHAR(20) NOT NULL DEFAULT '',
	billing_name VARCHAR(255) NOT NULL DEFAULT '',
	order_id BIGINT UNSIGNED NULL,
	payment_method VARCHAR(64) NOT NULL DEFAULT '',
	metadata LONGTEXT NULL,
	reported_at DATETIME NULL,
	PRIMARY KEY  (id),
	KEY session_id (session_id),
	KEY email (email),
	KEY recorded_at (recorded_at)
) {$collate};";
	}
}
