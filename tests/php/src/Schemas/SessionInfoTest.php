<?php
/**
 * SessionInfoTest class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\FraudProtection\Schemas;

use Automattic\WooCommerce\FraudProtection\Schemas\SessionInfo;

/**
 * Tests for SessionInfo schema.
 *
 * @covers \Automattic\WooCommerce\FraudProtection\Schemas\SessionInfo
 */
class SessionInfoTest extends \WC_Unit_Test_Case {

	/**
	 * Test session ID.
	 *
	 * @var string
	 */
	private string $session_id = 'test-session-id';

	/**
	 * Runs before each test.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! did_action( 'woocommerce_load_cart_from_session' ) && function_exists( 'wc_load_cart' ) ) {
			wc_load_cart();
		}
	}

	/**
	 * @testdox from_request() builds SessionInfo with wc_identity_id and email.
	 */
	public function test_from_request_builds_session_info(): void {
		$info = SessionInfo::from_request( $this->session_id );
		$arr  = $info->to_array();

		$this->assertCount( 2, $arr );
		$this->assertArrayHasKey( 'wc_identity_id', $arr );
		$this->assertArrayHasKey( 'email', $arr );
	}

	/**
	 * @testdox from_request() stores the provided session_id.
	 */
	public function test_session_id_is_stored(): void {
		$info = SessionInfo::from_request( $this->session_id );
		$arr  = $info->to_array();

		$this->assertEquals( $this->session_id, $arr['wc_identity_id'] );
	}

	/**
	 * @testdox from_request() gets email from logged-in user.
	 */
	public function test_email_from_logged_in_user(): void {
		$user_id = $this->factory->user->create(
			array( 'user_email' => 'session-test@example.com' )
		);
		$this->assertIsInt( $user_id );
		wp_set_current_user( $user_id );

		$info = SessionInfo::from_request( $this->session_id );
		$arr  = $info->to_array();

		$this->assertEquals( 'session-test@example.com', $arr['email'] );
	}

	/**
	 * @testdox from_request() returns null email for guest users.
	 */
	public function test_email_null_for_guest_users(): void {
		wp_set_current_user( 0 );

		$info = SessionInfo::from_request( $this->session_id );
		$arr  = $info->to_array();

		$this->assertNull( $arr['email'] );
	}

	/**
	 * @testdox empty() returns all nulls.
	 */
	public function test_empty_returns_defaults(): void {
		$info = SessionInfo::empty();
		$arr  = $info->to_array();

		$this->assertCount( 2, $arr );
		$this->assertNull( $arr['wc_identity_id'] );
		$this->assertNull( $arr['email'] );
	}
}
