<?php
/**
 * BlockedSessionNoticeTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin;

use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\BlockedSessionNotice;
use Automattic\WooCommerce\FraudProtection\BlockedSessionMessage;
use Automattic\WooCommerce\FraudProtection\MessageContext;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Sessions\SessionClearanceManager;

/**
 * Tests for BlockedSessionNotice.
 *
 * @covers \Automattic\WooCommerce\Internal\FraudProtectionPlugin\BlockedSessionNotice
 */
class BlockedSessionNoticeTest extends FraudProtectionUnitTestCase {

	/**
	 * The System Under Test.
	 *
	 * @var BlockedSessionNotice
	 */
	private $sut;

	/**
	 * Mock session clearance manager.
	 *
	 * @var SessionClearanceManager|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $mock_session_manager;

	/**
	 * Blocked-session message generator injected into the SUT, used to compute expected notice text.
	 *
	 * @var BlockedSessionMessage
	 */
	private $message;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->mock_session_manager = $this->createMock( SessionClearanceManager::class );
		$this->message              = new BlockedSessionMessage();

		$this->sut = new BlockedSessionNotice();
		$this->sut->init( $this->mock_session_manager, $this->message );
		$this->sut->register();

		// Set a custom support email.
		update_option( 'woocommerce_email_from_address', 'support@example.com' );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		parent::tearDown();
		remove_all_actions( 'before_woocommerce_add_payment_method' );
		remove_all_actions( 'wp' );
		delete_option( 'woocommerce_email_from_address' );
		wc_clear_notices();
	}

	/**
	 * @testdox register should add all notice hooks.
	 */
	public function test_register_adds_all_hooks(): void {
		$this->assertNotFalse(
			has_action( 'wp', array( $this->sut, 'maybe_add_blocked_purchase_notice' ) ),
			'Should register wp action for purchase notices'
		);
		$this->assertNotFalse(
			has_action( 'before_woocommerce_add_payment_method', array( $this->sut, 'maybe_display_generic_blocked_notice' ) ),
			'Should register before_woocommerce_add_payment_method action'
		);
	}

	/**
	 * Test blocked purchase notice added on checkout.
	 *
	 * @testdox maybe_add_blocked_purchase_notice should add notice when session is blocked and on checkout page.
	 */
	public function test_blocked_purchase_notice_added_on_checkout(): void {
		$this->mock_session_manager->method( 'is_session_blocked' )->willReturn( true );

		// Mock being on checkout page.
		add_filter( 'woocommerce_is_checkout', '__return_true' );

		do_action( 'wp' ); // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment

		remove_filter( 'woocommerce_is_checkout', '__return_true' );

		$this->assertTrue( wc_has_notice( $this->message->get_html( MessageContext::Purchase ), 'error' ), 'Should add purchase notice on checkout' );
	}

	/**
	 * Test blocked purchase notice added on cart.
	 *
	 * @testdox maybe_add_blocked_purchase_notice should add notice when session is blocked and on cart page.
	 */
	public function test_blocked_purchase_notice_added_on_cart(): void {
		$this->mock_session_manager->method( 'is_session_blocked' )->willReturn( true );

		// Mock being on cart page.
		add_filter( 'woocommerce_is_cart', '__return_true' );

		do_action( 'wp' ); // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment

		remove_filter( 'woocommerce_is_cart', '__return_true' );

		$this->assertTrue( wc_has_notice( $this->message->get_html( MessageContext::Purchase ), 'error' ), 'Should add purchase notice on cart' );
	}

	/**
	 * Test blocked purchase notice not added when session allowed.
	 *
	 * @testdox maybe_add_blocked_purchase_notice should not add notice when session is not blocked.
	 */
	public function test_blocked_purchase_notice_not_added_when_session_allowed(): void {
		$this->mock_session_manager->method( 'is_session_blocked' )->willReturn( false );

		// Mock being on checkout page.
		add_filter( 'woocommerce_is_checkout', '__return_true' );

		do_action( 'wp' ); // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment

		remove_filter( 'woocommerce_is_checkout', '__return_true' );

		$this->assertFalse( wc_has_notice( $this->message->get_html( MessageContext::Purchase ), 'error' ), 'Should not add notice when session is allowed' );
	}

	/**
	 * Test blocked purchase notice prevents duplicates.
	 *
	 * @testdox maybe_add_blocked_purchase_notice should not add duplicate notices.
	 */
	public function test_blocked_purchase_notice_prevents_duplicates(): void {
		$this->mock_session_manager->method( 'is_session_blocked' )->willReturn( true );

		// Mock being on checkout page.
		add_filter( 'woocommerce_is_checkout', '__return_true' );

		do_action( 'wp' ); // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment
		do_action( 'wp' ); // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment

		remove_filter( 'woocommerce_is_checkout', '__return_true' );

		// Count error notices.
		$notices      = wc_get_notices( 'error' );
		$message      = $this->message->get_html( MessageContext::Purchase );
		$notice_count = 0;
		foreach ( $notices as $notice ) {
			if ( $notice['notice'] === $message ) {
				++$notice_count;
			}
		}

		$this->assertEquals( 1, $notice_count, 'Should only have one notice even after calling twice' );
	}

	/**
	 * Test add payment method action displays blocked message.
	 *
	 * @testdox Should display generic error notice when before_woocommerce_add_payment_method action fires for blocked sessions.
	 */
	public function test_add_payment_method_action_displays_blocked_message(): void {
		$this->mock_session_manager->method( 'is_session_blocked' )->willReturn( true );

		do_action( 'before_woocommerce_add_payment_method' ); // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment

		$this->assertTrue(
			wc_has_notice( $this->message->get_html(), 'error' ),
			'Should add blocked notice on add payment method page'
		);
	}

	/**
	 * Test add payment method action no message for non blocked session.
	 *
	 * @testdox Should not display message when add payment method action fires for non-blocked sessions.
	 */
	public function test_add_payment_method_action_no_message_for_non_blocked_session(): void {
		$this->mock_session_manager->method( 'is_session_blocked' )->willReturn( false );

		do_action( 'before_woocommerce_add_payment_method' ); // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment

		$this->assertFalse(
			wc_has_notice( $this->message->get_html(), 'error' ),
			'Non-blocked sessions should not add any notice'
		);
	}

}
