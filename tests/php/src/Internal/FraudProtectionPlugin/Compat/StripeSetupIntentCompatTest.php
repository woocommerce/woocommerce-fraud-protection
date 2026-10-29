<?php
/**
 * StripeSetupIntentCompatTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin\Compat;

use Automattic\WooCommerce\FraudProtection\BlockedSessionMessage;
use Automattic\WooCommerce\FraudProtection\FraudProtectionFeature;
use Automattic\WooCommerce\FraudProtection\MessageContext;
use Automattic\WooCommerce\FraudProtection\Schemas\FraudDecision;
use Automattic\WooCommerce\FraudProtection\SessionIdNormalizer;
use Automattic\WooCommerce\FraudProtection\SessionVerifier;
use Automattic\WooCommerce\FraudProtection\SuppliedDecision;
use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;
use Automattic\WooCommerce\FraudProtection\Tests\Support\FakeNativeGateway;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Compat\GatewayNativeSupport;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Compat\StripeSetupIntentCompat;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Sessions\SessionIdError;

require_once __DIR__ . '/../../../../stubs/wc-stripe-api.php';

/**
 * Tests for the StripeSetupIntentCompat class.
 *
 * @covers \Automattic\WooCommerce\Internal\FraudProtectionPlugin\Compat\StripeSetupIntentCompat
 */
class StripeSetupIntentCompatTest extends FraudProtectionUnitTestCase {

	private const CAPTCHA_FILTER = 'wc_stripe_is_valid_create_and_confirm_setup_intent_captcha';

	private const SKIP_VERIFY_FILTER = 'woocommerce_fraud_protection_skip_session_verify';

	private const RECORD_KEY = '_fraud_protection_stripe_setup_intent_verification';

	private const SETUP_INTENT_ID = 'seti_123';

	private const SETUP_INTENT_API = 'setup_intents/seti_123';

	/**
	 * The System Under Test.
	 *
	 * @var StripeSetupIntentCompat
	 */
	private StripeSetupIntentCompat $sut;

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

		$this->sut = new StripeSetupIntentCompat();
		$this->sut->init(
			$this->session_verifier,
			$this->blocked_session_message,
			new SessionIdNormalizer(),
			new GatewayNativeSupport()
		);
		$this->sut->register();

		wp_set_current_user( self::factory()->user->create() );

		\WC_Stripe_API::reset();
		\WC_Stripe_API::set_mock_retrieve_response( self::SETUP_INTENT_API, $this->create_setup_intent( 'succeeded', 'pm_123' ) );
	}

	/**
	 * Tear down after each test.
	 */
	public function tearDown(): void {
		$_POST = array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		remove_filter( self::CAPTCHA_FILTER, array( $this->sut, 'verify_and_block_setup_intent' ) );
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
		\WC_Stripe_API::reset();

		parent::tearDown();
	}

	/**
	 * Create a Stripe setup intent response.
	 *
	 * @param string $status         Setup intent status.
	 * @param mixed  $payment_method Payment method ID, or the expanded payment method.
	 * @return object
	 */
	private function create_setup_intent( string $status, $payment_method ): object {
		return (object) array(
			'id'             => self::SETUP_INTENT_ID,
			'object'         => 'setup_intent',
			'status'         => $status,
			'payment_method' => $payment_method,
		);
	}

	/**
	 * Set the POST data of a Stripe create-and-confirm setup intent request.
	 *
	 * @param array $extra Fields to add to the request.
	 */
	private function set_setup_intent_post( array $extra = array() ): void {
		$_POST = array_merge(
			array(
				'action'                   => 'wc_stripe_create_and_confirm_setup_intent',
				'_ajax_nonce'              => 'nonce-value',
				'wc-stripe-payment-method' => 'pm_123',
				'wc-stripe-payment-type'   => 'card',
			),
			$extra
		);
	}

	/**
	 * Run the setup intent filter, capturing a JSON block response instead of dying.
	 *
	 * @return array{blocked: bool, output: string, result: mixed}
	 */
	private function run_setup_intent_filter(): array {
		add_filter( 'wp_doing_ajax', '__return_true' );
		$die_handler = function () {
			return function () {
				throw new \WPDieException();
			};
		};
		add_filter( 'wp_die_ajax_handler', $die_handler );

		$blocked = false;
		$result  = null;
		ob_start();
		try {
			// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Test invokes the hook.
			$result = apply_filters( self::CAPTCHA_FILTER, true );
		} catch ( \WPDieException $e ) {
			$blocked = true;
		} finally {
			$output = (string) ob_get_clean();
			remove_filter( 'wp_doing_ajax', '__return_true' );
			remove_filter( 'wp_die_ajax_handler', $die_handler );
		}

		return array(
			'blocked' => $blocked,
			'output'  => $output,
			'result'  => $result,
		);
	}

	/**
	 * Run the skip-verify filter as the add-payment-method form post does.
	 *
	 * @param string $session_id      Submitted session ID.
	 * @param string $payment_method  Submitted gateway ID.
	 * @param string $setup_intent_id Submitted Stripe setup intent ID; empty to submit none.
	 * @return SuppliedDecision|false
	 */
	private function supply_for_add_payment_method( string $session_id, string $payment_method = 'stripe', string $setup_intent_id = self::SETUP_INTENT_ID ) {
		$payment_data = '' === $setup_intent_id ? array() : array( 'wc-stripe-setup-intent' => $setup_intent_id );

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
		$this->set_setup_intent_post( array( SessionVerifier::SESSION_ID_FIELD => 'sess-stripe' ) );
		$this->session_verifier->method( 'verify_session' )->willReturn( $decision );
		$this->session_verifier->method( 'last_verified_session_id' )->willReturn( 'sess-stripe' );
		$this->run_setup_intent_filter();
	}

	/**
	 * @testdox register() hooks setup intent verification, decision reuse, and the add-payment-method script.
	 */
	public function test_register_hooks(): void {
		$this->assertSame( 10, has_filter( self::CAPTCHA_FILTER, array( $this->sut, 'verify_and_block_setup_intent' ) ) );
		$this->assertSame( 10, has_filter( self::SKIP_VERIFY_FILTER, array( $this->sut, 'supply_decision_for_add_payment_method' ) ) );
		$this->assertSame( 20, has_action( 'woocommerce_add_payment_method_form_bottom', array( $this->sut, 'enqueue_add_payment_method_script' ) ) );
	}

	/**
	 * @testdox Every hook stands aside when the main Stripe gateway declares native add-payment-method support.
	 */
	public function test_stands_aside_when_gateway_declares_native_add_payment_method(): void {
		$this->register_payment_gateways( new FakeNativeGateway( 'stripe', array( FraudProtectionFeature::ADD_PAYMENT_METHOD ) ) );
		$this->set_setup_intent_post( array( SessionVerifier::SESSION_ID_FIELD => 'sess-stripe' ) );
		$this->session_verifier->expects( $this->never() )->method( 'verify_session' );
		wp_enqueue_script( 'wc-fraud-protection-add-payment-method', 'https://example.com/add-payment-method.js', array(), '1', true );
		$this->set_available_gateway_ids( array( 'stripe' ) );

		$outcome  = $this->run_setup_intent_filter();
		$supplied = $this->supply_for_add_payment_method( 'sess-stripe' );
		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Test invokes the hook.
		do_action( 'woocommerce_add_payment_method_form_bottom' );

		$this->assertFalse( $outcome['blocked'] );
		$this->assertTrue( $outcome['result'], 'The filter value must be returned unchanged' );
		$this->assertFalse( $supplied );
		$this->assertFalse( wp_script_is( 'wc-fraud-protection-stripe-add-payment-method', 'enqueued' ) );
	}

	/**
	 * @testdox A declaration on one Stripe payment method gateway covers only that payment method.
	 */
	public function test_payment_method_gateway_declaration_covers_only_its_requests(): void {
		$this->register_payment_gateways( new FakeNativeGateway( 'stripe_sepa_debit', array( FraudProtectionFeature::ADD_PAYMENT_METHOD ) ) );
		$this->session_verifier
			->expects( $this->once() )
			->method( 'verify_session' )
			->with( $this->anything(), StripeSetupIntentCompat::SOURCE, 0, $this->callback( fn( $data ) => 'stripe' === $data['payment_method'] ) )
			->willReturn( FraudDecision::Allow );

		$this->set_setup_intent_post( array( 'wc-stripe-payment-type' => 'sepa_debit' ) );
		$this->run_setup_intent_filter();
		$this->set_setup_intent_post();
		$this->run_setup_intent_filter();
	}

	/**
	 * @testdox Declaring only native checkout support keeps setup intent verification on.
	 */
	public function test_checkout_declaration_keeps_setup_intent_verification(): void {
		$this->register_payment_gateways( new FakeNativeGateway( 'stripe', array( FraudProtectionFeature::CHECKOUT ) ) );
		$this->set_setup_intent_post();
		$this->session_verifier->expects( $this->once() )->method( 'verify_session' )->willReturn( FraudDecision::Allow );

		$this->run_setup_intent_filter();
	}

	/**
	 * @testdox The setup intent request is verified with the session ID and Stripe payment data, and is allowed on ALLOW.
	 */
	public function test_verifies_setup_intent_request_and_allows(): void {
		$this->set_setup_intent_post( array( SessionVerifier::SESSION_ID_FIELD => 'sess-stripe' ) );

		$this->session_verifier
			->expects( $this->once() )
			->method( 'verify_session' )
			->with(
				'sess-stripe',
				StripeSetupIntentCompat::SOURCE,
				0,
				array(
					'payment_method' => 'stripe',
					'payment_data'   => array(
						'wc-stripe-payment-method' => 'pm_123',
						'wc-stripe-payment-type'   => 'card',
					),
				)
			)
			->willReturn( FraudDecision::Allow );

		$outcome = $this->run_setup_intent_filter();

		$this->assertFalse( $outcome['blocked'], 'An allowed request must reach Stripe' );
		$this->assertTrue( $outcome['result'], 'The filter value must be returned unchanged' );
	}

	/**
	 * @testdox A non-card payment method type is verified with the matching Stripe gateway ID.
	 */
	public function test_derives_gateway_id_from_payment_type(): void {
		$this->set_setup_intent_post( array( 'wc-stripe-payment-type' => 'sepa_debit' ) );

		$this->session_verifier
			->expects( $this->once() )
			->method( 'verify_session' )
			->with(
				'',
				StripeSetupIntentCompat::SOURCE,
				0,
				$this->callback(
					function ( array $request_data ): bool {
						return 'stripe_sepa_debit' === $request_data['payment_method'];
					}
				)
			)
			->willReturn( FraudDecision::Allow );

		$this->run_setup_intent_filter();
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
				StripeSetupIntentCompat::SOURCE,
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
	 * @testdox The setup intent request is stopped with Stripe's error shape and the generic message on BLOCK.
	 */
	public function test_blocks_setup_intent_request(): void {
		$this->set_setup_intent_post( array( SessionVerifier::SESSION_ID_FIELD => 'sess-stripe' ) );
		$this->session_verifier->method( 'verify_session' )->willReturn( FraudDecision::Block );

		$this->blocked_session_message
			->expects( $this->once() )
			->method( 'get_plaintext' )
			->with( MessageContext::Generic );

		$outcome = $this->run_setup_intent_filter();

		$this->assertTrue( $outcome['blocked'], 'A blocked request must not reach Stripe' );
		$response = json_decode( $outcome['output'], true );
		$this->assertFalse( $response['success'] );
		$this->assertSame( 'We are unable to process this request online.', $response['data']['error']['message'], 'Stripe displays data.error.message to the shopper' );
	}

	/**
	 * @testdox A request that already failed an earlier check is not verified.
	 */
	public function test_respects_prior_failure(): void {
		$this->set_setup_intent_post();
		$this->session_verifier->expects( $this->never() )->method( 'verify_session' );

		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Test invokes the hook.
		$this->assertFalse( apply_filters( self::CAPTCHA_FILTER, false ) );
	}

	/**
	 * @testdox The add-payment-method form post of the same attempt reuses the setup intent decision once.
	 * @dataProvider actionable_decisions
	 *
	 * @param FraudDecision $decision The setup intent decision.
	 */
	public function test_form_post_reuses_decision_once( FraudDecision $decision ): void {
		$this->set_setup_intent_post( array( SessionVerifier::SESSION_ID_FIELD => 'sess-stripe' ) );
		$this->session_verifier->method( 'verify_session' )->willReturn( $decision );
		$this->session_verifier->method( 'last_verified_session_id' )->willReturn( 'sess-stripe' );
		$this->run_setup_intent_filter();

		$supplied = $this->supply_for_add_payment_method( 'sess-stripe' );

		$this->assertInstanceOf( SuppliedDecision::class, $supplied );
		$this->assertSame( $decision, $supplied->decision );
		$this->assertFalse( $this->supply_for_add_payment_method( 'sess-stripe' ), 'The decision must be used only once' );
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
	 * @param mixed  $setup_intent    Stripe response for the setup intent.
	 */
	public function test_allow_reused_without_another_payment_method( string $setup_intent_id, $setup_intent ): void {
		\WC_Stripe_API::set_mock_retrieve_response( self::SETUP_INTENT_API, $setup_intent );
		$this->verify_setup_intent( FraudDecision::Allow );

		$supplied = $this->supply_for_add_payment_method( 'sess-stripe', 'stripe', $setup_intent_id );

		$this->assertInstanceOf( SuppliedDecision::class, $supplied );
		$this->assertSame( FraudDecision::Allow, $supplied->decision );
	}

	/**
	 * Setup intents, or failures to read them, that do not show another payment method.
	 *
	 * @return array<string, array{string, mixed}>
	 */
	public function setup_intents_without_another_payment_method(): array {
		$matching = $this->create_setup_intent( 'succeeded', 'pm_123' );

		return array(
			'verified payment method'               => array( self::SETUP_INTENT_ID, $matching ),
			'verified payment method, expanded'     => array( self::SETUP_INTENT_ID, $this->create_setup_intent( 'succeeded', (object) array( 'id' => 'pm_123' ) ) ),
			'verified payment method, not complete' => array( self::SETUP_INTENT_ID, $this->create_setup_intent( 'requires_action', 'pm_123' ) ),
			'no payment method'                     => array( self::SETUP_INTENT_ID, $this->create_setup_intent( 'requires_payment_method', null ) ),
			'Stripe error'                          => array( self::SETUP_INTENT_ID, (object) array( 'error' => (object) array( 'message' => 'Rate limited' ) ) ),
			'request failure'                       => array( self::SETUP_INTENT_ID, new \WP_Error( 'stripe_error', 'Connection failed' ) ),
			'no response'                           => array( self::SETUP_INTENT_ID, null ),
			'no setup intent posted'                => array( '', $matching ),
		);
	}

	/**
	 * @testdox An ALLOW is reused, and the failure is logged, when reading the setup intent throws.
	 */
	public function test_allow_reused_when_reading_setup_intent_throws(): void {
		\WC_Stripe_API::set_mock_retrieve_response( self::SETUP_INTENT_API, new \RuntimeException( 'Stripe unavailable' ) );
		$this->verify_setup_intent( FraudDecision::Allow );

		$supplied = $this->supply_for_add_payment_method( 'sess-stripe' );

		$this->assertInstanceOf( SuppliedDecision::class, $supplied );
		$this->assertSame( FraudDecision::Allow, $supplied->decision );
		$this->assertLogged(
			'warning',
			'Reading the Stripe setup intent failed; keeping the setup intent decision',
			array(
				'event_source'    => 'add_payment_method',
				'exception_class' => \RuntimeException::class,
			)
		);
	}

	/**
	 * @testdox An ALLOW is not reused, and the form post verifies, when the setup intent holds another payment method.
	 * @dataProvider setup_intents_with_another_payment_method
	 *
	 * @param mixed $setup_intent Stripe response for the setup intent.
	 */
	public function test_allow_not_reused_for_another_payment_method( $setup_intent ): void {
		\WC_Stripe_API::set_mock_retrieve_response( self::SETUP_INTENT_API, $setup_intent );
		$this->verify_setup_intent( FraudDecision::Allow );

		$this->assertFalse( $this->supply_for_add_payment_method( 'sess-stripe' ) );
		$this->assertSame( array( self::SETUP_INTENT_API ), \WC_Stripe_API::get_retrieved() );
		$this->assertLogged(
			'info',
			'Stripe setup intent holds another payment method than the verified one',
			array( 'event_source' => 'add_payment_method' )
		);

		\WC_Stripe_API::set_mock_retrieve_response( self::SETUP_INTENT_API, $this->create_setup_intent( 'succeeded', 'pm_123' ) );
		$this->assertFalse( $this->supply_for_add_payment_method( 'sess-stripe' ), 'The decision must be retired' );
	}

	/**
	 * Setup intents that hold another payment method than the verified one.
	 *
	 * @return array<string, array{mixed}>
	 */
	public function setup_intents_with_another_payment_method(): array {
		return array(
			'completed'    => array( $this->create_setup_intent( 'succeeded', 'pm_other' ) ),
			'not complete' => array( $this->create_setup_intent( 'requires_action', 'pm_other' ) ),
			'expanded'     => array( $this->create_setup_intent( 'succeeded', (object) array( 'id' => 'pm_other' ) ) ),
		);
	}

	/**
	 * @testdox A BLOCK is reused without checking the setup intent.
	 */
	public function test_block_reused_without_setup_intent_check(): void {
		\WC_Stripe_API::set_mock_retrieve_response( self::SETUP_INTENT_API, $this->create_setup_intent( 'succeeded', 'pm_other' ) );
		$this->verify_setup_intent( FraudDecision::Block );

		$supplied = $this->supply_for_add_payment_method( 'sess-stripe' );

		$this->assertInstanceOf( SuppliedDecision::class, $supplied );
		$this->assertSame( FraudDecision::Block, $supplied->decision );
		$this->assertSame( array(), \WC_Stripe_API::get_retrieved() );
	}

	/**
	 * @testdox A form post with another session ID verifies normally and retires the decision.
	 */
	public function test_form_post_with_other_session_id_verifies(): void {
		$this->set_setup_intent_post( array( SessionVerifier::SESSION_ID_FIELD => 'sess-stripe' ) );
		$this->session_verifier->method( 'verify_session' )->willReturn( FraudDecision::Allow );
		$this->session_verifier->method( 'last_verified_session_id' )->willReturn( 'sess-stripe' );
		$this->run_setup_intent_filter();

		$this->assertFalse( $this->supply_for_add_payment_method( 'sess-other' ) );
		$this->assertFalse( $this->supply_for_add_payment_method( 'sess-stripe' ), 'A mismatched post must retire the decision' );
	}

	/**
	 * @testdox A form post by another user verifies normally.
	 */
	public function test_form_post_by_other_user_verifies(): void {
		$this->set_setup_intent_post( array( SessionVerifier::SESSION_ID_FIELD => 'sess-stripe' ) );
		$this->session_verifier->method( 'verify_session' )->willReturn( FraudDecision::Allow );
		$this->session_verifier->method( 'last_verified_session_id' )->willReturn( 'sess-stripe' );
		$this->run_setup_intent_filter();

		wp_set_current_user( self::factory()->user->create() );

		$this->assertFalse( $this->supply_for_add_payment_method( 'sess-stripe' ) );
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
		$this->set_setup_intent_post( array( SessionVerifier::SESSION_ID_FIELD => 'sess-stripe' ) );
		$this->session_verifier->method( 'verify_session' )->willReturn( FraudDecision::Allow );
		$this->session_verifier->method( 'last_verified_session_id' )->willReturn( 'sess-stripe' );
		$this->run_setup_intent_filter();

		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Test invokes the hook.
		$other_source = apply_filters(
			self::SKIP_VERIFY_FILTER,
			false,
			'shortcode_checkout',
			array( 'payment_method' => 'stripe' ),
			'sess-stripe'
		);

		$this->assertFalse( $other_source );
		$this->assertFalse( $this->supply_for_add_payment_method( 'sess-stripe', 'woocommerce_payments' ) );
		$this->assertInstanceOf( SuppliedDecision::class, $this->supply_for_add_payment_method( 'sess-stripe' ), 'Non-matching requests must not consume the decision' );
	}

	/**
	 * @testdox A failure to record the decision still enforces the block and is logged.
	 */
	public function test_record_failure_still_blocks(): void {
		$this->set_setup_intent_post( array( SessionVerifier::SESSION_ID_FIELD => 'sess-stripe' ) );
		$this->session_verifier->method( 'verify_session' )->willReturn( FraudDecision::Block );
		$this->session_verifier->method( 'last_verified_session_id' )->willReturn( 'sess-stripe' );

		$session = $this->createMock( \WC_Session::class );
		$session->method( 'set' )->willThrowException( new \RuntimeException( 'session unavailable' ) );
		WC()->session = $session;

		$outcome = $this->run_setup_intent_filter();

		$this->assertTrue( $outcome['blocked'], 'The block must still be enforced when recording the decision fails' );
		$this->assertLogged(
			'warning',
			'Recording the Stripe setup intent verification failed',
			array(
				'event_source'    => StripeSetupIntentCompat::SOURCE,
				'exception_class' => \RuntimeException::class,
			),
			true
		);
	}

	/**
	 * @testdox The script is enqueued when the add-payment-method script is enqueued and a Stripe gateway is available.
	 */
	public function test_enqueues_script_with_add_payment_method_script_and_stripe_gateway(): void {
		wp_enqueue_script( 'wc-fraud-protection-add-payment-method', 'https://example.com/add-payment-method.js', array(), '1', true );
		$this->set_available_gateway_ids( array( 'dummy', 'stripe' ) );

		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Test invokes the hook.
		do_action( 'woocommerce_add_payment_method_form_bottom' );

		$this->assertTrue( wp_script_is( 'wc-fraud-protection-stripe-add-payment-method', 'enqueued' ) );
		$script = wp_scripts()->query( 'wc-fraud-protection-stripe-add-payment-method', 'registered' );
		$this->assertSame( plugins_url( 'assets/js/stripe-add-payment-method.js', WC_FRAUD_PROTECTION_PLUGIN_FILE ), $script->src );
		$this->assertContains( 'wc-fraud-protection-add-payment-method', $script->deps );
	}

	/**
	 * @testdox The script is not enqueued without the add-payment-method script or without an available Stripe gateway.
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

		$this->assertFalse( wp_script_is( 'wc-fraud-protection-stripe-add-payment-method', 'enqueued' ) );
	}

	/**
	 * Cases where one of the requirements for the script is missing.
	 *
	 * @return array<string, array{bool, string[]}>
	 */
	public function missing_requirement_cases(): array {
		return array(
			'no Stripe gateway'            => array( true, array( 'dummy' ) ),
			'no add-payment-method script' => array( false, array( 'stripe' ) ),
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
