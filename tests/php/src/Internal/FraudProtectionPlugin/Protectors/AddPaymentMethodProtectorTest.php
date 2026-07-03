<?php
/**
 * AddPaymentMethodProtectorTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin\Protectors;

use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Protectors\AddPaymentMethodProtector;
use Automattic\WooCommerce\FraudProtection\Schemas\FraudDecision;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\BlockedSessionNotice;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\MessageContext;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\ClassicFormDataExtractionTrait;
use Automattic\WooCommerce\FraudProtection\SessionVerifier;
use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;

/**
 * Tests for the AddPaymentMethodProtector class.
 *
 * @covers \Automattic\WooCommerce\Internal\FraudProtectionPlugin\Protectors\AddPaymentMethodProtector
 */
class AddPaymentMethodProtectorTest extends FraudProtectionUnitTestCase {

	/**
	 * The System Under Test.
	 *
	 * @var AddPaymentMethodProtector
	 */
	private AddPaymentMethodProtector $sut;

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
			->method( 'get_message_html' )
			->willReturn( 'We are unable to process this request online. Please <a href="mailto:test@example.com">contact support (test@example.com)</a> for assistance.' );

		$this->sut = new AddPaymentMethodProtector();
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
		wc_clear_notices();
		remove_all_filters( 'woocommerce_add_payment_method_form_is_valid' );
		remove_all_actions( 'wp_enqueue_scripts' );

		parent::tearDown();
	}

	/**
	 * @testdox AddPaymentMethodProtector uses ClassicFormDataExtractionTrait.
	 */
	public function test_uses_classic_form_data_extraction_trait(): void {
		$this->assertContains(
			ClassicFormDataExtractionTrait::class,
			class_uses( AddPaymentMethodProtector::class )
		);
	}

	/*
	|--------------------------------------------------------------------------
	| register() Tests
	|--------------------------------------------------------------------------
	*/

	/**
	 * @testdox register() hooks woocommerce_add_payment_method_form_is_valid and wp_enqueue_scripts.
	 */
	public function test_register_hooks(): void {
		$this->sut->register();

		$this->assertNotFalse(
			has_filter( 'woocommerce_add_payment_method_form_is_valid', array( $this->sut, 'verify_and_block' ) ),
			'woocommerce_add_payment_method_form_is_valid filter should be registered'
		);
		$this->assertNotFalse(
			has_action( 'wp_enqueue_scripts', array( $this->sut, 'enqueue_add_payment_method_script' ) ),
			'wp_enqueue_scripts hook should be registered'
		);
	}

	/*
	|--------------------------------------------------------------------------
	| verify_and_block() Tests
	|--------------------------------------------------------------------------
	*/

	/**
	 * @testdox verify_and_block() passes session_id and request_data to SessionVerifier, returns true on ALLOW.
	 */
	public function test_verify_returns_true_on_allow_decision(): void {
		$_POST['wc_fraud_protection_session_id'] = 'test-session-123';
		$_POST['payment_method']                 = 'stripe';

		$this->session_verifier
			->expects( $this->once() )
			->method( 'verify_session' )
			->with( 'test-session-123', 'add_payment_method', 0, $this->isType( 'array' ) )
			->willReturn( FraudDecision::Allow );

		$result = $this->sut->verify_and_block( true );

		$this->assertTrue( $result );
	}

	/**
	 * @testdox verify_and_block() returns false and adds notice on BLOCK decision.
	 */
	public function test_verify_returns_false_and_adds_notice_on_block_decision(): void {
		$_POST['wc_fraud_protection_session_id'] = 'test-session-456';
		$_POST['payment_method']                 = 'woocommerce_payments';

		$this->session_verifier
			->expects( $this->once() )
			->method( 'verify_session' )
			->willReturn( FraudDecision::Block );

		$result = $this->sut->verify_and_block( true );

		$this->assertFalse( $result );
		$this->assertTrue(
			wc_has_notice(
				'We are unable to process this request online. Please <a href="mailto:test@example.com">contact support (test@example.com)</a> for assistance.',
				'error'
			)
		);
	}

	/**
	 * @testdox verify_and_block() uses generic context for blocked message.
	 */
	public function test_verify_uses_generic_context_for_blocked_message(): void {
		$_POST['payment_method'] = 'stripe';

		$this->blocked_session_notice
			->expects( $this->once() )
			->method( 'get_message_html' )
			->with( MessageContext::Generic );

		$this->session_verifier
			->method( 'verify_session' )
			->willReturn( FraudDecision::Block );

		$this->sut->verify_and_block( true );
	}

	/**
	 * @testdox verify_and_block() respects prior validation failure and skips verification.
	 */
	public function test_verify_respects_prior_validation_failure(): void {
		$this->session_verifier
			->expects( $this->never() )
			->method( 'verify_session' );

		$result = $this->sut->verify_and_block( false );

		$this->assertFalse( $result );
	}

}
