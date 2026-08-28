<?php
/**
 * BlocksCheckoutProtectorTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin\Protectors;

use Automattic\WooCommerce\Blocks\BlockTypes\AbstractBlock;
use Automattic\WooCommerce\Blocks\BlockTypes\Checkout;
use Automattic\WooCommerce\FraudProtection\BlackboxScriptHandler;
use Automattic\WooCommerce\FraudProtection\Schemas\FraudDecision;
use Automattic\WooCommerce\FraudProtection\BlockedSessionMessage;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Protectors\BlocksCheckoutProtector;
use Automattic\WooCommerce\FraudProtection\SessionVerifier;
use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;
use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;

/**
 * Tests for the BlocksCheckoutProtector class.
 *
 * @covers \Automattic\WooCommerce\Internal\FraudProtectionPlugin\Protectors\BlocksCheckoutProtector
 */
class BlocksCheckoutProtectorTest extends FraudProtectionUnitTestCase {

	/**
	 * The System Under Test.
	 *
	 * @var BlocksCheckoutProtector
	 */
	private BlocksCheckoutProtector $sut;

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
			->method( 'get_plaintext' )
			->willReturn( 'We are unable to process this request online. Please contact support (test@example.com) to complete your purchase.' );

		$this->sut = new BlocksCheckoutProtector();
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
		remove_action( 'woocommerce_blocks_enqueue_checkout_block_scripts_before', array( $this->sut, 'enqueue_blocks_checkout_script' ) );
		remove_action( 'woocommerce_blocks_checkout_enqueue_data', array( $this->sut, 'enqueue_blocks_checkout_script' ) );
		$this->reset_fraud_protection_scripts();

		global $wp;
		unset( $wp->query_vars['order-pay'] );
		unset( $wp->query_vars['order-received'] );
		set_current_screen( 'front' );

		parent::tearDown();
	}

	/*
	|--------------------------------------------------------------------------
	| enqueue_blocks_checkout_script() Tests
	|--------------------------------------------------------------------------
	*/

	/**
	 * @testdox register() uses the frontend pre-assets hook, never the editor-facing checkout-data hook.
	 */
	public function test_register_uses_frontend_checkout_block_hook(): void {
		$this->sut->register();

		$this->assertSame(
			10,
			has_action( 'woocommerce_blocks_enqueue_checkout_block_scripts_before', array( $this->sut, 'enqueue_blocks_checkout_script' ) )
		);
		$this->assertFalse(
			has_action( 'woocommerce_blocks_checkout_enqueue_data', array( $this->sut, 'enqueue_blocks_checkout_script' ) )
		);
	}

	/**
	 * @testdox enqueue_blocks_checkout_script() enqueues its script when the shared scripts are available.
	 */
	public function test_enqueue_blocks_checkout_script_when_shared_scripts_are_available(): void {
		$this->blackbox_script_handler->expects( $this->once() )->method( 'request_scripts' )->willReturn( true );

		$this->sut->enqueue_blocks_checkout_script();

		$this->assertTrue( wp_script_is( 'wc-fraud-protection-blocks-checkout', 'enqueued' ) );
		$script = wp_scripts()->query( 'wc-fraud-protection-blocks-checkout', 'registered' );
		$this->assertNotFalse( $script );
		$this->assertSame(
			plugins_url( 'assets/js/blocks-checkout.js', WC_FRAUD_PROTECTION_PLUGIN_FILE ),
			$script->src
		);
	}

	/**
	 * @testdox enqueue_blocks_checkout_script() does not enqueue when the shared scripts are unavailable.
	 */
	public function test_enqueue_blocks_checkout_script_skips_when_shared_scripts_are_unavailable(): void {
		$this->blackbox_script_handler->expects( $this->once() )->method( 'request_scripts' )->willReturn( false );

		$this->sut->enqueue_blocks_checkout_script();

		$this->assertFalse( wp_script_is( 'wc-fraud-protection-blocks-checkout', 'enqueued' ) );
	}

	/**
	 * @testdox Real Site Editor bootstrap does not request or enqueue the Blocks protector.
	 */
	public function test_site_editor_lifecycle_does_not_enqueue_or_resolve_blocks_protector(): void {
		$checkout = $this->get_checkout_block_type();
		$previous_enqueue_state = $this->set_checkout_enqueue_state( $checkout, false );
		$this->blackbox_script_handler->expects( $this->never() )->method( 'request_scripts' );
		$this->sut->register();
		set_current_screen( 'site-editor' );

		$checkout_data_runs = 0;
		$count_data_action  = function () use ( &$checkout_data_runs ): void {
			++$checkout_data_runs;
		};

		add_action( 'woocommerce_blocks_checkout_enqueue_data', $count_data_action, PHP_INT_MAX );

		try {
			do_action( 'enqueue_block_editor_assets' ); // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Exercising Woo's real editor lifecycle.

			$this->assertGreaterThan( 0, $checkout_data_runs, 'Woo Checkout::enqueue_editor_assets() must reach Checkout::enqueue_data().' );
			$this->assertFalse( wp_script_is( 'wc-fraud-protection-blocks-checkout', 'enqueued' ) );
		} finally {
			remove_action( 'woocommerce_blocks_checkout_enqueue_data', $count_data_action, PHP_INT_MAX );
			$this->set_checkout_enqueue_state( $checkout, $previous_enqueue_state );
		}
	}

	/**
	 * @testdox Real frontend Checkout lifecycle registers every dependency before queuing the Blocks protector.
	 */
	public function test_frontend_checkout_lifecycle_registers_dependencies_before_protector(): void {
		$checkout = $this->get_checkout_block_type();
		$previous_enqueue_state = $this->set_checkout_enqueue_state( $checkout, false );
		$this->mock_jetpack_blog_id( 12345 );
		$this->sut->init(
			$this->session_verifier,
			$this->blocked_session_message,
			$this->make_blackbox_script_handler()
		);
		$this->sut->register();

		$dependencies_ready = false;
		$probe_dependencies  = function () use ( &$dependencies_ready ): void {
			$dependencies_ready = wp_script_is( 'wp-data', 'registered' )
				&& wp_script_is( 'wc-blocks-checkout-events', 'registered' );
		};

		add_action( 'woocommerce_blocks_enqueue_checkout_block_scripts_before', $probe_dependencies, 9, 0 );

		try {
			$checkout->render_callback(
				array(),
				'<div class="wp-block-woocommerce-checkout"></div>',
				null
			);
			$resolved = wp_scripts()->all_deps( array( 'wc-fraud-protection-blocks-checkout' ) );

			$this->assertTrue( $dependencies_ready, 'Woo must register wp-data and wc-blocks-checkout-events before the pre-assets hook.' );
			$this->assertTrue( wp_script_is( 'wc-fraud-protection-blackbox-init', 'enqueued' ) );
			$this->assertTrue( wp_script_is( 'wc-fraud-protection-blocks-checkout', 'enqueued' ) );
			$this->assertTrue( $resolved, 'WordPress should resolve every Blocks protector dependency.' );
		} finally {
			remove_action( 'woocommerce_blocks_enqueue_checkout_block_scripts_before', $probe_dependencies, 9 );
			$this->set_checkout_enqueue_state( $checkout, $previous_enqueue_state );
		}
	}

	/**
	 * @testdox Real order-received block fallback does not request or enqueue the Blocks protector.
	 */
	public function test_order_received_checkout_block_lifecycle_does_not_enqueue_blocks_protector(): void {
		global $wp;

		$checkout = $this->get_checkout_block_type();
		$previous_enqueue_state = $this->set_checkout_enqueue_state( $checkout, false );
		$this->blackbox_script_handler->expects( $this->never() )->method( 'request_scripts' );
		$this->sut->register();
		$wp->query_vars['order-received'] = '123';

		try {
			$checkout->render_callback(
				array(),
				'<div class="wp-block-woocommerce-checkout"></div>',
				null
			);
			$this->assertFalse( wp_script_is( 'wc-fraud-protection-blocks-checkout', 'enqueued' ) );
		} finally {
			$this->set_checkout_enqueue_state( $checkout, $previous_enqueue_state );
		}
	}

	/**
	 * @testdox Blocks protector stays absent on both Checkout endpoint fallbacks.
	 *
	 * @dataProvider checkout_endpoint_provider
	 *
	 * @param string $endpoint Endpoint query variable.
	 */
	public function test_blocks_protector_does_not_enqueue_on_checkout_endpoint( string $endpoint ): void {
		global $wp;

		$this->blackbox_script_handler->expects( $this->never() )->method( 'request_scripts' );
		$wp->query_vars[ $endpoint ] = '123';

		$this->sut->enqueue_blocks_checkout_script();

		$this->assertFalse( wp_script_is( 'wc-fraud-protection-blocks-checkout', 'enqueued' ) );
	}

	/**
	 * Checkout endpoint cases.
	 *
	 * @return array<string, array{string}>
	 */
	public function checkout_endpoint_provider(): array {
		return array(
			'order pay'      => array( 'order-pay' ),
			'order received' => array( 'order-received' ),
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
		$request_data = $this->create_mock_request(
			'test-session-123',
			array(
				'billing_address' => array( 'first_name' => 'Bob' ),
				'payment_method'  => 'stripe',
			)
		)->get_params();
		$this->set_request_data( $request_data );

		$order = $this->create_mock_order( 123 );

		$this->session_verifier
			->expects( $this->once() )
			->method( 'verify_session' )
			->with( 'test-session-123', 'blocks_checkout', 123, $request_data )
			->willReturn( FraudDecision::Allow );

		// Should not throw.
		$this->sut->verify_and_block( $order );
	}

	/**
	 * @testdox verify_and_block() throws RouteException when SessionVerifier returns BLOCK decision.
	 */
	public function test_verify_throws_on_block_decision(): void {
		$request_data = $this->create_mock_request(
			'test-session-456',
			array(
				'billing_address' => array( 'first_name' => 'Jane' ),
				'payment_method'  => 'woocommerce_payments',
			)
		)->get_params();
		$this->set_request_data( $request_data );

		$this->session_verifier
			->expects( $this->once() )
			->method( 'verify_session' )
			->with( 'test-session-456', 'blocks_checkout', 456, $request_data )
			->willReturn( FraudDecision::Block );

		$order = $this->create_mock_order( 456 );

		$this->expectException( RouteException::class );
		$this->expectExceptionMessage( 'We are unable to process this request online. Please contact support (test@example.com) to complete your purchase.' );

		$this->sut->verify_and_block( $order );
	}

	/**
	 * @testdox verify_and_block() calls verify with empty session_id when no request data was extracted.
	 */
	public function test_verify_with_empty_session_id(): void {
		$order = $this->create_mock_order( 101 );

		// No extract_request_data called, so session_id is an empty string.
		$this->session_verifier
			->expects( $this->once() )
			->method( 'verify_session' )
			->with( '', 'blocks_checkout', 101, array() )
			->willReturn( FraudDecision::Allow );

		// Should not throw.
		$this->sut->verify_and_block( $order );
	}

	/**
	 * @testdox verify_and_block() passes the submitted extension value to SessionVerifier
	 *
	 * @dataProvider submitted_session_value_provider
	 *
	 * @param mixed $value Submitted value.
	 */
	public function test_verify_passes_submitted_value_to_session_verifier( $value ): void {
		$request_data = array(
			'extensions' => array(
				'woocommerce/fraud-protection' => array(
					'blackbox_session_id' => $value,
				),
			),
		);
		$this->set_request_data( $request_data );

		$this->session_verifier
			->expects( $this->once() )
			->method( 'verify_session' )
			->with( $value, 'blocks_checkout', 201, $this->anything() )
			->willReturn( FraudDecision::Allow );

		$this->sut->verify_and_block( $this->create_mock_order( 201 ) );
	}

	/**
	 * Submitted extension values.
	 *
	 * @return array<string, array{mixed}>
	 */
	public function submitted_session_value_provider(): array {
		return array(
			'null'  => array( null ),
			'array' => array( array( 'private' ) ),
		);
	}

	/*
	|--------------------------------------------------------------------------
	| extract_request_data() Tests
	|--------------------------------------------------------------------------
	*/

	/**
	 * @testdox extract_request_data() correctly extracts blackbox_session_id from request extensions.
	 */
	public function test_extract_request_data_extracts_session_id(): void {
		$request = $this->create_mock_request( 'test-session-303' );
		$order   = $this->create_mock_order( 303 );

		$this->sut->extract_request_data( $order, $request );

		$request_data = $this->get_request_data();
		$session_id   = $request_data['extensions']['woocommerce/fraud-protection']['blackbox_session_id'] ?? '';

		$this->assertSame( 'test-session-303', $session_id );
	}

	/**
	 * @testdox extract_request_data() handles missing extensions gracefully.
	 */
	public function test_extract_handles_missing_extensions(): void {
		$request = $this->create_mock_request( null );
		$order   = $this->create_mock_order( 404 );

		$this->sut->extract_request_data( $order, $request );

		$request_data = $this->get_request_data();

		// Extensions should be null when not provided in the request.
		$this->assertNull( $request_data['extensions'] );
	}

	/**
	 * @testdox extract_request_data() populates all fields including extensions.
	 */
	public function test_extract_request_data_populates_all_fields(): void {
		$request = $this->create_mock_request(
			'test-session-500',
			array(
				'billing_address'   => array( 'first_name' => 'John' ),
				'shipping_address'  => array( 'first_name' => 'John' ),
				'payment_method'    => 'woocommerce_payments',
				'payment_data'      => array( array( 'key' => 'wcpay-fingerprint', 'value' => 'abc123' ) ),
				'create_account'    => true,
				'additional_fields' => array( 'custom_field' => 'value' ),
				'extensions'        => array(
					'woocommerce/order-attribution' => array(
						'source_type' => 'typein',
						'referrer'    => '(none)',
						'utm_source'  => '(direct)',
					),
				),
			)
		);
		$order = $this->create_mock_order( 500 );

		$this->sut->extract_request_data( $order, $request );

		$request_data = $this->get_request_data();

		$this->assertSame( array( 'first_name' => 'John' ), $request_data['billing_address'] );
		$this->assertSame( array( 'first_name' => 'John' ), $request_data['shipping_address'] );
		$this->assertSame( 'woocommerce_payments', $request_data['payment_method'] );
		// payment_data is normalized from [{key, value}, ...] to flat map in extract_request_data.
		$this->assertSame( array( 'wcpay-fingerprint' => 'abc123' ), $request_data['payment_data'] );
		$this->assertTrue( $request_data['create_account'] );
		$this->assertSame( array( 'custom_field' => 'value' ), $request_data['additional_fields'] );
		$this->assertSame( 'typein', $request_data['extensions']['woocommerce/order-attribution']['source_type'] );
	}

	/**
	 * @testdox extract_request_data() applies Store API payment data normalization.
	 *
	 * @dataProvider payment_data_normalization_provider
	 *
	 * @param array<string, mixed>  $request_params Request parameters.
	 * @param array<string, string> $expected       Expected payment data.
	 */
	public function test_extract_request_data_normalizes_payment_data(
		array $request_params,
		array $expected
	): void {
		$request = $this->create_mock_request( null, $request_params );

		$this->sut->extract_request_data( $this->create_mock_order( 800 ), $request );

		$request_data = $this->get_request_data();
		$this->assertSame( $expected, $request_data['payment_data'] );
	}

	/**
	 * Store API payment data normalization cases.
	 *
	 * @return array<string, array{array<string, mixed>, array<string, string>}>
	 */
	public function payment_data_normalization_provider(): array {
		return array(
			'sanitizes key and cleans value' => array(
				array(
					'payment_data' => array(
						array( 'key' => 'WC-STRIPE-PAYMENT-TOKEN', 'value' => ' <b>235</b> ' ),
					),
				),
				array( 'wc-stripe-payment-token' => '235' ),
			),
			'converts true to string'         => array(
				array(
					'payment_data' => array(
						array( 'key' => 'BOOL-TRUE', 'value' => true ),
					),
				),
				array( 'bool-true' => '1' ),
			),
			'converts false to empty string'  => array(
				array(
					'payment_data' => array(
						array( 'key' => 'BOOL-FALSE', 'value' => false ),
					),
				),
				array( 'bool-false' => '' ),
			),
			'keeps empty normalized key'      => array(
				array(
					'payment_data' => array(
						array( 'key' => '!', 'value' => 'empty-key' ),
					),
				),
				array( '' => 'empty-key' ),
			),
			'keeps last mixed-case duplicate' => array(
				array(
					'payment_data' => array(
						array( 'key' => 'token', 'value' => '234' ),
						array( 'key' => 'Token', 'value' => '235' ),
					),
				),
				array( 'token' => '235' ),
			),
			'keeps last lowercase duplicate'  => array(
				array(
					'payment_data' => array(
						array( 'key' => 'Token', 'value' => '235' ),
						array( 'key' => 'token', 'value' => '234' ),
					),
				),
				array( 'token' => '234' ),
			),
			'missing payment data'             => array( array(), array() ),
			'empty payment data'               => array( array( 'payment_data' => array() ), array() ),
		);
	}

	/**
	 * @testdox extract_request_data() excludes customer_password from request_data.
	 */
	public function test_extract_request_data_excludes_customer_password(): void {
		$request = $this->create_mock_request(
			'test-session-501',
			array(
				'billing_address'    => array( 'first_name' => 'Jane' ),
				'customer_password'  => 'super_secret_password_123',
				'create_account'     => true,
			)
		);
		$order = $this->create_mock_order( 501 );

		$this->sut->extract_request_data( $order, $request );

		$request_data = $this->get_request_data();

		$this->assertArrayNotHasKey( 'customer_password', $request_data );
		$this->assertSame( array( 'first_name' => 'Jane' ), $request_data['billing_address'] );
		$this->assertTrue( $request_data['create_account'] );
	}

	/*
	|--------------------------------------------------------------------------
	| Helpers
	|--------------------------------------------------------------------------
	*/

	/**
	 * Get WooCommerce's registered Checkout block type instance.
	 *
	 * @return Checkout
	 */
	private function get_checkout_block_type(): Checkout {
		$block_type = \WP_Block_Type_Registry::get_instance()->get_registered( 'woocommerce/checkout' );
		$this->assertInstanceOf( \WP_Block_Type::class, $block_type );
		$this->assertIsArray( $block_type->render_callback );
		$this->assertInstanceOf( Checkout::class, $block_type->render_callback[0] );

		return $block_type->render_callback[0];
	}

	/**
	 * Set the request-lifetime enqueue flag on WooCommerce's shared Checkout instance.
	 *
	 * @param Checkout $checkout Checkout block type instance.
	 * @param bool     $state    New enqueue state.
	 * @return bool Previous enqueue state.
	 */
	private function set_checkout_enqueue_state( Checkout $checkout, bool $state ): bool {
		$property = new \ReflectionProperty( AbstractBlock::class, 'enqueued_assets' );
		$property->setAccessible( true );
		$previous = (bool) $property->getValue( $checkout );
		$property->setValue( $checkout, $state );

		return $previous;
	}

	/**
	 * Create a mock WC_Order.
	 *
	 * @param int $order_id The order ID.
	 * @return \WC_Order
	 */
	private function create_mock_order( int $order_id ): \WC_Order {
		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_id' )->willReturn( $order_id );
		return $order;
	}

	/**
	 * Create a WP_REST_Request with checkout data.
	 *
	 * @param string|null $session_id The blackbox session ID, or null for no session_id.
	 * @param array       $params     Additional request parameters.
	 * @return \WP_REST_Request
	 */
	private function create_mock_request( ?string $session_id, array $params = array() ): \WP_REST_Request {
		$request = new \WP_REST_Request();

		if ( null !== $session_id ) {
			$extensions = $params['extensions'] ?? array();
			$extensions['woocommerce/fraud-protection'] = array(
				'blackbox_session_id' => $session_id,
			);
			$request->set_param( 'extensions', $extensions );
		}

		foreach ( $params as $key => $value ) {
			if ( 'extensions' !== $key ) {
				$request->set_param( $key, $value );
			}
		}

		return $request;
	}

	/**
	 * Get the request_data from the SUT via reflection.
	 *
	 * @return array
	 */
	private function get_request_data(): array {
		$property = new \ReflectionProperty( BlocksCheckoutProtector::class, 'request_data' );
		$property->setAccessible( true );
		return $property->getValue( $this->sut );
	}

	/**
	 * Set the request_data on the SUT via reflection.
	 *
	 * @param array $request_data The request data to set.
	 */
	private function set_request_data( array $request_data ): void {
		$property = new \ReflectionProperty( BlocksCheckoutProtector::class, 'request_data' );
		$property->setAccessible( true );
		$property->setValue( $this->sut, $request_data );
	}
}
