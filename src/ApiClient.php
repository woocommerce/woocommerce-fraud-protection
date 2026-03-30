<?php
/**
 * ApiClient class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\FraudProtection;

use Automattic\Jetpack\Connection\Client as Jetpack_Connection_Client;

defined( 'ABSPATH' ) || exit;

/**
 * Handles communication with the Blackbox fraud protection API.
 *
 * Uses Jetpack Connection for authenticated requests to the Blackbox API
 * to verify sessions and report fraud events. The API returns fraud protection
 * decisions (allow, block, or challenge).
 *
 * This class implements a fail-open pattern: if the endpoint is unreachable,
 * times out, or returns an error, it returns an "allow" decision to ensure
 * legitimate transactions are never blocked due to service issues.
 *
 * @internal This class is part of the internal API and is subject to change without notice.
 */
class ApiClient {

	/**
	 * Default timeout for API requests in seconds.
	 *
	 * Using 10 seconds as a reasonable timeout for fraud verification during checkout.
	 * This balances giving the API enough time to respond while not blocking
	 * checkout for too long if the service is slow.
	 */
	private const DEFAULT_TIMEOUT = 10;

	/**
	 * Blackbox API base URL.
	 */
	private const BLACKBOX_API_BASE_URL = 'https://blackbox-api.wp.com/v1';

	/**
	 * Blackbox API verify endpoint path.
	 */
	private const VERIFY_ENDPOINT = '/verify';

	/**
	 * Blackbox API report endpoint path.
	 */
	private const REPORT_ENDPOINT = '/report';

	/**
	 * Decision type: allow session.
	 */
	public const DECISION_ALLOW = 'allow';

	/**
	 * Decision type: block session.
	 */
	public const DECISION_BLOCK = 'block';

	/**
	 * Decision type: challenge session.
	 */
	public const DECISION_CHALLENGE = 'challenge';

	/**
	 * Valid decision values that can be returned by the API.
	 *
	 * @var array<string>
	 */
	public const VALID_DECISIONS = array(
		self::DECISION_ALLOW,
		self::DECISION_BLOCK,
	);

	/**
	 * Report status: good outcome.
	 */
	public const REPORT_STATUS_GOOD = 'good';

	/**
	 * Report status: bad outcome.
	 */
	public const REPORT_STATUS_BAD = 'bad';

	/**
	 * Valid report status values.
	 *
	 * @var array<string>
	 */
	public const VALID_REPORT_STATUSES = array(
		self::REPORT_STATUS_GOOD,
		self::REPORT_STATUS_BAD,
	);

	/**
	 * Report source: chargeback event.
	 */
	public const REPORT_SOURCE_CHARGEBACK = 'chargeback';

	/**
	 * Report source: manual review outcome.
	 */
	public const REPORT_SOURCE_MANUAL_REVIEW = 'manual_review';

	/**
	 * Report source: API-driven event.
	 */
	public const REPORT_SOURCE_API = 'api';

	/**
	 * Valid report source values.
	 *
	 * @var array<string>
	 */
	public const VALID_REPORT_SOURCES = array(
		self::REPORT_SOURCE_CHARGEBACK,
		self::REPORT_SOURCE_MANUAL_REVIEW,
		self::REPORT_SOURCE_API,
	);

	/**
	 * Verify a session with the Blackbox API and get a fraud decision.
	 *
	 * Implements fail-open pattern: if the endpoint is unreachable or times out,
	 * returns "allow" decision and logs the error.
	 *
	 * @param string               $session_id Session ID to verify.
	 * @param array<string, mixed> $context    Session context data to send to the endpoint.
	 * @return string Decision: "allow" or "block".
	 */
	public function verify( string $session_id, array $context ): string {
		$payload = array( 'context' => $this->filter_empty_values( $context ) );

		// No-session case: send visitor_ip and full_headers at top level.
		if ( '' === $session_id ) {
			$payload['visitor_ip']   = Schemas\SessionInfo::get_ip_address();
			$payload['full_headers'] = self::get_request_headers();
		}

		$log_payload = $payload;
		if ( isset( $log_payload['full_headers'] ) ) {
			$log_payload['full_headers'] = sprintf( '(%d headers)', count( $log_payload['full_headers'] ) );
		}

		FraudProtectionController::log(
			'info',
			'Verifying session with Blackbox API',
			array(
				'session_id' => $session_id,
				'payload'    => $log_payload,
			)
		);

		$response = $this->make_request(
			'POST',
			self::VERIFY_ENDPOINT,
			$session_id,
			$payload
		);

		return $this->process_decision_response( $response, $payload );
	}

	/**
	 * Report a fraud event to the Blackbox API.
	 *
	 * Used for reporting outcomes and feedback to improve fraud detection.
	 * This is a fire-and-forget operation - errors are logged but do not
	 * affect the checkout flow.
	 *
	 * @param string               $session_id Session ID to report.
	 * @param array<string, mixed> $payload    Event data to send to the endpoint.
	 * @return bool True if report was sent successfully, false otherwise.
	 */
	public function report( string $session_id, array $payload ): bool {
		FraudProtectionController::log(
			'info',
			'Reporting event to Blackbox API',
			array( 'payload' => $payload )
		);

		$response = $this->make_request( 'POST', self::REPORT_ENDPOINT, $session_id, $payload );

		if ( is_wp_error( $response ) ) {
			FraudProtectionController::log(
				'error',
				sprintf(
					'Failed to report event to Blackbox API: %s',
					$response->get_error_message()
				),
				array( 'error' => $response->get_error_data() )
			);
			return false;
		}

		FraudProtectionController::log(
			'info',
			'Event reported successfully',
			array( 'response' => $response )
		);

		return true;
	}

	/**
	 * Process the API response and extract the decision.
	 *
	 * @param array<string, mixed>|\WP_Error $response   API response or WP_Error.
	 * @param array<string, mixed>           $event_data Event data for logging.
	 * @return string Decision: "allow" or "block".
	 */
	private function process_decision_response( $response, array $event_data ): string {
		if ( is_wp_error( $response ) ) {
			$error_data = $response->get_error_data() ?? array();
			$error_data = is_array( $error_data ) ? $error_data : array( 'error' => $error_data );
			FraudProtectionController::log(
				'error',
				sprintf(
					'Blackbox API request failed: %s. Failing open with "allow" decision.',
					$response->get_error_message()
				),
				$error_data
			);
			return self::DECISION_ALLOW;
		}

		$decision = $this->extract_decision( $response );

		if ( null === $decision ) {
			FraudProtectionController::log(
				'error',
				'Could not extract decision from response. Failing open with "allow" decision.',
				array( 'response' => $response )
			);
			return self::DECISION_ALLOW;
		}

		if ( ! in_array( $decision, self::VALID_DECISIONS, true ) ) {
			FraudProtectionController::log(
				'error',
				sprintf(
					'Invalid decision value "%s". Failing open with "allow" decision.',
					$decision
				),
				array( 'response' => $response )
			);
			return self::DECISION_ALLOW;
		}

		$context = is_array( $event_data['context'] ?? null ) ? $event_data['context'] : array();
		$source  = $context['source'] ?? 'unknown';
		FraudProtectionController::log(
			'info',
			sprintf(
				'Fraud decision received: %s | Source: %s',
				$decision,
				$source
			),
			array( 'response' => $response )
		);

		return $decision;
	}

	/**
	 * Make an HTTP request to the Blackbox API via Jetpack Connection.
	 *
	 * Uses Jetpack's signed request mechanism which authenticates with the
	 * blog token scoped to the blog_id.
	 *
	 * @param string               $method     HTTP method (GET, POST, etc.).
	 * @param string               $path       Endpoint path (relative to Blackbox API base URL).
	 * @param string               $session_id Session ID for the request.
	 * @param array<string, mixed> $payload    Request payload.
	 * @return array<string, mixed>|\WP_Error Parsed JSON response or WP_Error on failure.
	 */
	private function make_request( string $method, string $path, string $session_id, array $payload ) {
		if ( ! class_exists( Jetpack_Connection_Client::class ) ) {
			return new \WP_Error(
				'jetpack_not_available',
				'Jetpack Connection is not available'
			);
		}

		if ( ! $this->get_blog_id() ) {
			return new \WP_Error(
				'blog_id_not_found',
				'Jetpack blog ID not found'
			);
		}

		$body = \wp_json_encode(
			array_merge(
				$payload,
				array(
					'session_id' => $session_id,
				)
			)
		);

		if ( false === $body ) {
			return new \WP_Error(
				'json_encode_error',
				'Failed to encode payload',
				array( 'payload' => $payload )
			);
		}

		$url = self::BLACKBOX_API_BASE_URL . $path . '/' . $session_id;

		// Use Jetpack Connection Client to make a signed request.
		// This authenticates with the blog token automatically.
		$response = Jetpack_Connection_Client::remote_request(
			array(
				'url'           => $url,
				'method'        => $method,
				'timeout'       => self::DEFAULT_TIMEOUT,
				'headers'       => array( 'Content-Type' => 'application/json' ),
				'auth_location' => 'header',
			),
			$body
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		/**
		 * Type assertion for PHPStan - Jetpack returns array on success.
		 *
		 * @var array $response
		 */
		$response_code = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );

		$data = json_decode( $response_body, true );

		if ( $response_code >= 300 ) {
			return new \WP_Error(
				'api_error',
				sprintf( 'Blackbox API %s %s returned status code %d', $method, $path, $response_code ),
				array( 'response' => JSON_ERROR_NONE === json_last_error() ? $data : $response_body )
			);
		}

		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $data ) ) {
			return new \WP_Error(
				'json_decode_error',
				sprintf( 'Failed to decode JSON response: %s', json_last_error_msg() ),
				array( 'response' => $response_body )
			);
		}

		return $data;
	}

	/**
	 * Extract the decision string from the API response.
	 *
	 * Response format: { "data": { "decision": "allow", ... } }
	 *
	 * @param array<string, mixed> $response Parsed JSON response.
	 * @return string|null Lowercased decision string, or null if not extractable.
	 */
	private function extract_decision( array $response ): ?string {
		$data = $response['data'] ?? null;

		if ( is_array( $data ) && isset( $data['decision'] ) && is_string( $data['decision'] ) ) {
			return \strtolower( $data['decision'] );
		}

		return null;
	}

	/**
	 * Recursively remove null and empty-string values from an array.
	 *
	 * Preserves false, 0, 0.0, and empty arrays since those carry semantic meaning.
	 *
	 * @param array<string, mixed> $data The array to filter.
	 * @return array<string, mixed> Filtered array.
	 */
	private function filter_empty_values( array $data ): array {
		$filtered = array();
		foreach ( $data as $key => $value ) {
			if ( null === $value || '' === $value ) {
				continue;
			}
			if ( is_array( $value ) ) {
				$value = $this->filter_empty_values( $value );
			}
			$filtered[ $key ] = $value;
		}
		return $filtered;
	}

	/**
	 * Get all HTTP request headers for the no-session case.
	 *
	 * Uses getallheaders() when available, augmented with GEOIP and
	 * crawler server variables.
	 *
	 * @return array<string, ?string> Header name => value map.
	 */
	private static function get_request_headers(): array {
		$raw_headers = function_exists( 'getallheaders' ) ? getallheaders() : false;
		$headers     = array();
		if ( is_array( $raw_headers ) ) {
			foreach ( $raw_headers as $name => $value ) {
				$headers[ \sanitize_text_field( $name ) ] = \wp_strip_all_tags( $value );
			}
		}

		$server_keys = array(
			'GEOIP_COUNTRY_CODE',
			'GEOIP_ASN',
			'GEOIP_REGISTERED_COUNTRY_CODE',
			'GEOIP_CITY',
			'GEOIP_LATITUDE',
			'GEOIP_LONGITUDE',
			'GEOIP_TIME_ZONE',
			'HTTP_X_IS_CRAWLER',
		);
		foreach ( $server_keys as $key ) {
			if ( isset( $_SERVER[ $key ] ) ) {
				$headers[ $key ] = \wp_strip_all_tags( \wp_unslash( $_SERVER[ $key ] ) );
			}
		}

		// Strip sensitive headers (case-insensitive — header names vary by server).
		$sensitive = array( 'cookie', 'authorization', 'x-wp-nonce', 'x-woo-session', 'nonce' );
		foreach ( array_keys( $headers ) as $name ) {
			if ( in_array( strtolower( $name ), $sensitive, true ) ) {
				unset( $headers[ $name ] );
			}
		}

		return $headers;
	}

	/**
	 * Get the Jetpack blog ID.
	 *
	 * @return int|false Blog ID or false if not available.
	 */
	private function get_blog_id() {
		if ( ! class_exists( \Jetpack_Options::class ) ) {
			return false;
		}
		return \Jetpack_Options::get_option( 'id' );
	}
}
