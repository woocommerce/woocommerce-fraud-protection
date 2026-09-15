<?php
/**
 * RulesRestController class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\Rules;

use Automattic\WooCommerce\FraudProtection\Schemas\FraudDecision;
use Automattic\WooCommerce\FraudProtection\SessionIdNormalizer;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\ApiClient;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Database\SchemaManager;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\FraudProtectionController;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Sessions\SessionEventStore;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Schemas\Rule;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Settings\SettingsTelemetry;

defined( 'ABSPATH' ) || exit;

/**
 * Provides the merchant rules management endpoints.
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
	 * Recorded event persistence.
	 *
	 * @var SessionEventStore
	 */
	private SessionEventStore $event_store;

	/**
	 * API client for contextual feedback.
	 *
	 * @var ApiClient
	 */
	private ApiClient $api_client;

	/**
	 * Stored session ID normalizer.
	 *
	 * @var SessionIdNormalizer
	 */
	private SessionIdNormalizer $session_id_normalizer;

	/**
	 * Settings telemetry.
	 *
	 * @var SettingsTelemetry
	 */
	private SettingsTelemetry $telemetry;

	/**
	 * Initialize with dependencies.
	 *
	 * @internal
	 *
	 * @param RuleStore           $rule_store           Rule persistence.
	 * @param SchemaManager       $schema_manager       Database schema manager.
	 * @param SessionEventStore   $event_store           Session event persistence.
	 * @param ApiClient           $api_client            Blackbox API client.
	 * @param SessionIdNormalizer $session_id_normalizer Session ID normalizer.
	 * @param SettingsTelemetry   $telemetry             Settings telemetry.
	 */
	final public function init(
		RuleStore $rule_store,
		SchemaManager $schema_manager,
		SessionEventStore $event_store,
		ApiClient $api_client,
		SessionIdNormalizer $session_id_normalizer,
		SettingsTelemetry $telemetry
	): void {
		$this->namespace             = self::REST_NAMESPACE;
		$this->rest_base             = 'rules';
		$this->rule_store            = $rule_store;
		$this->schema_manager        = $schema_manager;
		$this->event_store           = $event_store;
		$this->api_client            = $api_client;
		$this->session_id_normalizer = $session_id_normalizer;
		$this->telemetry             = $telemetry;
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
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_rule' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => $this->get_create_request_args(),
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
		$order   = $request->get_param( 'order' );
		if ( is_string( $orderby ) && '' !== $orderby ) {
			$filters['orderby'] = $orderby;
		}
		if ( is_string( $order ) && '' !== $order ) {
			$filters['order'] = $order;
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
	 * Create an active exact-value rule.
	 *
	 * @internal
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_rule( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( ! $this->schema_manager->is_schema_installed() ) {
			return new \WP_Error( 'woocommerce_fraud_protection_rules_not_loaded', __( 'The fraud prevention rules could not be loaded.', 'woocommerce-fraud-protection' ), array( 'status' => 503 ) );
		}

		$action = $request->get_param( 'action' );
		$type   = $request->get_param( 'type' );
		$value  = $request->get_param( 'value' );
		if ( ! is_string( $action ) || ! is_string( $type ) || ! is_string( $value ) ) {
			return $this->invalid_create_error();
		}

		$decision = FraudDecision::tryFrom( $action );
		if ( ! $decision instanceof FraudDecision || ! in_array( $decision, FraudDecision::ACTIONABLE, true ) ) {
			return $this->invalid_create_error();
		}

		$conditions = RuleConditions::validate_and_normalize(
			array(
				'field'    => $type,
				'operator' => 'equals',
				'value'    => $value,
			)
		);
		if ( is_null( $conditions ) ) {
			return $this->invalid_create_error();
		}

		$origin      = $request->get_param( 'origin' );
		$origin      = in_array( $origin, array( 'rules', 'checkout_attempts' ), true ) ? $origin : 'api';
		$event_id    = (int) $request->get_param( 'recorded_attempt_id' );
		$session_id  = null;
		$source_meta = array( 'origin' => $origin );

		if ( $event_id > 0 ) {
			$event = $this->event_store->get_event( $event_id );
			if ( ! is_array( $event ) ) {
				return new \WP_Error( 'woocommerce_fraud_protection_recorded_attempt_not_found', __( 'The recorded checkout attempt could not be found.', 'woocommerce-fraud-protection' ), array( 'status' => 404 ) );
			}

			$session_id = $this->session_id_normalizer->normalize_stored( $event['session_id'] );
			if ( '' === $session_id || ! in_array( $event['final_status'], array( 'allowed', 'blocked' ), true ) ) {
				return new \WP_Error( 'woocommerce_fraud_protection_recorded_attempt_invalid', __( 'This checkout attempt cannot be used to create a rule.', 'woocommerce-fraud-protection' ), array( 'status' => 400 ) );
			}

			$event_value      = $event[ $type ] ?? null;
			$event_conditions = is_string( $event_value )
				? RuleConditions::validate_and_normalize(
					array(
						'field'    => $type,
						'operator' => 'equals',
						'value'    => $event_value,
					)
				)
				: null;
			if ( is_null( $event_conditions ) || $event_conditions['value'] !== $conditions['value'] ) {
				return new \WP_Error( 'woocommerce_fraud_protection_recorded_attempt_mismatch', __( 'The rule value does not match the recorded checkout attempt.', 'woocommerce-fraud-protection' ), array( 'status' => 400 ) );
			}

			$source_meta['recorded_attempt_id'] = $event_id;
		}

		try {
			$rule = $this->rule_store->create_rule( $decision, $conditions, $session_id, $source_meta );
		} catch ( DuplicateRuleException $error ) {
			$existing        = $this->rule_store->get_rule( $error->existing_rule_id );
			$existing_action = $existing instanceof Rule ? $existing->action->value : null;
			$display_type    = RuleConditions::FIELD_EMAIL === $type ? 'email' : 'IP';
			$display_action  = FraudDecision::Allow->value === $existing_action ? 'allowed' : 'blocked';
			return new \WP_Error(
				'woocommerce_fraud_protection_duplicate_rule',
				sprintf(
					/* translators: 1: rule type, 2: existing rule action. */
					__( 'This %1$s is already %2$s by a rule.', 'woocommerce-fraud-protection' ),
					$display_type,
					$display_action
				),
				array(
					'status'  => 409,
					'rule_id' => $error->existing_rule_id,
					'action'  => $existing_action,
				)
			);
		} catch ( \InvalidArgumentException ) {
			return $this->invalid_create_error();
		} catch ( \RuntimeException ) {
			return new \WP_Error( 'woocommerce_fraud_protection_rule_create_failed', __( 'The rule could not be created.', 'woocommerce-fraud-protection' ), array( 'status' => 500 ) );
		}

		if ( ! is_null( $session_id ) ) {
			try {
				$this->api_client->report(
					$session_id,
					array(
						'report_id'      => 'wc-fraud-protection-rule-' . $rule->id,
						'asserted_label' => FraudDecision::Allow === $decision ? 'good' : 'bad',
					)
				);
			} catch ( \Throwable $error ) {
				FraudProtectionController::log(
					'warning',
					'Unable to send rule feedback after creation.',
					array(
						'exception_class'   => $error::class,
						'exception_message' => $error->getMessage(),
					)
				);
			}
		}
		$this->telemetry->record_rule_change( 'created', $decision, $type, $origin );

		return rest_ensure_response( $this->to_public_rule( $rule ) );
	}

	/**
	 * Return the common invalid create response.
	 *
	 * @return \WP_Error
	 */
	private function invalid_create_error(): \WP_Error {
		return new \WP_Error( 'woocommerce_fraud_protection_invalid_rule', __( 'Enter a complete email address or IP address.', 'woocommerce-fraud-protection' ), array( 'status' => 400 ) );
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
				'pattern' => '^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}Z$',
			),
			'to'       => array(
				'type'    => 'string',
				'pattern' => '^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}Z$',
			),
			'orderby'  => array(
				'type'    => 'string',
				'enum'    => array( 'action', 'value', 'type', 'created_at' ),
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
	 * REST argument definitions for creation.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function get_create_request_args(): array {
		return array(
			'action'              => array(
				'required' => true,
				'type'     => 'string',
				'enum'     => array( FraudDecision::Allow->value, FraudDecision::Block->value ),
			),
			'type'                => array(
				'required' => true,
				'type'     => 'string',
				'enum'     => array( RuleConditions::FIELD_EMAIL, RuleConditions::FIELD_IP ),
			),
			'value'               => array(
				'required' => true,
				'type'     => 'string',
			),
			'recorded_attempt_id' => array(
				'type'              => 'integer',
				'minimum'           => 1,
				'sanitize_callback' => 'absint',
			),
			'origin'              => array(
				'type' => 'string',
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
