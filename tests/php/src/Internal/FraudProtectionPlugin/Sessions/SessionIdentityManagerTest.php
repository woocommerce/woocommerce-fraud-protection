<?php
/**
 * SessionIdentityManagerTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin\Sessions;

use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Sessions\SessionIdentityManager;

/**
 * Tests for SessionIdentityManager.
 *
 * @covers \Automattic\WooCommerce\Internal\FraudProtectionPlugin\Sessions\SessionIdentityManager
 */
class SessionIdentityManagerTest extends FraudProtectionUnitTestCase {

	/**
	 * The System Under Test.
	 *
	 * @var SessionIdentityManager
	 */
	private $sut;

	/**
	 * The session handler in place before the test, restored in tearDown().
	 *
	 * @var \WC_Session|null
	 */
	private $original_session;

	/**
	 * Runs before each test.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->original_session = WC()->session;

		WC()->session = new \WC_Session_Handler();
		WC()->session->init();

		$this->sut = new SessionIdentityManager();
	}

	/**
	 * Runs after each test.
	 */
	public function tearDown(): void {
		WC()->session = $this->original_session;
		parent::tearDown();
	}

	/**
	 * @testdox Should return stored identity ID from session when already present.
	 */
	public function test_get_identity_id_returns_stored_identity_from_session(): void {
		$expected_id = 'existing-identity-id';
		WC()->session->set( '_fraud_protection_customer_identity_id', $expected_id );

		$result = $this->sut->get_identity_id();

		$this->assertSame( $expected_id, $result, 'Should return the identity ID already stored in the session' );
	}

	/**
	 * @testdox Should return a non-empty session ID when no identity is stored in the session.
	 */
	public function test_get_identity_id_returns_non_empty_id_when_not_in_session(): void {
		$result = $this->sut->get_identity_id();

		$this->assertNotEmpty( $result, 'Should return a non-empty session ID' );
	}

	/**
	 * @testdox Should persist identity ID to the session for future use.
	 */
	public function test_get_identity_id_persists_identity_to_session(): void {
		$this->assertEmpty(
			WC()->session->get( '_fraud_protection_customer_identity_id' ),
			'Session should not have an identity ID before calling get_identity_id'
		);

		$result = $this->sut->get_identity_id();

		$this->assertSame(
			$result,
			WC()->session->get( '_fraud_protection_customer_identity_id' ),
			'Identity ID should be persisted to the session'
		);
	}

	/**
	 * @testdox Should return consistent session ID on subsequent calls.
	 */
	public function test_get_identity_id_returns_consistent_value(): void {
		$first_call  = $this->sut->get_identity_id();
		$second_call = $this->sut->get_identity_id();

		$this->assertSame( $first_call, $second_call, 'Subsequent calls should return the same session ID' );
	}

	/**
	 * @testdox Stored identities are normalized before reuse.
	 * @dataProvider stored_identity_provider
	 *
	 * @param mixed  $stored   Stored identity.
	 * @param string $expected Expected identity, or an empty value for a fallback.
	 */
	public function test_get_identity_id_normalizes_stored_values( mixed $stored, string $expected ): void {
		WC()->session->set( SessionIdentityManager::CUSTOMER_IDENTITY_ID_KEY, $stored );

		$result = $this->sut->get_identity_id();

		if ( '' === $expected ) {
			$this->assertStringStartsWith( 'customer_', $result );
			$this->assertLessThanOrEqual( 64, strlen( $result ) );
		} else {
			$this->assertSame( $expected, $result );
		}
		$this->assertSame( $result, WC()->session->get( SessionIdentityManager::CUSTOMER_IDENTITY_ID_KEY ) );
		$this->assertSame( $result, $this->sut->get_identity_id() );
	}

	/**
	 * Stored identity cases.
	 *
	 * @return array<string, array{mixed, string}>
	 */
	public function stored_identity_provider(): array {
		$exact = str_repeat( 'a', 64 );

		return array(
			'known Woo form'        => array( 'customer_1234:abc_DEF-+/=', 'customer_1234:abc_DEF-+/=' ),
			'integer'               => array( 123456, '123456' ),
			'exactly 64 bytes'      => array( $exact, $exact ),
			'valid long value'      => array( $exact . 'tail', $exact ),
			'empty value'           => array( '', '' ),
			'unsupported character' => array( 'identity with spaces', '' ),
			'trailing newline'       => array( "identity\n", '' ),
			'unsupported type'      => array( array( 'identity' ), '' ),
		);
	}

	/**
	 * @testdox Should initialize session and return valid ID when session is not available.
	 */
	public function test_get_identity_id_initializes_session_when_unavailable(): void {
		// @phpstan-ignore assign.propertyType
		WC()->session = null;

		$result = $this->sut->get_identity_id();

		$this->assertNotEmpty( $result, 'Should return a valid session ID even when session was not initially available' );
		// @phpstan-ignore method.impossibleType
		$this->assertInstanceOf( \WC_Session::class, WC()->session, 'Session should be initialized after the call' );
	}

	/**
	 * @testdox Should use Tracks Client identity when session has no stored identity ID.
	 */
	public function test_get_identity_id_uses_tracks_identity(): void {
		$user_id = $this->factory->user->create();
		$this->assertIsInt( $user_id );
		wp_set_current_user( $user_id );

		$tracks_identity = \WC_Tracks_Client::get_identity( $user_id );
		$expected_id     = $tracks_identity['_ui'] ?? '';

		$result = $this->sut->get_identity_id();

		$this->assertSame( $expected_id, $result, 'Should use the Tracks Client identity ID' );

		wp_set_current_user( 0 );
		wp_delete_user( $user_id );
	}

	/**
	 * @testdox Should use Tracks Client identity for anonymous (guest) users.
	 */
	public function test_get_identity_id_uses_tracks_identity_for_anonymous_users(): void {
		wp_set_current_user( 0 );

		$tracks_identity = \WC_Tracks_Client::get_identity( 0 );
		$expected_id     = $tracks_identity['_ui'] ?? '';

		$result = $this->sut->get_identity_id();

		$this->assertNotEmpty( $result, 'Should return a non-empty session ID for anonymous users' );
		$this->assertSame( $expected_id, $result, 'Should use the Tracks Client identity ID for anonymous users' );
	}
}
