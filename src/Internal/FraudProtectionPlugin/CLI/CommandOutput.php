<?php
/**
 * CommandOutput class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\CLI;

use Automattic\WooCommerce\Proxies\LegacyProxy;
use WP_CLI;
use WP_CLI\Formatter;

defined( 'ABSPATH' ) || exit;

/**
 * Shared WP-CLI input parsing and output for the Fraud Protection data commands.
 *
 * WP-CLI is reached through the legacy proxy so tests can intercept it.
 */
class CommandOutput {

	/**
	 * Output formats accepted by list commands.
	 */
	public const LIST_FORMATS = array( 'table', 'csv', 'json', 'yaml', 'ids', 'count' );

	/**
	 * Output formats accepted by single-item commands.
	 */
	public const ITEM_FORMATS = array( 'table', 'json', 'yaml' );

	/**
	 * Default number of items listed.
	 */
	public const DEFAULT_LIMIT = 20;

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
	 * @param LegacyProxy $legacy_proxy The legacy proxy instance.
	 */
	final public function init( LegacyProxy $legacy_proxy ): void {
		$this->legacy_proxy = $legacy_proxy;
	}

	/**
	 * Register a command leaf.
	 *
	 * @param string   $name      Command name, without the `wp` prefix.
	 * @param callable $callback  Command callback.
	 * @param string   $shortdesc Short description.
	 * @param array    $synopsis  Synopsis definition, see `WP_CLI::add_command()`.
	 * @param string   $longdesc  Additional description, such as examples.
	 */
	public function add_command( string $name, callable $callback, string $shortdesc, array $synopsis = array(), string $longdesc = '' ): void {
		$args = array(
			'shortdesc' => $shortdesc,
			'synopsis'  => $synopsis,
		);
		if ( '' !== $longdesc ) {
			$args['longdesc'] = $longdesc;
		}

		$this->legacy_proxy->call_static( WP_CLI::class, 'add_command', $name, $callback, $args );
	}

	/**
	 * Print an error and stop the command.
	 *
	 * WP-CLI exits on error. Callers still return right after calling this.
	 *
	 * @param string $message The message.
	 */
	public function error( string $message ): void {
		$this->legacy_proxy->call_static( WP_CLI::class, 'error', $message );
	}

	/**
	 * Print a warning.
	 *
	 * @param string $message The message.
	 */
	public function warning( string $message ): void {
		$this->legacy_proxy->call_static( WP_CLI::class, 'warning', $message );
	}

	/**
	 * Print a success message.
	 *
	 * @param string $message The message.
	 */
	public function success( string $message ): void {
		$this->legacy_proxy->call_static( WP_CLI::class, 'success', $message );
	}

	/**
	 * Print a line.
	 *
	 * @param string $message The line.
	 */
	public function line( string $message ): void {
		$this->legacy_proxy->call_static( WP_CLI::class, 'line', $message );
	}

	/**
	 * Ask for confirmation, unless `--yes` was given. WP-CLI exits when the answer is not yes.
	 *
	 * @param string $question   The question.
	 * @param array  $assoc_args Command associative arguments.
	 */
	public function confirm( string $question, array $assoc_args ): void {
		$this->legacy_proxy->call_static( WP_CLI::class, 'confirm', $question, $assoc_args );
	}

	/**
	 * Display a list of items in the requested format.
	 *
	 * @param array                            $assoc_args     Command associative arguments.
	 * @param array<int, array<string, mixed>> $items          Items, each with an `id` key.
	 * @param int                              $total          Total matching items, ignoring the limit.
	 * @param string[]                         $default_fields Fields shown when `--fields` is not given.
	 */
	public function display_list( array $assoc_args, array $items, int $total, array $default_fields ): void {
		$format = $assoc_args['format'] ?? 'table';

		if ( 'count' === $format ) {
			$this->line( (string) $total );
			return;
		}

		if ( 'ids' === $format ) {
			$this->line( implode( ' ', array_column( $items, 'id' ) ) );
			return;
		}

		$this->get_formatter( $assoc_args, $default_fields )->display_items( $items );
	}

	/**
	 * Display one item in the requested format.
	 *
	 * @param array                $assoc_args Command associative arguments.
	 * @param array<string, mixed> $item       The item.
	 */
	public function display_item( array $assoc_args, array $item ): void {
		$this->get_formatter( $assoc_args, array_keys( $item ) )->display_item( $item );
	}

	/**
	 * Read the `--limit` argument.
	 *
	 * @param array $assoc_args Command associative arguments.
	 * @return ?int The limit, or null after reporting an invalid value.
	 */
	public function get_limit( array $assoc_args ): ?int {
		$limit = self::positive_int( $assoc_args['limit'] ?? self::DEFAULT_LIMIT );
		if ( is_null( $limit ) ) {
			$this->error( __( 'The limit must be a positive whole number.', 'woocommerce-fraud-protection' ) );
		}

		return $limit;
	}

	/**
	 * Parse a positive integer argument.
	 *
	 * @param mixed $value The argument value.
	 * @return ?int The integer, or null when the value is not a positive whole number.
	 */
	public static function positive_int( $value ): ?int {
		if ( is_int( $value ) ) {
			return $value > 0 ? $value : null;
		}

		return is_string( $value ) && 1 === preg_match( '/^[1-9][0-9]{0,9}$/', $value ) ? (int) $value : null;
	}

	/**
	 * Split a comma-separated argument into its non-empty values.
	 *
	 * @param mixed $value The argument value.
	 * @return string[]
	 */
	public static function comma_list( $value ): array {
		if ( ! is_string( $value ) ) {
			return array();
		}

		return array_values( array_filter( array_map( 'trim', explode( ',', $value ) ), fn( string $item ): bool => '' !== $item ) );
	}

	/**
	 * Build the synopsis of the output arguments of a command.
	 *
	 * @param string[] $formats Accepted formats.
	 * @return array<int, array<string, mixed>>
	 */
	public static function format_synopsis( array $formats ): array {
		return array(
			array(
				'type'        => 'assoc',
				'name'        => 'fields',
				'description' => __( 'Limit the output to specific fields, separated by commas.', 'woocommerce-fraud-protection' ),
				'optional'    => true,
			),
			array(
				'type'        => 'assoc',
				'name'        => 'field',
				'description' => __( 'Print the value of a single field.', 'woocommerce-fraud-protection' ),
				'optional'    => true,
			),
			array(
				'type'        => 'assoc',
				'name'        => 'format',
				'description' => __( 'Render the output in a particular format.', 'woocommerce-fraud-protection' ),
				'optional'    => true,
				'default'     => 'table',
				'options'     => $formats,
			),
		);
	}

	/**
	 * Build the synopsis of the `--limit`, `--orderby` and `--order` arguments of a list command.
	 *
	 * @param string[] $orderby_options Accepted sort fields.
	 * @param string   $orderby_default Default sort field.
	 * @return array<int, array<string, mixed>>
	 */
	public static function list_synopsis( array $orderby_options, string $orderby_default ): array {
		return array(
			array(
				'type'        => 'assoc',
				'name'        => 'orderby',
				'description' => __( 'Sort the list by this field.', 'woocommerce-fraud-protection' ),
				'optional'    => true,
				'default'     => $orderby_default,
				'options'     => $orderby_options,
			),
			array(
				'type'        => 'assoc',
				'name'        => 'order',
				'description' => __( 'Sort in ascending or descending order.', 'woocommerce-fraud-protection' ),
				'optional'    => true,
				'default'     => 'desc',
				'options'     => array( 'asc', 'desc' ),
			),
			array(
				'type'        => 'assoc',
				'name'        => 'limit',
				'description' => __( 'Maximum number of items to list.', 'woocommerce-fraud-protection' ),
				'optional'    => true,
				'default'     => (string) self::DEFAULT_LIMIT,
			),
		);
	}

	/**
	 * Get a WP-CLI formatter for the output arguments.
	 *
	 * @param array    $assoc_args     Command associative arguments.
	 * @param string[] $default_fields Fields shown when `--fields` is not given.
	 * @return Formatter
	 */
	private function get_formatter( array $assoc_args, array $default_fields ): Formatter {
		return new Formatter( $assoc_args, $default_fields );
	}
}
