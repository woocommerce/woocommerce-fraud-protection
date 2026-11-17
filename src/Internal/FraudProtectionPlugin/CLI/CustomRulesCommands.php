<?php
/**
 * CustomRulesCommands class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\CLI;

use Automattic\WooCommerce\FraudProtection\Schemas\FraudDecision;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Database\SchemaManager;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Rules\DuplicateRuleException;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Rules\RuleConditions;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Rules\RuleManager;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Rules\RuleStore;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Schemas\Rule;

defined( 'ABSPATH' ) || exit;

/**
 * WP-CLI commands that list and manage custom rules.
 */
class CustomRulesCommands {

	/**
	 * Command namespace.
	 */
	private const COMMAND = 'wc fraud-protection custom-rules';

	/**
	 * Fields of a listed rule.
	 */
	private const FIELDS = array( 'id', 'action', 'type', 'value', 'created_at', 'updated_at' );

	/**
	 * Rule persistence.
	 *
	 * @var RuleStore
	 */
	private RuleStore $rule_store;

	/**
	 * Custom rule management.
	 *
	 * @var RuleManager
	 */
	private RuleManager $rule_manager;

	/**
	 * Schema manager instance.
	 *
	 * @var SchemaManager
	 */
	private SchemaManager $schema_manager;

	/**
	 * Command output.
	 *
	 * @var CommandOutput
	 */
	private CommandOutput $output;

	/**
	 * Initialize with dependencies.
	 *
	 * @internal
	 *
	 * @param RuleStore     $rule_store     Rule persistence.
	 * @param RuleManager   $rule_manager   Custom rule management.
	 * @param SchemaManager $schema_manager The schema manager instance.
	 * @param CommandOutput $output         Command output.
	 */
	final public function init( RuleStore $rule_store, RuleManager $rule_manager, SchemaManager $schema_manager, CommandOutput $output ): void {
		$this->rule_store     = $rule_store;
		$this->rule_manager   = $rule_manager;
		$this->schema_manager = $schema_manager;
		$this->output         = $output;
	}

	/**
	 * Register the custom rules command leaves.
	 */
	public function register_commands(): void {
		$action_options = array( FraudDecision::Allow->value, FraudDecision::Block->value );
		$type_options   = array( RuleConditions::FIELD_IP, RuleConditions::FIELD_EMAIL );

		$this->output->add_command(
			self::COMMAND . ' list',
			array( $this, 'list_rules' ),
			__( 'List custom rules.', 'woocommerce-fraud-protection' ),
			array_merge(
				array(
					array(
						'type'        => 'assoc',
						'name'        => 'action',
						'description' => __( 'Only list rules with this action.', 'woocommerce-fraud-protection' ),
						'optional'    => true,
						'options'     => $action_options,
					),
					array(
						'type'        => 'assoc',
						'name'        => 'type',
						'description' => __( 'Only list rules of this type.', 'woocommerce-fraud-protection' ),
						'optional'    => true,
						'options'     => $type_options,
					),
					array(
						'type'        => 'assoc',
						'name'        => 'value',
						'description' => __( 'Only list rules for this exact email address or IP address.', 'woocommerce-fraud-protection' ),
						'optional'    => true,
					),
					array(
						'type'        => 'assoc',
						'name'        => 'from',
						'value'       => array( 'name' => 'date' ),
						'description' => __( 'Only list rules created at or after this UTC date, as YYYY-MM-DD or "YYYY-MM-DD HH:MM:SS".', 'woocommerce-fraud-protection' ),
						'optional'    => true,
					),
					array(
						'type'        => 'assoc',
						'name'        => 'to',
						'value'       => array( 'name' => 'date' ),
						'description' => __( 'Only list rules created at or before this UTC date, as YYYY-MM-DD or "YYYY-MM-DD HH:MM:SS".', 'woocommerce-fraud-protection' ),
						'optional'    => true,
					),
				),
				CommandOutput::list_synopsis( RuleStore::SORTABLE_COLUMNS, 'created_at' ),
				CommandOutput::format_synopsis( CommandOutput::LIST_FORMATS )
			),
			"## AVAILABLE FIELDS\n\n" . sprintf(
				/* translators: %s: Field names. */
				__( 'Times are in UTC. These fields are shown by default: %s.', 'woocommerce-fraud-protection' ),
				implode( ', ', self::FIELDS )
			)
		);

		$this->output->add_command(
			self::COMMAND . ' get',
			array( $this, 'get_rule' ),
			__( 'Show a custom rule.', 'woocommerce-fraud-protection' ),
			array_merge(
				array(
					array(
						'type'        => 'positional',
						'name'        => 'id',
						'description' => __( 'The rule ID.', 'woocommerce-fraud-protection' ),
					),
				),
				CommandOutput::format_synopsis( CommandOutput::ITEM_FORMATS )
			)
		);

		$this->output->add_command(
			self::COMMAND . ' create',
			array( $this, 'create_rule' ),
			__( 'Create a custom rule.', 'woocommerce-fraud-protection' ),
			array(
				array(
					'type'        => 'positional',
					'name'        => 'action',
					'description' => __( 'Whether the rule allows or blocks matching payment attempts.', 'woocommerce-fraud-protection' ),
					'options'     => $action_options,
				),
				array(
					'type'        => 'positional',
					'name'        => 'value',
					'description' => __( 'The email address or IP address. The rule type is detected from the value.', 'woocommerce-fraud-protection' ),
				),
				array(
					'type'        => 'assoc',
					'name'        => 'type',
					'description' => __( 'Accept the value only as this type instead of detecting it.', 'woocommerce-fraud-protection' ),
					'optional'    => true,
					'options'     => $type_options,
				),
				array(
					'type'        => 'flag',
					'name'        => 'porcelain',
					'description' => __( 'Output only the new rule ID.', 'woocommerce-fraud-protection' ),
					'optional'    => true,
				),
			)
		);

		$this->output->add_command(
			self::COMMAND . ' update',
			array( $this, 'update_rule' ),
			__( 'Update a custom rule.', 'woocommerce-fraud-protection' ),
			array(
				array(
					'type'        => 'positional',
					'name'        => 'id',
					'description' => __( 'The rule ID.', 'woocommerce-fraud-protection' ),
				),
				array(
					'type'        => 'assoc',
					'name'        => 'action',
					'description' => __( 'The new action.', 'woocommerce-fraud-protection' ),
					'optional'    => true,
					'options'     => $action_options,
				),
				array(
					'type'        => 'assoc',
					'name'        => 'value',
					'description' => __( 'The new email address or IP address. The rule type is detected from the value.', 'woocommerce-fraud-protection' ),
					'optional'    => true,
				),
				array(
					'type'        => 'assoc',
					'name'        => 'type',
					'description' => __( 'Accept the new value only as this type instead of detecting it. Requires --value.', 'woocommerce-fraud-protection' ),
					'optional'    => true,
					'options'     => $type_options,
				),
			)
		);

		$this->output->add_command(
			self::COMMAND . ' delete',
			array( $this, 'delete_rules' ),
			__( 'Delete custom rules.', 'woocommerce-fraud-protection' ),
			array(
				array(
					'type'        => 'positional',
					'name'        => 'rule',
					'description' => __( 'A rule ID, or the email address or IP address of a rule.', 'woocommerce-fraud-protection' ),
					'repeating'   => true,
				),
				array(
					'type'        => 'flag',
					'name'        => 'yes',
					'description' => __( 'Delete the rules without asking for confirmation.', 'woocommerce-fraud-protection' ),
					'optional'    => true,
				),
			)
		);
	}

	/**
	 * List custom rules.
	 *
	 * @internal
	 *
	 * @param string[]             $args       Positional command arguments.
	 * @param array<string, mixed> $assoc_args Associative command arguments.
	 */
	public function list_rules( array $args, array $assoc_args ): void {
		unset( $args );

		if ( ! $this->require_schema() ) {
			return;
		}

		$limit = $this->output->get_limit( $assoc_args );
		if ( is_null( $limit ) ) {
			return;
		}

		$filters = $this->get_list_filters( $assoc_args );
		if ( is_null( $filters ) ) {
			return;
		}

		$orderby  = is_string( $assoc_args['orderby'] ?? null ) ? $assoc_args['orderby'] : 'created_at';
		$order    = is_string( $assoc_args['order'] ?? null ) ? $assoc_args['order'] : 'desc';
		$is_count = 'count' === ( $assoc_args['format'] ?? '' );
		$per_page = $is_count ? 1 : min( 100, $limit );

		$items = array();
		$total = 0;
		$page  = 1;
		try {
			do {
				$result  = $this->rule_store->get_active_rules_page( $filters, $page, $per_page, $orderby, $order );
				$total   = $result['total'];
				$items   = array_merge( $items, array_map( array( self::class, 'to_item' ), $result['items'] ) );
				$fetched = count( $items );
				++$page;
			} while ( ! $is_count && $fetched < $limit && $page <= $result['pages'] );
		} catch ( \RuntimeException ) {
			$this->output->error( __( 'The custom rules could not be loaded.', 'woocommerce-fraud-protection' ) );
			return;
		}

		$this->output->display_list( $assoc_args, array_slice( $items, 0, $limit ), $total, self::FIELDS );
	}

	/**
	 * Show a custom rule.
	 *
	 * @internal
	 *
	 * @param string[]             $args       Positional command arguments.
	 * @param array<string, mixed> $assoc_args Associative command arguments.
	 */
	public function get_rule( array $args, array $assoc_args ): void {
		if ( ! $this->require_schema() ) {
			return;
		}

		$id = $this->get_id_argument( $args );
		if ( is_null( $id ) ) {
			return;
		}

		try {
			$rule = $this->rule_manager->get_active_rule( $id );
		} catch ( \RuntimeException ) {
			$this->output->error( __( 'The rule could not be loaded.', 'woocommerce-fraud-protection' ) );
			return;
		}

		if ( ! $rule instanceof Rule ) {
			$this->output->error( __( 'The rule could not be found.', 'woocommerce-fraud-protection' ) );
			return;
		}

		$this->output->display_item( $assoc_args, self::to_item( $rule ) );
	}

	/**
	 * Create a custom rule.
	 *
	 * @internal
	 *
	 * @param string[]             $args       Positional command arguments.
	 * @param array<string, mixed> $assoc_args Associative command arguments.
	 */
	public function create_rule( array $args, array $assoc_args ): void {
		if ( ! $this->require_schema() ) {
			return;
		}

		$action = $this->get_action( $args[0] ?? null );
		if ( is_null( $action ) ) {
			return;
		}

		$conditions = $this->get_conditions( $args[1] ?? null, $assoc_args['type'] ?? null );
		if ( is_null( $conditions ) ) {
			return;
		}

		try {
			$rule = $this->rule_manager->create_rule( $action, $conditions, RuleManager::ORIGIN_CLI, null, array( 'origin' => RuleManager::ORIGIN_CLI ) );
		} catch ( DuplicateRuleException $error ) {
			$this->output->error( $this->duplicate_rule_message( $error, (string) $conditions['field'] ) );
			return;
		} catch ( \InvalidArgumentException ) {
			$this->output->error( self::invalid_value_message() );
			return;
		} catch ( \RuntimeException ) {
			$this->output->error( __( 'The rule could not be created.', 'woocommerce-fraud-protection' ) );
			return;
		}

		if ( ! empty( $assoc_args['porcelain'] ) ) {
			$this->output->line( (string) $rule->id );
			return;
		}

		$this->output->success(
			sprintf(
				/* translators: %d: Rule ID. */
				__( 'Created custom rule %d.', 'woocommerce-fraud-protection' ),
				$rule->id
			)
		);
	}

	/**
	 * Update the action or value of a custom rule.
	 *
	 * @internal
	 *
	 * @param string[]             $args       Positional command arguments.
	 * @param array<string, mixed> $assoc_args Associative command arguments.
	 */
	public function update_rule( array $args, array $assoc_args ): void {
		if ( ! $this->require_schema() ) {
			return;
		}

		$id = $this->get_id_argument( $args );
		if ( is_null( $id ) ) {
			return;
		}

		$has_action = array_key_exists( 'action', $assoc_args );
		$has_value  = array_key_exists( 'value', $assoc_args );
		if ( array_key_exists( 'type', $assoc_args ) && ! $has_value ) {
			$this->output->error( __( 'Use --type only together with --value.', 'woocommerce-fraud-protection' ) );
			return;
		}
		if ( ! $has_action && ! $has_value ) {
			$this->output->error( __( 'Use --action, --value, or both.', 'woocommerce-fraud-protection' ) );
			return;
		}

		$action = null;
		if ( $has_action ) {
			$action = $this->get_action( $assoc_args['action'] );
			if ( is_null( $action ) ) {
				return;
			}
		}

		$conditions = null;
		if ( $has_value ) {
			$conditions = $this->get_conditions( $assoc_args['value'], $assoc_args['type'] ?? null );
			if ( is_null( $conditions ) ) {
				return;
			}
		}

		try {
			$existing = $this->rule_manager->get_active_rule( $id );
		} catch ( \RuntimeException ) {
			$this->output->error( self::update_failed_message() );
			return;
		}
		if ( ! $existing instanceof Rule ) {
			$this->output->error( __( 'The rule could not be found.', 'woocommerce-fraud-protection' ) );
			return;
		}

		$conditions ??= $existing->conditions;
		try {
			$result = $this->rule_manager->update_rule( $id, $action ?? $existing->action, $conditions, RuleManager::ORIGIN_CLI );
		} catch ( DuplicateRuleException $error ) {
			$this->output->error( $this->duplicate_rule_message( $error, (string) ( $conditions['field'] ?? '' ) ) );
			return;
		} catch ( \InvalidArgumentException ) {
			$this->output->error( self::invalid_value_message() );
			return;
		} catch ( \RuntimeException ) {
			$this->output->error( self::update_failed_message() );
			return;
		}

		if ( is_null( $result ) ) {
			$this->output->error( __( 'The rule could not be found.', 'woocommerce-fraud-protection' ) );
			return;
		}

		$this->output->success(
			$result['changed']
				? sprintf(
					/* translators: %d: Rule ID. */
					__( 'Updated custom rule %d.', 'woocommerce-fraud-protection' ),
					$id
				)
				: sprintf(
					/* translators: %d: Rule ID. */
					__( 'Custom rule %d is already up to date.', 'woocommerce-fraud-protection' ),
					$id
				)
		);
	}

	/**
	 * Delete custom rules by ID or value.
	 *
	 * @internal
	 *
	 * @param string[]             $args       Positional command arguments.
	 * @param array<string, mixed> $assoc_args Associative command arguments.
	 */
	public function delete_rules( array $args, array $assoc_args ): void {
		if ( ! $this->require_schema() ) {
			return;
		}

		$rules    = array();
		$failures = 0;
		foreach ( array_unique( array_map( 'strval', $args ) ) as $arg ) {
			try {
				$rule = $this->find_rule( $arg );
			} catch ( \RuntimeException ) {
				$this->output->error( __( 'The custom rules could not be loaded.', 'woocommerce-fraud-protection' ) );
				return;
			}

			if ( false === $rule ) {
				$this->output->warning(
					sprintf(
						/* translators: %s: Command argument. */
						__( '"%s" is not a rule ID, an email address, or an IP address.', 'woocommerce-fraud-protection' ),
						$arg
					)
				);
				++$failures;
				continue;
			}

			if ( is_null( $rule ) ) {
				$this->output->warning(
					sprintf(
						/* translators: %s: Rule ID, email address, or IP address. */
						__( 'No custom rule matches "%s".', 'woocommerce-fraud-protection' ),
						$arg
					)
				);
				++$failures;
				continue;
			}

			$rules[ $rule->id ] = $rule;
		}

		if ( array() === $rules ) {
			$this->output->error( __( 'No matching custom rules were found.', 'woocommerce-fraud-protection' ) );
			return;
		}

		$items = array_values( array_map( array( self::class, 'to_item' ), $rules ) );
		$this->output->display_list( array( 'format' => 'table' ), $items, count( $items ), self::FIELDS );
		$this->output->confirm(
			sprintf(
				/* translators: %d: Number of rules. */
				_n( 'Delete %d custom rule?', 'Delete %d custom rules?', count( $rules ), 'woocommerce-fraud-protection' ),
				count( $rules )
			),
			$assoc_args
		);

		$deleted = 0;
		foreach ( $rules as $id => $rule ) {
			try {
				$deleted_rule = $this->rule_manager->delete_rule( $id, RuleManager::ORIGIN_CLI );
			} catch ( \RuntimeException ) {
				$deleted_rule = null;
			}

			if ( ! $deleted_rule instanceof Rule ) {
				$this->output->warning(
					sprintf(
						/* translators: %d: Rule ID. */
						__( 'Custom rule %d could not be deleted.', 'woocommerce-fraud-protection' ),
						$id
					)
				);
				++$failures;
				continue;
			}

			++$deleted;
		}

		if ( 0 === $failures ) {
			$this->output->success(
				sprintf(
					/* translators: %d: Number of deleted rules. */
					_n( 'Deleted %d custom rule.', 'Deleted %d custom rules.', $deleted, 'woocommerce-fraud-protection' ),
					$deleted
				)
			);
			return;
		}

		$total = $deleted + $failures;
		$this->output->error(
			sprintf(
				/* translators: 1: Number of deleted rules, 2: Number of rules requested. */
				_n( 'Deleted %1$d of %2$d custom rule.', 'Deleted %1$d of %2$d custom rules.', $total, 'woocommerce-fraud-protection' ),
				$deleted,
				$total
			)
		);
	}

	/**
	 * Read the list filters.
	 *
	 * @param array<string, mixed> $assoc_args Associative command arguments.
	 * @return ?array<string, string> The filters, or null after reporting an invalid value.
	 */
	private function get_list_filters( array $assoc_args ): ?array {
		$filters = array();

		foreach ( array( 'action', 'type', 'value' ) as $key ) {
			if ( is_string( $assoc_args[ $key ] ?? null ) && '' !== $assoc_args[ $key ] ) {
				$filters[ $key ] = $assoc_args[ $key ];
			}
		}

		foreach ( array(
			'from' => false,
			'to'   => true,
		) as $key => $is_end ) {
			if ( ! array_key_exists( $key, $assoc_args ) ) {
				continue;
			}

			$date = self::parse_date( $assoc_args[ $key ], $is_end );
			if ( is_null( $date ) ) {
				$this->output->error( __( 'Enter dates as YYYY-MM-DD or "YYYY-MM-DD HH:MM:SS".', 'woocommerce-fraud-protection' ) );
				return null;
			}
			$filters[ $key ] = $date;
		}

		return $filters;
	}

	/**
	 * Parse a UTC date argument into a database timestamp.
	 *
	 * @param mixed $value  The argument value.
	 * @param bool  $is_end Whether a date without a time means the end of that day.
	 * @return ?string The UTC MySQL timestamp, or null when the value is not a valid date.
	 */
	private static function parse_date( $value, bool $is_end ): ?string {
		if ( ! is_string( $value ) ) {
			return null;
		}

		$utc = new \DateTimeZone( 'UTC' );
		foreach ( array( 'Y-m-d H:i:s', 'Y-m-d' ) as $format ) {
			$date = \DateTimeImmutable::createFromFormat( '!' . $format, $value, $utc );
			if ( false !== $date && $date->format( $format ) === $value ) {
				return 'Y-m-d' === $format
					? $date->format( $is_end ? 'Y-m-d 23:59:59' : 'Y-m-d 00:00:00' )
					: $date->format( 'Y-m-d H:i:s' );
			}
		}

		return null;
	}

	/**
	 * Read a rule action argument.
	 *
	 * @param mixed $value The argument value.
	 * @return ?FraudDecision The action, or null after reporting an invalid value.
	 */
	private function get_action( $value ): ?FraudDecision {
		$action = is_string( $value ) ? FraudDecision::tryFrom( $value ) : null;
		if ( ! $action instanceof FraudDecision || ! in_array( $action, FraudDecision::ACTIONABLE, true ) ) {
			$this->output->error( __( 'Use one of: allow or block.', 'woocommerce-fraud-protection' ) );
			return null;
		}

		return $action;
	}

	/**
	 * Build the conditions of a rule value, detecting its type unless one is given.
	 *
	 * @param mixed $value The value argument.
	 * @param mixed $type  The type argument, if any.
	 * @return ?array The normalized conditions, or null after reporting an invalid value.
	 */
	private function get_conditions( $value, $type ): ?array {
		$value      = is_string( $value ) ? $value : '';
		$type       = is_string( $type ) ? $type : RuleManager::detect_type( $value );
		$conditions = is_string( $type ) ? RuleManager::build_conditions( $type, $value ) : null;
		if ( is_null( $conditions ) ) {
			$this->output->error( self::invalid_value_message() );
		}

		return $conditions;
	}

	/**
	 * Read a positive rule ID from the first positional argument.
	 *
	 * @param string[] $args Positional command arguments.
	 * @return ?int The ID, or null after reporting an invalid value.
	 */
	private function get_id_argument( array $args ): ?int {
		$id = CommandOutput::positive_int( $args[0] ?? null );
		if ( is_null( $id ) ) {
			$this->output->error( __( 'The rule ID must be a positive whole number.', 'woocommerce-fraud-protection' ) );
		}

		return $id;
	}

	/**
	 * Find the active rule a delete argument refers to.
	 *
	 * Digits only are a rule ID, which is never a valid IP address or email address.
	 *
	 * @param string $arg A rule ID, email address, or IP address.
	 * @return Rule|null|false The rule, null when no active rule matches, or false when the argument is not a rule ID or rule value.
	 * @throws \RuntimeException When the rule query fails.
	 */
	private function find_rule( string $arg ): Rule|null|false {
		$id = CommandOutput::positive_int( $arg );
		if ( ! is_null( $id ) ) {
			return $this->rule_manager->get_active_rule( $id );
		}

		$type = RuleManager::detect_type( $arg );

		return is_null( $type ) ? false : $this->rule_manager->find_active_rule_by_value( $type, $arg );
	}

	/**
	 * Build the message for a rule that duplicates an existing one.
	 *
	 * @param DuplicateRuleException $error Duplicate rule error.
	 * @param string                 $type  Rule type of the rejected rule.
	 * @return string
	 */
	private function duplicate_rule_message( DuplicateRuleException $error, string $type ): string {
		try {
			$existing = $this->rule_manager->get_active_rule( $error->existing_rule_id );
		} catch ( \RuntimeException ) {
			$existing = null;
		}

		$message = RuleManager::duplicate_rule_message( $type, $existing?->action );
		if ( ! $existing instanceof Rule ) {
			return $message;
		}

		return sprintf(
			/* translators: 1: Duplicate rule message, 2: ID of the existing rule. */
			__( '%1$s Rule ID: %2$d.', 'woocommerce-fraud-protection' ),
			$message,
			$existing->id
		);
	}

	/**
	 * Stop with an error when the database schema is not installed.
	 *
	 * @return bool True when the schema is installed.
	 */
	private function require_schema(): bool {
		if ( $this->schema_manager->is_schema_installed() ) {
			return true;
		}

		$this->output->error( __( 'The Fraud Protection database schema is incomplete. Run "wp wc fraud-protection database install" to repair it.', 'woocommerce-fraud-protection' ) );
		return false;
	}

	/**
	 * Get the message for an invalid rule value.
	 *
	 * @return string
	 */
	private static function invalid_value_message(): string {
		return __( 'Enter a complete email address or IP address.', 'woocommerce-fraud-protection' );
	}

	/**
	 * Get the message for a failed rule update.
	 *
	 * @return string
	 */
	private static function update_failed_message(): string {
		return __( 'The rule could not be updated.', 'woocommerce-fraud-protection' );
	}

	/**
	 * Convert a rule to its command output fields.
	 *
	 * @param Rule $rule The rule.
	 * @return array{id: int, action: string, type: string, value: string, created_at: string, updated_at: ?string}
	 */
	private static function to_item( Rule $rule ): array {
		return array(
			'id'         => $rule->id,
			'action'     => $rule->action->value,
			'type'       => (string) ( $rule->conditions['field'] ?? '' ),
			'value'      => (string) ( $rule->conditions['value'] ?? '' ),
			'created_at' => $rule->created_at,
			'updated_at' => $rule->updated_at,
		);
	}
}
