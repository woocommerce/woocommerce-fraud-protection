<?php
/**
 * ApiClientTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin;

use Automattic\WooCommerce\Internal\FraudProtectionPlugin\ApiClient;
use Automattic\WooCommerce\FraudProtection\Schemas\FraudDecision;
use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\VisitorIpResolver;
use WP_Error;

/**
 * Tests for the ApiClient class.
 *
 * Tests the Blackbox API client which provides:
 * - verify(): Verify a session and get a fraud decision (allow/block)
 * - report(): Report fraud events for feedback
 *
 * API calls are exercised by stubbing the transport seam ({@see ApiClient::jetpack_remote_request()})
 * rather than the WordPress HTTP pipeline, so the tests do not depend on Jetpack Connection.
 */
class ApiClientTest extends FraudProtectionUnitTestCase {

	/**
	 * The System Under Test.
	 *
	 * @var ApiClient
	 */
	private ApiClient $sut;

	/**
	 * Mock visitor IP resolver.
	 *
	 * @var VisitorIpResolver&\PHPUnit\Framework\MockObject\MockObject
	 */
	private $visitor_ip_resolver;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->visitor_ip_resolver = $this->createMock( VisitorIpResolver::class );
		$this->sut                 = $this->getMockBuilder( ApiClient::class )
			->onlyMethods( array( 'jetpack_remote_request', 'get_raw_request_headers' ) )
			->getMock();
		$this->sut->init( $this->visitor_ip_resolver );

		update_option( 'jetpack_options', array( 'id' => 12345 ) );
		update_option( 'jetpack_private_options', array( 'blog_token' => 'IAM.AJETPACKBLOGTOKEN' ) );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		delete_option( 'jetpack_options' );
		delete_option( 'jetpack_private_options' );

		parent::tearDown();
	}

	/**
	 * Configure the ApiClient transport to return $response.
	 *
	 * When given, $capture receives the request args and JSON body the client
	 * built.
	 *
	 * @param array<string, mixed>|WP_Error $response The stubbed transport response.
	 * @param ?callable                     $capture  Optional callback( array $request_args, string $body ).
	 * @return ApiClient
	 */
	private function api_client_returning( $response, ?callable $capture = null ): ApiClient {
		$this->sut->method( 'jetpack_remote_request' )->willReturnCallback(
			function ( array $request_args, string $body ) use ( $response, $capture ) {
				if ( null !== $capture ) {
					$capture( $request_args, $body );
				}

				return $response;
			}
		);

		return $this->sut;
	}

	/**
	 * A canned successful transport response carrying the given decision.
	 *
	 * @param string $decision The decision to return in the response body.
	 * @return array<string, mixed>
	 */
	private function decision_response( string $decision ): array {
		return array(
			'response' => array( 'code' => 200 ),
			'body'     => wp_json_encode( array( 'data' => array( 'decision' => $decision ) ) ),
		);
	}

	/*
	|--------------------------------------------------------------------------
	| verify() Tests
	|--------------------------------------------------------------------------
	*/

	/**
	 * Test verify calls correct endpoint with payload.
	 *
	 * @testdox verify() calls Blackbox API /verify endpoint with the correct payload
	 */
	public function test_verify_calls_verify_endpoint(): void {
		$captured_url  = null;
		$captured_body = null;

		$sut = $this->api_client_returning(
			$this->decision_response( 'allow' ),
			function ( array $request_args, string $body ) use ( &$captured_url, &$captured_body ) {
				$captured_url  = $request_args['url'];
				$captured_body = json_decode( $body, true );
			}
		);

		$sut->verify( 'test-session-id', array( 'source' => 'blocks_checkout' ) );

		$this->assertStringContainsString( 'blackbox-api.wp.com/v1/verify/test-session-id', $captured_url );
		$this->assertSame( 'test-session-id', $captured_body['session_id'] );
		$this->assertArrayHasKey( 'context', $captured_body );
	}

	/**
	 * @testdox verify() URL-encodes the session ID so a crafted value cannot alter the request URL
	 */
	public function test_verify_url_encodes_session_id(): void {
		$captured_url = null;

		$sut = $this->api_client_returning(
			$this->decision_response( 'allow' ),
			function ( array $request_args ) use ( &$captured_url ) {
				$captured_url = $request_args['url'];
			}
		);

		// Characters that survive sanitize_text_field() but would malform the URL or pivot the path.
		$sut->verify( 'a b%#?/../report', array( 'source' => 'blocks_checkout' ) );

		$this->assertStringContainsString( 'blackbox-api.wp.com/v1/verify/a%20b%25%23%3F%2F..%2Freport', $captured_url );
	}

	/**
	 * @testdox verify() caps an over-length session ID so the request URL stays within transport limits
	 */
	public function test_verify_caps_over_length_session_id(): void {
		$captured_url  = null;
		$captured_body = null;

		$sut = $this->api_client_returning(
			$this->decision_response( 'allow' ),
			function ( array $request_args, string $body ) use ( &$captured_url, &$captured_body ) {
				$captured_url  = $request_args['url'];
				$captured_body = json_decode( $body, true );
			}
		);

		$sut->verify( str_repeat( 'a', 5000 ), array( 'source' => 'blocks_checkout' ) );

		// The over-length value is truncated (to MAX_SESSION_ID_LENGTH, 255) in both URL and body.
		$expected = str_repeat( 'a', 255 );
		$this->assertStringEndsWith( '/verify/' . $expected, $captured_url );
		$this->assertSame( $expected, $captured_body['session_id'] );
	}

	/**
	 * @testdox verify() strips null and empty-string values from context but preserves top-level fields
	 */
	public function test_verify_filters_empty_values_only_in_context(): void {
		$captured_body = null;

		$sut = $this->api_client_returning(
			$this->decision_response( 'allow' ),
			function ( array $request_args, string $body ) use ( &$captured_body ) {
				$captured_body = json_decode( $body, true );
			}
		);

		// Use empty session_id to trigger no-session top-level fields.
		$sut->verify(
			'',
			array(
				'keep_string'  => 'hello',
				'keep_false'   => false,
				'keep_zero'    => 0,
				'keep_float'   => 0.0,
				'keep_array'   => array(),
				'drop_null'    => null,
				'drop_empty'   => '',
				'nested'       => array(
					'keep'  => 'yes',
					'drop'  => null,
				),
			)
		);

		$context = $captured_body['context'];

		// Null and empty-string values stripped inside context.
		$this->assertSame( 'hello', $context['keep_string'] );
		$this->assertFalse( $context['keep_false'] );
		$this->assertSame( 0, $context['keep_zero'] );
		$this->assertSame( 0, $context['keep_float'] ); // JSON encodes 0.0 as 0.
		$this->assertSame( array(), $context['keep_array'] );
		$this->assertArrayNotHasKey( 'drop_null', $context );
		$this->assertArrayNotHasKey( 'drop_empty', $context );
		$this->assertSame( 'yes', $context['nested']['keep'] );
		$this->assertArrayNotHasKey( 'drop', $context['nested'] );

		// Top-level fields are NOT filtered — empty session_id preserved.
		$this->assertSame( '', $captured_body['session_id'] );
	}

	/**
	 * Test verify returns allow decision.
	 *
	 * @testdox verify() returns allow decision from API
	 */
	public function test_verify_returns_allow_decision(): void {
		$sut = $this->api_client_returning( $this->decision_response( 'allow' ) );

		$result = $sut->verify( 'test-session-id', array( 'source' => 'blocks_checkout' ) );

		$this->assertSame( FraudDecision::Allow, $result->decision );
	}

	/**
	 * Test verify returns block decision.
	 *
	 * @testdox verify() returns block decision from API
	 */
	public function test_verify_returns_block_decision(): void {
		$sut = $this->api_client_returning( $this->decision_response( 'block' ) );

		$result = $sut->verify( 'test-session-id', array( 'source' => 'blocks_checkout' ) );

		$this->assertSame( FraudDecision::Block, $result->decision );
		$this->assertFalse( $result->fail_open, 'A parsed verdict is not a fail-open result' );
	}

	/**
	 * Test verify fails open when blog_id not found.
	 *
	 * Exercises the real default transport (jetpack_remote_request) so the
	 * Jetpack blog-ID guard is covered.
	 *
	 * @testdox verify() fails open with allow when blog_id not found
	 */
	public function test_verify_fails_open_when_blog_id_not_found(): void {
		update_option( 'jetpack_options', array( 'id' => null ) );
		$sut = new ApiClient();
		$sut->init( $this->visitor_ip_resolver );

		$result = $sut->verify( 'test-session-id', array( 'source' => 'blocks_checkout' ) );

		$this->assertSame( FraudDecision::Allow, $result->decision );
		$this->assertTrue( $result->fail_open );
		$this->assertLogged( 'error', 'Jetpack blog ID not found' );
	}

	/**
	 * Test verify fails open on HTTP error.
	 *
	 * @testdox verify() fails open with allow when HTTP request fails
	 */
	public function test_verify_fails_open_on_http_error(): void {
		$sut = $this->api_client_returning( new WP_Error( 'http_error', 'Connection timeout' ) );

		$result = $sut->verify( 'test-session-id', array( 'source' => 'blocks_checkout' ) );

		$this->assertSame( FraudDecision::Allow, $result->decision );
		$this->assertSame( 'test-session-id', $result->session_id, 'The fail-open result should carry the session ID the request was made with' );
		$this->assertTrue( $result->fail_open );
		$this->assertLogged( 'error', 'Connection timeout' );
	}

	/**
	 * Test verify fails open on server error.
	 *
	 * @testdox verify() fails open with allow when API returns 5xx error
	 */
	public function test_verify_fails_open_on_server_error(): void {
		$sut = $this->api_client_returning(
			array(
				'response' => array( 'code' => 500 ),
				'body'     => 'Internal Server Error',
			)
		);

		$result = $sut->verify( 'test-session-id', array( 'source' => 'blocks_checkout' ) );

		$this->assertSame( FraudDecision::Allow, $result->decision );
		$this->assertTrue( $result->fail_open );
		$this->assertLogged( 'error', 'status code 500' );
	}

	/**
	 * Test verify fails open on invalid JSON.
	 *
	 * @testdox verify() fails open with allow when API returns invalid JSON
	 */
	public function test_verify_fails_open_on_invalid_json(): void {
		$sut = $this->api_client_returning(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => 'not valid json',
			)
		);

		$result = $sut->verify( 'test-session-id', array( 'source' => 'blocks_checkout' ) );

		$this->assertSame( FraudDecision::Allow, $result->decision );
		$this->assertTrue( $result->fail_open );
		$this->assertLogged( 'error', 'Failed to decode JSON' );
	}

	/**
	 * Test verify fails open when data field missing.
	 *
	 * @testdox verify() fails open with allow when response missing data field
	 */
	public function test_verify_fails_open_when_missing_data(): void {
		$sut = $this->api_client_returning(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'risk_score' => 50 ) ),
			)
		);

		$result = $sut->verify( 'test-session-id', array( 'source' => 'blocks_checkout' ) );

		$this->assertSame( FraudDecision::Allow, $result->decision );
		$this->assertSame( 'test-session-id', $result->session_id, 'The fail-open result should carry the session ID the request was made with' );
		$this->assertTrue( $result->fail_open );
		$this->assertLogged( 'error', 'Could not extract decision' );
	}

	/**
	 * Test verify fails open on invalid decision value.
	 *
	 * @testdox verify() fails open with allow when decision value is invalid
	 */
	public function test_verify_fails_open_on_invalid_decision(): void {
		$sut = $this->api_client_returning( $this->decision_response( 'unknown_value' ) );

		$result = $sut->verify( 'test-session-id', array( 'source' => 'blocks_checkout' ) );

		$this->assertSame( FraudDecision::Allow, $result->decision );
		$this->assertTrue( $result->fail_open );
		$this->assertLogged( 'error', 'Invalid decision value' );
	}

	/**
	 * Test verify passes through a known-but-non-actionable decision.
	 *
	 * `challenge` is a valid FraudDecision case that is not in FraudDecision::ACTIONABLE.
	 * It survives parsing so the session event recorder can see the received decision;
	 * DecisionHandler is responsible for coercing it to allow.
	 *
	 * @testdox verify() returns the challenge decision unchanged (coercion happens in DecisionHandler)
	 */
	public function test_verify_passes_through_non_actionable_decision(): void {
		$sut = $this->api_client_returning( $this->decision_response( 'challenge' ) );

		$result = $sut->verify( 'test-session-id', array( 'source' => 'blocks_checkout' ) );

		$this->assertSame( FraudDecision::Challenge, $result->decision );
	}

	/*
	|--------------------------------------------------------------------------
	| Verify payload tests
	|--------------------------------------------------------------------------
	*/

	/**
	 * @testdox verify() adds visitor_ip and full_headers regardless of session_id
	 *
	 * @dataProvider verify_session_id_provider
	 *
	 * @param string $session_id Session ID passed to verify(), or empty for no-session.
	 */
	public function test_verify_adds_visitor_ip_and_full_headers( string $session_id ): void {
		$captured_body = null;

		$sut = $this->api_client_returning(
			$this->decision_response( 'allow' ),
			function ( array $request_args, string $body ) use ( &$captured_body ) {
				$captured_body = json_decode( $body, true );
			}
		);

		$sut->verify(
			$session_id,
			array(
				'session' => array(
					'wc_identity_id' => 'abc',
					'email'          => 'test@example.com',
				),
				'source'  => 'blocks_checkout',
			)
		);

		// Session fields preserved in context.
		$this->assertSame( 'abc', $captured_body['context']['session']['wc_identity_id'] );
		$this->assertSame( 'test@example.com', $captured_body['context']['session']['email'] );

		// Top-level request metadata fields present.
		$this->assertArrayHasKey( 'visitor_ip', $captured_body );
		$this->assertArrayHasKey( 'full_headers', $captured_body );
		$this->assertIsArray( $captured_body['full_headers'] );
	}

	/**
	 * @testdox verify() gets visitor_ip from VisitorIpResolver and keeps forwarding headers in full_headers.
	 */
	public function test_verify_uses_visitor_ip_resolver_and_keeps_forwarding_headers(): void {
		$captured_body      = null;
		$forwarding_headers = array(
			'X-Real-IP'        => '198.51.100.1',
			'X-Forwarded-For'  => '198.51.100.2',
			'Client-IP'         => '198.51.100.3',
			'Forwarded'         => 'for=198.51.100.4',
			'CF-Connecting-IP' => '198.51.100.5',
		);
		$this->visitor_ip_resolver
			->expects( $this->once() )
			->method( 'get_ip_address' )
			->willReturn( '203.0.113.7' );

		$this->sut->method( 'get_raw_request_headers' )->willReturn( $forwarding_headers );
		$sut = $this->api_client_returning(
			$this->decision_response( 'allow' ),
			function ( array $request_args, string $body ) use ( &$captured_body ) {
				$captured_body = json_decode( $body, true );
			}
		);

		$sut->verify( 'has-session', array( 'source' => 'blocks_checkout' ) );

		$this->assertSame( '203.0.113.7', $captured_body['visitor_ip'] );
		foreach ( $forwarding_headers as $name => $value ) {
			$this->assertSame( $value, $captured_body['full_headers'][ $name ] );
		}
	}

	/**
	 * @testdox verify() keeps a null visitor_ip from VisitorIpResolver.
	 */
	public function test_verify_keeps_null_visitor_ip_from_resolver(): void {
		$captured_body = null;
		$this->visitor_ip_resolver
			->expects( $this->once() )
			->method( 'get_ip_address' )
			->willReturn( null );

		$this->sut->method( 'get_raw_request_headers' )->willReturn( array( 'X-Real-IP' => '198.51.100.1' ) );
		$sut = $this->api_client_returning(
			$this->decision_response( 'allow' ),
			function ( array $request_args, string $body ) use ( &$captured_body ) {
				$captured_body = json_decode( $body, true );
			}
		);

		$sut->verify( 'has-session', array( 'source' => 'blocks_checkout' ) );

		$this->assertArrayHasKey( 'visitor_ip', $captured_body );
		$this->assertNull( $captured_body['visitor_ip'] );
	}

	/**
	 * Session IDs for verify() request-metadata payload coverage.
	 *
	 * @return array<string, array{string}>
	 */
	public function verify_session_id_provider(): array {
		return array(
			'no session'   => array( '' ),
			'with session' => array( 'has-session' ),
		);
	}

	/**
	 * @testdox verify() strips sensitive headers from full_headers case-insensitively and keeps the rest
	 */
	public function test_verify_strips_sensitive_headers(): void {
		$this->set_server_variables( array( 'GEOIP_COUNTRY_CODE' => 'DE' ) );
		$captured_body = null;

		$this->sut->method( 'get_raw_request_headers' )->willReturn(
			array(
				'User-Agent'          => 'Mozilla/5.0',
				'Accept-Language'     => 'en-US',
				'Cookie'              => 'wp_logged_in=secret',
				'AUTHORIZATION'       => 'Bearer secret',
				'Proxy-Authorization' => 'Basic secret',
				'X-Api-Key'           => 'secret-key',
				'X-WP-Nonce'          => 'nonce-value',
			)
		);
		$sut = $this->api_client_returning(
			$this->decision_response( 'allow' ),
			function ( array $request_args, string $body ) use ( &$captured_body ) {
				$captured_body = json_decode( $body, true );
			}
		);

		$sut->verify( 'has-session', array( 'source' => 'blocks_checkout' ) );

		$headers = $captured_body['full_headers'];

		// Benign headers and GEOIP server variables pass through.
		$this->assertSame( 'Mozilla/5.0', $headers['User-Agent'] );
		$this->assertSame( 'en-US', $headers['Accept-Language'] );
		$this->assertSame( 'DE', $headers['GEOIP_COUNTRY_CODE'] );

		// Credential-bearing headers are stripped regardless of casing.
		foreach ( array( 'Cookie', 'AUTHORIZATION', 'Proxy-Authorization', 'X-Api-Key', 'X-WP-Nonce' ) as $stripped ) {
			$this->assertArrayNotHasKey( $stripped, $headers );
		}
	}

	/**
	 * @testdox verify() keeps valid HTTP header names verbatim and drops malformed ones without colliding keys
	 */
	public function test_verify_drops_malformed_header_names(): void {
		$captured_body = null;

		$this->sut->method( 'get_raw_request_headers' )->willReturn(
			array(
				'User-Agent'  => 'Mozilla/5.0',
				"Bad\xFFName" => 'first',
				"Other\xFEId" => 'second',
			)
		);
		$sut = $this->api_client_returning(
			$this->decision_response( 'allow' ),
			function ( array $request_args, string $body ) use ( &$captured_body ) {
				$captured_body = json_decode( $body, true );
			}
		);

		$sut->verify( 'has-session', array( 'source' => 'blocks_checkout' ) );

		$headers = $captured_body['full_headers'];

		// The valid token name survives verbatim.
		$this->assertSame( 'Mozilla/5.0', $headers['User-Agent'] );
		// Malformed names are dropped, not folded to a single empty key that collides.
		$this->assertArrayNotHasKey( '', $headers );
		$this->assertNotContains( 'first', $headers );
		$this->assertNotContains( 'second', $headers );
	}

	/**
	 * @testdox verify() still builds a valid JSON body when a header value contains invalid UTF-8
	 */
	public function test_verify_encodes_non_utf8_header_value(): void {
		$captured_body = null;

		$this->sut->method( 'get_raw_request_headers' )->willReturn( array( 'X-Note' => "K\xFFln" ) );
		$sut = $this->api_client_returning(
			$this->decision_response( 'allow' ),
			function ( array $request_args, string $body ) use ( &$captured_body ) {
				$captured_body = $body;
			}
		);

		$sut->verify( 'has-session', array( 'source' => 'blocks_checkout' ) );

		// Invalid bytes don't break encoding: the transport received a valid JSON body
		// (a failed encode would return a WP_Error before the request is made, leaving this null).
		$decoded = json_decode( (string) $captured_body, true );
		$this->assertIsArray( $decoded );
		$this->assertArrayHasKey( 'X-Note', $decoded['full_headers'] );
	}

	/*
	|--------------------------------------------------------------------------
	| Session ID capture tests
	|--------------------------------------------------------------------------
	*/

	/**
	 * @testdox verify() captures the Blackbox-generated session ID on the no-session path
	 */
	public function test_verify_captures_generated_session_id_for_no_session(): void {
		$sut = $this->api_client_returning(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode(
					array(
						'message' => 'OK',
						'data'    => array(
							'session_id' => '82vHd2iPY4JvJZQE-A6jHg',
							'risk_score' => 0.4033,
							'decision'   => 'allow',
						),
					)
				),
			)
		);

		$result = $sut->verify( '', array( 'source' => 'blocks_checkout' ) );

		$this->assertSame( FraudDecision::Allow, $result->decision );
		$this->assertSame( '82vHd2iPY4JvJZQE-A6jHg', $result->session_id );
		$this->assertSame( 0.4033, $result->risk_score );
	}

	/**
	 * @testdox verify() returns an empty session ID when the response omits one
	 */
	public function test_verify_returns_empty_session_id_when_absent(): void {
		$sut = $this->api_client_returning( $this->decision_response( 'allow' ) );

		$result = $sut->verify( '', array( 'source' => 'blocks_checkout' ) );

		$this->assertSame( FraudDecision::Allow, $result->decision );
		$this->assertSame( '', $result->session_id );
		$this->assertNull( $result->risk_score );
	}

	/**
	 * @testdox verify() prefers the session ID the response returned over the requested one (degraded verify)
	 */
	public function test_verify_prefers_response_session_id(): void {
		$sut = $this->api_client_returning(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode(
					array(
						'data' => array(
							'session_id' => 'server-generated-id',
							'decision'   => 'allow',
						),
					)
				),
			)
		);

		$result = $sut->verify( 'requested-id', array( 'source' => 'blocks_checkout' ) );

		$this->assertSame( 'server-generated-id', $result->session_id, 'A degraded sessionful verify may create a new session; /report must attach outcomes to the ID Blackbox knows' );
	}

	/**
	 * @testdox verify() falls back to the requested session ID when the response omits one (normal sessionful path)
	 */
	public function test_verify_falls_back_to_requested_session_id(): void {
		$sut = $this->api_client_returning( $this->decision_response( 'allow' ) );

		$result = $sut->verify( 'requested-id', array( 'source' => 'blocks_checkout' ) );

		$this->assertSame( 'requested-id', $result->session_id );
	}

	/*
	|--------------------------------------------------------------------------
	| report() Tests
	|--------------------------------------------------------------------------
	*/

	/**
	 * Test report calls correct endpoint.
	 *
	 * @testdox report() calls Blackbox API /report endpoint
	 */
	public function test_report_calls_report_endpoint(): void {
		$captured_url  = null;
		$captured_body = null;

		$sut = $this->api_client_returning(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'status' => 'ok' ) ),
			),
			function ( array $request_args, string $body ) use ( &$captured_url, &$captured_body ) {
				$captured_url  = $request_args['url'];
				$captured_body = json_decode( $body, true );
			}
		);

		$sut->report( 'test-session-id', array( 'event_type' => 'payment_success' ) );

		$this->assertStringContainsString( 'blackbox-api.wp.com/v1/report/test-session-id', $captured_url );
		$this->assertSame( 'test-session-id', $captured_body['session_id'] );
		$this->assertArrayNotHasKey( 'context', $captured_body );
		$this->assertSame( 'payment_success', $captured_body['event_type'] );
	}

	/**
	 * Test report strips null/empty context values, mirroring verify().
	 *
	 * @testdox report() strips null and empty-string values from context, preserving zero and top-level fields
	 */
	public function test_report_filters_empty_values_in_context(): void {
		$captured_body = null;

		$sut = $this->api_client_returning(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'status' => 'ok' ) ),
			),
			function ( array $request_args, string $body ) use ( &$captured_body ) {
				$captured_body = json_decode( $body, true );
			}
		);

		$sut->report(
			'test-session-id',
			array(
				'source'  => 'chargeback',
				'notes'   => '',
				'context' => array(
					'type'               => 'dispute',
					'result'             => 'lost',
					'amount_minor_units' => 0,
					'reason'             => null,
					'amount_currency'    => null,
					'instrument'         => array(
						'bin'   => '424242',
						'last4' => null,
					),
				),
			)
		);

		$context = $captured_body['context'];

		// Null/empty context values are stripped; a real zero amount survives.
		$this->assertSame( 'dispute', $context['type'] );
		$this->assertSame( 0, $context['amount_minor_units'] );
		$this->assertArrayNotHasKey( 'reason', $context );
		$this->assertArrayNotHasKey( 'amount_currency', $context );
		$this->assertSame( '424242', $context['instrument']['bin'] );
		$this->assertArrayNotHasKey( 'last4', $context['instrument'] );

		// Top-level report fields are not filtered.
		$this->assertSame( 'chargeback', $captured_body['source'] );
		$this->assertSame( '', $captured_body['notes'] );
	}

	/**
	 * Test report returns true on success.
	 *
	 * @testdox report() returns true on success
	 */
	public function test_report_returns_true_on_success(): void {
		$sut = $this->api_client_returning(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'status' => 'ok' ) ),
			)
		);

		$result = $sut->report( 'test-session-id', array( 'event_type' => 'payment_success' ) );

		$this->assertTrue( $result );
		$this->assertLogged( 'info', 'Event reported successfully' );
	}

	/**
	 * Test report returns false on HTTP error.
	 *
	 * @testdox report() returns false when HTTP request fails
	 */
	public function test_report_returns_false_on_http_error(): void {
		$sut = $this->api_client_returning( new WP_Error( 'http_error', 'Connection timeout' ) );

		$result = $sut->report( 'test-session-id', array( 'event_type' => 'payment_success' ) );

		$this->assertFalse( $result );
		$this->assertLogged( 'error', 'Failed to report event' );
	}

	/**
	 * Test report returns false on server error.
	 *
	 * @testdox report() returns false when API returns error status
	 */
	public function test_report_returns_false_on_server_error(): void {
		$sut = $this->api_client_returning(
			array(
				'response' => array( 'code' => 500 ),
				'body'     => 'Internal Server Error',
			)
		);

		$result = $sut->report( 'test-session-id', array( 'event_type' => 'payment_success' ) );

		$this->assertFalse( $result );
		$this->assertLogged( 'error', 'status code 500' );
	}

	/**
	 * @testdox verify() drops an unencodable value and still reaches the transport
	 *
	 * One case per shape the allowlist rejects. Each costs its own field: the body still encodes,
	 * the transport is still called, and the verdict comes back as normal.
	 *
	 * @dataProvider unencodable_quantity_provider
	 *
	 * @param mixed $quantity          Value carrying something the encoder cannot represent.
	 * @param mixed $expected_quantity What survives on the wire, or '<absent>' when the key is dropped.
	 */
	public function test_verify_drops_unencodable_values_and_still_calls_transport( mixed $quantity, mixed $expected_quantity ): void {
		$captured_body = null;
		$sut           = $this->api_client_returning(
			$this->decision_response( 'block' ),
			function ( array $request_args, string $body ) use ( &$captured_body ) {
				$captured_body = json_decode( $body, true );
			}
		);

		$result = $sut->verify(
			'test-session-id',
			array(
				'events' => array(
					array(
						'action'   => 'item_added',
						'quantity' => $quantity,
					),
				),
			)
		);

		$this->assertIsArray( $captured_body, 'The transport must be reached with an encodable body' );
		$this->assertSame( FraudDecision::Block, $result->decision, 'The real verdict must be honoured' );
		$this->assertFalse( $result->fail_open, 'Verification must not fail open' );

		$event = $captured_body['context']['events'][0];
		$this->assertSame( 'item_added', $event['action'], 'The surrounding event must survive' );
		$this->assertSame( $expected_quantity, $event['quantity'] ?? '<absent>', 'The unencodable value must not survive' );
	}

	/**
	 * @return array<string, array{mixed, mixed}>
	 */
	public function unencodable_quantity_provider(): array {
		return array(
			// A scalar is dropped outright; a nested one leaves its container behind.
			'positive infinity'        => array( INF, '<absent>' ),
			'negative infinity'        => array( -INF, '<absent>' ),
			'not a number'             => array( NAN, '<absent>' ),
			'nested positive infinity' => array( array( INF ), array() ),
			'object carrying INF'      => array( new NonFiniteBearer(), '<absent>' ),
			// The type the previous, enumerate-the-bad-shapes guard let through.
			'resource'                 => array( STDERR, '<absent>' ),
		);
	}

	/**
	 * @testdox verify() relays every encodable type untouched, including strings JSON must repair
	 *
	 * The allowlist keeps by type, so it has to be shown keeping things — a guard that dropped
	 * everything would pass the test above. Invalid UTF-8 matters most: json_encode() rejects it
	 * outright, and only wp_json_encode()'s repair pass saves it, so testing each string here
	 * would destroy data WordPress was going to salvage.
	 *
	 * @dataProvider encodable_quantity_provider
	 *
	 * @param mixed $quantity Value that must survive.
	 * @param mixed $expected What the wire should carry.
	 */
	public function test_verify_relays_encodable_values_untouched( mixed $quantity, mixed $expected ): void {
		$captured_body = null;
		$sut           = $this->api_client_returning(
			$this->decision_response( 'allow' ),
			function ( array $request_args, string $body ) use ( &$captured_body ) {
				$captured_body = json_decode( $body, true );
			}
		);

		$sut->verify( 'test-session-id', array( 'events' => array( array( 'quantity' => $quantity ) ) ) );

		$this->assertIsArray( $captured_body, 'The transport must be reached' );
		$this->assertSame( $expected, $captured_body['context']['events'][0]['quantity'] ?? '<absent>' );
	}

	/**
	 * @return array<string, array{mixed, mixed}>
	 */
	public function encodable_quantity_provider(): array {
		return array(
			'int'                  => array( 2, 2 ),
			'finite float'         => array( 2.5, 2.5 ),
			'numeric string'       => array( '2', '2' ),
			'non-numeric string'   => array( 'not-a-number', 'not-a-number' ),
			'bool'                 => array( true, true ),
			'zero'                 => array( 0, 0 ),
			'array of safe values' => array( array( 1, '2' ), array( 1, '2' ) ),
			// Repaired by wp_json_encode(), not dropped: "\xB1\x31" reaches the wire as "?1".
			'invalid UTF-8 string' => array( "\xB1\x31", '?1' ),
		);
	}

	/**
	 * @testdox verify() logs the dropped fields once per request, by path and never by value
	 */
	public function test_verify_logs_dropped_fields_once(): void {
		$spy = $this->spy_on_controller_logging();
		$sut = $this->api_client_returning( $this->decision_response( 'allow' ) );

		$sut->verify(
			'test-session-id',
			array(
				'order'  => array( 'tax_total' => INF ),
				'events' => array( array( 'cart_item_count' => INF ) ),
			)
		);

		$this->assertLogged(
			'warning',
			'Dropped unencodable values from the request payload',
			array(
				'session_id' => 'test-session-id',
				'path'       => '/verify',
				'fields'     => array( 'context.order.tax_total', 'context.events.0.cart_item_count' ),
			)
		);

		// Two fields dropped, one log entry: the anti-flooding property, not just the message.
		$matching = array_filter(
			$spy->entries,
			static function ( array $entry ): bool {
				return false !== strpos( $entry['message'], 'Dropped unencodable values' );
			}
		);
		$this->assertCount( 1, $matching, 'The drop must be logged once per request, not once per field' );
	}

	/**
	 * @testdox verify() still fails open before transport when the payload cannot be encoded at all
	 *
	 * The allowlist works per value, so it cannot fix a failure that belongs to the document as a
	 * whole. Nesting past the encoder's depth budget is the remaining case, and the encode-failure
	 * branch must still catch it rather than sending a half-formed body.
	 */
	public function test_verify_fails_open_before_transport_when_payload_cannot_be_encoded(): void {
		$transport_called = false;
		$sut              = $this->api_client_returning(
			$this->decision_response( 'block' ),
			function () use ( &$transport_called ) {
				$transport_called = true;
			}
		);

		$too_deep = 1;
		for ( $i = 0; $i < 600; $i++ ) {
			$too_deep = array( $too_deep );
		}

		$result = $sut->verify( 'test-session-id', array( 'events' => array( array( 'quantity' => $too_deep ) ) ) );

		$this->assertFalse( $transport_called, 'An unencodable payload must not be sent' );
		$this->assertSame( FraudDecision::Allow, $result->decision );
		$this->assertLogged( 'error', 'Failed to encode payload' );
	}
}

/**
 * An object exposing a non-finite float, as a third-party attribute object could.
 *
 * The payload already carries objects (order.items[].attributes), so the allowlist has to cover
 * object nodes as well as arrays.
 */
class NonFiniteBearer {

	/**
	 * A value the JSON encoder cannot represent.
	 *
	 * @var float
	 */
	public $ratio = INF;
}
