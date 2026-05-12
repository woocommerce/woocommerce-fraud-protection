<?php
/**
 * BlockedSessionNoticeTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal;

use Automattic\WooCommerce\FraudProtection\BlockedSessionNotice;
use Automattic\WooCommerce\FraudProtection\SessionClearanceManager;

/**
 * Tests for BlockedSessionNotice.
 *
 * @covers \Automattic\WooCommerce\FraudProtection\BlockedSessionNotice
 */
class BlockedSessionNoticeTest extends \WC_Unit_Test_Case {

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
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->mock_session_manager = $this->createMock( SessionClearanceManager::class );

		$this->sut = new BlockedSessionNotice();
		$this->sut->init( $this->mock_session_manager );
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

		$this->assertTrue( wc_has_notice( $this->sut->get_message_html( 'purchase' ), 'error' ), 'Should add purchase notice on checkout' );
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

		$this->assertTrue( wc_has_notice( $this->sut->get_message_html( 'purchase' ), 'error' ), 'Should add purchase notice on cart' );
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

		$this->assertFalse( wc_has_notice( $this->sut->get_message_html( 'purchase' ), 'error' ), 'Should not add notice when session is allowed' );
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
		$message      = $this->sut->get_message_html( 'purchase' );
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
			wc_has_notice( $this->sut->get_message_html(), 'error' ),
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
			wc_has_notice( $this->sut->get_message_html(), 'error' ),
			'Non-blocked sessions should not add any notice'
		);
	}

	/**
	 * Test get message html purchase context.
	 *
	 * @testdox get_message_html should return purchase-specific message when context is 'purchase'.
	 */
	public function test_get_message_html_purchase_context(): void {
		$message = $this->sut->get_message_html( 'purchase' );

		$this->assertEquals(
			'We are unable to process this request online. Please <a href="mailto:support@example.com">contact support (support@example.com)</a> to complete your purchase.',
			$message
		);
	}

	/**
	 * Test get message html generic context.
	 *
	 * @testdox get_message_html should return generic message when context is 'generic' or not specified.
	 */
	public function test_get_message_html_generic_context(): void {
		$message_default  = $this->sut->get_message_html();
		$message_explicit = $this->sut->get_message_html( 'generic' );

		$expected = 'We are unable to process this request online. Please <a href="mailto:support@example.com">contact support (support@example.com)</a> for assistance.';

		$this->assertEquals( $expected, $message_default, 'Default context should return generic message' );
		$this->assertEquals( $expected, $message_explicit, 'Explicit generic context should return generic message' );
	}

	/**
	 * Test get message plaintext purchase context.
	 *
	 * @testdox get_message_plaintext should return purchase-specific message when context is 'purchase'.
	 */
	public function test_get_message_plaintext_purchase_context(): void {
		$message = $this->sut->get_message_plaintext( 'purchase' );

		$this->assertEquals(
			'We are unable to process this request online. Please contact support (support@example.com) to complete your purchase.',
			$message
		);
	}

	/**
	 * Test get message plaintext generic context.
	 *
	 * @testdox get_message_plaintext should return generic message when context is 'generic' or not specified.
	 */
	public function test_get_message_plaintext_generic_context(): void {
		$message_default  = $this->sut->get_message_plaintext();
		$message_explicit = $this->sut->get_message_plaintext( 'generic' );

		$expected = 'We are unable to process this request online. Please contact support (support@example.com) for assistance.';

		$this->assertEquals( $expected, $message_default, 'Default context should return generic message' );
		$this->assertEquals( $expected, $message_explicit, 'Explicit generic context should return generic message' );
	}

	/**
	 * Test support email fallback to admin_email when from address is unset.
	 *
	 * @testdox Should fall back to admin_email when woocommerce_email_from_address is unset.
	 */
	public function test_get_message_falls_back_to_admin_email_when_from_address_unset(): void {
		delete_option( 'woocommerce_email_from_address' );
		$original_admin_email = get_option( 'admin_email' );
		update_option( 'admin_email', 'admin-fallback@example.com' );

		try {
			$message = $this->sut->get_message_plaintext( 'purchase' );

			$this->assertStringContainsString(
				'admin-fallback@example.com',
				$message,
				'Helper must fall back to admin_email when from address is empty.'
			);
		} finally {
			update_option( 'admin_email', $original_admin_email );
		}
	}

	/**
	 * Test message omits support-contact sentence when no email is available.
	 *
	 * @testdox Should return base message without empty "contact support ()" parenthetical when no email is resolvable.
	 */
	public function test_get_message_omits_support_contact_when_email_empty(): void {
		// WP's sanitize_option layer rejects an empty admin_email and keeps the previous value,
		// so we use pre_option_* filters to force-return empty for both options. These short-circuit
		// the option resolution before sanitize_option runs, simulating a true "no email available" state.
		add_filter( 'pre_option_woocommerce_email_from_address', '__return_empty_string' );
		add_filter( 'pre_option_admin_email', '__return_empty_string' );

		try {
			$html_purchase     = $this->sut->get_message_html( 'purchase' );
			$plaintext_generic = $this->sut->get_message_plaintext();

			$this->assertStringNotContainsString( '()', $html_purchase, 'HTML message must not render an empty parenthetical.' );
			$this->assertStringNotContainsString( 'contact support', $html_purchase, 'HTML message must omit the unactionable contact-support sentence when no email is available.' );
			$this->assertStringNotContainsString( '()', $plaintext_generic, 'Plaintext message must not render an empty parenthetical.' );
			$this->assertStringNotContainsString( 'contact support', $plaintext_generic, 'Plaintext message must omit the unactionable contact-support sentence when no email is available.' );
		} finally {
			remove_filter( 'pre_option_woocommerce_email_from_address', '__return_empty_string' );
			remove_filter( 'pre_option_admin_email', '__return_empty_string' );
		}
	}
}
