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

		wc_clear_notices();
	}

	/**
	 * Tear down after each test.
	 */
	public function tearDown(): void {
		$_POST = array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		WC()->cart->empty_cart();
		wc_clear_notices();

		remove_all_actions( 'woocommerce_after_checkout_validation' );
		remove_all_actions( 'woocommerce_checkout_process' );
		remove_all_actions( 'wp_enqueue_scripts' );
		remove_all_filters( 'woocommerce_add_error' );

		parent::tearDown();
	}

	/**
	 * Run WC core's checkout validation, which fires woocommerce_after_checkout_validation
	 * (where the SUT is hooked) with whatever errors core produced.
	 *
	 * @param array $data Posted checkout data.
	 * @return \WP_Error The errors produced by core validation.
	 */
	private function run_checkout_validation( array $data ): \WP_Error {
		WC()->cart->empty_cart();

		// ship_to_different_address is always posted by the real checkout form.
		$data = array_merge( array( 'ship_to_different_address' => false ), $data );

		// phpcs:disable Generic.CodeAnalysis, Squiz.Commenting
		$checkout = new class() extends \WC_Checkout {
			public function validate_checkout( &$data, &$errors ): void {
				parent::validate_checkout( $data, $errors );
			}
		};
		// phpcs:enable Generic.CodeAnalysis, Squiz.Commenting

		$errors = new \WP_Error();
		$checkout->validate_checkout( $data, $errors );

		return $errors;
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
	 * @testdox register() hooks woocommerce_after_checkout_validation at PHP_INT_MAX and wp_enqueue_scripts.
	 */
	public function test_register_hooks(): void {
		$this->sut->register();

		$this->assertSame(
			PHP_INT_MAX,
			has_action( 'woocommerce_after_checkout_validation', array( $this->sut, 'verify_and_block' ) ),
			'woocommerce_after_checkout_validation hook should be registered at PHP_INT_MAX'
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

	/**
	 * @testdox verify_and_block() skips verification when core checkout validation already failed.
	 */
	public function test_skips_verify_when_checkout_validation_already_failed(): void {
		$this->session_verifier
			->expects( $this->never() )
			->method( 'verify_session' );

		$this->sut->register();

		$errors = $this->run_checkout_validation( array( 'billing_country' => 'XX' ) );

		$this->assertNotEmpty(
			$errors->get_error_codes(),
			'Core validation should fail for an invalid country, so verification must be skipped'
		);
	}

	/**
	 * @testdox verify_and_block() skips verification when a woocommerce_checkout_process validator added an error notice.
	 */
	public function test_skips_verify_when_checkout_process_added_error_notice(): void {
		$this->session_verifier
			->expects( $this->never() )
			->method( 'verify_session' );

		// Mirrors how WCPay/Subscriptions validate: an error notice, not $errors.
		add_action(
			'woocommerce_checkout_process',
			function (): void {
				wc_add_notice( 'Mobile number is required.', 'error' );
			}
		);
		do_action( 'woocommerce_checkout_process' );

		$this->sut->register();

		// Valid data, so the only blocking signal is the notice above.
		$this->run_checkout_validation( array( 'billing_country' => 'US' ) );

		$this->assertSame(
			1,
			wc_notice_count( 'error' ),
			'The checkout_process error notice should be present and block verification'
		);
	}

	/**
	 * @testdox verify_and_block() still verifies when a pre-existing error has an empty message (non-blocking in core).
	 */
	public function test_runs_verify_when_pre_existing_error_has_empty_message(): void {
		$this->session_verifier
			->expects( $this->once() )
			->method( 'verify_session' )
			->willReturn( ApiClient::DECISION_ALLOW );

		// A message-less WP_Error entry does not block order creation in core
		// (wc_add_notice drops empty messages), so verification must still run.
		add_action(
			'woocommerce_after_checkout_validation',
			function ( $data, \WP_Error $errors ): void {
				$errors->add( 'soft_signal', '' );
			},
			10,
			2
		);

		$this->sut->register();

		$errors = $this->run_checkout_validation( array( 'billing_country' => 'US' ) );

		$this->assertNotEmpty(
			$errors->get_error_codes(),
			'The empty-message error should be present but must not block verification'
		);
	}

	/**
	 * @testdox verify_and_block() still verifies when an error message is filtered to empty (non-blocking in core).
	 */
	public function test_runs_verify_when_error_message_filtered_to_empty(): void {
		$this->session_verifier
			->expects( $this->once() )
			->method( 'verify_session' )
			->willReturn( ApiClient::DECISION_ALLOW );

		// Core runs each error message through woocommerce_add_error before deciding
		// whether to store an error notice. A filter that blanks the message means the
		// order is still created, so verification must run despite the raw $errors entry.
		add_filter( 'woocommerce_add_error', '__return_empty_string' );

		$this->sut->register();

		$errors = $this->run_checkout_validation( array( 'billing_country' => 'XX' ) );

		$this->assertNotEmpty(
			$errors->get_error_messages(),
			'The raw error message is present, but the filter blanks it so it must not block verification'
		);
	}

	/**
	 * @testdox verify_and_block() runs verification when core checkout validation passes.
	 */
	public function test_runs_verify_when_checkout_validation_passes(): void {
		$this->session_verifier
			->expects( $this->once() )
			->method( 'verify_session' )
			->willReturn( ApiClient::DECISION_ALLOW );

		$this->sut->register();

		$errors = $this->run_checkout_validation( array( 'billing_country' => 'US' ) );

		$this->assertEmpty(
			$errors->get_error_codes(),
			'Core validation should pass for a valid country, so verification must run'
		);
	}

}
