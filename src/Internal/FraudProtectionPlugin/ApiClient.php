<?php
/**
 * ApiClient class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin;

use Automattic\Jetpack\Connection\Client as Jetpack_Connection_Client;
use Automattic\WooCommerce\FraudProtection\Schemas\FraudDecision;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Schemas\VerifyResult;

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
 */
class ApiClient {

	/**
	 * Default timeout for API requests in seconds.
	 *
	 * Sized above the Blackbox API's observed p99 server response time with
	 * headroom for network jitter. Bounds checkout latency when the service is
	 * slow while still letting normal verifications complete; failures fall
	 * back to the fail-open path.
	 */
	private const DEFAULT_TIMEOUT = 3;

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
	 * Upper bound on the session ID length used when building a request.
	 *
	 * Bounds the request URL size for an arbitrary client-supplied ID so an
	 * unexpectedly long value cannot push the URL past transport limits. Set well
	 * above any legitimate session ID length.
	 */
	private const MAX_SESSION_ID_LENGTH = 255;

	/**
	 * Verify a session with the Blackbox API and get a fraud decision.
	 *
	 * Implements fail-open pattern: if the endpoint is unreachable or times out,
	 * returns "allow" decision and logs the error.
	 *
	 * @param string               $session_id Session ID to verify.
	 * @param array<string, mixed> $context    Session context data to send to the endpoint.
	 * @return VerifyResult The decision, the Blackbox session ID (generated server-side on the no-session or degraded path), and the risk score.
	 */
	public function verify( string $session_id, array $context ): VerifyResult {
		$payload = array(
			'context'      => $this->filter_empty_values( $context ),
			'visitor_ip'   => Schemas\SessionInfo::get_ip_address(),
			'full_headers' => $this->get_request_headers(),
		);

		$log_payload                 = $payload;
		$log_payload['full_headers'] = sprintf( '(%d headers)', count( $payload['full_headers'] ) );

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

		return $this->process_decision_response( $response, $payload, $session_id );
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
		// Prune null/empty context values before sending, mirroring verify().
		if ( isset( $payload['context'] ) && is_array( $payload['context'] ) ) {
			$payload['context'] = $this->filter_empty_values( $payload['context'] );
		}

		FraudProtectionController::log(
			'info',
			'Reporting event to Blackbox API',
			array( 'payload' => $payload )
		);

		$response = $this->make_request( 'POST', self::REPORT_ENDPOINT, $session_id, $payload );

		if ( is_wp_error( $response ) ) {
			$error_data = $response->get_error_data() ?? array();
			$error_data = is_array( $error_data ) ? $error_data : array( 'error' => $error_data );
			FraudProtectionController::log(
				'error',
				sprintf(
					'Failed to report event to Blackbox API: %s',
					$response->get_error_message()
				),
				array_merge(
					$error_data,
					array(
						'event_source' => 'api_report',
						'session_id'   => $session_id,
						'api_endpoint' => self::REPORT_ENDPOINT,
						'error_code'   => (string) $response->get_error_code(),
					)
				),
				true
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
	 * @param string                         $session_id Session ID associated with the request, included in log context for cross-system tracing.
	 * @return VerifyResult The decision plus any Blackbox session ID returned in the response.
	 */
	private function process_decision_response( array|\WP_Error $response, array $event_data, string $session_id ): VerifyResult {
		if ( is_wp_error( $response ) ) {
			$error_data = $response->get_error_data() ?? array();
			$error_data = is_array( $error_data ) ? $error_data : array( 'error' => $error_data );
			FraudProtectionController::log(
				'error',
				sprintf(
					'Blackbox API request failed: %s. Failing open with "allow" decision.',
					$response->get_error_message()
				),
				array_merge(
					$error_data,
					array(
						'event_source' => 'api_verify',
						'session_id'   => $session_id,
						'api_endpoint' => self::VERIFY_ENDPOINT,
						'error_code'   => (string) $response->get_error_code(),
					)
				),
				true
			);
			return VerifyResult::create( FraudDecision::Allow, '' );
		}

		$raw = $this->extract_decision( $response );

		if ( is_null( $raw ) ) {
			FraudProtectionController::log(
				'error',
				'Could not extract decision from response. Failing open with "allow" decision.',
				array(
					'event_source' => 'api_verify',
					'session_id'   => $session_id,
					'api_endpoint' => self::VERIFY_ENDPOINT,
					'http_status'  => (int) wp_remote_retrieve_response_code( $response ),
					'response'     => $response,
				),
				true
			);
			return VerifyResult::create( FraudDecision::Allow, '' );
		}

		$decision = FraudDecision::tryFrom( $raw );

		if ( is_null( $decision ) || ! in_array( $decision, FraudDecision::ACTIONABLE, true ) ) {
			FraudProtectionController::log(
				'error',
				sprintf(
					'Invalid decision value "%s". Failing open with "allow" decision.',
					$raw
				),
				array(
					'event_source'      => 'api_verify',
					'session_id'        => $session_id,
					'api_endpoint'      => self::VERIFY_ENDPOINT,
					'http_status'       => (int) wp_remote_retrieve_response_code( $response ),
					'decision_received' => $raw,
					'response'          => $response,
				),
				true
			);
			return VerifyResult::create( FraudDecision::Allow, '' );
		}

		$context = is_array( $event_data['context'] ?? null ) ? $event_data['context'] : array();
		$source  = $context['source'] ?? 'unknown';
		FraudProtectionController::log(
			'info',
			sprintf(
				'Fraud decision received: %s | Source: %s',
				$decision->value,
				$source
			),
			array( 'response' => $response )
		);

		return VerifyResult::create(
			$decision,
			$this->extract_session_id( $response ),
			$this->extract_risk_score( $response )
		);
	}

	/**
	 * Make a request to the Blackbox API and parse the JSON response.
	 *
	 * Builds the request and hands it to {@see jetpack_remote_request()}, which
	 * performs the actual transport. The parsed response `data` array is returned
	 * on success; any transport, status, or parsing failure becomes a WP_Error so
	 * the caller can fail open.
	 *
	 * @param string               $method     HTTP method (GET, POST, etc.).
	 * @param string               $path       Endpoint path (relative to Blackbox API base URL).
	 * @param string               $session_id Session ID for the request.
	 * @param array<string, mixed> $payload    Request payload.
	 * @return array<string, mixed>|\WP_Error Parsed JSON response or WP_Error on failure.
	 */
	private function make_request( string $method, string $path, string $session_id, array $payload ): array|\WP_Error {
		// Bound the client-supplied session ID before it goes into the URL and body so an
		// unexpectedly long value cannot push the request URL past transport limits.
		$session_id = substr( $session_id, 0, self::MAX_SESSION_ID_LENGTH );

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

		$request_args = array(
			// The session ID is client-supplied: encode it so it cannot malform or redirect the request URL.
			'url'           => self::BLACKBOX_API_BASE_URL . $path . '/' . rawurlencode( $session_id ),
			'method'        => $method,
			'timeout'       => self::DEFAULT_TIMEOUT,
			'headers'       => array( 'Content-Type' => 'application/json' ),
			'auth_location' => 'header',
		);

		$response = $this->jetpack_remote_request( $request_args, $body );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

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
	 * The Blackbox API transport: a signed request via Jetpack Connection.
	 *
	 * Authenticates with the blog token scoped to the Jetpack blog ID. Returns a
	 * WP_Error (so the caller can fail open) when Jetpack Connection is
	 * unavailable or the site is not Jetpack-connected. Isolated in its own
	 * `protected` method so tests can override it (see {@see ApiClientTest}).
	 *
	 * @param array<string, mixed> $request_args Request arguments (url, method, timeout, headers, auth_location).
	 * @param string               $body         JSON-encoded request body.
	 * @return array<string, mixed>|\WP_Error WordPress HTTP response array, or WP_Error on failure.
	 */
	protected function jetpack_remote_request( array $request_args, string $body ): array|\WP_Error {
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

		// Use Jetpack Connection Client to make a signed request.
		// This authenticates with the blog token automatically.
		return Jetpack_Connection_Client::remote_request( $request_args, $body );
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
	 * Extract the Blackbox session ID from the verify response.
	 *
	 * Response format: { "data": { "session_id": "...", ... } }
	 *
	 * @param array<string, mixed> $response Parsed JSON response.
	 * @return string The session ID, or empty string if not present.
	 */
	private function extract_session_id( array $response ): string {
		$data = $response['data'] ?? null;

		if ( is_array( $data ) && isset( $data['session_id'] ) && is_string( $data['session_id'] ) ) {
			return $data['session_id'];
		}

		return '';
	}

	/**
	 * Extract the Blackbox risk score from the verify response.
	 *
	 * Response format: { "data": { "risk_score": 0.40, ... } }
	 *
	 * @param array<string, mixed> $response Parsed JSON response.
	 * @return ?float The risk score, or null if absent or non-numeric.
	 */
	private function extract_risk_score( array $response ): ?float {
		$data = $response['data'] ?? null;

		if ( is_array( $data ) && isset( $data['risk_score'] ) && is_numeric( $data['risk_score'] ) ) {
			return (float) $data['risk_score'];
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
	 * Get all HTTP request headers to send with every verify request.
	 *
	 * Uses getallheaders() when available, augmented with GEOIP and
	 * crawler server variables.
	 *
	 * @return array<string, ?string> Header name => value map.
	 */
	private function get_request_headers(): array {
		$raw_headers = $this->get_raw_request_headers();
		$headers     = array();
		if ( is_array( $raw_headers ) ) {
			foreach ( $raw_headers as $name => $value ) {
				// Header names are HTTP tokens (RFC 9110). Keep a conforming name verbatim and skip a malformed one.
				if ( ! is_string( $name ) || 1 !== preg_match( '/^[A-Za-z0-9!#$%&\'*+.^_`|~-]+$/', $name ) ) {
					continue;
				}
				$headers[ $name ] = $value;
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
		$sensitive = array(
			'cookie',
			'authorization',
			'proxy-authorization',
			'authentication',
			'x-api-key',
			'www-authenticate',
			'x-wp-nonce',
			'x-woo-session',
			'nonce',
		);
		foreach ( array_keys( $headers ) as $name ) {
			if ( in_array( strtolower( $name ), $sensitive, true ) ) {
				unset( $headers[ $name ] );
			}
		}

		return $headers;
	}

	/**
	 * Read the raw request headers from the SAPI.
	 *
	 * Isolated in its own `protected` method so tests can override it (see
	 * {@see ApiClientTest}); getallheaders() is unavailable under CLI.
	 *
	 * @return array<string, string>|false Raw header map, or false when unavailable.
	 */
	protected function get_raw_request_headers(): array|false {
		return function_exists( 'getallheaders' ) ? getallheaders() : false;
	}

	/**
	 * Get the Jetpack blog ID.
	 *
	 * @return int|false Blog ID or false if not available.
	 */
	private function get_blog_id(): int|false {
		if ( ! class_exists( \Jetpack_Options::class ) ) {
			return false;
		}

		$blog_id = \Jetpack_Options::get_option( 'id' );

		if ( ! is_numeric( $blog_id ) || (int) $blog_id <= 0 ) {
			return false;
		}

		return (int) $blog_id;
	}
}
