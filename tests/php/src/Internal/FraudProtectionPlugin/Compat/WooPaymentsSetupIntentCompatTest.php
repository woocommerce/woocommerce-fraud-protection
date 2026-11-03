<?php
/**
 * WooPaymentsSetupIntentCompatTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin\Compat;

use Automattic\WooCommerce\FraudProtection\BlockedSessionMessage;
use Automattic\WooCommerce\FraudProtection\MessageContext;
use Automattic\WooCommerce\FraudProtection\Schemas\FraudDecision;
use Automattic\WooCommerce\FraudProtection\SessionIdNormalizer;
use Automattic\WooCommerce\FraudProtection\SessionVerifier;
use Automattic\WooCommerce\FraudProtection\SuppliedDecision;
use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Compat\WooPaymentsSetupIntentCompat;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Sessions\SessionIdError;
use WCPay\Core\Server\Request\Get_Setup_Intention;

require_once __DIR__ . '/../../../../stubs/wc-payments-get-setup-intention.php';

/**
 * Tests for the WooPaymentsSetupIntentCompat class.
 *
 * @covers \Automattic\WooCommerce\Internal\FraudProtectionPlugin\Compat\WooPaymentsSetupIntentCompat
 */
class WooPaymentsSetupIntentCompatTest extends FraudProtectionUnitTestCase {

	private const SETUP_INTENT_REQUEST_FILTER = 'wcpay_create_and_confirm_setup_intention_request';

	private const SETUP_INTENT_AJAX_ACTION = 'wp_ajax_create_setup_intent';

	private const SKIP_VERIFY_FILTER = 'woocommerce_fraud_protection_skip_session_verify';

	private const RECORD_KEY = '_fraud_protection_woopayments_setup_intent_verification';

	private const SETUP_INTENT_ID = 'seti_123';

	/**
	 * The System Under Test.
	 *
	 * @var WooPaymentsSetupIntentCompat
	 */
	private WooPaymentsSetupIntentCompat $sut;

	/**
	 * Mock session verifier.
	 *
	 * @var SessionVerifier&\PHPUnit\Framework\MockObject\MockObject
	 */
	private $session_verifier;

	/**
	 * Mock blocked-session message generator.
	 *
	 * @var BlockedSessionMessage&\PHPUnit\Framework\MockObject\MockObject
	 */
	private $blocked_session_message;

	/**
	 * Filter callback that sets the available payment gateways, if any.
	 *
	 * @var ?\Closure
	 */
	private ?\Closure $available_gateways_filter = null;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->session_verifier        = $this->createMock( SessionVerifier::class );
		$this->blocked_session_message = $this->createMock( BlockedSessionMessage::class );
		$this->blocked_session_message
			->method( 'get_plaintext' )
			->willReturn( 'We are unable to process this request online.' );

		$this->sut = new WooPaymentsSetupIntentCompat();
		$this->sut->init(
			$this->session_verifier,
			$this->blocked_session_message,
			new SessionIdNormalizer()
		);
		$this->sut->register();

		wp_set_current_user( self::factory()->user->create() );

		Get_Setup_Intention::reset();
		Get_Setup_Intention::set_mock_setup_intent( self::SETUP_INTENT_ID, 'pm_123' );
	}

	/**
	 * Tear down after each test.
	 */
	public function tearDown(): void {
		$_POST = array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		remove_filter( self::SETUP_INTENT_REQUEST_FILTER, array( $this->sut, 'verify_and_block_setup_intent' ) );
		remove_filter( self::SKIP_VERIFY_FILTER, array( $this->sut, 'supply_decision_for_add_payment_method' ) );
		remove_action( 'woocommerce_add_payment_method_form_bottom', array( $this->sut, 'enqueue_add_payment_method_script' ), 20 );
		if ( $this->available_gateways_filter ) {
			remove_filter( 'woocommerce_available_payment_gateways', $this->available_gateways_filter );
			$this->available_gateways_filter = null;
		}
		$this->reset_fraud_protection_scripts();

		$session = $this->get_original_woocommerce_session();
		if ( $session ) {
			$session->set( self::RECORD_KEY, null );
		}

		wp_set_current_user( 0 );
		Get_Setup_Intention::reset();

		parent::tearDown();
	}

	/**
	 * Set the POST data of a WooPayments create setup intent request.
	 *
	 * @param array $extra Fields to add to the request.
	 */
	private function set_setup_intent_post( array $extra = array() ): void {
		$_POST = array_merge(
			array(
				'action'                       => 'create_setup_intent',
				'_ajax_nonce'                  => 'nonce-value',
				'wcpay-payment-method'         => 'pm_123',
				'wcpay-fraud-prevention-token' => 'token-value',
				'wcpay-fingerprint'            => 'fingerprint-value',
			),
			$extra
		);
	}

	/**
	 * Run the setup intent request filter, capturing a JSON block response instead of dying.
	 *
	 * @param bool $in_ajax_request Whether the filter runs within the WooPayments setup intent AJAX action.
	 * @return array{blocked: bool, output: string, request_unchanged: bool}
	 */
	private function run_setup_intent_filter( bool $in_ajax_request = true ): array {
		add_filter( 'wp_doing_ajax', '__return_true' );
		$die_handler = function () {
			return function () {
				throw new \WPDieException();
			};
		};
		add_filter( 'wp_die_ajax_handler', $die_handler );

		$request      = new \stdClass();
		$result       = null;
		$apply_filter = function () use ( $request, &$result ) {
			// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Test invokes the hook.
			$result = apply_filters( self::SETUP_INTENT_REQUEST_FILTER, $request, null, false, false );
		};

		$blocked = false;
		ob_start();
		try {
			if ( $in_ajax_request ) {
				add_action( self::SETUP_INTENT_AJAX_ACTION, $apply_filter );
				// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Test invokes the hook.
				do_action( self::SETUP_INTENT_AJAX_ACTION );
			} else {
				$apply_filter();
			}
		} catch ( \WPDieException $e ) {
			$blocked = true;
		} finally {
			$output = (string) ob_get_clean();
			remove_action( self::SETUP_INTENT_AJAX_ACTION, $apply_filter );
			remove_filter( 'wp_doing_ajax', '__return_true' );
			remove_filter( 'wp_die_ajax_handler', $die_handler );
		}

		return array(
			'blocked'           => $blocked,
			'output'            => $output,
			'request_unchanged' => $result === $request,
		);
	}

	/**
	 * Run the skip-verify filter as the add-payment-method form post does.
	 *
	 * @param string $session_id      Submitted session ID.
	 * @param string $payment_method  Submitted gateway ID.
	 * @param string $setup_intent_id Submitted WooPayments setup intent ID; empty to submit none.
	 * @return SuppliedDecision|false
	 */
	private function supply_for_add_payment_method( string $session_id, string $payment_method = 'woocommerce_payments', string $setup_intent_id = self::SETUP_INTENT_ID ) {
		$payment_data = '' === $setup_intent_id ? array() : array( 'wcpay-setup-intent' => $setup_intent_id );

		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Test invokes the hook.
		return apply_filters(
			self::SKIP_VERIFY_FILTER,
			false,
			'add_payment_method',
			array(
				'payment_method' => $payment_method,
				'payment_data'   => $payment_data,
			),
			$session_id
		);
	}

	/**
	 * Verify a setup intent request for the session ID with the given decision.
	 *
	 * @param FraudDecision $decision The setup intent decision.
	 */
	private function verify_setup_intent( FraudDecision $decision ): void {
		$this->set_setup_intent_post( array( SessionVerifier::SESSION_ID_FIELD => 'sess-wcpay' ) );
		$this->session_verifier->method( 'verify_session' )->willReturn( $decision );
		$this->session_verifier->method( 'last_verified_session_id' )->willReturn( 'sess-wcpay' );
		$this->run_setup_intent_filter();
	}

	/**
	 * @testdox register() hooks setup intent verification, decision reuse, and the add-payment-method script.
	 */
	public function test_register_hooks(): void {
		$this->assertSame( 10, has_filter( self::SETUP_INTENT_REQUEST_FILTER, array( $this->sut, 'verify_and_block_setup_intent' ) ) );
		$this->assertSame( 10, has_filter( self::SKIP_VERIFY_FILTER, array( $this->sut, 'supply_decision_for_add_payment_method' ) ) );
		$this->assertSame( 20, has_action( 'woocommerce_add_payment_method_form_bottom', array( $this->sut, 'enqueue_add_payment_method_script' ) ) );
	}

	/**
	 * @testdox The setup intent request is verified with the session ID and WooPayments payment data, and is allowed on ALLOW.
	 */
	public function test_verifies_setup_intent_request_and_allows(): void {
		$this->set_setup_intent_post( array( SessionVerifier::SESSION_ID_FIELD => 'sess-wcpay' ) );

		$this->session_verifier
			->expects( $this->once() )
			->method( 'verify_session' )
			->with(
				'sess-wcpay',
				WooPaymentsSetupIntentCompat::SOURCE,
				0,
				array(
					'payment_method' => 'woocommerce_payments',
					'payment_data'   => array(
						'wcpay-payment-method'         => 'pm_123',
						'wcpay-fraud-prevention-token' => 'token-value',
						'wcpay-fingerprint'            => 'fingerprint-value',
					),
				)
			)
			->willReturn( FraudDecision::Allow );

		$outcome = $this->run_setup_intent_filter();

		$this->assertFalse( $outcome['blocked'], 'An allowed request must reach WooPayments' );
		$this->assertTrue( $outcome['request_unchanged'], 'The WooPayments request must be returned unchanged' );
	}

	/**
	 * @testdox The setup intent request filter is ignored outside the add-payment-method AJAX request.
	 */
	public function test_ignores_setup_intent_requests_outside_ajax_action(): void {
		$this->set_setup_intent_post( array( SessionVerifier::SESSION_ID_FIELD => 'sess-wcpay' ) );
		$this->session_verifier->expects( $this->never() )->method( 'verify_session' );

		$outcome = $this->run_setup_intent_filter( false );

		$this->assertFalse( $outcome['blocked'] );
		$this->assertTrue( $outcome['request_unchanged'], 'The WooPayments request must be returned unchanged' );
	}

	/**
	 * @testdox The session ID error reason is sent when the request has no session ID.
	 */
	public function test_sends_session_id_error_without_session_id(): void {
		$this->set_setup_intent_post( array( SessionIdError::FIELD => 'timeout' ) );

		$this->session_verifier
			->expects( $this->once() )
			->method( 'verify_session' )
			->with(
				'',
				WooPaymentsSetupIntentCompat::SOURCE,
				0,
				$this->callback(
					function ( array $request_data ): bool {
						return 'timeout' === ( $request_data[ SessionIdError::FIELD ] ?? null );
					}
				)
			)
			->willReturn( FraudDecision::Allow );

		$this->run_setup_intent_filter();
	}

	/**
	 * @testdox The setup intent request is stopped with the WooPayments error shape and the generic message on BLOCK.
	 */
	public function test_blocks_setup_intent_request(): void {
		$this->set_setup_intent_post( array( SessionVerifier::SESSION_ID_FIELD => 'sess-wcpay' ) );
		$this->session_verifier->method( 'verify_session' )->willReturn( FraudDecision::Block );

		$this->blocked_session_message
			->expects( $this->once() )
			->method( 'get_plaintext' )
			->with( MessageContext::Generic );

		$outcome = $this->run_setup_intent_filter();

		$this->assertTrue( $outcome['blocked'], 'A blocked request must not reach WooPayments' );
		$response = json_decode( $outcome['output'], true );
		$this->assertFalse( $response['success'] );
		$this->assertSame( 'We are unable to process this request online.', $response['data']['error']['message'], 'WooPayments displays data.error.message to the shopper' );
	}

	/**
	 * @testdox The add-payment-method form post of the same attempt reuses the setup intent decision once.
	 * @dataProvider actionable_decisions
	 *
	 * @param FraudDecision $decision The setup intent decision.
	 */
	public function test_form_post_reuses_decision_once( FraudDecision $decision ): void {
		$this->verify_setup_intent( $decision );

		$supplied = $this->supply_for_add_payment_method( 'sess-wcpay' );

		$this->assertInstanceOf( SuppliedDecision::class, $supplied );
		$this->assertSame( $decision, $supplied->decision );
		$this->assertFalse( $this->supply_for_add_payment_method( 'sess-wcpay' ), 'The decision must be used only once' );
	}

	/**
	 * Actionable decisions.
	 *
	 * @return array<string, array{FraudDecision}>
	 */
	public function actionable_decisions(): array {
		return array(
			'allow' => array( FraudDecision::Allow ),
			'block' => array( FraudDecision::Block ),
		);
	}

	/**
	 * @testdox An ALLOW is reused unless the posted setup intent is read and holds another payment method.
	 * @dataProvider setup_intents_without_another_payment_method
	 *
	 * @param string $setup_intent_id Posted setup intent ID; empty to post none.
	 * @param mixed  $setup_intent    WooPayments response for the setup intent; a string or null is the payment method ID it holds.
	 */
	public function test_allow_reused_without_another_payment_method( string $setup_intent_id, $setup_intent ): void {
		if ( null === $setup_intent || is_string( $setup_intent ) ) {
			Get_Setup_Intention::set_mock_setup_intent( self::SETUP_INTENT_ID, $setup_intent );
		} else {
			Get_Setup_Intention::set_mock_response( self::SETUP_INTENT_ID, $setup_intent );
		}
		$this->verify_setup_intent( FraudDecision::Allow );

		$supplied = $this->supply_for_add_payment_method( 'sess-wcpay', 'woocommerce_payments', $setup_intent_id );

		$this->assertInstanceOf( SuppliedDecision::class, $supplied );
		$this->assertSame( FraudDecision::Allow, $supplied->decision );
	}

	/**
	 * Setup intents, or responses without one, that do not show another payment method.
	 *
	 * @return array<string, array{string, mixed}>
	 */
	public function setup_intents_without_another_payment_method(): array {
		return array(
			'verified payment method' => array( self::SETUP_INTENT_ID, 'pm_123' ),
			'no payment method'       => array( self::SETUP_INTENT_ID, null ),
			'unexpected response'     => array( self::SETUP_INTENT_ID, array( 'payment_method' => 'pm_other' ) ),
			'no setup intent posted'  => array( '', 'pm_other' ),
		);
	}

	/**
	 * @testdox An ALLOW is reused, and the failure is logged, when reading the setup intent throws.
	 */
	public function test_allow_reused_when_reading_setup_intent_throws(): void {
		Get_Setup_Intention::set_mock_response( self::SETUP_INTENT_ID, new \RuntimeException( 'WooPayments unavailable' ) );
		$this->verify_setup_intent( FraudDecision::Allow );

		$supplied = $this->supply_for_add_payment_method( 'sess-wcpay' );

		$this->assertInstanceOf( SuppliedDecision::class, $supplied );
		$this->assertSame( FraudDecision::Allow, $supplied->decision );
		$this->assertLogged(
			'warning',
			'Reading the WooPayments setup intent failed; keeping the setup intent decision',
			array(
				'event_source'    => 'add_payment_method',
				'exception_class' => \RuntimeException::class,
			)
		);
	}

	/**
	 * @testdox An ALLOW is not reused, and the form post verifies, when the setup intent holds another payment method.
	 */
	public function test_allow_not_reused_for_another_payment_method(): void {
		Get_Setup_Intention::set_mock_setup_intent( self::SETUP_INTENT_ID, 'pm_other' );
		$this->verify_setup_intent( FraudDecision::Allow );

		$this->assertFalse( $this->supply_for_add_payment_method( 'sess-wcpay' ) );
		$this->assertSame( array( self::SETUP_INTENT_ID ), Get_Setup_Intention::get_sent() );
		$this->assertLogged(
			'info',
			'WooPayments setup intent holds another payment method than the verified one',
			array( 'event_source' => 'add_payment_method' )
		);

		Get_Setup_Intention::set_mock_setup_intent( self::SETUP_INTENT_ID, 'pm_123' );
		$this->assertFalse( $this->supply_for_add_payment_method( 'sess-wcpay' ), 'The decision must be retired' );
	}

	/**
	 * @testdox A BLOCK is reused without checking the setup intent.
	 */
	public function test_block_reused_without_setup_intent_check(): void {
		Get_Setup_Intention::set_mock_setup_intent( self::SETUP_INTENT_ID, 'pm_other' );
		$this->verify_setup_intent( FraudDecision::Block );

		$supplied = $this->supply_for_add_payment_method( 'sess-wcpay' );

		$this->assertInstanceOf( SuppliedDecision::class, $supplied );
		$this->assertSame( FraudDecision::Block, $supplied->decision );
		$this->assertSame( array(), Get_Setup_Intention::get_sent() );
	}

	/**
	 * @testdox A form post with another session ID verifies normally and retires the decision.
	 */
	public function test_form_post_with_other_session_id_verifies(): void {
		$this->verify_setup_intent( FraudDecision::Allow );

		$this->assertFalse( $this->supply_for_add_payment_method( 'sess-other' ) );
		$this->assertFalse( $this->supply_for_add_payment_method( 'sess-wcpay' ), 'A mismatched post must retire the decision' );
	}

	/**
	 * @testdox A form post by another user verifies normally.
	 */
	public function test_form_post_by_other_user_verifies(): void {
		$this->verify_setup_intent( FraudDecision::Allow );

		wp_set_current_user( self::factory()->user->create() );

		$this->assertFalse( $this->supply_for_add_payment_method( 'sess-wcpay' ) );
	}

	/**
	 * @testdox No decision is kept when the setup intent request had no session ID.
	 */
	public function test_no_reuse_without_submitted_session_id(): void {
		$this->set_setup_intent_post();
		$this->session_verifier->method( 'verify_session' )->willReturn( FraudDecision::Allow );
		$this->session_verifier->method( 'last_verified_session_id' )->willReturn( 'sess-from-service' );
		$this->run_setup_intent_filter();

		$this->assertFalse( $this->supply_for_add_payment_method( 'sess-from-service' ) );
	}

	/**
	 * @testdox The decision is not supplied to other sources or to other gateways.
	 */
	public function test_decision_not_supplied_to_other_sources_or_gateways(): void {
		$this->verify_setup_intent( FraudDecision::Allow );

		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Test invokes the hook.
		$other_source = apply_filters(
			self::SKIP_VERIFY_FILTER,
			false,
			'shortcode_checkout',
			array( 'payment_method' => 'woocommerce_payments' ),
			'sess-wcpay'
		);

		$this->assertFalse( $other_source );
		$this->assertFalse( $this->supply_for_add_payment_method( 'sess-wcpay', 'stripe' ) );
		$this->assertFalse( $this->supply_for_add_payment_method( 'sess-wcpay', 'woocommerce_payments_sepa_debit' ) );
		$this->assertInstanceOf( SuppliedDecision::class, $this->supply_for_add_payment_method( 'sess-wcpay' ), 'Non-matching requests must not consume the decision' );
	}

	/**
	 * @testdox A failure to record the decision still enforces the block and is logged.
	 */
	public function test_record_failure_still_blocks(): void {
		$this->set_setup_intent_post( array( SessionVerifier::SESSION_ID_FIELD => 'sess-wcpay' ) );
		$this->session_verifier->method( 'verify_session' )->willReturn( FraudDecision::Block );
		$this->session_verifier->method( 'last_verified_session_id' )->willReturn( 'sess-wcpay' );

		$session = $this->createMock( \WC_Session::class );
		$session->method( 'set' )->willThrowException( new \RuntimeException( 'session unavailable' ) );
		WC()->session = $session;

		$outcome = $this->run_setup_intent_filter();

		$this->assertTrue( $outcome['blocked'], 'The block must still be enforced when recording the decision fails' );
		$this->assertLogged(
			'warning',
			'Recording the WooPayments setup intent verification failed',
			array(
				'event_source'    => WooPaymentsSetupIntentCompat::SOURCE,
				'exception_class' => \RuntimeException::class,
			),
			true
		);
	}

	/**
	 * @testdox The script is enqueued when the add-payment-method script is enqueued and the WooPayments gateway is available.
	 */
	public function test_enqueues_script_with_add_payment_method_script_and_woopayments_gateway(): void {
		wp_enqueue_script( 'wc-fraud-protection-add-payment-method', 'https://example.com/add-payment-method.js', array(), '1', true );
		$this->set_available_gateway_ids( array( 'dummy', 'woocommerce_payments' ) );

		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Test invokes the hook.
		do_action( 'woocommerce_add_payment_method_form_bottom' );

		$this->assertTrue( wp_script_is( 'wc-fraud-protection-woopayments-add-payment-method', 'enqueued' ) );
		$script = wp_scripts()->query( 'wc-fraud-protection-woopayments-add-payment-method', 'registered' );
		$this->assertSame( plugins_url( 'assets/js/woopayments-add-payment-method.js', WC_FRAUD_PROTECTION_PLUGIN_FILE ), $script->src );
		$this->assertContains( 'wc-fraud-protection-add-payment-method', $script->deps );
	}

	/**
	 * @testdox The script is not enqueued without the add-payment-method script or without the available WooPayments gateway.
	 * @dataProvider missing_requirement_cases
	 *
	 * @param bool     $add_payment_method_script Whether the add-payment-method script is enqueued.
	 * @param string[] $gateway_ids               IDs of the available payment gateways.
	 */
	public function test_does_not_enqueue_script_when_a_requirement_is_missing( bool $add_payment_method_script, array $gateway_ids ): void {
		if ( $add_payment_method_script ) {
			wp_enqueue_script( 'wc-fraud-protection-add-payment-method', 'https://example.com/add-payment-method.js', array(), '1', true );
		}
		$this->set_available_gateway_ids( $gateway_ids );

		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Test invokes the hook.
		do_action( 'woocommerce_add_payment_method_form_bottom' );

		$this->assertFalse( wp_script_is( 'wc-fraud-protection-woopayments-add-payment-method', 'enqueued' ) );
	}

	/**
	 * Cases where one of the requirements for the script is missing.
	 *
	 * @return array<string, array{bool, string[]}>
	 */
	public function missing_requirement_cases(): array {
		return array(
			'no WooPayments gateway'       => array( true, array( 'dummy', 'woocommerce_payments_sepa_debit' ) ),
			'no add-payment-method script' => array( false, array( 'woocommerce_payments' ) ),
		);
	}

	/**
	 * Make only the given payment gateways available.
	 *
	 * @param string[] $gateway_ids Gateway IDs.
	 */
	private function set_available_gateway_ids( array $gateway_ids ): void {
		$gateways = array();
		foreach ( $gateway_ids as $gateway_id ) {
			$gateway                 = new class() extends \WC_Payment_Gateway {};
			$gateway->id             = $gateway_id;
			$gateways[ $gateway_id ] = $gateway;
		}

		$this->available_gateways_filter = static fn() => $gateways;
		add_filter( 'woocommerce_available_payment_gateways', $this->available_gateways_filter );
	}
}
