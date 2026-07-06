<?php
/**
 * BlockedSessionMessageTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\FraudProtection;

use Automattic\WooCommerce\FraudProtection\BlockedSessionMessage;
use Automattic\WooCommerce\FraudProtection\MessageContext;
use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;

/**
 * Tests for BlockedSessionMessage.
 *
 * @covers \Automattic\WooCommerce\FraudProtection\BlockedSessionMessage
 */
class BlockedSessionMessageTest extends FraudProtectionUnitTestCase {

	/**
	 * The System Under Test.
	 *
	 * @var BlockedSessionMessage
	 */
	private BlockedSessionMessage $sut;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->sut = new BlockedSessionMessage();

		// Set a custom support email.
		update_option( 'woocommerce_email_from_address', 'support@example.com' );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		delete_option( 'woocommerce_email_from_address' );

		parent::tearDown();
	}

	/**
	 * @testdox get_html should return purchase-specific message when context is MessageContext::Purchase.
	 */
	public function test_get_html_purchase_context(): void {
		$message = $this->sut->get_html( MessageContext::Purchase );

		$this->assertEquals(
			'We are unable to process this request online. Please <a href="mailto:support@example.com">contact support (support@example.com)</a> to complete your purchase.',
			$message
		);
	}

	/**
	 * @testdox get_html should return generic message when context is MessageContext::Generic or not specified.
	 */
	public function test_get_html_generic_context(): void {
		$message_default  = $this->sut->get_html();
		$message_explicit = $this->sut->get_html( MessageContext::Generic );

		$expected = 'We are unable to process this request online. Please <a href="mailto:support@example.com">contact support (support@example.com)</a> for assistance.';

		$this->assertEquals( $expected, $message_default, 'Default context should return generic message' );
		$this->assertEquals( $expected, $message_explicit, 'Explicit generic context should return generic message' );
	}

	/**
	 * @testdox get_plaintext should return purchase-specific message when context is MessageContext::Purchase.
	 */
	public function test_get_plaintext_purchase_context(): void {
		$message = $this->sut->get_plaintext( MessageContext::Purchase );

		$this->assertEquals(
			'We are unable to process this request online. Please contact support (support@example.com) to complete your purchase.',
			$message
		);
	}

	/**
	 * @testdox get_plaintext should return generic message when context is MessageContext::Generic or not specified.
	 */
	public function test_get_plaintext_generic_context(): void {
		$message_default  = $this->sut->get_plaintext();
		$message_explicit = $this->sut->get_plaintext( MessageContext::Generic );

		$expected = 'We are unable to process this request online. Please contact support (support@example.com) for assistance.';

		$this->assertEquals( $expected, $message_default, 'Default context should return generic message' );
		$this->assertEquals( $expected, $message_explicit, 'Explicit generic context should return generic message' );
	}

	/**
	 * @testdox Should fall back to admin_email when woocommerce_email_from_address is unset.
	 */
	public function test_falls_back_to_admin_email_when_from_address_unset(): void {
		delete_option( 'woocommerce_email_from_address' );
		$original_admin_email = get_option( 'admin_email' );
		update_option( 'admin_email', 'admin-fallback@example.com' );

		try {
			$message = $this->sut->get_plaintext( MessageContext::Purchase );

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
	 * @testdox Should return base message without empty "contact support ()" parenthetical when no email is resolvable.
	 */
	public function test_omits_support_contact_when_email_empty(): void {
		// WP's sanitize_option layer rejects an empty admin_email and keeps the previous value,
		// so we use pre_option_* filters to force-return empty for both options. These short-circuit
		// the option resolution before sanitize_option runs, simulating a true "no email available" state.
		add_filter( 'pre_option_woocommerce_email_from_address', '__return_empty_string' );
		add_filter( 'pre_option_admin_email', '__return_empty_string' );

		try {
			$html_purchase     = $this->sut->get_html( MessageContext::Purchase );
			$plaintext_generic = $this->sut->get_plaintext();

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
