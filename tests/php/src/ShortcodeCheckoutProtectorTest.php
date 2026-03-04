<?php
/**
 * ShortcodeCheckoutProtectorTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\FraudProtection\FraudProtection;

use Automattic\WooCommerce\FraudProtection\ApiClient;
use Automattic\WooCommerce\FraudProtection\BlockedSessionNotice;
use Automattic\WooCommerce\FraudProtection\ClassicFormDataExtractionTrait;
use Automattic\WooCommerce\FraudProtection\SessionVerifier;
use Automattic\WooCommerce\FraudProtection\ShortcodeCheckoutProtector;
use WC_Unit_Test_Case;

/**
 * Tests for the ShortcodeCheckoutProtector class.
 *
 * @covers \Automattic\WooCommerce\FraudProtection\ShortcodeCheckoutProtector
 */
class ShortcodeCheckoutProtectorTest extends WC_Unit_Test_Case {

	/**
	 * The System Under Test.
	 *
	 * @var ShortcodeCheckoutProtector
	 */
	private ShortcodeCheckoutProtector $sut;

	/**
	 * Mock session verifier.
	 *
	 * @var SessionVerifier&\PHPUnit\Framework\MockObject\MockObject
	 */
	private $session_verifier;

	/**
	 * Mock blocked session notice.
	 *
	 * @var BlockedSessionNotice&\PHPUnit\Framework\MockObject\MockObject
	 */
	private $blocked_session_notice;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->session_verifier       = $this->createMock( SessionVerifier::class );
		$this->blocked_session_notice = $this->createMock( BlockedSessionNotice::class );

		$this->blocked_session_notice
			->method( 'get_message_plaintext' )
			->willReturn( 'We are unable to process this request online. Please contact support (test@example.com) to complete your purchase.' );

		$this->sut = new ShortcodeCheckoutProtector();
		$this->sut->init(
			$this->session_verifier,
			$this->blocked_session_notice
		);
	}

	/**
	 * Tear down after each test.
	 */
	public function tearDown(): void {
		$_POST = array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		parent::tearDown();
	}

	/**
	 * @testdox ShortcodeCheckoutProtector uses ClassicFormDataExtractionTrait.
	 */
	public function test_uses_classic_form_data_extraction_trait(): void {
		$this->assertContains(
			ClassicFormDataExtractionTrait::class,
			class_uses( ShortcodeCheckoutProtector::class )
		);
	}

	/*
	|--------------------------------------------------------------------------
	| register() Tests
	|--------------------------------------------------------------------------
	*/

	/**
	 * @testdox register() hooks woocommerce_after_checkout_validation and wp_enqueue_scripts.
	 */
	public function test_register_hooks(): void {
		$this->sut->register();

		$this->assertNotFalse(
			has_action( 'woocommerce_after_checkout_validation', array( $this->sut, 'verify_and_block' ) ),
			'woocommerce_after_checkout_validation hook should be registered'
		);
		$this->assertNotFalse(
			has_action( 'wp_enqueue_scripts', array( $this->sut, 'enqueue_shortcode_checkout_script' ) ),
			'wp_enqueue_scripts hook should be registered'
		);
	}

	/*
	|--------------------------------------------------------------------------
	| verify_and_block() Tests
	|--------------------------------------------------------------------------
	*/

	/**
	 * @testdox verify_and_block() passes session_id and request_data to SessionVerifier, allows on ALLOW.
	 */
	public function test_verify_allows_on_allow_decision(): void {
		$_POST['wc_fraud_protection_session_id'] = 'test-session-123';

		$posted_data = array(
			'billing_first_name' => 'Bob',
			'payment_method'     => 'stripe',
		);

		$this->session_verifier
			->expects( $this->once() )
			->method( 'verify_session' )
			->with( 'test-session-123', 'shortcode_checkout', 0, $this->isType( 'array' ) )
			->willReturn( ApiClient::DECISION_ALLOW );

		$errors = new \WP_Error();
		$this->sut->verify_and_block( $posted_data, $errors );

		$this->assertEmpty( $errors->get_error_codes() );
	}

	/**
	 * @testdox verify_and_block() adds error on BLOCK decision.
	 */
	public function test_verify_adds_error_on_block_decision(): void {
		$_POST['wc_fraud_protection_session_id'] = 'test-session-456';

		$posted_data = array(
			'billing_first_name' => 'Jane',
			'payment_method'     => 'woocommerce_payments',
		);

		$this->session_verifier
			->expects( $this->once() )
			->method( 'verify_session' )
			->willReturn( ApiClient::DECISION_BLOCK );

		$errors = new \WP_Error();
		$this->sut->verify_and_block( $posted_data, $errors );

		$this->assertContains( 'woocommerce_checkout_error', $errors->get_error_codes() );
		$this->assertStringContainsString(
			'We are unable to process this request online',
			$errors->get_error_message( 'woocommerce_checkout_error' )
		);
	}

}
