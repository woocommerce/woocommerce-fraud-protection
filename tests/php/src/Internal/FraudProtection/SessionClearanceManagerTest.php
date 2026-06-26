<?php
/**
 * SessionClearanceManagerTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtection;

use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;
use Automattic\WooCommerce\Internal\FraudProtection\SessionClearanceManager;

/**
 * Tests for SessionClearanceManager.
 *
 * @covers \Automattic\WooCommerce\Internal\FraudProtection\SessionClearanceManager
 */
class SessionClearanceManagerTest extends FraudProtectionUnitTestCase {

	/**
	 * The system under test.
	 *
	 * @var SessionClearanceManager
	 */
	private $sut;

	/**
	 * Runs before each test.
	 */
	public function setUp(): void {
		parent::setUp();

		WC()->session = new \WC_Session_Handler();
		WC()->session->init();

		$this->sut = new SessionClearanceManager();
	}

	/**
	 * Test that session status constants are defined correctly.
	 */
	public function test_session_status_constants(): void {
		$this->assertEquals( 'pending', SessionClearanceManager::STATUS_PENDING );
		$this->assertEquals( 'allowed', SessionClearanceManager::STATUS_ALLOWED );
		$this->assertEquals( 'blocked', SessionClearanceManager::STATUS_BLOCKED );
		$this->assertEquals( SessionClearanceManager::STATUS_ALLOWED, SessionClearanceManager::DEFAULT_STATUS );
	}

	/**
	 * Test default session status when session is not available.
	 */
	public function test_default_session_status_without_session(): void {
		// If session is not available, should return DEFAULT_STATUS.
		$status = $this->sut->get_session_status();
		$this->assertEquals( SessionClearanceManager::DEFAULT_STATUS, $status );
	}

	/**
	 * Test that is_session_allowed returns true for allowed status.
	 */
	public function test_is_session_allowed_returns_true_for_allowed(): void {
		$this->sut->allow_session();
		$this->assertTrue( $this->sut->is_session_allowed() );
		$this->assertFalse( $this->sut->is_session_blocked() );
	}

	/**
	 * Test that pending session is neither allowed nor blocked.
	 */
	public function test_is_session_allowed_returns_false_for_pending(): void {
		$this->sut->challenge_session();
		$this->assertFalse( $this->sut->is_session_allowed() );
		$this->assertFalse( $this->sut->is_session_blocked() );
	}

	/**
	 * Test blocked status.
	 */
	public function test_is_session_allowed_returns_false_for_blocked(): void {
		$this->sut->block_session();
		$this->assertFalse( $this->sut->is_session_allowed() );
		$this->assertTrue( $this->sut->is_session_blocked() );
	}

	/**
	 * Test block_session empties the cart.
	 */
	public function test_block_session_empties_cart(): void {
		// Add item to cart.
		$product = \WC_Helper_Product::create_simple_product();
		WC()->cart->add_to_cart( $product->get_id(), 1 );
		$this->assertGreaterThan( 0, WC()->cart->get_cart_contents_count() );

		// Block session should empty cart.
		$this->sut->block_session();
		$this->assertEquals( 0, WC()->cart->get_cart_contents_count() );

		// Clean up.
		$product->delete( true );
	}

	/**
	 * Test reset_session sets status to DEFAULT_STATUS.
	 */
	public function test_reset_session_sets_status_to_default(): void {
		// Set to blocked first.
		$this->sut->block_session();
		$this->assertEquals( SessionClearanceManager::STATUS_BLOCKED, $this->sut->get_session_status() );

		// Reset should go back to DEFAULT_STATUS.
		$this->sut->reset_session();
		$this->assertEquals( SessionClearanceManager::DEFAULT_STATUS, $this->sut->get_session_status() );
	}

	/**
	 * Test session status transitions.
	 */
	public function test_session_status_transitions(): void {
		// Start with allowed.
		$this->sut->allow_session();
		$this->assertEquals( SessionClearanceManager::STATUS_ALLOWED, $this->sut->get_session_status() );

		// Transition to pending.
		$this->sut->challenge_session();
		$this->assertEquals( SessionClearanceManager::STATUS_PENDING, $this->sut->get_session_status() );

		// Transition to blocked.
		$this->sut->block_session();
		$this->assertEquals( SessionClearanceManager::STATUS_BLOCKED, $this->sut->get_session_status() );

		// Transition back to allowed.
		$this->sut->allow_session();
		$this->assertEquals( SessionClearanceManager::STATUS_ALLOWED, $this->sut->get_session_status() );
	}

	/**
	 * Test get_session_status returns default status for invalid stored values.
	 */
	public function test_get_session_status_returns_default_status_for_invalid_values(): void {
		// Set an invalid value directly in session.
		WC()->session->set( '_fraud_protection_clearance_status', 'invalid_status' );

		// Should return default status for invalid values.
		$this->assertEquals( SessionClearanceManager::DEFAULT_STATUS, $this->sut->get_session_status() );
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
