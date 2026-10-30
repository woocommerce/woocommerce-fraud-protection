<?php
/**
 * StripeSetupIntentCompatTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin\Compat;

use Automattic\WooCommerce\FraudProtection\BlockedSessionMessage;
use Automattic\WooCommerce\FraudProtection\FraudProtectionFeature;
use Automattic\WooCommerce\FraudProtection\GatewayRequestVerifier;
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
use Automattic\WooCommerce\Proxies\LegacyProxy;

require_once __DIR__ . '/../../../../stubs/wc-stripe-api.php';

/**
 * Tests for the StripeSetupIntentCompat class.
 *
 * @covers \Automattic\WooCommerce\Internal\FraudProtectionPlugin\Compat\StripeSetupIntentCompat
 */
class StripeSetupIntentCompatTest extends FraudProtectionUnitTestCase {

	private const CAPTCHA_FILTER = 'wc_stripe_is_valid_create_and_confirm_setup_intent_captcha';

	private const SKIP_VERIFY_FILTER = 'woocommerce_fraud_protection_skip_session_verify';

	private const REUSABLE_DECISIONS_KEY = '_wc_fraud_protection_reusable_decisions';

	private const SETUP_INTENT_ID = 'seti_123';

	private const SETUP_INTENT_API = 'setup_intents/seti_123';

	/**
	 * The System Under Test.
	 *
	 * @var StripeSetupIntentCompat
	 */
	private StripeSetupIntentCompat $sut;

	/**
	 * The gateway request verifier that the compatibility layer uses.
	 *
	 * @var GatewayRequestVerifier
	 */
	private GatewayRequestVerifier $gateway_request_verifier;

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
	 * Hooks of the plugin's shared instances removed for the test, to restore afterwards.
	 *
	 * @var array<array{string, callable, int, int}>
	 */
	private array $removed_shared_hooks = array();

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		// The plugin's own instances, registered when it loaded, would verify and supply decisions alongside the ones under test.
		$this->remove_shared_hook( self::CAPTCHA_FILTER, array( wc_get_container()->get( StripeSetupIntentCompat::class ), 'verify_and_block_setup_intent' ), 1 );
		$this->remove_shared_hook( self::SKIP_VERIFY_FILTER, array( wc_get_container()->get( GatewayRequestVerifier::class ), 'supply_reused_decision' ), 3 );

		$this->session_verifier        = $this->createMock( SessionVerifier::class );
		$this->blocked_session_message = $this->createMock( BlockedSessionMessage::class );
		$this->blocked_session_message
			->method( 'get_plaintext' )
			->willReturn( 'We are unable to process this request online.' );

		$this->gateway_request_verifier = new GatewayRequestVerifier();
		$this->gateway_request_verifier->init( $this->session_verifier, new SessionIdNormalizer(), wc_get_container()->get( LegacyProxy::class ), new GatewayNativeSupport() );
		$this->gateway_request_verifier->register();

		$this->sut = new StripeSetupIntentCompat();
		$this->sut->init( $this->gateway_request_verifier, $this->blocked_session_message, new GatewayNativeSupport() );
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
		remove_filter( self::SKIP_VERIFY_FILTER, array( $this->gateway_request_verifier, 'supply_reused_decision' ) );
		remove_action( 'woocommerce_add_payment_method_form_bottom', array( $this->sut, 'enqueue_add_payment_method_script' ), 20 );
		foreach ( $this->removed_shared_hooks as list( $hook, $callback, $priority, $accepted_args ) ) {
			add_filter( $hook, $callback, $priority, $accepted_args );
		}
		$this->removed_shared_hooks = array();
		if ( $this->available_gateways_filter ) {
			remove_filter( 'woocommerce_available_payment_gateways', $this->available_gateways_filter );
			$this->available_gateways_filter = null;
		}
		$this->reset_fraud_protection_scripts();

		$session = $this->get_original_woocommerce_session();
		if ( $session ) {
			$session->set( self::REUSABLE_DECISIONS_KEY, null );
		}

		wp_set_current_user( 0 );
		\WC_Stripe_API::reset();

		parent::tearDown();
	}

	/**
	 * Remove a hook of one of the plugin's shared instances until the test ends.
	 *
	 * @param string   $hook          The hook name.
	 * @param callable $callback      The callback.
	 * @param int      $accepted_args The number of arguments the callback accepts.
	 */
	private function remove_shared_hook( string $hook, callable $callback, int $accepted_args ): void {
		$priority = has_filter( $hook, $callback );
		if ( false === $priority ) {
			return;
		}

		remove_filter( $hook, $callback, $priority );
		$this->removed_shared_hooks[] = array( $hook, $callback, $priority, $accepted_args );
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
	 * Run the skip-verify filter as a later form post does.
	 *
	 * @param string $payment_method  Submitted gateway ID.
	 * @param string $setup_intent_id Submitted Stripe setup intent ID; empty to submit none.
	 * @param string $source          Verification source of the form post.
	 * @return SuppliedDecision|false
	 */
	private function supply_for_add_payment_method( string $payment_method = 'stripe', string $setup_intent_id = self::SETUP_INTENT_ID, string $source = 'add_payment_method' ) {
		$payment_data = '' === $setup_intent_id ? array() : array( 'wc-stripe-setup-intent' => $setup_intent_id );

		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Test invokes the hook.
		return apply_filters(
			self::SKIP_VERIFY_FILTER,
			false,
			$source,
			array(
				'payment_method' => $payment_method,
				'payment_data'   => $payment_data,
			),
			'sess-form'
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
	 * @testdox register() hooks setup intent verification and the add-payment-method script.
	 */
	public function test_register_hooks(): void {
		$this->assertSame( 10, has_filter( self::CAPTCHA_FILTER, array( $this->sut, 'verify_and_block_setup_intent' ) ) );
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

		$outcome = $this->run_setup_intent_filter();
		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Test invokes the hook.
		do_action( 'woocommerce_add_payment_method_form_bottom' );

		$this->assertFalse( $outcome['blocked'] );
		$this->assertTrue( $outcome['result'], 'The filter value must be returned unchanged' );
		$this->assertFalse( wp_script_is( 'wc-fraud-protection-stripe-add-payment-method', 'enqueued' ) );
	}

	/**
	 * @testdox A form post verifies normally once the main Stripe gateway declares native add-payment-method support.
	 */
	public function test_form_post_verifies_once_gateway_declares_native_add_payment_method(): void {
		$this->verify_setup_intent( FraudDecision::Allow );
		$this->register_payment_gateways( new FakeNativeGateway( 'stripe', array( FraudProtectionFeature::ADD_PAYMENT_METHOD ) ) );

		$this->assertFalse( $this->supply_for_add_payment_method() );
		$this->assertSame( array(), \WC_Stripe_API::get_retrieved(), 'The setup intent is not read' );
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
	 * @testdox The add-payment-method form post whose setup intent holds the verified payment method reuses the decision once.
	 * @dataProvider actionable_decisions
	 *
	 * @param FraudDecision $decision The setup intent decision.
	 */
	public function test_form_post_reuses_decision_once( FraudDecision $decision ): void {
		$this->verify_setup_intent( $decision );

		$supplied = $this->supply_for_add_payment_method();

		$this->assertInstanceOf( SuppliedDecision::class, $supplied );
		$this->assertSame( $decision, $supplied->decision );
		$this->assertSame( 'sess-stripe', $supplied->session_id_for_order );
		$this->assertSame( array( self::SETUP_INTENT_API ), \WC_Stripe_API::get_retrieved() );
		$this->assertFalse( $this->supply_for_add_payment_method(), 'The decision must be used only once' );
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
	 * @testdox The decision is reused whatever the setup intent's status and payment method format, as long as it holds the verified payment method.
	 * @dataProvider setup_intents_with_the_verified_payment_method
	 *
	 * @param object $setup_intent Stripe response for the setup intent.
	 */
	public function test_reused_for_setup_intent_with_the_verified_payment_method( object $setup_intent ): void {
		\WC_Stripe_API::set_mock_retrieve_response( self::SETUP_INTENT_API, $setup_intent );
		$this->verify_setup_intent( FraudDecision::Allow );

		$this->assertInstanceOf( SuppliedDecision::class, $this->supply_for_add_payment_method() );
	}

	/**
	 * Setup intents that hold the verified payment method.
	 *
	 * @return array<string, array{object}>
	 */
	public function setup_intents_with_the_verified_payment_method(): array {
		return array(
			'completed'    => array( $this->create_setup_intent( 'succeeded', 'pm_123' ) ),
			'not complete' => array( $this->create_setup_intent( 'requires_action', 'pm_123' ) ),
			'expanded'     => array( $this->create_setup_intent( 'succeeded', (object) array( 'id' => 'pm_123' ) ) ),
		);
	}

	/**
	 * @testdox The form post verifies normally, and the decision stays for the form post it belongs to, when the setup intent does not show the verified payment method.
	 * @dataProvider setup_intents_without_the_verified_payment_method
	 *
	 * @param FraudDecision $decision        The setup intent decision.
	 * @param string        $setup_intent_id Posted setup intent ID; empty to post none.
	 * @param mixed         $setup_intent    Stripe response for the setup intent.
	 */
	public function test_not_reused_without_the_verified_payment_method( FraudDecision $decision, string $setup_intent_id, $setup_intent ): void {
		\WC_Stripe_API::set_mock_retrieve_response( self::SETUP_INTENT_API, $setup_intent );
		$this->verify_setup_intent( $decision );

		$this->assertFalse( $this->supply_for_add_payment_method( 'stripe', $setup_intent_id ) );

		\WC_Stripe_API::set_mock_retrieve_response( self::SETUP_INTENT_API, $this->create_setup_intent( 'succeeded', 'pm_123' ) );
		$this->assertInstanceOf( SuppliedDecision::class, $this->supply_for_add_payment_method() );
	}

	/**
	 * Setup intents, or failures to read them, that do not show the verified payment method.
	 *
	 * @return array<string, array{FraudDecision, string, mixed}>
	 */
	public function setup_intents_without_the_verified_payment_method(): array {
		$other = $this->create_setup_intent( 'succeeded', 'pm_other' );

		return array(
			'another payment method'           => array( FraudDecision::Allow, self::SETUP_INTENT_ID, $other ),
			'another payment method, expanded' => array( FraudDecision::Allow, self::SETUP_INTENT_ID, $this->create_setup_intent( 'succeeded', (object) array( 'id' => 'pm_other' ) ) ),
			'another payment method, BLOCK'    => array( FraudDecision::Block, self::SETUP_INTENT_ID, $other ),
			'no payment method'                => array( FraudDecision::Allow, self::SETUP_INTENT_ID, $this->create_setup_intent( 'requires_payment_method', null ) ),
			'Stripe error'                     => array( FraudDecision::Allow, self::SETUP_INTENT_ID, (object) array( 'error' => (object) array( 'message' => 'Rate limited' ) ) ),
			'request failure'                  => array( FraudDecision::Allow, self::SETUP_INTENT_ID, new \WP_Error( 'stripe_error', 'Connection failed' ) ),
			'no response'                      => array( FraudDecision::Allow, self::SETUP_INTENT_ID, null ),
			'no setup intent posted'           => array( FraudDecision::Allow, '', $other ),
		);
	}

	/**
	 * @testdox The form post verifies normally, and the failure is logged locally, when reading the setup intent throws.
	 */
	public function test_not_reused_when_reading_setup_intent_throws(): void {
		$this->spy_on_controller_logging();
		\WC_Stripe_API::set_mock_retrieve_response( self::SETUP_INTENT_API, new \RuntimeException( 'Stripe unavailable' ) );
		$this->verify_setup_intent( FraudDecision::Allow );

		$this->assertFalse( $this->supply_for_add_payment_method() );
		$this->assertLogged(
			'warning',
			'Reading the Stripe setup intent failed; add-payment-method request will verify',
			array(
				'event_source'    => 'add_payment_method',
				'exception_class' => \RuntimeException::class,
			),
			false
		);
	}

	/**
	 * @testdox The decision reaches the form post of another Stripe gateway, but not other sources or gateways outside Stripe.
	 */
	public function test_decision_reaches_only_stripe_add_payment_method_form_posts(): void {
		$this->verify_setup_intent( FraudDecision::Allow );

		$this->assertFalse( $this->supply_for_add_payment_method( 'stripe', self::SETUP_INTENT_ID, 'shortcode_checkout' ) );
		$this->assertFalse( $this->supply_for_add_payment_method( 'woocommerce_payments' ) );
		$this->assertSame( array(), \WC_Stripe_API::get_retrieved(), 'The setup intent is read only for add-payment-method form posts' );
		$this->assertInstanceOf( SuppliedDecision::class, $this->supply_for_add_payment_method( 'stripe_sepa_debit' ), 'Non-matching requests must not consume the decision' );
	}

	/**
	 * @testdox A failure to keep the decision still enforces the block and is logged.
	 */
	public function test_failure_to_keep_the_decision_still_blocks(): void {
		$this->spy_on_controller_logging();
		$this->set_setup_intent_post( array( SessionVerifier::SESSION_ID_FIELD => 'sess-stripe' ) );
		$this->session_verifier->method( 'verify_session' )->willReturn( FraudDecision::Block );
		$this->session_verifier->method( 'last_verified_session_id' )->willReturn( 'sess-stripe' );

		$session = $this->createMock( \WC_Session::class );
		$session->method( 'get' )->willThrowException( new \RuntimeException( 'session unavailable' ) );
		WC()->session = $session;

		$outcome = $this->run_setup_intent_filter();

		$this->assertTrue( $outcome['blocked'], 'The block must still be enforced when keeping the decision fails' );
		$this->assertLogged(
			'warning',
			'Keeping a request decision failed',
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
