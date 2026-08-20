<?php
/**
 * AddPaymentMethodProtectorTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin\Protectors;

use Automattic\WooCommerce\FraudProtection\BlackboxScriptHandler;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Protectors\AddPaymentMethodProtector;
use Automattic\WooCommerce\FraudProtection\Schemas\FraudDecision;
use Automattic\WooCommerce\FraudProtection\BlockedSessionMessage;
use Automattic\WooCommerce\FraudProtection\MessageContext;
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
	 * @var BlockedSessionMessage&\PHPUnit\Framework\MockObject\MockObject
	 */
	private $blocked_session_message;

	/**
	 * Mock Blackbox script handler.
	 *
	 * @var BlackboxScriptHandler&\PHPUnit\Framework\MockObject\MockObject
	 */
	private $blackbox_script_handler;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->session_verifier       = $this->createMock( SessionVerifier::class );
		$this->blocked_session_message = $this->createMock( BlockedSessionMessage::class );
		$this->blackbox_script_handler = $this->createMock( BlackboxScriptHandler::class );

		$this->blocked_session_message
			->method( 'get_html' )
			->willReturn( 'We are unable to process this request online. Please <a href="mailto:test@example.com">contact support (test@example.com)</a> for assistance.' );

		$this->sut = new AddPaymentMethodProtector();
		$this->sut->init(
			$this->session_verifier,
			$this->blocked_session_message,
			$this->blackbox_script_handler
		);
	}

	/**
	 * Tear down after each test.
	 */
	public function tearDown(): void {
		$_POST = array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		wc_clear_notices();
		remove_all_filters( 'woocommerce_add_payment_method_form_is_valid' );
		remove_action( 'woocommerce_add_payment_method_form_bottom', array( $this->sut, 'enqueue_add_payment_method_script' ), 10 );
		$this->reset_fraud_protection_scripts();

		parent::tearDown();
	}

	/*
	|--------------------------------------------------------------------------
	| enqueue_add_payment_method_script() Tests
	|--------------------------------------------------------------------------
	*/

	/**
	 * @testdox enqueue_add_payment_method_script() enqueues its script when the shared scripts are available.
	 */
	public function test_enqueue_add_payment_method_script_when_shared_scripts_are_available(): void {
		$this->blackbox_script_handler->expects( $this->once() )->method( 'request_scripts' )->willReturn( true );

		$this->sut->enqueue_add_payment_method_script();

		$this->assertTrue( wp_script_is( 'wc-fraud-protection-add-payment-method', 'enqueued' ) );
		$script = wp_scripts()->query( 'wc-fraud-protection-add-payment-method', 'registered' );
		$this->assertNotFalse( $script );
		$this->assertContains( 'wc-fraud-protection-blackbox-init', $script->deps );
	}

	/**
	 * @testdox enqueue_add_payment_method_script() does not enqueue when the shared scripts are unavailable.
	 */
	public function test_enqueue_add_payment_method_script_skips_when_shared_scripts_are_unavailable(): void {
		$this->blackbox_script_handler->expects( $this->once() )->method( 'request_scripts' )->willReturn( false );

		$this->sut->enqueue_add_payment_method_script();

		$this->assertFalse( wp_script_is( 'wc-fraud-protection-add-payment-method', 'enqueued' ) );
	}

	/**
	 * @testdox The real add-payment-method form requests scripts when a gateway is available.
	 */
	public function test_available_gateway_form_render_enqueues_scripts(): void {
		$this->mock_jetpack_blog_id( 12345 );
		$this->sut->init(
			$this->session_verifier,
			$this->blocked_session_message,
			$this->make_blackbox_script_handler()
		);
		$gateway     = $this->getMockBuilder( \WC_Payment_Gateway::class )
			->disableOriginalConstructor()
			->getMock();
		$gateway->id = 'test-gateway';
		$add_gateway = function ( array $gateways ) use ( $gateway ): array {
			$gateways[ $gateway->id ] = $gateway;
			return $gateways;
		};
		add_filter( 'woocommerce_available_payment_gateways', $add_gateway );
		$this->sut->register();

		try {
			$this->render_add_payment_method_template();
		} finally {
			remove_filter( 'woocommerce_available_payment_gateways', $add_gateway );
		}

		$this->assertTrue( wp_script_is( 'wc-fraud-protection-blackbox-init', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'wc-fraud-protection-add-payment-method', 'enqueued' ) );
	}

	/**
	 * @testdox A no-gateway add-payment-method response returns before the form hook.
	 */
	public function test_no_gateway_response_does_not_enqueue_scripts(): void {
		$this->blackbox_script_handler->expects( $this->never() )->method( 'request_scripts' );
		$remove_gateways = '__return_empty_array';
		add_filter( 'woocommerce_available_payment_gateways', $remove_gateways, PHP_INT_MAX );
		$this->sut->register();

		try {
			$this->render_add_payment_method_template();
		} finally {
			remove_filter( 'woocommerce_available_payment_gateways', $remove_gateways, PHP_INT_MAX );
		}

		$this->assertFalse( wp_script_is( 'wc-fraud-protection-blackbox-init', 'enqueued' ) );
		$this->assertFalse( wp_script_is( 'wc-fraud-protection-add-payment-method', 'enqueued' ) );
	}

	/**
	 * Render WooCommerce's add-payment-method template and discard its HTML.
	 */
	private function render_add_payment_method_template(): void {
		ob_start();
		wc_get_template( 'myaccount/form-add-payment-method.php' );
		ob_end_clean();
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
	 * @testdox register() hooks verification and the add-payment-method form signal.
	 */
	public function test_register_hooks(): void {
		$this->sut->register();

		$this->assertNotFalse(
			has_filter( 'woocommerce_add_payment_method_form_is_valid', array( $this->sut, 'verify_and_block' ) ),
			'woocommerce_add_payment_method_form_is_valid filter should be registered'
		);
		$this->assertSame(
			10,
			has_action( 'woocommerce_add_payment_method_form_bottom', array( $this->sut, 'enqueue_add_payment_method_script' ) )
		);
		$this->assertFalse( has_action( 'wp_enqueue_scripts', array( $this->sut, 'enqueue_add_payment_method_script' ) ) );
	}

	/*
	|--------------------------------------------------------------------------
	| verify_and_block() Tests
	|--------------------------------------------------------------------------
	*/

	/**
	 * @testdox verify_and_block() passes session_id and request_data to SessionVerifier, returns true on ALLOW.
	 * @dataProvider truthy_prior_validation_values_provider
	 *
	 * @param mixed $prior_value Value returned by a prior filter.
	 */
	public function test_verify_returns_true_on_allow_decision( mixed $prior_value ): void {
		$_POST['wc_fraud_protection_session_id'] = 'test-session-123';
		$_POST['payment_method']                 = 'stripe';

		$this->session_verifier
			->expects( $this->once() )
			->method( 'verify_session' )
			->with( 'test-session-123', 'add_payment_method', 0, $this->isType( 'array' ) )
			->willReturn( FraudDecision::Allow );

		$result = $this->sut->verify_and_block( $prior_value );

		$this->assertTrue( $result );
	}

	/**
	 * @testdox verify_and_block() returns false and adds notice on BLOCK decision.
	 * @dataProvider truthy_prior_validation_values_provider
	 *
	 * @param mixed $prior_value Value returned by a prior filter.
	 */
	public function test_verify_returns_false_and_adds_notice_on_block_decision( mixed $prior_value ): void {
		$_POST['wc_fraud_protection_session_id'] = 'test-session-456';
		$_POST['payment_method']                 = 'woocommerce_payments';

		$this->session_verifier
			->expects( $this->once() )
			->method( 'verify_session' )
			->willReturn( FraudDecision::Block );

		$result = $this->sut->verify_and_block( $prior_value );

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

		$this->blocked_session_message
			->expects( $this->once() )
			->method( 'get_html' )
			->with( MessageContext::Generic );

		$this->session_verifier
			->method( 'verify_session' )
			->willReturn( FraudDecision::Block );

		$this->sut->verify_and_block( true );
	}

	/**
	 * @testdox verify_and_block() respects prior validation failure and skips verification.
	 * @dataProvider falsey_prior_validation_values_provider
	 *
	 * @param mixed $prior_value Value returned by a prior filter.
	 */
	public function test_verify_respects_prior_validation_failure( mixed $prior_value ): void {
		$this->session_verifier
			->expects( $this->never() )
			->method( 'verify_session' );

		$result = $this->sut->verify_and_block( $prior_value );

		$this->assertFalse( $result );
	}

	/**
	 * Falsey prior validation values.
	 *
	 * @return array<string, array{mixed}>
	 */
	public function falsey_prior_validation_values_provider(): array {
		return array(
			'false'        => array( false ),
			'null'         => array( null ),
			'zero'         => array( 0 ),
			'empty string' => array( '' ),
			'empty array'  => array( array() ),
		);
	}

	/**
	 * Truthy prior validation values.
	 *
	 * @return array<string, array{mixed}>
	 */
	public function truthy_prior_validation_values_provider(): array {
		return array(
			'true'            => array( true ),
			'non-empty array' => array( array( 'valid' ) ),
			'object'          => array( new \stdClass() ),
		);
	}

}
