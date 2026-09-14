<?php
/**
 * SessionsRestController class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\Sessions;

use Automattic\WooCommerce\FraudProtection\Schemas\FraudDecision;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Database\SchemaManager;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\FraudProtectionController;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Rules\RuleConditions;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Schemas\Rule;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Schemas\SessionFinalStatus;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Schemas\SessionOutcome;

defined( 'ABSPATH' ) || exit;

/**
 * Provides the merchant-facing checkout attempts endpoint.
 *
 * Exposes the retained session events as a paginated, sortable and
 * filterable list. Risk scores are never exposed.
 */
class SessionsRestController extends \WP_REST_Controller {

	/**
	 * REST namespace.
	 */
	private const REST_NAMESPACE = 'wc-fraud-protection/v1';

	/**
	 * Default rows per page.
	 */
	private const DEFAULT_PER_PAGE = 20;

	/**
	 * Schema manager instance.
	 *
	 * @var SchemaManager
	 */
	private SchemaManager $schema_manager;

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
	 * Initialize with dependencies.
	 *
	 * @internal
	 *
	 * @param SchemaManager              $schema_manager        The schema manager instance.
	 * @param SessionEventStore          $event_store           The session event store instance.
	 * @param SessionRuleFinder          $rule_finder           The session rule finder instance.
	 * @param PaymentMethodTitleResolver $payment_method_titles The payment method title resolver instance.
	 */
	final public function init( SchemaManager $schema_manager, SessionEventStore $event_store, SessionRuleFinder $rule_finder, PaymentMethodTitleResolver $payment_method_titles ): void {
		$this->namespace             = self::REST_NAMESPACE;
		$this->rest_base             = 'sessions';
		$this->schema_manager        = $schema_manager;
		$this->event_store           = $event_store;
		$this->rule_finder           = $rule_finder;
		$this->payment_method_titles = $payment_method_titles;
	}

	/**
	 * Register the routes.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the sessions list route.
	 *
	 * @internal
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => $this->get_collection_params(),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
	}

	/**
	 * Check access to the recorded checkout attempts.
	 *
	 * @internal
	 */
	public function permissions_check(): bool {
		return current_user_can( 'manage_woocommerce' );
	}

	/**
	 * List the retained checkout attempts.
	 *
	 * @internal
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_items( $request ): \WP_REST_Response|\WP_Error {
		$per_page = (int) $request->get_param( 'per_page' );
		$page     = (int) $request->get_param( 'page' );

		if ( ! $this->schema_manager->is_schema_installed() ) {
			return $this->collection_response( array(), 0, $per_page );
		}

		$rules = $request->get_param( 'rules' );
		$rules = in_array( $rules, array( 'with', 'without' ), true ) ? $rules : '';

		try {
			$result = $this->event_store->query_events(
				array(
					'page'            => $page,
					'per_page'        => $per_page,
					'orderby'         => (string) $request->get_param( 'orderby' ),
					'order'           => (string) $request->get_param( 'order' ),
					'final_status'    => self::final_status_param( $request->get_param( 'final_status' ) ),
					'outcomes'        => self::outcome_params( $request->get_param( 'outcome' ) ),
					'payment_methods' => self::string_list_param( $request->get_param( 'payment_method' ) ),
					'search'          => (string) $request->get_param( 'search' ),
					'rules'           => $rules,
					'rule_values'     => '' === $rules ? array() : $this->rule_finder->get_targeted_values(),
				)
			);
		} catch ( \RuntimeException $e ) {
			return $this->unavailable_error( 'Sessions list query failed.', $e );
		}

		$rules = $this->rule_finder->find_for_events( $result['items'] );
		$items = array();
		foreach ( $result['items'] as $row ) {
			$items[] = $this->prepare_item( $row, $rules[ $row['id'] ] ?? array() );
		}

		return $this->collection_response( $items, $result['total'], $per_page );
	}

	/**
	 * Get the accepted list query parameters.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_collection_params(): array {
		$string_list = array(
			'type'              => 'array',
			'items'             => array(
				'type'      => 'string',
				'maxLength' => 64,
			),
			'default'           => array(),
			'validate_callback' => 'rest_validate_request_arg',
			'sanitize_callback' => 'rest_sanitize_request_arg',
		);

		return array(
			'page'           => array(
				'description'       => __( 'Current page of the collection.', 'woocommerce-fraud-protection' ),
				'type'              => 'integer',
				'default'           => 1,
				'minimum'           => 1,
				'validate_callback' => 'rest_validate_request_arg',
				'sanitize_callback' => 'rest_sanitize_request_arg',
			),
			'per_page'       => array(
				'description'       => __( 'Maximum number of items to be returned in result set.', 'woocommerce-fraud-protection' ),
				'type'              => 'integer',
				'default'           => self::DEFAULT_PER_PAGE,
				'minimum'           => 1,
				'maximum'           => SessionEventStore::MAX_PER_PAGE,
				'validate_callback' => 'rest_validate_request_arg',
				'sanitize_callback' => 'rest_sanitize_request_arg',
			),
			'orderby'        => array(
				'description'       => __( 'Sort collection by attribute.', 'woocommerce-fraud-protection' ),
				'type'              => 'string',
				'default'           => 'recorded_at',
				'enum'              => SessionEventStore::SORTABLE_COLUMNS,
				'validate_callback' => 'rest_validate_request_arg',
				'sanitize_callback' => 'rest_sanitize_request_arg',
			),
			'order'          => array(
				'description'       => __( 'Order sort attribute ascending or descending.', 'woocommerce-fraud-protection' ),
				'type'              => 'string',
				'default'           => 'desc',
				'enum'              => array( 'asc', 'desc' ),
				'validate_callback' => 'rest_validate_request_arg',
				'sanitize_callback' => 'rest_sanitize_request_arg',
			),
			'final_status'   => array(
				'description'       => __( 'Limit results to attempts with this enforced status.', 'woocommerce-fraud-protection' ),
				'type'              => 'string',
				'enum'              => array_map( fn( SessionFinalStatus $status ): string => $status->value, SessionFinalStatus::cases() ),
				'validate_callback' => 'rest_validate_request_arg',
				'sanitize_callback' => 'rest_sanitize_request_arg',
			),
			'outcome'        => array_merge(
				$string_list,
				array(
					'description' => __( 'Limit results to attempts with these outcomes.', 'woocommerce-fraud-protection' ),
					'items'       => array(
						'type' => 'string',
						'enum' => array_map( fn( SessionOutcome $outcome ): string => $outcome->value, SessionOutcome::cases() ),
					),
				)
			),
			'payment_method' => array_merge(
				$string_list,
				array( 'description' => __( 'Limit results to attempts paid with these payment method ids.', 'woocommerce-fraud-protection' ) )
			),
			'rules'          => array(
				'description'       => __( 'Limit results to attempts with or without an active merchant rule targeting their email or IP.', 'woocommerce-fraud-protection' ),
				'type'              => 'string',
				'enum'              => array( 'with', 'without' ),
				'validate_callback' => 'rest_validate_request_arg',
				'sanitize_callback' => 'rest_sanitize_request_arg',
			),
			'search'         => array(
				'description'       => __( 'Limit results to attempts whose email or IP contains the text.', 'woocommerce-fraud-protection' ),
				'type'              => 'string',
				'default'           => '',
				'maxLength'         => 254,
				'validate_callback' => 'rest_validate_request_arg',
				'sanitize_callback' => 'rest_sanitize_request_arg',
			),
		);
	}

	/**
	 * Get the checkout attempt schema.
	 *
	 * @return array<string, mixed>
	 */
	public function get_item_schema(): array {
		$country = array(
			'type'       => array( 'object', 'null' ),
			'properties' => array(
				'code' => array(
					'description' => __( 'Two-letter country code.', 'woocommerce-fraud-protection' ),
					'type'        => 'string',
				),
				'name' => array(
					'description' => __( 'Country name.', 'woocommerce-fraud-protection' ),
					'type'        => 'string',
				),
			),
		);
		$rule    = array(
			'type'       => array( 'object', 'null' ),
			'properties' => array(
				'id'         => array(
					'description' => __( 'Rule identifier.', 'woocommerce-fraud-protection' ),
					'type'        => 'integer',
				),
				'action'     => array(
					'description' => __( 'Rule action.', 'woocommerce-fraud-protection' ),
					'type'        => 'string',
					'enum'        => array_map( fn( FraudDecision $decision ): string => $decision->value, FraudDecision::ACTIONABLE ),
				),
				'created_at' => array(
					'description' => __( 'Time the rule was created, as GMT.', 'woocommerce-fraud-protection' ),
					'type'        => 'string',
					'format'      => 'date-time',
				),
				'updated_at' => array(
					'description' => __( 'Time the rule was last updated, as GMT, or null when never updated.', 'woocommerce-fraud-protection' ),
					'type'        => array( 'string', 'null' ),
					'format'      => 'date-time',
				),
			),
		);

		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'woocommerce_fraud_protection_session',
			'type'       => 'object',
			'properties' => array(
				'id'              => array(
					'description' => __( 'Unique identifier of the recorded checkout attempt.', 'woocommerce-fraud-protection' ),
					'type'        => 'integer',
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'recorded_at_gmt' => array(
					'description' => __( 'Time the attempt was recorded, as GMT.', 'woocommerce-fraud-protection' ),
					'type'        => 'string',
					'format'      => 'date-time',
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'payment_method'  => array(
					'description' => __( 'Payment method used by the attempt.', 'woocommerce-fraud-protection' ),
					'type'        => 'object',
					'context'     => array( 'view' ),
					'readonly'    => true,
					'properties'  => array(
						'id'    => array(
							'description' => __( 'Payment gateway id.', 'woocommerce-fraud-protection' ),
							'type'        => 'string',
						),
						'title' => array(
							'description' => __( 'Payment gateway title.', 'woocommerce-fraud-protection' ),
							'type'        => 'string',
						),
						'icon'  => array(
							'description' => __( 'Payment gateway icon URL, when available.', 'woocommerce-fraud-protection' ),
							'type'        => array( 'string', 'null' ),
						),
					),
				),
				'email'           => array(
					'description' => __( 'Customer email.', 'woocommerce-fraud-protection' ),
					'type'        => array( 'string', 'null' ),
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'ip'              => array(
					'description' => __( 'Customer IP address.', 'woocommerce-fraud-protection' ),
					'type'        => array( 'string', 'null' ),
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'ip_country'      => array_merge(
					$country,
					array(
						'description' => __( 'Country of the customer IP address.', 'woocommerce-fraud-protection' ),
						'context'     => array( 'view' ),
						'readonly'    => true,
					)
				),
				'billing_address' => array(
					'description' => __( 'Billing address of the attempt.', 'woocommerce-fraud-protection' ),
					'type'        => 'object',
					'context'     => array( 'view' ),
					'readonly'    => true,
					'properties'  => array(
						'country'  => $country,
						'city'     => array(
							'description' => __( 'Billing city.', 'woocommerce-fraud-protection' ),
							'type'        => 'string',
						),
						'postcode' => array(
							'description' => __( 'Billing postcode.', 'woocommerce-fraud-protection' ),
							'type'        => 'string',
						),
					),
				),
				'final_status'    => array(
					'description' => __( 'Whether the attempt was allowed or blocked.', 'woocommerce-fraud-protection' ),
					'type'        => 'string',
					'enum'        => array_map( fn( SessionFinalStatus $status ): string => $status->value, SessionFinalStatus::cases() ),
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'outcome'         => array(
					'description' => __( 'Merchant-facing outcome of the attempt.', 'woocommerce-fraud-protection' ),
					'type'        => 'string',
					'enum'        => array_map( fn( SessionOutcome $outcome ): string => $outcome->value, SessionOutcome::cases() ),
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'order_id'        => array(
					'description' => __( 'Order created by the attempt, when known.', 'woocommerce-fraud-protection' ),
					'type'        => array( 'integer', 'null' ),
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'rules'           => array(
					'description' => __( 'Active merchant rules currently targeting the attempt values.', 'woocommerce-fraud-protection' ),
					'type'        => 'object',
					'context'     => array( 'view' ),
					'readonly'    => true,
					'properties'  => array(
						RuleConditions::FIELD_EMAIL => $rule,
						RuleConditions::FIELD_IP    => $rule,
					),
				),
			),
		);
	}

	/**
	 * Map an event row to its response representation.
	 *
	 * @param array<string, mixed> $row   The typed event row.
	 * @param array<string, ?Rule> $rules The rules targeting the row values, keyed by condition field.
	 * @return array<string, mixed>
	 */
	private function prepare_item( array $row, array $rules ): array {
		$payment_method = (string) ( $row['payment_method'] ?? '' );

		return array(
			'id'              => (int) $row['id'],
			'recorded_at_gmt' => mysql_to_rfc3339( (string) $row['recorded_at'] ),
			'payment_method'  => array(
				'id'    => $payment_method,
				'title' => $this->payment_method_titles->resolve( $payment_method ),
				'icon'  => $this->payment_method_titles->resolve_icon( $payment_method ),
			),
			'email'           => self::nullable_string( $row['email'] ?? '' ),
			'ip'              => self::nullable_string( $row['ip'] ?? '' ),
			'ip_country'      => self::country( (string) ( $row['ip_country'] ?? '' ) ),
			'billing_address' => array(
				'country'  => self::country( (string) ( $row['billing_country'] ?? '' ) ),
				'city'     => (string) ( $row['billing_city'] ?? '' ),
				'postcode' => (string) ( $row['billing_postcode'] ?? '' ),
			),
			'final_status'    => (string) $row['final_status'],
			'outcome'         => SessionOutcome::from_row( $row )->value,
			'order_id'        => $row['order_id'],
			'rules'           => array(
				RuleConditions::FIELD_EMAIL => self::rule_reference( $rules[ RuleConditions::FIELD_EMAIL ] ?? null ),
				RuleConditions::FIELD_IP    => self::rule_reference( $rules[ RuleConditions::FIELD_IP ] ?? null ),
			),
		);
	}

	/**
	 * Build a paginated collection response.
	 *
	 * @param array<int, array<string, mixed>> $items    The prepared items.
	 * @param int                              $total    The total matching count.
	 * @param int                              $per_page The page size.
	 * @return \WP_REST_Response
	 */
	private function collection_response( array $items, int $total, int $per_page ): \WP_REST_Response {
		$response = new \WP_REST_Response( $items );
		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) (int) ceil( $total / max( 1, $per_page ) ) );

		return $response;
	}

	/**
	 * Log a failed query and build the generic error response.
	 *
	 * @param string            $message The log message.
	 * @param \RuntimeException $e       The store exception.
	 * @return \WP_Error
	 */
	private function unavailable_error( string $message, \RuntimeException $e ): \WP_Error {
		global $wpdb;

		FraudProtectionController::log(
			'warning',
			$message,
			array(
				'event_source'      => 'sessions_rest_controller',
				'exception_message' => $e->getMessage(),
				'db_error'          => $wpdb->last_error,
			)
		);

		return new \WP_Error(
			'woocommerce_fraud_protection_sessions_unavailable',
			__( 'The checkout attempts could not be loaded.', 'woocommerce-fraud-protection' ),
			array( 'status' => 500 )
		);
	}

	/**
	 * Convert a final status parameter to its enum case.
	 *
	 * @param mixed $value The sanitized parameter value.
	 * @return ?SessionFinalStatus
	 */
	private static function final_status_param( $value ): ?SessionFinalStatus {
		return is_string( $value ) ? SessionFinalStatus::tryFrom( $value ) : null;
	}

	/**
	 * Convert an outcome parameter list to enum cases.
	 *
	 * @param mixed $value The sanitized parameter value.
	 * @return SessionOutcome[]
	 */
	private static function outcome_params( $value ): array {
		$outcomes = array();
		foreach ( self::string_list_param( $value ) as $outcome ) {
			$case = SessionOutcome::tryFrom( $outcome );
			if ( ! is_null( $case ) ) {
				$outcomes[] = $case;
			}
		}

		return $outcomes;
	}

	/**
	 * Keep the string entries of a list parameter.
	 *
	 * @param mixed $value The sanitized parameter value.
	 * @return string[]
	 */
	private static function string_list_param( $value ): array {
		return is_array( $value ) ? array_values( array_filter( $value, 'is_string' ) ) : array();
	}

	/**
	 * Map an empty string to null.
	 *
	 * @param mixed $value The column value.
	 * @return ?string
	 */
	private static function nullable_string( $value ): ?string {
		$value = (string) $value;

		return '' === $value ? null : $value;
	}

	/**
	 * Build a country representation from a stored country code.
	 *
	 * @param string $code The two-letter country code, possibly empty.
	 * @return ?array{code: string, name: string}
	 */
	private static function country( string $code ): ?array {
		if ( '' === $code ) {
			return null;
		}

		$countries = WC()->countries->get_countries();
		$name      = is_array( $countries ) ? ( $countries[ $code ] ?? null ) : null;

		return array(
			'code' => $code,
			'name' => is_string( $name ) && '' !== $name ? $name : $code,
		);
	}

	/**
	 * Build the response reference of a rule.
	 *
	 * Carries the creation and last-update times so the list can show when the
	 * rule was created or, if it was ever edited, when it was last updated.
	 *
	 * @param ?Rule $rule The rule, if any.
	 * @return ?array{id: int, action: string, created_at: string, updated_at: ?string}
	 */
	private static function rule_reference( ?Rule $rule ): ?array {
		if ( is_null( $rule ) ) {
			return null;
		}

		return array(
			'id'         => $rule->id,
			'action'     => $rule->action->value,
			'created_at' => mysql_to_rfc3339( $rule->created_at ),
			'updated_at' => is_null( $rule->updated_at ) ? null : mysql_to_rfc3339( $rule->updated_at ),
		);
	}
}
