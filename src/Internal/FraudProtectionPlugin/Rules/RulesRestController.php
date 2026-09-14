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
					'callback'            => array( $this, 'get_rules' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => $this->get_request_args(),
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
	public function get_rules( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( ! $this->schema_manager->is_schema_installed() ) {
			return new \WP_Error( 'woocommerce_fraud_protection_rules_not_loaded', __( 'The fraud prevention rules could not be loaded.', 'woocommerce-fraud-protection' ), array( 'status' => 503 ) );
		}

		$filters = array();
		foreach ( array( 'action', 'type', 'value' ) as $key ) {
			$value = $request->get_param( $key );
			if ( is_string( $value ) && '' !== $value ) {
				$filters[ $key ] = sanitize_text_field( $value );
			}
		}

		foreach ( array( 'from', 'to' ) as $key ) {
			$value = $request->get_param( $key );
			if ( is_string( $value ) && '' !== $value ) {
				$utc = $this->date_bound_to_utc( $value, 'to' === $key );
				if ( is_null( $utc ) ) {
					return new \WP_Error( 'woocommerce_fraud_protection_invalid_date', __( 'The rule date filter is invalid.', 'woocommerce-fraud-protection' ), array( 'status' => 400 ) );
				}
				$filters[ $key ] = $utc;
			}
		}

		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ) );

		try {
			$result = $this->rule_store->get_active_rules_page( $filters, $page, $per_page );
		} catch ( \RuntimeException ) {
			return new \WP_Error( 'woocommerce_fraud_protection_rules_not_loaded', __( 'The fraud prevention rules could not be loaded.', 'woocommerce-fraud-protection' ), array( 'status' => 500 ) );
		}

		$data     = array_map( array( $this, 'to_public_rule' ), $result['items'] );
		$response = rest_ensure_response(
			array(
				'data'       => $data,
				'totalItems' => $result['total'],
				'totalPages' => $result['pages'],
				'page'       => $page,
				'perPage'    => $per_page,
			)
		);
		$response->header( 'X-WP-Total', (string) $result['total'] );
		$response->header( 'X-WP-TotalPages', (string) $result['pages'] );

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
	 * Convert a site-local date to a UTC database boundary.
	 *
	 * @param string $date End-user date in YYYY-MM-DD form.
	 * @param bool   $end  Whether this is an inclusive end-of-day bound.
	 * @return ?string UTC MySQL timestamp.
	 */
	private function date_bound_to_utc( string $date, bool $end ): ?string {
		$parsed = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date, wp_timezone() );
		$errors = \DateTimeImmutable::getLastErrors();
		if ( false === $parsed || ( is_array( $errors ) && ( $errors['warning_count'] > 0 || $errors['error_count'] > 0 ) ) || $parsed->format( 'Y-m-d' ) !== $date ) {
			return null;
		}

		if ( $end ) {
			$parsed = $parsed->setTime( 23, 59, 59 );
		}

		return $parsed->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * REST argument definitions.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function get_request_args(): array {
		return array(
			'page'     => array(
				'type'              => 'integer',
				'default'           => 1,
				'minimum'           => 1,
				'sanitize_callback' => 'absint',
			),
			'per_page' => array(
				'type'              => 'integer',
				'default'           => 20,
				'minimum'           => 1,
				'maximum'           => 100,
				'sanitize_callback' => 'absint',
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
				'pattern' => '^\\d{4}-\\d{2}-\\d{2}$',
			),
			'to'       => array(
				'type'    => 'string',
				'pattern' => '^\\d{4}-\\d{2}-\\d{2}$',
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
