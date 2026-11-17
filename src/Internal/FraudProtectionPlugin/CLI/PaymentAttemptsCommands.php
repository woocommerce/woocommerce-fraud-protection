<?php
/**
 * PaymentAttemptsCommands class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\CLI;

use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Rules\RuleConditions;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Schemas\Rule;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Schemas\SessionFinalStatus;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Schemas\SessionOutcome;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Sessions\PaymentMethodTitleResolver;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Sessions\SessionEventPruner;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Sessions\SessionEventStore;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Sessions\SessionRuleFinder;

defined( 'ABSPATH' ) || exit;

/**
 * WP-CLI commands that list recorded payment attempts.
 */
class PaymentAttemptsCommands {

	/**
	 * Command namespace.
	 */
	public const COMMAND = 'wc fraud-protection payment-attempts';

	/**
	 * Fields listed when `--fields` is not given.
	 */
	private const DEFAULT_FIELDS = array( 'id', 'recorded_at', 'outcome', 'email', 'ip', 'ip_country', 'payment_method', 'order_id', 'email_rule_id', 'ip_rule_id' );

	/**
	 * Session event store instance.
	 *
	 * @var SessionEventStore
	 */
	private SessionEventStore $event_store;

	/**
	 * Session rule finder instance.
	 *
	 * @var SessionRuleFinder
	 */
	private SessionRuleFinder $rule_finder;

	/**
	 * Payment method title resolver instance.
	 *
	 * @var PaymentMethodTitleResolver
	 */
	private PaymentMethodTitleResolver $payment_method_titles;

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
	 * @param SessionEventStore          $event_store           The session event store instance.
	 * @param SessionRuleFinder          $rule_finder           The session rule finder instance.
	 * @param PaymentMethodTitleResolver $payment_method_titles The payment method title resolver instance.
	 * @param CommandOutput              $output                Command output.
	 */
	final public function init( SessionEventStore $event_store, SessionRuleFinder $rule_finder, PaymentMethodTitleResolver $payment_method_titles, CommandOutput $output ): void {
		$this->event_store           = $event_store;
		$this->rule_finder           = $rule_finder;
		$this->payment_method_titles = $payment_method_titles;
		$this->output                = $output;
	}

	/**
	 * Register the payment attempts list and get command leaves.
	 */
	public function register_commands(): void {
		$this->output->add_command(
			self::COMMAND . ' list',
			array( $this, 'list_attempts' ),
			__( 'List recorded payment attempts.', 'woocommerce-fraud-protection' ),
			array_merge(
				array(
					array(
						'type'        => 'assoc',
						'name'        => 'final_status',
						'description' => __( 'Only list attempts that were allowed or blocked.', 'woocommerce-fraud-protection' ),
						'optional'    => true,
						'options'     => array_map( fn( SessionFinalStatus $status ): string => $status->value, SessionFinalStatus::cases() ),
					),
					array(
						'type'        => 'assoc',
						'name'        => 'outcome',
						'description' => sprintf(
							/* translators: %s: Accepted outcome values. */
							__( 'Only list attempts with these outcomes, separated by commas. Accepted values: %s.', 'woocommerce-fraud-protection' ),
							implode( ', ', self::outcome_values() )
						),
						'optional'    => true,
					),
					array(
						'type'        => 'assoc',
						'name'        => 'payment_method',
						'description' => __( 'Only list attempts with these payment gateway IDs, separated by commas.', 'woocommerce-fraud-protection' ),
						'optional'    => true,
					),
					array(
						'type'        => 'assoc',
						'name'        => 'search',
						'description' => __( 'Only list attempts whose email address or IP address contains this text.', 'woocommerce-fraud-protection' ),
						'optional'    => true,
					),
					array(
						'type'        => 'assoc',
						'name'        => 'rules',
						'description' => __( 'Only list attempts with or without an active custom rule for their email address or IP address.', 'woocommerce-fraud-protection' ),
						'optional'    => true,
						'options'     => array( 'with', 'without' ),
					),
					array(
						'type'        => 'assoc',
						'name'        => 'days',
						'description' => __( 'Only list attempts recorded during this many days.', 'woocommerce-fraud-protection' ),
						'optional'    => true,
						'default'     => (string) SessionEventPruner::RETENTION_DAYS,
					),
				),
				CommandOutput::list_synopsis( SessionEventStore::SORTABLE_COLUMNS, 'recorded_at' ),
				CommandOutput::format_synopsis( CommandOutput::LIST_FORMATS )
			),
			"## AVAILABLE FIELDS\n\n" . sprintf(
				/* translators: 1: Field names shown by default, 2: Other field names. */
				__( 'Times are in UTC. These fields are shown by default: %1$s. These fields are also available: %2$s.', 'woocommerce-fraud-protection' ),
				implode( ', ', self::DEFAULT_FIELDS ),
				implode( ', ', array( 'final_status', 'payment_method_title', 'billing_country', 'billing_city', 'billing_postcode' ) )
			)
		);

		$this->output->add_command(
			self::COMMAND . ' get',
			array( $this, 'get_attempt' ),
			__( 'Show a recorded payment attempt.', 'woocommerce-fraud-protection' ),
			array_merge(
				array(
					array(
						'type'        => 'positional',
						'name'        => 'id',
						'description' => __( 'The payment attempt ID.', 'woocommerce-fraud-protection' ),
					),
				),
				CommandOutput::format_synopsis( CommandOutput::ITEM_FORMATS )
			)
		);
	}

	/**
	 * List recorded payment attempts.
	 *
	 * @internal
	 *
	 * @param string[]             $args       Positional command arguments.
	 * @param array<string, mixed> $assoc_args Associative command arguments.
	 */
	public function list_attempts( array $args, array $assoc_args ): void {
		unset( $args );

		if ( ! $this->output->require_schema() ) {
			return;
		}

		$limit = $this->output->get_limit( $assoc_args );
		if ( is_null( $limit ) ) {
			return;
		}

		$query = $this->get_query_args( $assoc_args );
		if ( is_null( $query ) ) {
			return;
		}

		$is_count          = 'count' === ( $assoc_args['format'] ?? '' );
		$query['per_page'] = $is_count ? 1 : min( SessionEventStore::MAX_PER_PAGE, $limit );

		$rows  = array();
		$total = 0;
		try {
			if ( 'payment_method' === $query['orderby'] ) {
				$query['payment_method_order'] = $this->payment_method_titles->sort_by_title( $this->event_store->get_payment_methods( $query['days'] ), $query['order'] );
			}
			if ( '' !== $query['rules'] ) {
				$query['rule_values'] = $this->rule_finder->get_targeted_values();
			}

			// Attempts recorded while the pages are read would shift the later pages.
			$query['max_id'] = $this->event_store->get_latest_event_id();
			$query['page']   = 1;
			do {
				$result  = $this->event_store->query_events( $query );
				$total   = $result['total'];
				$rows    = array_merge( $rows, $result['items'] );
				$fetched = count( $rows );
				++$query['page'];
			} while ( ! $is_count && $fetched < $limit && array() !== $result['items'] && $fetched < $total );
		} catch ( \RuntimeException ) {
			$this->output->error( self::load_failed_message() );
			return;
		}

		$this->output->display_list( $assoc_args, $this->to_items( array_slice( $rows, 0, $limit ) ), $total, self::DEFAULT_FIELDS );
	}

	/**
	 * Show a recorded payment attempt.
	 *
	 * @internal
	 *
	 * @param string[]             $args       Positional command arguments.
	 * @param array<string, mixed> $assoc_args Associative command arguments.
	 */
	public function get_attempt( array $args, array $assoc_args ): void {
		if ( ! $this->output->require_schema() ) {
			return;
		}

		$id = CommandOutput::positive_int( $args[0] ?? null );
		if ( is_null( $id ) ) {
			$this->output->error( __( 'The payment attempt ID must be a positive whole number.', 'woocommerce-fraud-protection' ) );
			return;
		}

		try {
			$row = $this->event_store->get_list_event( $id );
		} catch ( \RuntimeException ) {
			$this->output->error( self::load_failed_message() );
			return;
		}

		if ( is_null( $row ) ) {
			$this->output->error( __( 'The payment attempt could not be found.', 'woocommerce-fraud-protection' ) );
			return;
		}

		$this->output->display_item( $assoc_args, $this->to_items( array( $row ) )[0] );
	}

	/**
	 * Read the list query arguments.
	 *
	 * @param array<string, mixed> $assoc_args Associative command arguments.
	 * @return ?array<string, mixed> The query arguments, or null after reporting an invalid value.
	 */
	private function get_query_args( array $assoc_args ): ?array {
		$final_status = null;
		if ( array_key_exists( 'final_status', $assoc_args ) ) {
			$final_status = is_string( $assoc_args['final_status'] ) ? SessionFinalStatus::tryFrom( $assoc_args['final_status'] ) : null;
			if ( is_null( $final_status ) ) {
				$this->output->error( __( 'Use one of: allowed or blocked.', 'woocommerce-fraud-protection' ) );
				return null;
			}
		}

		$outcomes = array();
		foreach ( CommandOutput::comma_list( $assoc_args['outcome'] ?? null ) as $value ) {
			$outcome = SessionOutcome::tryFrom( $value );
			if ( is_null( $outcome ) ) {
				$this->output->error(
					sprintf(
						/* translators: 1: Outcome given, 2: Accepted outcome values. */
						__( 'Unknown outcome "%1$s". Use one or more of: %2$s.', 'woocommerce-fraud-protection' ),
						$value,
						implode( ', ', self::outcome_values() )
					)
				);
				return null;
			}
			$outcomes[] = $outcome;
		}

		$rules = $assoc_args['rules'] ?? '';
		if ( ! in_array( $rules, array( '', 'with', 'without' ), true ) ) {
			$this->output->error( __( 'Use one of: with or without.', 'woocommerce-fraud-protection' ) );
			return null;
		}

		$days = CommandOutput::positive_int( $assoc_args['days'] ?? SessionEventPruner::RETENTION_DAYS );
		if ( is_null( $days ) ) {
			$this->output->error( __( 'The number of days must be a positive whole number.', 'woocommerce-fraud-protection' ) );
			return null;
		}

		$orderby = $assoc_args['orderby'] ?? 'recorded_at';
		$order   = $assoc_args['order'] ?? 'desc';
		$search  = $assoc_args['search'] ?? '';

		return array(
			'days'            => $days,
			'orderby'         => in_array( $orderby, SessionEventStore::SORTABLE_COLUMNS, true ) ? $orderby : 'recorded_at',
			'order'           => 'asc' === $order ? 'asc' : 'desc',
			'final_status'    => $final_status,
			'outcomes'        => $outcomes,
			'payment_methods' => CommandOutput::comma_list( $assoc_args['payment_method'] ?? null ),
			'search'          => is_string( $search ) ? $search : '',
			'rules'           => $rules,
		);
	}

	/**
	 * Convert event rows to their command output fields.
	 *
	 * @param array<int, array<string, mixed>> $rows Typed event rows.
	 * @return array<int, array<string, mixed>>
	 */
	private function to_items( array $rows ): array {
		$rules = $this->rule_finder->find_for_events( $rows );
		$items = array();

		foreach ( $rows as $row ) {
			$payment_method = (string) ( $row['payment_method'] ?? '' );
			$row_rules      = $rules[ $row['id'] ] ?? array();

			$items[] = array(
				'id'                   => (int) $row['id'],
				'recorded_at'          => (string) $row['recorded_at'],
				'outcome'              => SessionOutcome::from_row( $row )->value,
				'final_status'         => (string) $row['final_status'],
				'email'                => self::nullable_string( $row['email'] ?? null ),
				'ip'                   => self::nullable_string( $row['ip'] ?? null ),
				'ip_country'           => self::nullable_string( $row['ip_country'] ?? null ),
				'payment_method'       => self::nullable_string( $payment_method ),
				'payment_method_title' => '' === $payment_method ? null : $this->payment_method_titles->resolve( $payment_method ),
				'billing_country'      => self::nullable_string( $row['billing_country'] ?? null ),
				'billing_city'         => self::nullable_string( $row['billing_city'] ?? null ),
				'billing_postcode'     => self::nullable_string( $row['billing_postcode'] ?? null ),
				'order_id'             => $row['order_id'],
				'email_rule_id'        => self::rule_id( $row_rules[ RuleConditions::FIELD_EMAIL ] ?? null ),
				'ip_rule_id'           => self::rule_id( $row_rules[ RuleConditions::FIELD_IP ] ?? null ),
			);
		}

		return $items;
	}

	/**
	 * Get the message for a failed payment attempts read.
	 *
	 * @return string
	 */
	private static function load_failed_message(): string {
		return __( 'The payment activity could not be loaded.', 'woocommerce-fraud-protection' );
	}

	/**
	 * Get the accepted outcome values.
	 *
	 * @return string[]
	 */
	private static function outcome_values(): array {
		return array_map( fn( SessionOutcome $outcome ): string => $outcome->value, SessionOutcome::cases() );
	}

	/**
	 * Map an empty value to null.
	 *
	 * @param mixed $value The column value.
	 * @return ?string
	 */
	private static function nullable_string( $value ): ?string {
		$value = is_scalar( $value ) ? (string) $value : '';

		return '' === $value ? null : $value;
	}

	/**
	 * Get the ID of a rule, if any.
	 *
	 * @param ?Rule $rule The rule.
	 * @return ?int
	 */
	private static function rule_id( ?Rule $rule ): ?int {
		return $rule?->id;
	}
}
