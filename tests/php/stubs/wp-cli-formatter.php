<?php
/**
 * Test stub for the WP-CLI formatter.
 *
 * WP-CLI is not loaded in PHPUnit. This stub records what the commands ask
 * the formatter to display so tests can assert on it.
 */

declare( strict_types=1 );

namespace WP_CLI;

/**
 * Records the items that WP-CLI commands display.
 */
class Formatter {

	/**
	 * Recorded display calls.
	 *
	 * @var array<int, array{method: string, assoc_args: array, fields: mixed, data: array}>
	 */
	public static array $calls = array();

	/**
	 * Output arguments.
	 *
	 * @var array
	 */
	private array $assoc_args;

	/**
	 * Default fields.
	 *
	 * @var mixed
	 */
	private $fields;

	/**
	 * Store the output arguments.
	 *
	 * @param array $assoc_args Output format arguments.
	 * @param mixed $fields     Fields to display.
	 * @param mixed $prefix     Unused field prefix.
	 */
	public function __construct( &$assoc_args, $fields = null, $prefix = false ) {
		unset( $prefix );
		$this->assoc_args = $assoc_args;
		$this->fields     = $fields;
	}

	/**
	 * Record a list display.
	 *
	 * @param array $items The items.
	 */
	public function display_items( $items ): void {
		$this->record( 'display_items', $items );
	}

	/**
	 * Record a single item display.
	 *
	 * @param array $item The item.
	 */
	public function display_item( $item ): void {
		$this->record( 'display_item', $item );
	}

	/**
	 * Record a display call.
	 *
	 * @param string $method The display method.
	 * @param array  $data   The displayed data.
	 */
	private function record( string $method, array $data ): void {
		self::$calls[] = array(
			'method'     => $method,
			'assoc_args' => $this->assoc_args,
			'fields'     => $this->fields,
			'data'       => $data,
		);
	}
}
