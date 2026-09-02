<?php
/**
 * PayPalCompatTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin\Compat;

require_once dirname( __DIR__, 4 ) . '/Support/PayPalPPCPStubs.php';

use Automattic\WooCommerce\FraudProtection\Schemas\FraudDecision;
use Automattic\WooCommerce\FraudProtection\BlockedSessionMessage;
use Automattic\WooCommerce\FraudProtection\MessageContext;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Compat\PayPalCompat;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Compat\PayPalDecisionReuse;
use Automattic\WooCommerce\FraudProtection\SessionVerifier;
use Automattic\WooCommerce\FraudProtection\SessionIdNormalizer;
use Automattic\WooCommerce\FraudProtection\SuppliedDecision;
use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;
use Automattic\WooCommerce\FraudProtection\Tests\Support\FakePayPalOrder;
use Automattic\WooCommerce\FraudProtection\Tests\Support\PayPalContainerStub;
use Automattic\WooCommerce\FraudProtection\Tests\Support\PayPalJsonResponseCapture;
use Automattic\WooCommerce\FraudProtection\Tests\Support\PayPalPPCPStub;
use Automattic\WooCommerce\FraudProtection\Tests\Support\PayPalRequestDataStub;
use Automattic\WooCommerce\FraudProtection\Tests\Support\PayPalSubscriptionsStub;
use Automattic\WooCommerce\FraudProtection\Tests\Support\ThrowingPayPalOrder;

/**
 * Tests for the PayPalCompat class.
 *
 * @covers \Automattic\WooCommerce\Internal\FraudProtectionPlugin\Compat\PayPalCompat
 */
class PayPalCompatTest extends FraudProtectionUnitTestCase {

	/**
	 * The System Under Test.
	 *
	 * @var PayPalCompat
	 */
	private PayPalCompat $sut;

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
	 * Session ID normalizer.
	 *
	 * @var SessionIdNormalizer
	 */
	private $session_id_normalizer;

	/** @var PayPalDecisionReuse */
	private PayPalDecisionReuse $decision_reuse;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		if ( 'test_missing_paypal_classes_verify_without_session_id' !== $this->getName() && ! class_exists( '\WooCommerce\PayPalCommerce\PPCP', false ) ) {
			class_alias( PayPalPPCPStub::class, 'WooCommerce\PayPalCommerce\PPCP' );
		}
		if ( ! class_exists( 'WC_Subscriptions' ) ) {
			class_alias( PayPalSubscriptionsStub::class, 'WC_Subscriptions' );
		}
		PayPalRequestDataStub::$data  = array();
		PayPalRequestDataStub::$error = null;
		PayPalContainerStub::reset();
		PayPalPPCPStub::set_error( null );
		PayPalJsonResponseCapture::reset();
		$this->session_verifier        = $this->createMock( SessionVerifier::class );
		$this->blocked_session_message = $this->createMock( BlockedSessionMessage::class );
		$this->session_id_normalizer    = new SessionIdNormalizer();
		$this->decision_reuse           = new PayPalDecisionReuse();
		$this->decision_reuse->init( $this->session_id_normalizer );
		$this->blocked_session_message
			->method( 'get_plaintext' )
			->willReturn( 'We are unable to process this request online. Please contact support (test@example.com) to complete your purchase.' );

		$this->sut = new PayPalCompat();
		$this->sut->init(
			$this->session_verifier,
			$this->blocked_session_message,
			$this->session_id_normalizer,
			$this->decision_reuse
		);
	}

	/**
	 * Tear down after each test.
	 */
	public function tearDown(): void {
		PayPalContainerStub::reset();
		PayPalPPCPStub::set_error( null );
		PayPalJsonResponseCapture::reset();
		$this->reset_fraud_protection_scripts();

		$session = $this->get_original_woocommerce_session();
		if ( $session ) {
			$session->set( 'ppcp', null );
			$session->set( '_fraud_protection_paypal_verification', null );
			$session->set( '_fraud_protection_paypal_verified_session_id', null );
		}

		parent::tearDown();
	}

	/*
	|--------------------------------------------------------------------------
	| register() Tests
	|--------------------------------------------------------------------------
	*/

	/**
	 * @testdox register() hooks verification and the exact PayPal render signals.
	 */
	public function test_register_hooks(): void {
		$this->sut->register();

		$this->assertNotFalse(
			has_action( 'woocommerce_paypal_payments_create_order_request_started', array( $this->sut, 'verify_and_block_create_order' ) ),
			'create_order_request_started action should be registered'
		);

		$this->assertSame( 10, has_filter( 'ppcp_request_args', array( $this->sut, 'verify_protected_paypal_request' ) ) );
		$this->assertNotFalse(
			has_action( 'woocommerce_paypal_payments_paypal_order_created', array( $this->sut, 'associate_created_order_with_verification' ) ),
			'paypal_order_created action should be registered'
		);
	}

	/*
	|--------------------------------------------------------------------------
	| verify_and_block_create_order() Tests
	|--------------------------------------------------------------------------
	*/

	/**
	 * @testdox A complete create-order request is verified unchanged and stored on Allow.
	 */
	public function test_complete_create_order_request_allows_and_stores_record(): void {
		$data = array(
			SessionVerifier::SESSION_ID_FIELD => 'test-session-abc',
			'payment_method'                  => 'ppcp-credit-card-gateway',
			'payment_data'                    => array( 'card_number' => 'tokenized-value' ),
		);

		$this->session_verifier
			->expects( $this->once() )
			->method( 'verify_session' )
			->with( 'test-session-abc', 'paypal_express_order_creation', 0, $data )
			->willReturn( FraudDecision::Allow );

		$this->session_verifier->method( 'last_verified_session_id' )->willReturn( 'test-session-abc' );

		$this->sut->verify_and_block_create_order( $data );

		$this->assertSame(
			array(
				'origin'     => 'paypal_express_order_creation',
				'session_id' => 'test-session-abc',
				'decision'   => FraudDecision::Allow,
				'used'       => false,
				'order_id'   => '',
				'cart_hash'  => '',
			),
			WC()->session->get( '_fraud_protection_paypal_verification' )
		);
	}

	/** @testdox A verified request associates only the first created PayPal order. */
	public function test_association_covers_only_the_one_order_a_request_creates(): void {
		$this->session_verifier->method( 'verify_session' )->willReturn( FraudDecision::Allow );
		$this->session_verifier->method( 'last_verified_session_id' )->willReturn( 'scored-session' );

		$this->sut->verify_and_block_create_order( array( SessionVerifier::SESSION_ID_FIELD => 'scored-session' ) );
		$this->sut->associate_created_order_with_verification( new FakePayPalOrder( 'PP-1' ) );
		$this->sut->associate_created_order_with_verification( new FakePayPalOrder( 'PP-2' ) );

		$record = WC()->session->get( '_fraud_protection_paypal_verification' );
		$this->assertIsArray( $record );
		$this->assertSame( 'PP-1', $record['order_id'] );
	}

	/**
	 * @testdox verify_and_block_create_order() removes a prior record when no response-backed ID exists.
	 */
	public function test_verify_removes_prior_record_without_response_session_id(): void {
		$data = array( 'context' => 'product' );
		WC()->session->set(
			'_fraud_protection_paypal_verification',
			array(
				'session_id'  => 'prior-session',
				'stand_downs' => 0,
				'decision'    => FraudDecision::Allow,
			)
		);

		$this->session_verifier
			->method( 'verify_session' )
			->willReturn( FraudDecision::Allow );

		$this->sut->verify_and_block_create_order( $data );

		$this->assertNull( WC()->session->get( '_fraud_protection_paypal_verification' ) );
	}

	/**
	 * @testdox verify_and_block_create_order() passes the submitted value to SessionVerifier
	 *
	 * @dataProvider submitted_session_value_provider
	 *
	 * @param mixed $value Submitted value.
	 */
	public function test_verify_passes_submitted_value_to_session_verifier( $value, FraudDecision $decision ): void {
		$data = array(
			SessionVerifier::SESSION_ID_FIELD => $value,
			'context'                         => array( 'source' => 'product' ),
		);

		$this->session_verifier
			->expects( $this->once() )
			->method( 'verify_session' )
			->with( $value, 'paypal_express_order_creation', 0, $this->anything() )
			->willReturn( $decision );
		$this->session_verifier->method( 'last_verified_session_id' )->willReturn( 'response-id' );

		$this->sut->verify_and_block_create_order( $data );
		$this->sut->associate_created_order_with_verification( new FakePayPalOrder( 'PP-123' ) );

		$this->assertNull( WC()->session->get( '_fraud_protection_paypal_verification' ) );
	}

	/**
	 * Submitted session values.
	 *
	 * @return array<string, array{mixed, FraudDecision}>
	 */
	public function submitted_session_value_provider(): array {
		return array(
			'empty string'          => array( '', FraudDecision::Allow ),
			'invalid characters'    => array( '.', FraudDecision::Allow ),
			'null'                  => array( null, FraudDecision::Allow ),
			'array'                 => array( array( 'private' ), FraudDecision::Allow ),
			'non-actionable result' => array( 'browser-session', FraudDecision::Challenge ),
		);
	}

	/**
	 * @testdox verify_and_block_create_order() sends JSON error with 403 on BLOCK decision.
	 */
	public function test_verify_blocks_on_block_decision(): void {
		$data = array( SessionVerifier::SESSION_ID_FIELD => 'test-session-blocked' );

		$this->session_verifier
			->expects( $this->once() )
			->method( 'verify_session' )
			->with( 'test-session-blocked', 'paypal_express_order_creation', 0, $data )
			->willReturn( FraudDecision::Block );

		$this->blocked_session_message
			->expects( $this->once() )
			->method( 'get_plaintext' )
			->with( MessageContext::Purchase );

		// wp_send_json_error() echoes JSON then calls wp_die(). Force AJAX
		// context (otherwise it calls bare die()) and override the die
		// handler to throw a catchable exception.
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter(
			'wp_die_ajax_handler',
			function () {
				return function () {
					throw new \WPDieException();
				};
			}
		);

		$this->expectException( \WPDieException::class );
		$this->expectOutputRegex( '/"success":false.*unable to process this request/' );

		$this->sut->verify_and_block_create_order( $data );
	}

	/**
	 * @testdox verify_and_block_create_order() still enforces the block when recording the verification throws.
	 *
	 * The record is best-effort, but it is written before the block check. A
	 * throw from the session read/write must not escape past that check: the
	 * block stays enforced.
	 */
	public function test_verify_enforces_the_block_when_recording_throws(): void {
		$data = array( SessionVerifier::SESSION_ID_FIELD => 'blocked-session' );

		$this->session_verifier->method( 'verify_session' )->willReturn( FraudDecision::Block );
		$this->session_verifier->method( 'last_verified_session_id' )->willReturn( 'blocked-session' );

		$session          = $this->createMock( \WC_Session::class );
		$session->expects( $this->exactly( 2 ) )->method( 'set' )->willThrowException( new \RuntimeException( 'session unavailable' ) );
		WC()->session = $session;

		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter(
			'wp_die_ajax_handler',
			function () {
				return function () {
					throw new \WPDieException();
				};
			}
		);

		$blocked = false;
		ob_start();
		try {
			$this->sut->verify_and_block_create_order( $data );
		} catch ( \WPDieException $e ) {
			$blocked = true;
		} finally {
			ob_end_clean();
		}

		$this->assertTrue( $blocked, 'The block must still be enforced when recording the verification throws.' );
		$this->assertLogged(
			'warning',
			'Recording the PayPal request verification failed',
			array(
				'event_source'      => 'paypal_express_order_creation',
				'session_id'        => 'blocked-session',
				'exception_class'   => 'RuntimeException',
				'exception_message' => 'session unavailable',
			)
		);
	}

	/** @testdox A failed current record write retires an older record and leaves no order association marker. */
	public function test_failed_record_write_leaves_no_order_association_marker(): void {
		$unassociated_record = array(
			'origin'     => 'paypal_express_order_creation',
			'session_id' => 'response-session',
			'decision'   => FraudDecision::Allow,
			'used'       => false,
			'order_id'   => '',
			'cart_hash'  => '',
		);
		$stored_record       = $unassociated_record;
		$session             = $this->createMock( \WC_Session::class );
		$write_count         = 0;
		$session->expects( $this->exactly( 2 ) )->method( 'set' )->willReturnCallback(
			function ( string $key, $value ) use ( $unassociated_record, &$stored_record, &$write_count ): void {
				$this->assertSame( '_fraud_protection_paypal_verification', $key );
				++$write_count;
				if ( 1 === $write_count ) {
					$this->assertSame( $unassociated_record, $value );
					throw new \RuntimeException( 'session write unavailable' );
				}

				$this->assertNull( $value );
				$stored_record = $value;
			}
		);
		$session->method( 'get' )->willReturnCallback(
			static fn( string $key, $default = null ) => '_fraud_protection_paypal_verification' === $key ? $stored_record : $default
		);
		WC()->session = $session;
		$this->session_verifier->method( 'verify_session' )->willReturn( FraudDecision::Allow );
		$this->session_verifier->method( 'last_verified_session_id' )->willReturn( 'response-session' );

		$this->sut->verify_and_block_create_order( array( SessionVerifier::SESSION_ID_FIELD => 'browser-session' ) );
		$this->assertNull( $stored_record );
		$stored_record = $unassociated_record;
		$this->sut->associate_created_order_with_verification( new FakePayPalOrder( 'PP-NEW' ) );

		$this->assertIsArray( $stored_record );
		$this->assertSame( '', $stored_record['order_id'] );
		$this->assertLogged(
			'warning',
			'Recording the PayPal request verification failed',
			array(
				'event_source'      => 'paypal_express_order_creation',
				'session_id'        => 'response-session',
				'exception_class'   => 'RuntimeException',
				'exception_message' => 'session write unavailable',
			)
		);
	}

	/** @testdox A record update failure before storage retires an older reusable record. */
	public function test_record_update_failure_retires_an_older_reusable_record(): void {
		WC()->session->set(
			'_fraud_protection_paypal_verification',
			array(
				'origin'     => 'paypal_setup_token_creation',
				'session_id' => 'old-session',
				'decision'   => FraudDecision::Allow,
				'used'       => false,
				'order_id'   => '',
				'cart_hash'  => 'old-cart',
			)
		);
		$cart = $this->getMockBuilder( \WC_Cart::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_empty' ) )
			->getMock();
		$cart->method( 'is_empty' )->willThrowException( new \RuntimeException( 'cart unavailable' ) );
		WC()->cart = $cart;

		$this->configure_paypal_request_data( array( SessionVerifier::SESSION_ID_FIELD => 'browser-session' ) );
		$this->session_verifier->method( 'verify_session' )->willReturn( FraudDecision::Allow );
		$this->session_verifier->method( 'last_verified_session_id' )->willReturn( 'new-session' );

		$this->run_setup_token_request();
		$this->assertNull( WC()->session->get( '_fraud_protection_paypal_verification' ) );
		$this->assertLogged(
			'warning',
			'Recording the PayPal request verification failed',
			array(
				'event_source'      => 'paypal_setup_token_creation',
				'session_id'        => 'new-session',
				'exception_class'   => 'RuntimeException',
				'exception_message' => 'cart unavailable',
			)
		);
	}

	/**
	 * @testdox verify_and_block_create_order() calls verify with empty session_id when field is missing.
	 */
	public function test_verify_with_missing_session_id(): void {
		$data = array( 'context' => 'product' );

		$this->session_verifier
			->expects( $this->once() )
			->method( 'verify_session' )
			->with( '', 'paypal_express_order_creation', 0, $data )
			->willReturn( FraudDecision::Allow );

		$this->sut->verify_and_block_create_order( $data );
	}

	/**
	 * @testdox Only the exact protected action, method, and PayPal path verify.
	 *
	 * @dataProvider protected_request_gate_provider
	 */
	public function test_protected_request_gates( string $action, string $method, string $path, bool $expected ): void {
		$this->configure_paypal_request_data( array( SessionVerifier::SESSION_ID_FIELD => 'browser-session' ) );
		$this->session_verifier
			->expects( $expected ? $this->once() : $this->never() )
			->method( 'verify_session' )
			->willReturn( FraudDecision::Allow );
		$this->session_verifier->method( 'last_verified_session_id' )->willReturn( 'response-session' );

		$result = $this->run_protected_request( $action, array( 'method' => $method, 'body' => '{}' ), $path );

		$this->assertSame( array( 'method' => $method, 'body' => '{}' ), $result );
	}

	/** @return array<string, array{string, string, string, bool}> */
	public function protected_request_gate_provider(): array {
		return array(
			'setup'        => array( 'wc_ajax_ppc-create-setup-token', 'POST', '/v3/vault/setup-tokens', true ),
			'vault'        => array( 'wc_ajax_ppc-vault-create-order', 'POST', '/v2/checkout/orders', true ),
			'wrong action' => array( 'wc_ajax_ppc-create-order', 'POST', '/v3/vault/setup-tokens', false ),
			'wrong method' => array( 'wc_ajax_ppc-create-setup-token', 'GET', '/v3/vault/setup-tokens', false ),
			'wrong path'   => array( 'wc_ajax_ppc-vault-create-order', 'POST', '/v2/checkout/orders/PP-1', false ),
		);
	}

	/**
	 * @testdox Successful protected requests use validated data and store the reusable record.
	 *
	 * @dataProvider protected_request_provider
	 */
	public function test_protected_request_uses_validated_data( string $action, string $path, string $source, string $nonce ): void {
		if ( PayPalDecisionReuse::SETUP_TOKEN_CREATION_SOURCE === $source ) {
			$this->set_eligible_setup_cart();
		}
		$this->configure_paypal_request_data( array( SessionVerifier::SESSION_ID_FIELD => 'browser-session' ) );
		$this->session_verifier
			->expects( $this->once() )
			->method( 'verify_session' )
			->with(
				'browser-session',
				$source,
				0,
				array(
					SessionVerifier::SESSION_ID_FIELD => 'browser-session',
					'validated_nonce'                 => $nonce,
				)
			)
			->willReturn( FraudDecision::Allow );
		$this->session_verifier->method( 'last_verified_session_id' )->willReturn( 'response-session' );

		$this->run_protected_request( $action, array( 'method' => 'POST' ), $path );
		if ( PayPalDecisionReuse::VAULT_ORDER_CREATION_SOURCE === $source ) {
			$this->sut->associate_created_order_with_verification( new FakePayPalOrder( 'PP-123' ) );
		}

		$record = WC()->session->get( '_fraud_protection_paypal_verification' );
		$this->assertIsArray( $record );
		$this->assertSame( $source, $record['origin'] );
		$this->assertSame( 'response-session', $record['session_id'] );
		$this->assertSame( PayPalDecisionReuse::SETUP_TOKEN_CREATION_SOURCE === $source ? 'cart-hash' : '', $record['cart_hash'] );
		$this->assertSame( PayPalDecisionReuse::VAULT_ORDER_CREATION_SOURCE === $source ? 'PP-123' : '', $record['order_id'] );
	}

	/** @return array<string, array{string, string, string, string}> */
	public function protected_request_provider(): array {
		return array(
			'setup' => array( 'wc_ajax_ppc-create-setup-token', '/v3/vault/setup-tokens', 'paypal_setup_token_creation', 'ppc-create-setup-token' ),
			'vault' => array( 'wc_ajax_ppc-vault-create-order', '/v2/checkout/orders', 'paypal_vault_order_creation', 'ppc-vault-create-order' ),
		);
	}

	/**
	 * @testdox An unusable submitted session ID verifies but creates no reusable record.
	 *
	 * @dataProvider unusable_session_id_provider
	 */
	public function test_unusable_validated_session_id_is_not_recorded( array $data, $session_id ): void {
		$validated = $data + array( 'validated_nonce' => 'ppc-create-setup-token' );
		$this->configure_paypal_request_data( $data );
		$this->session_verifier
			->expects( $this->once() )
			->method( 'verify_session' )
			->with( $session_id, 'paypal_setup_token_creation', 0, $validated )
			->willReturn( FraudDecision::Allow );
		$this->session_verifier->method( 'last_verified_session_id' )->willReturn( 'response-session' );

		$this->run_setup_token_request();

		$this->assertNull( WC()->session->get( '_fraud_protection_paypal_verification' ) );
	}

	/** @return array<string, array{array, mixed}> */
	public function unusable_session_id_provider(): array {
		return array(
			'missing'   => array( array(), '' ),
			'empty'     => array( array( SessionVerifier::SESSION_ID_FIELD => '' ), '' ),
			'malformed' => array( array( SessionVerifier::SESSION_ID_FIELD => array( 'invalid' ) ), array( 'invalid' ) ),
		);
	}

	/**
	 * @testdox Protected routes return the plaintext purchase message on Block.
	 *
	 * @dataProvider protected_request_provider
	 */
	public function test_protected_request_blocks_before_transport( string $action, string $path, string $source, string $nonce ): void {
		unset( $source, $nonce );
		$this->configure_paypal_request_data( array( SessionVerifier::SESSION_ID_FIELD => 'browser-session' ) );
		$this->session_verifier->method( 'verify_session' )->willReturn( FraudDecision::Block );
		$this->session_verifier->method( 'last_verified_session_id' )->willReturn( 'response-session' );
		PayPalJsonResponseCapture::$enabled = true;

		try {
			$this->run_protected_request( $action, array( 'method' => 'POST' ), $path );
			$this->fail( 'Expected the Block response to terminate the request.' );
		} catch ( \WPDieException $e ) {
			unset( $e );
		}

		$this->assertSame( 403, PayPalJsonResponseCapture::$status_code );
		$this->assertIsArray( PayPalJsonResponseCapture::$data );
		$this->assertStringContainsString( 'unable to process this request', PayPalJsonResponseCapture::$data['message'] );
	}

	/**
	 * @testdox PayPal RequestData failures verify without a submitted session ID and store no reusable record.
	 *
	 * @dataProvider request_data_failure_provider
	 */
	public function test_request_data_failure_verifies_without_session_id( string $failure, string $action, string $path, string $source ): void {
		$this->configure_paypal_request_data( array( SessionVerifier::SESSION_ID_FIELD => 'browser-session' ), $failure );
		$this->session_verifier
			->expects( $this->once() )
			->method( 'verify_session' )
			->with( '', $source, 0, array() )
			->willReturn( FraudDecision::Allow );
		$this->session_verifier->method( 'last_verified_session_id' )->willReturn( 'response-session' );

		$this->run_protected_request( $action, array( 'method' => 'POST' ), $path );

		$this->assertNull( WC()->session->get( '_fraud_protection_paypal_verification' ) );
	}

	/** @return array<string, array{string, string, string, string}> */
	public function request_data_failure_provider(): array {
		return array(
			'setup container'            => array( 'container', 'wc_ajax_ppc-create-setup-token', '/v3/vault/setup-tokens', 'paypal_setup_token_creation' ),
			'setup incompatible service' => array( 'service', 'wc_ajax_ppc-create-setup-token', '/v3/vault/setup-tokens', 'paypal_setup_token_creation' ),
			'setup read or nonce'        => array( 'read', 'wc_ajax_ppc-create-setup-token', '/v3/vault/setup-tokens', 'paypal_setup_token_creation' ),
			'vault container'            => array( 'container', 'wc_ajax_ppc-vault-create-order', '/v2/checkout/orders', 'paypal_vault_order_creation' ),
			'vault incompatible service' => array( 'service', 'wc_ajax_ppc-vault-create-order', '/v2/checkout/orders', 'paypal_vault_order_creation' ),
			'vault read or nonce'        => array( 'read', 'wc_ajax_ppc-vault-create-order', '/v2/checkout/orders', 'paypal_vault_order_creation' ),
		);
	}

	/**
	 * @testdox Missing PayPal classes verify without a submitted session ID.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_missing_paypal_classes_verify_without_session_id(): void {
		$this->session_verifier
			->expects( $this->once() )
			->method( 'verify_session' )
			->with( '', 'paypal_setup_token_creation', 0, array() )
			->willReturn( FraudDecision::Allow );

		$this->run_setup_token_request();
	}

	/** @testdox Mixed PayPal filter inputs pass through without verification. */
	public function test_protected_request_preserves_mixed_filter_inputs(): void {
		$this->session_verifier->expects( $this->never() )->method( 'verify_session' );

		$this->assertSame( 'invalid', $this->sut->verify_protected_paypal_request( 'invalid', array() ) );
	}

	/** Configure PayPal request-data compatibility stubs. */
	private function configure_paypal_request_data( array $data, string $failure = '' ): void {
		if ( ! class_exists( 'WooCommerce\\PayPalCommerce\\Button\\Endpoint\\RequestData' ) ) {
			class_alias( PayPalRequestDataStub::class, 'WooCommerce\\PayPalCommerce\\Button\\Endpoint\\RequestData' );
		}

		PayPalRequestDataStub::$data  = $data;
		PayPalRequestDataStub::$error = 'read' === $failure ? new \RuntimeException( 'invalid request' ) : null;
		PayPalPPCPStub::set_error( 'container' === $failure ? new \RuntimeException( 'container unavailable' ) : null );
		PayPalContainerStub::set_service(
			'button.request-data',
			'service' === $failure ? new \stdClass() : new PayPalRequestDataStub()
		);
	}

	/** Configure an eligible zero-total cart for setup-token record storage. */
	private function set_eligible_setup_cart(): void {
		$cart = $this->getMockBuilder( \WC_Cart::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_empty', 'get_total', 'needs_payment', 'get_cart', 'get_cart_hash' ) )
			->getMock();
		$cart->method( 'is_empty' )->willReturn( false );
		$cart->method( 'get_total' )->willReturn( '0' );
		$cart->method( 'needs_payment' )->willReturn( true );
		$cart->method( 'get_cart' )->willReturn( array() );
		$cart->method( 'get_cart_hash' )->willReturn( 'cart-hash' );
		WC()->cart = $cart;
	}

	/** Run a setup-token request. */
	private function run_setup_token_request(): void {
		$this->run_protected_request( 'wc_ajax_ppc-create-setup-token', array( 'method' => 'POST' ), '/v3/vault/setup-tokens' );
	}

	/** Run a protected request while its WooCommerce AJAX action is active. */
	private function run_protected_request( string $action, array $args, string $path ) {
		$this->sut->register();
		$result   = null;
		$callback = function () use ( &$result, $args, $path ): void {
			$result = apply_filters( 'ppcp_request_args', $args, 'https://api-m.paypal.com' . $path );
		};
		add_action( $action, $callback );
		try {
			do_action( $action );
		} finally {
			remove_action( $action, $callback );
		}

		return $result;
	}


}
