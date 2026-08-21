<?php
/**
 * FraudProtectionCommands class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\CLI;

use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Database\SchemaManager;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Sessions\SessionEventPruner;
use Automattic\WooCommerce\Proxies\LegacyProxy;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Registers Fraud Protection diagnostic and maintenance commands.
 */
class FraudProtectionCommands {

	/**
	 * Schema manager instance.
	 *
	 * @var SchemaManager
	 */
	private SchemaManager $schema_manager;

	/**
	 * Session pruner instance.
	 *
	 * @var SessionEventPruner
	 */
	private SessionEventPruner $session_event_pruner;

	/**
	 * Legacy proxy instance.
	 *
	 * @var LegacyProxy
	 */
	private LegacyProxy $legacy_proxy;

	/**
	 * Initialize with dependencies.
	 *
	 * @internal
	 *
	 * @param SchemaManager      $schema_manager       The schema manager instance.
	 * @param SessionEventPruner $session_event_pruner The session pruner instance.
	 * @param LegacyProxy        $legacy_proxy         The legacy proxy instance.
	 */
	final public function init( SchemaManager $schema_manager, SessionEventPruner $session_event_pruner, LegacyProxy $legacy_proxy ): void {
		$this->schema_manager       = $schema_manager;
		$this->session_event_pruner = $session_event_pruner;
		$this->legacy_proxy         = $legacy_proxy;
	}

	/**
	 * Register commands after WordPress finishes loading.
	 */
	public function register(): void {
		$this->legacy_proxy->call_static( WP_CLI::class, 'add_hook', 'after_wp_load', array( $this, 'register_commands' ) );
	}

	/**
	 * Register the supported command leaves.
	 *
	 * @internal
	 */
	public function register_commands(): void {
		$this->legacy_proxy->call_static(
			WP_CLI::class,
			'add_command',
			'wc fraud-protection status',
			array( $this, 'status' ),
			array( 'shortdesc' => __( 'Show Fraud Protection status.', 'woocommerce-fraud-protection' ) )
		);
		$this->legacy_proxy->call_static(
			WP_CLI::class,
			'add_command',
			'wc fraud-protection database install',
			array( $this, 'database_install' ),
			array( 'shortdesc' => __( 'Install or repair the Fraud Protection database schema.', 'woocommerce-fraud-protection' ) )
		);
		$this->legacy_proxy->call_static(
			WP_CLI::class,
			'add_command',
			'wc fraud-protection sessions prune',
			array( $this, 'sessions_prune' ),
			array( 'shortdesc' => __( 'Prune expired Fraud Protection sessions.', 'woocommerce-fraud-protection' ) )
		);
	}

	/**
	 * Show local Fraud Protection status.
	 *
	 * @internal
	 */
	public function status(): void {
		global $wpdb;

		$schema_status = $this->schema_manager->get_schema_status();
		$install_state = $schema_status['install_state'];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$database_defaults = $wpdb->get_row( 'SELECT @@character_set_database AS charset, @@collation_database AS collation', ARRAY_A );
		$database_defaults = is_array( $database_defaults ) ? $database_defaults : array();

		$this->write_line( __( 'Plugin version', 'woocommerce-fraud-protection' ), defined( 'WC_FRAUD_PROTECTION_VERSION' ) ? (string) WC_FRAUD_PROTECTION_VERSION : __( 'Unknown', 'woocommerce-fraud-protection' ) );
		/** Filter learning mode for local status output. */
		$learning_mode = (bool) apply_filters( 'woocommerce_fraud_protection_learning_mode', true );

		$this->write_line( __( 'Learning mode', 'woocommerce-fraud-protection' ), $learning_mode ? __( 'Enabled', 'woocommerce-fraud-protection' ) : __( 'Disabled', 'woocommerce-fraud-protection' ) );
		$this->write_line( __( 'Jetpack blog ID', 'woocommerce-fraud-protection' ), self::value_or_unavailable( $this->get_jetpack_blog_id() ) );
		$this->write_line( __( 'Required schema version', 'woocommerce-fraud-protection' ), (string) $schema_status['required_version'] );
		$this->write_line( __( 'Installed schema version', 'woocommerce-fraud-protection' ), (string) $schema_status['installed_version'] );
		if ( '' !== $install_state['last_error'] ) {
			$this->write_line( __( 'Schema installation attempts', 'woocommerce-fraud-protection' ), (string) $install_state['attempts'] );
			$this->write_line( __( 'Last schema installation attempt', 'woocommerce-fraud-protection' ), $this->format_timestamp( $install_state['last_attempt'], __( 'Unknown', 'woocommerce-fraud-protection' ) ) );
			$this->write_line( __( 'Last schema installation error', 'woocommerce-fraud-protection' ), $install_state['last_error'] );
		}

		$table_names   = array_column( $schema_status['tables'], 'name' );
		$missing_names = array_column( array_filter( $schema_status['tables'], fn( array $table ): bool => ! $table['exists'] ), 'name' );
		$this->write_line( __( 'Tables', 'woocommerce-fraud-protection' ), implode( ', ', $table_names ) );
		if ( array() !== $missing_names ) {
			$this->write_line( __( 'Missing tables', 'woocommerce-fraud-protection' ), implode( ', ', $missing_names ) );
		}

		$this->write_line( __( 'WordPress database charset', 'woocommerce-fraud-protection' ), self::value_or_unavailable( $wpdb->charset ?? null ) );
		$this->write_line( __( 'WordPress database collation', 'woocommerce-fraud-protection' ), self::value_or_unavailable( $wpdb->collate ?? null ) );
		$this->write_line( __( 'Database default charset', 'woocommerce-fraud-protection' ), self::value_or_unavailable( $database_defaults['charset'] ?? null ) );
		$this->write_line( __( 'Database default collation', 'woocommerce-fraud-protection' ), self::value_or_unavailable( $database_defaults['collation'] ?? null ) );
		$next_pruning_action = $this->session_event_pruner->get_next_scheduled_action();
		$this->write_line( __( 'Next session pruning action', 'woocommerce-fraud-protection' ), true === $next_pruning_action ? __( 'In progress', 'woocommerce-fraud-protection' ) : $this->format_timestamp( $next_pruning_action, __( 'Not scheduled', 'woocommerce-fraud-protection' ) ) );
	}

	/**
	 * Install or repair the Fraud Protection schema.
	 *
	 * @internal
	 */
	public function database_install(): void {
		$status = $this->schema_manager->get_schema_status();

		if ( $status['installed_version'] > $status['required_version'] ) {
			$this->legacy_proxy->call_static(
				WP_CLI::class,
				'error',
				sprintf(
					/* translators: 1: Installed schema version, 2: Required schema version. */
					__( 'Fraud Protection database schema version %1$d is newer than this plugin requires (%2$d). Use the matching or a newer plugin version to repair it.', 'woocommerce-fraud-protection' ),
					$status['installed_version'],
					$status['required_version']
				)
			);
			return;
		}

		if ( $status['complete'] ) {
			$this->legacy_proxy->call_static( WP_CLI::class, 'success', __( 'Fraud Protection database schema is already current.', 'woocommerce-fraud-protection' ) );
			return;
		}

		$this->schema_manager->maybe_install_schema( true );
		$status = $this->schema_manager->get_schema_status();

		if ( $status['complete'] ) {
			$this->legacy_proxy->call_static( WP_CLI::class, 'success', __( 'Fraud Protection database schema installed successfully.', 'woocommerce-fraud-protection' ) );
			return;
		}

		$error = $status['install_state']['last_error'];
		$this->legacy_proxy->call_static(
			WP_CLI::class,
			'error',
			'' !== $error
				? sprintf(
					/* translators: %s: Last schema installation error. */
					__( 'Fraud Protection database schema installation failed. %s', 'woocommerce-fraud-protection' ),
					$error
				)
				: __( 'Fraud Protection database schema installation did not complete.', 'woocommerce-fraud-protection' )
		);
	}

	/**
	 * Prune expired Fraud Protection sessions.
	 *
	 * @internal
	 */
	public function sessions_prune(): void {
		$status = $this->schema_manager->get_schema_status();

		if ( ! $status['complete'] ) {
			$this->legacy_proxy->call_static( WP_CLI::class, 'error', __( 'Fraud Protection sessions cannot be pruned because the database schema is incomplete.', 'woocommerce-fraud-protection' ) );
			return;
		}

		try {
			$deleted = $this->session_event_pruner->prune_sessions();
		} catch ( \RuntimeException $e ) {
			$this->legacy_proxy->call_static( WP_CLI::class, 'error', $e );
			return;
		}

		$this->legacy_proxy->call_static(
			WP_CLI::class,
			'success',
			sprintf(
				/* translators: %d: Number of deleted session rows. */
				_n( 'Pruned %d expired Fraud Protection session.', 'Pruned %d expired Fraud Protection sessions.', $deleted, 'woocommerce-fraud-protection' ),
				$deleted
			)
		);
	}

	/**
	 * Get the Jetpack blog ID.
	 *
	 * @return int|false
	 */
	private function get_jetpack_blog_id(): int|false {
		if ( ! class_exists( 'Jetpack_Options' ) ) {
			return false;
		}

		$blog_id = \Jetpack_Options::get_option( 'id' );

		return is_numeric( $blog_id ) && (int) $blog_id > 0 ? (int) $blog_id : false;
	}

	/**
	 * Format a status timestamp in UTC.
	 *
	 * @param int|false $timestamp The Unix timestamp.
	 * @param string    $fallback  Text used when no timestamp is available.
	 * @return string
	 */
	private static function format_timestamp( int|false $timestamp, string $fallback ): string {
		return false !== $timestamp && $timestamp > 0 ? gmdate( 'Y-m-d H:i:s \U\T\C', $timestamp ) : $fallback;
	}

	/**
	 * Format a local value or an unavailable label.
	 *
	 * @param mixed $value The local value.
	 * @return string
	 */
	private static function value_or_unavailable( $value ): string {
		return is_scalar( $value ) && '' !== (string) $value ? (string) $value : __( 'Unavailable', 'woocommerce-fraud-protection' );
	}

	/**
	 * Write one labeled status line.
	 *
	 * @param string $label The status label.
	 * @param string $value The status value.
	 */
	private function write_line( string $label, string $value ): void {
		$this->legacy_proxy->call_static(
			WP_CLI::class,
			'line',
			sprintf(
				/* translators: 1: Status label, 2: Status value. */
				__( '%1$s: %2$s', 'woocommerce-fraud-protection' ),
				$label,
				$value
			)
		);
	}
}
