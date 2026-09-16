<?php
/**
 * RulesRestController class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\Rules;

use Automattic\WooCommerce\FraudProtection\Schemas\FraudDecision;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Database\SchemaManager;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Schemas\Rule;

defined( 'ABSPATH' ) || exit;

/**
 * Provides the merchant rules read endpoint.
 */
class RulesRestController extends \WP_REST_Controller {

	private const REST_NAMESPACE = 'wc-fraud-protection/v1';

	/**
	 * Rule persistence.
	 *
	 * @var RuleStore
	 */
	private RuleStore $rule_store;

	/**
	 * Database schema manager.
	 *
	 * @var SchemaManager
	 */
	private SchemaManager $schema_manager;

	/**
	 * Initialize with dependencies.
	 *
	 * @internal
	 *
	 * @param RuleStore     $rule_store     Rule persistence.
	 * @param SchemaManager $schema_manager Database schema manager.
	 */
	final public function init( RuleStore $rule_store, SchemaManager $schema_manager ): void {
		$this->namespace      = self::REST_NAMESPACE;
		$this->rest_base      = 'rules';
		$this->rule_store     = $rule_store;
		$this->schema_manager = $schema_manager;
	}

	/**
	 * Register the route.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the rules list route.
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
	 * Check access to merchant rules.
	 *
	 * @internal
	 */
	public function permissions_check(): bool {
		return current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Return a filtered page of active rules.
	 *
	 * @internal
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_items( $request ): \WP_REST_Response|\WP_Error {
		if ( ! $this->schema_manager->is_schema_installed() ) {
			return new \WP_Error( 'woocommerce_fraud_protection_rules_not_loaded', __( 'The fraud prevention rules could not be loaded.', 'woocommerce-fraud-protection' ), array( 'status' => 503 ) );
		}

		$filters = array();
		foreach ( array( 'action', 'type' ) as $key ) {
			$value = $request->get_param( $key );
			if ( is_string( $value ) && '' !== $value ) {
				$filters[ $key ] = sanitize_text_field( $value );
			}
		}

		$value = $request->get_param( 'value' );
		if ( is_string( $value ) && '' !== $value ) {
			$filters['value'] = $value;
		}

		foreach ( array( 'from', 'to' ) as $key ) {
			$value = $request->get_param( $key );
			if ( is_string( $value ) && '' !== $value ) {
				$utc = $this->parse_utc_date_bound( $value );
				if ( is_null( $utc ) ) {
					return new \WP_Error( 'woocommerce_fraud_protection_invalid_date', __( 'The rule date filter is invalid.', 'woocommerce-fraud-protection' ), array( 'status' => 400 ) );
				}
				$filters[ $key ] = $utc;
			}
		}

		$orderby = $request->get_param( 'orderby' );
		$orderby = is_string( $orderby ) ? $orderby : 'created_at';
		$order   = $request->get_param( 'order' );
		$order   = is_string( $order ) ? $order : 'desc';

		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ) );

		try {
			$result = $this->rule_store->get_active_rules_page(
				filters: $filters,
				page: $page,
				per_page: $per_page,
				orderby: $orderby,
				order: $order
			);
		} catch ( \RuntimeException ) {
			return new \WP_Error( 'woocommerce_fraud_protection_rules_not_loaded', __( 'The fraud prevention rules could not be loaded.', 'woocommerce-fraud-protection' ), array( 'status' => 500 ) );
		}

		return $this->collection_response( array_map( array( $this, 'to_public_rule' ), $result['items'] ), $result['total'], $result['pages'] );
	}

	/**
	 * Build a collection response with pagination headers.
	 *
	 * @param array<array<string, mixed>> $data  Prepared rules.
	 * @param int                         $total Total matching rules.
	 * @param int                         $pages Total pages.
	 */
	private function collection_response( array $data, int $total, int $pages ): \WP_REST_Response {
		$response = rest_ensure_response( $data );
		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) $pages );

		return $response;
	}

	/**
	 * Convert a rule to the fields used by DataViews.
	 *
	 * @param Rule $rule Active rule.
	 * @return array{id: int, action: string, value: string, type: string, created_at: string}
	 */
	private function to_public_rule( Rule $rule ): array {
		return array(
			'id'         => $rule->id,
			'action'     => $rule->action->value,
			'value'      => (string) ( $rule->conditions['value'] ?? '' ),
			'type'       => (string) ( $rule->conditions['field'] ?? '' ),
			'created_at' => $this->format_created_at( $rule->created_at ),
		);
	}

	/**
	 * Format a UTC database timestamp as an explicit UTC date-time.
	 *
	 * @param string $created_at UTC MySQL timestamp.
	 * @return string RFC3339 timestamp.
	 */
	private function format_created_at( string $created_at ): string {
		$parsed = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $created_at, new \DateTimeZone( 'UTC' ) );
		if ( false === $parsed ) {
			return $created_at . '+00:00';
		}

		return $parsed->format( 'Y-m-d\\TH:i:s\\Z' );
	}

	/**
	 * Convert an RFC3339 UTC boundary to a database timestamp.
	 *
	 * @param string $date UTC boundary in YYYY-MM-DDTHH:MM:SSZ form.
	 * @return ?string UTC MySQL timestamp.
	 */
	private function parse_utc_date_bound( string $date ): ?string {
		$format = '!Y-m-d\TH:i:s\Z';
		$parsed = \DateTimeImmutable::createFromFormat( $format, $date, new \DateTimeZone( 'UTC' ) );
		$errors = \DateTimeImmutable::getLastErrors();
		if ( false === $parsed || ( is_array( $errors ) && ( $errors['warning_count'] > 0 || $errors['error_count'] > 0 ) ) || $parsed->format( 'Y-m-d\TH:i:s\Z' ) !== $date ) {
			return null;
		}

		return $parsed->format( 'Y-m-d H:i:s' );
	}

	/**
	 * REST argument definitions.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_collection_params(): array {
		return array(
			'page'     => array(
				'type'    => 'integer',
				'default' => 1,
				'minimum' => 1,
			),
			'per_page' => array(
				'type'    => 'integer',
				'default' => 20,
				'minimum' => 1,
				'maximum' => 100,
			),
			'action'   => array(
				'type' => 'string',
				'enum' => array( FraudDecision::Allow->value, FraudDecision::Block->value ),
			),
			'type'     => array(
				'type' => 'string',
				'enum' => array( RuleConditions::FIELD_EMAIL, RuleConditions::FIELD_IP ),
			),
			'value'    => array( 'type' => 'string' ),
			'from'     => array(
				'type'    => 'string',
				'pattern' => '^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}Z$',
			),
			'to'       => array(
				'type'    => 'string',
				'pattern' => '^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}Z$',
			),
			'orderby'  => array(
				'type'    => 'string',
				'enum'    => RuleStore::SORTABLE_COLUMNS,
				'default' => 'created_at',
			),
			'order'    => array(
				'type'    => 'string',
				'enum'    => array( 'asc', 'desc' ),
				'default' => 'desc',
			),
		);
	}

	/**
	 * Get the public response schema.
	 *
	 * @return array<string, mixed>
	 */
	public function get_public_item_schema(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'woocommerce_fraud_protection_rule',
			'type'       => 'object',
			'properties' => array(
				'id'         => array(
					'type'     => 'integer',
					'readonly' => true,
				),
				'action'     => array(
					'type'     => 'string',
					'enum'     => array( FraudDecision::Allow->value, FraudDecision::Block->value ),
					'readonly' => true,
				),
				'value'      => array(
					'type'     => 'string',
					'readonly' => true,
				),
				'type'       => array(
					'type'     => 'string',
					'enum'     => array( RuleConditions::FIELD_EMAIL, RuleConditions::FIELD_IP ),
					'readonly' => true,
				),
				'created_at' => array(
					'type'     => 'string',
					'format'   => 'date-time',
					'readonly' => true,
				),
			),
		);
	}
}
