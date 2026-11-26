<?php
/**
 * WooPaymentsSetupIntentCompatTest class file.
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
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Compat\WooPaymentsSetupIntentCompat;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Sessions\SessionIdError;
use Automattic\WooCommerce\Proxies\LegacyProxy;
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

	private const REUSABLE_DECISIONS_KEY = '_wc_fraud_protection_reusable_decisions';

	private const SETUP_INTENT_ID = 'seti_123';

	/**
	 * The System Under Test.
	 *
	 * @var WooPaymentsSetupIntentCompat
	 */
	private WooPaymentsSetupIntentCompat $sut;

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
		$this->remove_shared_hook( self::SETUP_INTENT_REQUEST_FILTER, array( wc_get_container()->get( WooPaymentsSetupIntentCompat::class ), 'verify_and_block_setup_intent' ), 1 );
		$this->remove_shared_hook( self::SKIP_VERIFY_FILTER, array( wc_get_container()->get( GatewayRequestVerifier::class ), 'supply_reused_decision' ), 3 );

		$this->session_verifier        = $this->createMock( SessionVerifier::class );
		$this->blocked_session_message = $this->createMock( BlockedSessionMessage::class );
		$this->blocked_session_message
			->method( 'get_plaintext' )
			->willReturn( 'We are unable to process this request online.' );

		$this->gateway_request_verifier = new GatewayRequestVerifier();
		$this->gateway_request_verifier->init( $this->session_verifier, new SessionIdNormalizer(), wc_get_container()->get( LegacyProxy::class ), new GatewayNativeSupport() );
		$this->gateway_request_verifier->register();

		$this->sut = new WooPaymentsSetupIntentCompat();
		$this->sut->init( $this->gateway_request_verifier, $this->blocked_session_message, new GatewayNativeSupport() );
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
		Get_Setup_Intention::reset();

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
	 * @param bool    $in_ajax_request Whether the filter runs within the WooPayments setup intent AJAX action.
	 * @param ?object $request         The filtered WooPayments request; an object without parameters when null.
	 * @return array{blocked: bool, output: string, request_unchanged: bool}
	 */
	private function run_setup_intent_filter( bool $in_ajax_request = true, ?object $request = null ): array {
		add_filter( 'wp_doing_ajax', '__return_true' );
		$die_handler = function () {
			return function () {
				throw new \WPDieException();
			};
		};
		add_filter( 'wp_die_ajax_handler', $die_handler );

		$request      = $request ?? new \stdClass();
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
	 * Run the skip-verify filter as a later form post does.
	 *
	 * @param string $payment_method  Submitted gateway ID.
	 * @param string $setup_intent_id Submitted WooPayments setup intent ID; empty to submit none.
	 * @param string $source          Verification source of the form post.
	 * @return SuppliedDecision|false
	 */
	private function supply_for_add_payment_method( string $payment_method = 'woocommerce_payments', string $setup_intent_id = self::SETUP_INTENT_ID, string $source = 'add_payment_method' ) {
		$payment_data = '' === $setup_intent_id ? array() : array( 'wcpay-setup-intent' => $setup_intent_id );

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
		$this->set_setup_intent_post( array( SessionVerifier::SESSION_ID_FIELD => 'sess-wcpay' ) );
		$this->session_verifier->method( 'verify_session' )->willReturn( $decision );
		$this->session_verifier->method( 'last_verified_session_id' )->willReturn( 'sess-wcpay' );
		$this->run_setup_intent_filter();
	}

	/**
	 * @testdox register() hooks setup intent verification and the add-payment-method script.
	 */
	public function test_register_hooks(): void {
		$this->assertSame( 10, has_filter( self::SETUP_INTENT_REQUEST_FILTER, array( $this->sut, 'verify_and_block_setup_intent' ) ) );
		$this->assertSame( 20, has_action( 'woocommerce_add_payment_method_form_bottom', array( $this->sut, 'enqueue_add_payment_method_script' ) ) );
	}

	/**
	 * @testdox The setup intent request is not verified and the script is not enqueued when WooPayments declares native add-payment-method support.
	 */
	public function test_stands_aside_when_gateway_declares_native_add_payment_method(): void {
		$this->register_payment_gateways( new FakeNativeGateway( 'woocommerce_payments', array( FraudProtectionFeature::ADD_PAYMENT_METHOD ) ) );
		$this->set_setup_intent_post( array( SessionVerifier::SESSION_ID_FIELD => 'sess-wcpay' ) );
		$this->session_verifier->expects( $this->never() )->method( 'verify_session' );
		wp_enqueue_script( 'wc-fraud-protection-add-payment-method', 'https://example.com/add-payment-method.js', array(), '1', true );
		$this->set_available_gateway_ids( array( 'woocommerce_payments' ) );

		$outcome = $this->run_setup_intent_filter();
		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Test invokes the hook.
		do_action( 'woocommerce_add_payment_method_form_bottom' );

		$this->assertFalse( $outcome['blocked'] );
		$this->assertTrue( $outcome['request_unchanged'] );
		$this->assertFalse( wp_script_is( 'wc-fraud-protection-woopayments-add-payment-method', 'enqueued' ) );
	}

	/**
	 * @testdox A form post verifies normally once WooPayments declares native add-payment-method support.
	 */
	public function test_form_post_verifies_normally_once_gateway_declares_native_add_payment_method(): void {
		$this->verify_setup_intent( FraudDecision::Allow );
		$this->register_payment_gateways( new FakeNativeGateway( 'woocommerce_payments', array( FraudProtectionFeature::ADD_PAYMENT_METHOD ) ) );

		$this->assertFalse( $this->supply_for_add_payment_method() );
		$this->assertSame( array(), Get_Setup_Intention::get_sent(), 'The setup intent is not read' );
	}

	/**
	 * @testdox Declaring only native checkout support keeps setup intent verification on.
	 */
	public function test_checkout_declaration_keeps_setup_intent_verification(): void {
		$this->register_payment_gateways( new FakeNativeGateway( 'woocommerce_payments', array( FraudProtectionFeature::CHECKOUT ) ) );
		$this->set_setup_intent_post();
		$this->session_verifier->expects( $this->once() )->method( 'verify_session' )->willReturn( FraudDecision::Allow );

		$this->run_setup_intent_filter();
	}

	/**
	 * @testdox The verification source fits the 32 characters that SessionVerifier keeps, so it reaches filters, logs, and stored rows unchanged.
	 */
	public function test_source_fits_session_verifier_limit(): void {
		$this->assertLessThanOrEqual( 32, mb_strlen( WooPaymentsSetupIntentCompat::SOURCE ) );
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
				'wcpay_setup_intent_creation',
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
	 * @testdox The payment method that WooPayments confirms is verified and kept as the reference, not the posted wcpay-payment-method.
	 * @dataProvider confirmed_payment_method_sources
	 *
	 * @param object $request  The filtered WooPayments request.
	 * @param string $expected The payment method ID that must be verified.
	 */
	public function test_verifies_and_keeps_confirmed_payment_method( object $request, string $expected ): void {
		$this->set_setup_intent_post(
			array(
				SessionVerifier::SESSION_ID_FIELD => 'sess-wcpay',
				'wcpay-confirmation-token'        => 'pm_token',
			)
		);
		$this->session_verifier->method( 'last_verified_session_id' )->willReturn( 'sess-wcpay' );
		$this->session_verifier
			->expects( $this->once() )
			->method( 'verify_session' )
			->with(
				'sess-wcpay',
				WooPaymentsSetupIntentCompat::SOURCE,
				0,
				$this->callback(
					function ( array $request_data ) use ( $expected ): bool {
						return ( $request_data['payment_data']['wcpay-payment-method'] ?? null ) === $expected;
					}
				)
			)
			->willReturn( FraudDecision::Allow );

		$outcome = $this->run_setup_intent_filter( true, $request );

		$this->assertTrue( $outcome['request_unchanged'], 'The WooPayments request must be returned unchanged' );

		Get_Setup_Intention::set_mock_setup_intent( self::SETUP_INTENT_ID, $expected );
		$supplied = $this->supply_for_add_payment_method();
		$this->assertInstanceOf( SuppliedDecision::class, $supplied, 'The decision must be reused for a setup intent that holds the verified payment method' );
		$this->assertSame( FraudDecision::Allow, $supplied->decision );
	}

	/**
	 * Filtered WooPayments requests, and the payment method ID that must be verified for each.
	 *
	 * The POST data has wcpay-payment-method pm_123 and wcpay-confirmation-token pm_token.
	 *
	 * @return array<string, array{object, string}>
	 */
	public function confirmed_payment_method_sources(): array {
		return array(
			'request parameter'        => array( $this->create_woopayments_request( 'pm_confirmed' ), 'pm_confirmed' ),
			'confirmation token field' => array( new \stdClass(), 'pm_token' ),
			'unreadable request'       => array( $this->create_woopayments_request( new \RuntimeException( 'Uninitialized parameter' ) ), 'pm_token' ),
			'empty request parameter'  => array( $this->create_woopayments_request( '' ), 'pm_token' ),
		);
	}

	/**
	 * Create a WooPayments request whose payment_method parameter has the given value.
	 *
	 * @param string|\Throwable $payment_method The parameter value, or the exception that reading it throws.
	 * @return object The request.
	 */
	private function create_woopayments_request( $payment_method ): object {
		return new class( $payment_method ) {
			/**
			 * The parameter value, or the exception that reading it throws.
			 *
			 * @var string|\Throwable
			 */
			private $payment_method;

			/**
			 * Constructor.
			 *
			 * @param string|\Throwable $payment_method The parameter value, or the exception that reading it throws.
			 */
			public function __construct( $payment_method ) {
				$this->payment_method = $payment_method;
			}

			/**
			 * Get a request parameter, as WooPayments requests do.
			 *
			 * @param string $key Parameter name.
			 * @return mixed The value.
			 * @throws \Throwable When the parameter cannot be read.
			 */
			public function get_param( $key ) {
				if ( 'payment_method' !== $key ) {
					return null;
				}
				if ( $this->payment_method instanceof \Throwable ) {
					throw $this->payment_method;
				}
				return $this->payment_method;
			}
		};
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
		$this->assertSame( 'sess-wcpay', $supplied->session_id_for_order );
		$this->assertSame( array( self::SETUP_INTENT_ID ), Get_Setup_Intention::get_sent() );
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
	 * @testdox The form post verifies normally, and the decision stays for the form post it belongs to, when the setup intent does not show the verified payment method.
	 * @dataProvider setup_intents_without_the_verified_payment_method
	 *
	 * @param FraudDecision $decision        The setup intent decision.
	 * @param string        $setup_intent_id Posted setup intent ID; empty to post none.
	 * @param mixed         $setup_intent    WooPayments response for the setup intent; a string or null is the payment method ID it holds.
	 */
	public function test_not_reused_without_the_verified_payment_method( FraudDecision $decision, string $setup_intent_id, $setup_intent ): void {
		if ( null === $setup_intent || is_string( $setup_intent ) ) {
			Get_Setup_Intention::set_mock_setup_intent( self::SETUP_INTENT_ID, $setup_intent );
		} else {
			Get_Setup_Intention::set_mock_response( self::SETUP_INTENT_ID, $setup_intent );
		}
		$this->verify_setup_intent( $decision );

		$this->assertFalse( $this->supply_for_add_payment_method( 'woocommerce_payments', $setup_intent_id ) );

		Get_Setup_Intention::set_mock_setup_intent( self::SETUP_INTENT_ID, 'pm_123' );
		$this->assertInstanceOf( SuppliedDecision::class, $this->supply_for_add_payment_method() );
	}

	/**
	 * Setup intents, or responses without one, that do not show the verified payment method.
	 *
	 * @return array<string, array{FraudDecision, string, mixed}>
	 */
	public function setup_intents_without_the_verified_payment_method(): array {
		return array(
			'another payment method'        => array( FraudDecision::Allow, self::SETUP_INTENT_ID, 'pm_other' ),
			'another payment method, BLOCK' => array( FraudDecision::Block, self::SETUP_INTENT_ID, 'pm_other' ),
			'no payment method'             => array( FraudDecision::Allow, self::SETUP_INTENT_ID, null ),
			'unexpected response'           => array( FraudDecision::Allow, self::SETUP_INTENT_ID, array( 'payment_method' => 'pm_123' ) ),
			'no setup intent posted'        => array( FraudDecision::Allow, '', 'pm_123' ),
		);
	}

	/**
	 * @testdox The form post verifies normally, and the failure is logged locally, when reading the setup intent throws.
	 */
	public function test_not_reused_when_reading_setup_intent_throws(): void {
		Get_Setup_Intention::set_mock_response( self::SETUP_INTENT_ID, new \RuntimeException( 'WooPayments unavailable' ) );
		$this->verify_setup_intent( FraudDecision::Allow );

		$this->assertFalse( $this->supply_for_add_payment_method() );
		$this->assertLogged(
			'warning',
			'Reading the WooPayments setup intent failed; add-payment-method request will verify',
			array(
				'event_source'    => 'add_payment_method',
				'exception_class' => \RuntimeException::class,
			),
			false
		);
	}

	/**
	 * @testdox The decision reaches only WooPayments add-payment-method form posts.
	 */
	public function test_decision_reaches_only_woopayments_add_payment_method_form_posts(): void {
		$this->verify_setup_intent( FraudDecision::Allow );

		$this->assertFalse( $this->supply_for_add_payment_method( 'woocommerce_payments', self::SETUP_INTENT_ID, 'shortcode_checkout' ) );
		$this->assertFalse( $this->supply_for_add_payment_method( 'stripe' ) );
		$this->assertFalse( $this->supply_for_add_payment_method( 'woocommerce_payments_sepa_debit' ) );
		$this->assertSame( array(), Get_Setup_Intention::get_sent(), 'The setup intent is read only for WooPayments add-payment-method form posts' );
		$this->assertInstanceOf( SuppliedDecision::class, $this->supply_for_add_payment_method(), 'Non-matching requests must not consume the decision' );
	}

	/**
	 * @testdox A failure to keep the decision still enforces the block and is logged.
	 */
	public function test_failure_to_keep_the_decision_still_blocks(): void {
		$this->set_setup_intent_post( array( SessionVerifier::SESSION_ID_FIELD => 'sess-wcpay' ) );
		$this->session_verifier->method( 'verify_session' )->willReturn( FraudDecision::Block );
		$this->session_verifier->method( 'last_verified_session_id' )->willReturn( 'sess-wcpay' );

		$session = $this->createMock( \WC_Session::class );
		$session->method( 'get' )->willThrowException( new \RuntimeException( 'session unavailable' ) );
		WC()->session = $session;

		$outcome = $this->run_setup_intent_filter();

		$this->assertTrue( $outcome['blocked'], 'The block must still be enforced when keeping the decision fails' );
		$this->assertLogged(
			'warning',
			'Keeping a request decision failed',
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
