<?php
/**
 * ExampleGatewayIntegrationTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Examples;

require_once dirname( __DIR__, 3 ) . '/examples/fraud-protection-example-gateway/includes/class-wc-gateway-fp-example.php';
require_once dirname( __DIR__, 3 ) . '/examples/fraud-protection-example-gateway/includes/class-fp-example-fraud-protection.php';
require_once dirname( __DIR__, 3 ) . '/examples/fraud-protection-example-gateway/includes/class-fp-example-gateway-plugin.php';

use Automattic\WooCommerce\FraudProtection\BlackboxScriptHandler;
use Automattic\WooCommerce\FraudProtection\BlockedSessionMessage;
use Automattic\WooCommerce\FraudProtection\ClassicFormRequestData;
use Automattic\WooCommerce\FraudProtection\FraudProtectionFeature;
use Automattic\WooCommerce\FraudProtection\GatewayPaymentData;
use Automattic\WooCommerce\FraudProtection\GatewayRequestVerifier;
use Automattic\WooCommerce\FraudProtection\Schemas\FraudDecision;
use Automattic\WooCommerce\FraudProtection\Schemas\PaymentInstrumentData;
use Automattic\WooCommerce\FraudProtection\Schemas\PaymentMethodData;
use Automattic\WooCommerce\FraudProtection\Schemas\PaymentMode;
use Automattic\WooCommerce\FraudProtection\SessionIdNormalizer;
use Automattic\WooCommerce\FraudProtection\SessionVerifier;
use Automattic\WooCommerce\FraudProtection\SuppliedDecision;
use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Compat\GatewayNativeSupport;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\PaymentDataResolver;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Protectors\BlocksCheckoutProtector;
use Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry;
use Automattic\WooCommerce\Proxies\LegacyProxy;

/**
 * Exercises the example gateway's native integration through the plugin's real extension points.
 */
class ExampleGatewayIntegrationTest extends FraudProtectionUnitTestCase {

	/**
	 * The example integration.
	 *
	 * @var \FP_Example_Fraud_Protection
	 */
	private \FP_Example_Fraud_Protection $integration;

	/**
	 * Mock session verifier behind the request verifier.
	 *
	 * @var SessionVerifier&\PHPUnit\Framework\MockObject\MockObject
	 */
	private $session_verifier;

	/**
	 * Request verifier installed in the container, with its decision reuse filter registered.
	 *
	 * @var GatewayRequestVerifier
	 */
	private GatewayRequestVerifier $request_verifier;

	/**
	 * Payment data service installed in the container, with its filter registered.
	 *
	 * @var GatewayPaymentData
	 */
	private GatewayPaymentData $payment_data;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! defined( 'FP_EXAMPLE_GATEWAY_FILE' ) ) {
			define( 'FP_EXAMPLE_GATEWAY_FILE', dirname( __DIR__, 3 ) . '/examples/fraud-protection-example-gateway/fraud-protection-example-gateway.php' );
		}

		$this->session_verifier = $this->createMock( SessionVerifier::class );
		$this->request_verifier = new GatewayRequestVerifier();
		$this->request_verifier->init( $this->session_verifier, new SessionIdNormalizer(), wc_get_container()->get( LegacyProxy::class ), new GatewayNativeSupport() );
		$this->request_verifier->register();
		$GLOBALS['wc_container']->replace( GatewayRequestVerifier::class, $this->request_verifier );
		$this->payment_data = new GatewayPaymentData();
		$this->payment_data->init( new GatewayNativeSupport() );
		$this->payment_data->register();
		$GLOBALS['wc_container']->replace( GatewayPaymentData::class, $this->payment_data );

		$this->integration = new \FP_Example_Fraud_Protection();
		$this->integration->register();
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		$GLOBALS['wc_container']->reset_replacement( GatewayRequestVerifier::class );
		$GLOBALS['wc_container']->reset_replacement( GatewayPaymentData::class );
		remove_filter( 'woocommerce_fraud_protection_resolved_payment_data', array( $this->payment_data, 'apply_resolver' ), 10 );
		remove_filter( 'woocommerce_fraud_protection_skip_session_verify', array( $this->request_verifier, 'supply_reused_decision' ), 10 );
		remove_action( 'init', array( $this->integration, 'register_scripts' ) );
		remove_action( 'woocommerce_proceed_to_checkout', array( $this->integration, 'render_provider_button' ), 30 );
		remove_action( 'woocommerce_proceed_to_checkout', array( $this->integration, 'render_express_button' ), 40 );
		remove_action( 'woocommerce_checkout_before_order_review', array( $this->integration, 'render_provider_order_field' ) );
		remove_action( 'wc_ajax_' . \FP_Example_Fraud_Protection::PROVIDER_ORDER_ACTION, array( $this->integration, 'handle_create_provider_order' ) );
		remove_filter( 'wp_doing_ajax', '__return_true' );
		remove_all_filters( 'wp_die_ajax_handler' );
		WC()->session->set( '_wc_fraud_protection_reusable_decisions', null );
		WC()->cart->empty_cart();
		$_POST = array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		unset( $_GET[ \FP_Example_Fraud_Protection::PROVIDER_ORDER_FIELD ] );
		delete_option( 'woocommerce_fp_example_settings' );
		foreach ( array( \FP_Example_Fraud_Protection::PROVIDER_SCRIPT, \FP_Example_Fraud_Protection::EXPRESS_SCRIPT, 'fp-example-blocks' ) as $handle ) {
			wp_dequeue_script( $handle );
			wp_deregister_script( $handle );
		}
		$this->reset_fraud_protection_scripts();

		parent::tearDown();
	}

	/**
	 * @testdox The gateway declares the three features it implements, which the plugin reads through supports().
	 */
	public function test_gateway_declares_its_features(): void {
		$this->register_payment_gateways( new \WC_Gateway_FP_Example() );

		$this->assertSame(
			array(
				\WC_Gateway_FP_Example::ID => array(
					FraudProtectionFeature::PAYMENT_DATA,
					FraudProtectionFeature::CHECKOUT,
					FraudProtectionFeature::EXPRESS_CHECKOUT,
				),
			),
			( new GatewayNativeSupport() )->get_declarations()
		);
	}

	/**
	 * @testdox The gateway's payment-data callback describes its own attempts and leaves other gateways alone.
	 */
	public function test_payment_data_is_resolved_natively(): void {
		$resolver = new PaymentDataResolver();

		$own   = $resolver->resolve(
			\WC_Gateway_FP_Example::ID,
			array(
				'fp_example_card_brand' => 'visa',
				'fp_example_card_last4' => '4242',
			)
		)->to_array();
		$other = $resolver->resolve( 'cod', array( 'fp_example_card_brand' => 'visa' ) )->to_array();

		$this->assertSame( 'card', $own['payment_type'] );
		$this->assertSame( 'visa', $own['instrument']['brand'] );
		$this->assertSame( '4242', $own['instrument']['last4'] );
		$this->assertSame( PaymentMode::Test->value, $own['transaction_mode'] );
		$this->assertSame( 'acct_example', $own['merchant_identifier'] );
		$this->assertSame( 'account', $own['merchant_identifier_type'] );
		$this->assertNull( $other['payment_type'], 'Other gateways are left to their own resolvers' );
	}

	/**
	 * @testdox The gateway's payment-data callback keeps the data that a saved card's token resolved, and adds the mode and the merchant account.
	 */
	public function test_payment_data_keeps_saved_card_data(): void {
		$resolved = new PaymentMethodData(
			\WC_Gateway_FP_Example::ID,
			'card',
			true,
			PaymentInstrumentData::from_array(
				array(
					'brand' => 'visa',
					'last4' => '1111',
				)
			)
		);

		$result = $this->integration->resolve_payment_data( array(), $resolved )->to_array();

		$this->assertTrue( $result['is_saved_payment_method'] );
		$this->assertSame( 'visa', $result['instrument']['brand'] );
		$this->assertSame( '1111', $result['instrument']['last4'] );
		$this->assertSame( PaymentMode::Test->value, $result['transaction_mode'] );
		$this->assertSame( 'acct_example', $result['merchant_identifier'] );
	}

	/**
	 * @testdox The provider order request is verified with the submitted session ID, and the checkout that posts the provider order's ID reuses the decision once.
	 */
	public function test_provider_order_request_is_verified_and_its_decision_reused_once(): void {
		$this->add_product_to_cart();
		$this->prepare_ajax_request( 'browser-session' );
		$this->session_verifier->expects( $this->once() )
			->method( 'verify_session' )
			->with( 'browser-session', \FP_Example_Fraud_Protection::PROVIDER_ORDER_SOURCE, 0, $this->callback( fn( array $data ): bool => \WC_Gateway_FP_Example::ID === $data['payment_method'] ) )
			->willReturn( FraudDecision::Allow );
		$this->session_verifier->method( 'last_verified_session_id' )->willReturn( 'resolved-session' );

		$response = $this->dispatch_provider_order_request();

		$this->assertTrue( $response['success'] );
		$reference = $this->get_reference( $response );
		$this->assertNotSame( '', $reference, 'The redirect carries the provider order ID' );
		$this->assertStringStartsWith( wc_get_checkout_url(), $response['data']['redirect'] );

		// The browser started a new session after the provider order request, so the checkout submits another session ID.
		$supplied = $this->apply_skip_filter( $reference );
		$this->assertInstanceOf( SuppliedDecision::class, $supplied, 'The checkout that posts the provider order ID reuses the decision' );
		$this->assertSame( FraudDecision::Allow, $supplied->decision );
		$this->assertSame( 'resolved-session', $supplied->session_id_for_order, 'The order gets the session ID the verification resolved' );
		$this->assertFalse( $this->apply_skip_filter( $reference ), 'The decision is consumed on first use' );
	}

	/**
	 * @testdox The button scripts are registered with the shared scripts as a dependency when they are available, and they load only once the buttons render.
	 *
	 * @dataProvider shared_scripts_provider
	 *
	 * @param bool $available Whether the store can load the shared scripts.
	 */
	public function test_button_scripts_depend_on_the_shared_scripts( bool $available ): void {
		if ( $available ) {
			$this->mock_jetpack_blog_id( 42 );
		} else {
			add_filter( 'pre_option_jetpack_options', '__return_empty_array' );
		}
		$this->enable_gateway();
		$this->add_product_to_cart();

		try {
			// Called directly: firing init would also run every other init callback.
			$this->integration->register_scripts();

			$shared = $available ? array( 'wc-fraud-protection-blackbox-init' ) : array();
			$this->assertSame( $shared, wp_scripts()->query( \FP_Example_Fraud_Protection::PROVIDER_SCRIPT, 'registered' )->deps );
			$this->assertSame( array_merge( array( 'wp-hooks' ), $shared ), wp_scripts()->query( \FP_Example_Fraud_Protection::EXPRESS_SCRIPT, 'registered' )->deps );
			$this->assertFalse( wp_script_is( 'wc-fraud-protection-blackbox-init', 'enqueued' ), 'Registering loads nothing' );

			$output = $this->render_cart_buttons();

			$this->assertStringContainsString( 'fp-example-provider-button', $output );
			$this->assertStringContainsString( 'fp-example-express-button', $output );
			$this->assertSame( $available, wp_script_is( 'wc-fraud-protection-blackbox-init', 'enqueued' ), 'The shared scripts load with the button scripts' );
		} finally {
			remove_filter( 'pre_option_jetpack_options', '__return_empty_array' );
			remove_filter( 'print_scripts_array', array( wc_get_container()->get( BlackboxScriptHandler::class ), 'leave_out_shared_scripts_on_excluded_pages' ) );
		}
	}

	/**
	 * Whether the shared scripts are available.
	 *
	 * @return array<string, array{bool}>
	 */
	public function shared_scripts_provider(): array {
		return array(
			'available'   => array( true ),
			'unavailable' => array( false ),
		);
	}

	/**
	 * @testdox The express button places its order with a Store API checkout request, which it sends with a Store API nonce.
	 */
	public function test_express_button_sends_a_store_api_checkout(): void {
		$this->enable_gateway();
		$this->integration->register_scripts();

		$this->render_cart_buttons();

		$data = (string) wp_scripts()->get_data( \FP_Example_Fraud_Protection::EXPRESS_SCRIPT, 'data' );
		$this->assertMatchesRegularExpression( '/^var fpExampleExpress = (.*);$/s', $data );
		$config = json_decode( (string) preg_replace( '/^var fpExampleExpress = (.*);$/s', '$1', $data ), true );
		$this->assertSame( rest_url( 'wc/store/v1/checkout' ), $config['checkoutUrl'] );
		$this->assertSame( 1, wp_verify_nonce( $config['nonce'], 'wc_store_api' ), 'The Store API accepts the nonce' );
		$this->assertNotEmpty( $config['address']['email'], 'The stand-in payment sheet returns a billing address' );
	}

	/**
	 * @testdox The checkout form carries the provider order ID from the redirect URL.
	 */
	public function test_checkout_form_carries_the_provider_order_id(): void {
		$_GET[ \FP_Example_Fraud_Protection::PROVIDER_ORDER_FIELD ] = 'abc-123';

		ob_start();
		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Test invokes the hook.
		do_action( 'woocommerce_checkout_before_order_review' );
		$output = (string) ob_get_clean();

		$this->assertSame( '<input type="hidden" name="fp_example_provider_order_id" value="abc-123" />', $output );

		unset( $_GET[ \FP_Example_Fraud_Protection::PROVIDER_ORDER_FIELD ] );
		ob_start();
		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Test invokes the hook.
		do_action( 'woocommerce_checkout_before_order_review' );
		$this->assertSame( '', (string) ob_get_clean(), 'A regular checkout has no provider order ID' );
	}

	/**
	 * @testdox The posted provider order ID reaches the payment data the plugin builds for a classic checkout.
	 */
	public function test_posted_reference_reaches_the_request_data(): void {
		$_POST = array(
			'payment_method' => \WC_Gateway_FP_Example::ID,
			\FP_Example_Fraud_Protection::PROVIDER_ORDER_FIELD => 'abc-123',
		);

		$request_data = ( new ClassicFormRequestData() )->build_request_data( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$this->assertSame( 'abc-123', $request_data['payment_data'][ \FP_Example_Fraud_Protection::PROVIDER_ORDER_FIELD ] );
	}

	/**
	 * @testdox A blocked provider order request is answered with a 403 and the blocked message.
	 */
	public function test_blocked_provider_order_request_is_stopped(): void {
		$this->add_product_to_cart();
		$this->prepare_ajax_request( 'browser-session' );
		$this->session_verifier->method( 'verify_session' )->willReturn( FraudDecision::Block );

		$response = $this->dispatch_provider_order_request();

		$this->assertFalse( $response['success'] );
		$this->assertStringContainsString( 'unable to process this request', $response['data']['message'] );
		$this->assertArrayNotHasKey( 'redirect', $response['data'], 'A block creates no provider order' );
		$this->assertNull( WC()->session->get( '_wc_fraud_protection_reusable_decisions' ), 'A block keeps nothing for reuse' );
	}

	/**
	 * @testdox A remembered decision is not reused for a checkout without the provider order ID, with another one, or with another cart.
	 *
	 * @dataProvider mismatch_provider
	 *
	 * @param ?string $reference   The provider order ID the final checkout posts; null for the one the request created.
	 * @param bool    $change_cart Whether the cart changes before the final checkout.
	 */
	public function test_remembered_decision_is_not_reused_for_another_attempt( ?string $reference, bool $change_cart ): void {
		$this->add_product_to_cart();
		$this->prepare_ajax_request( 'browser-session' );
		$this->session_verifier->method( 'verify_session' )->willReturn( FraudDecision::Allow );
		$this->session_verifier->method( 'last_verified_session_id' )->willReturn( 'resolved-session' );
		$created = $this->get_reference( $this->dispatch_provider_order_request() );

		if ( $change_cart ) {
			$this->add_product_to_cart();
		}

		$this->assertFalse( $this->apply_skip_filter( $reference ?? $created ) );
	}

	/**
	 * Mismatching final checkouts.
	 *
	 * @return array<string, array{?string, bool}>
	 */
	public function mismatch_provider(): array {
		return array(
			'another provider order' => array( 'another-order', false ),
			'no provider order'      => array( '', false ),
			'changed cart'           => array( null, true ),
		);
	}

	/**
	 * Put a product in the cart so it has a hash.
	 */
	private function add_product_to_cart(): void {
		$product = \WC_Helper_Product::create_simple_product();
		WC()->cart->add_to_cart( $product->get_id() );
	}

	/**
	 * @testdox The Checkout block submission that posts the provider order ID in its Store API payment data reuses the decision of the provider order request.
	 */
	public function test_checkout_block_submission_reuses_the_provider_order_decision(): void {
		$this->add_product_to_cart();
		$this->prepare_ajax_request( 'browser-session' );
		$this->session_verifier->method( 'verify_session' )->willReturn( FraudDecision::Allow );
		$this->session_verifier->method( 'last_verified_session_id' )->willReturn( 'resolved-session' );
		$reference = $this->get_reference( $this->dispatch_provider_order_request() );

		// The Store API checkout request that the Checkout block sends, with the fields that assets/blocks.js posts.
		$request = new \WP_REST_Request( 'POST', '/wc/store/v1/checkout' );
		$request->set_param( 'payment_method', \WC_Gateway_FP_Example::ID );
		$request->set_param(
			'payment_data',
			array(
				array(
					'key'   => 'fp_example_card_brand',
					'value' => 'visa',
				),
				array(
					'key'   => \FP_Example_Fraud_Protection::PROVIDER_ORDER_FIELD,
					'value' => $reference,
				),
			)
		);
		$request->set_param( 'extensions', array( 'woocommerce/fraud-protection' => array( 'blackbox_session_id' => 'checkout-session' ) ) );

		// The plugin's Checkout block protector verifies it: the skip filter receives the request data it builds.
		$supplied          = null;
		$checkout_verifier = $this->createMock( SessionVerifier::class );
		$checkout_verifier->expects( $this->once() )->method( 'verify_session' )->willReturnCallback(
			function ( $session_id, string $source, int $order_id, array $request_data ) use ( &$supplied ): FraudDecision {
				unset( $order_id );
				// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Test invokes the hook as SessionVerifier does.
				$supplied = apply_filters( 'woocommerce_fraud_protection_skip_session_verify', false, $source, $request_data, (string) $session_id );
				return FraudDecision::Allow;
			}
		);
		$protector = new BlocksCheckoutProtector();
		$protector->init( $checkout_verifier, new BlockedSessionMessage(), $this->make_blackbox_script_handler() );
		$order = \WC_Helper_Order::create_order();

		$protector->extract_request_data( $order, $request );
		$protector->verify_and_block( $order );

		$this->assertInstanceOf( SuppliedDecision::class, $supplied );
		$this->assertSame( 'resolved-session', $supplied->session_id_for_order );
		\WC_Helper_Order::delete_order( $order->get_id() );
	}

	/**
	 * @testdox The gateway registers a payment method for the Cart and Checkout blocks, offered while the gateway is enabled.
	 */
	public function test_gateway_registers_a_block_payment_method(): void {
		$registry = new PaymentMethodRegistry();

		\FP_Example_Gateway_Plugin::register_blocks_support( $registry );

		$this->assertTrue( $registry->is_registered( \WC_Gateway_FP_Example::ID ) );
		$payment_method = $registry->get_registered( \WC_Gateway_FP_Example::ID );

		update_option( 'woocommerce_fp_example_settings', array( 'enabled' => 'yes' ) );
		$payment_method->initialize();
		$this->assertTrue( $payment_method->is_active() );

		update_option( 'woocommerce_fp_example_settings', array( 'enabled' => 'no' ) );
		$payment_method->initialize();
		$this->assertFalse( $payment_method->is_active() );

		$data = $payment_method->get_payment_method_data();
		$this->assertSame( \FP_Example_Fraud_Protection::PROVIDER_ORDER_FIELD, $data['providerOrderField'], 'The block script posts the provider order ID under the field the reference reader reads' );
		$this->assertSame( 'Express pay (example)', $data['labels']['express'] );
	}

	/**
	 * @testdox The block script loads after the button scripts when the Fraud Protection integration registered them, and without them otherwise.
	 *
	 * @dataProvider shared_scripts_provider
	 *
	 * @param bool $button_scripts Whether the Fraud Protection integration registered the button scripts.
	 */
	public function test_block_script_loads_after_the_button_scripts( bool $button_scripts ): void {
		if ( $button_scripts ) {
			$this->integration->register_scripts();
		}
		$registry = new PaymentMethodRegistry();
		\FP_Example_Gateway_Plugin::register_blocks_support( $registry );

		$handles = $registry->get_registered( \WC_Gateway_FP_Example::ID )->get_payment_method_script_handles();

		$this->assertSame( array( 'fp-example-blocks' ), $handles );
		$button_handles = array( \FP_Example_Fraud_Protection::PROVIDER_SCRIPT, \FP_Example_Fraud_Protection::EXPRESS_SCRIPT );
		$this->assertSame(
			$button_scripts ? $button_handles : array(),
			array_values( array_intersect( wp_scripts()->query( 'fp-example-blocks', 'registered' )->deps, $button_handles ) )
		);
	}

	/**
	 * @testdox The button scripts are registered on init, before a block theme renders the Cart and Checkout blocks, which collect the block script's dependencies before wp_enqueue_scripts fires.
	 */
	public function test_button_scripts_are_registered_before_the_blocks_render(): void {
		$this->assertSame( 10, has_action( 'init', array( $this->integration, 'register_scripts' ) ) );
		$this->assertFalse( has_action( 'wp_enqueue_scripts', array( $this->integration, 'register_scripts' ) ) );
	}

	/**
	 * Make the example gateway available to the shopper.
	 */
	private function enable_gateway(): void {
		$gateway          = new \WC_Gateway_FP_Example();
		$gateway->enabled = 'yes';
		$this->register_payment_gateways( $gateway );
	}

	/**
	 * Render the buttons that the cart page shows before its checkout button.
	 *
	 * @return string The rendered output.
	 */
	private function render_cart_buttons(): string {
		ob_start();
		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Test invokes the hook.
		do_action( 'woocommerce_proceed_to_checkout' );

		return (string) ob_get_clean();
	}

	/**
	 * Set up the POST body and the AJAX context of a provider order request.
	 *
	 * @param string $session_id The session ID the browser adds.
	 */
	private function prepare_ajax_request( string $session_id ): void {
		$nonce                = wp_create_nonce( 'fp_example_create_provider_order' );
		$_POST                = array(
			'security'                        => $nonce,
			SessionVerifier::SESSION_ID_FIELD => $session_id,
		);
		$_REQUEST['security'] = $nonce;

		// wp_send_json_*() echoes JSON then calls wp_die(). Force the AJAX context and make
		// the die handler throw so the test can read the response.
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter(
			'wp_die_ajax_handler',
			static function () {
				return static function () {
					throw new \WPDieException();
				};
			}
		);
	}

	/**
	 * Dispatch the provider order request through its WooCommerce AJAX hook and decode the JSON answer.
	 *
	 * @return array{success: bool, data: array<string, mixed>}
	 */
	private function dispatch_provider_order_request(): array {
		ob_start();
		try {
			// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Test invokes the hook.
			do_action( 'wc_ajax_' . \FP_Example_Fraud_Protection::PROVIDER_ORDER_ACTION );
			$this->fail( 'The request should end with wp_die()' );
		} catch ( \WPDieException $e ) {
			unset( $e );
		} finally {
			$output = (string) ob_get_clean();
		}

		$response = json_decode( $output, true );
		$this->assertIsArray( $response, 'The response should be JSON: ' . $output );

		return $response;
	}

	/**
	 * Read the provider order ID from a provider order response's redirect URL.
	 *
	 * @param array{success: bool, data: array<string, mixed>} $response The provider order response.
	 * @return string
	 */
	private function get_reference( array $response ): string {
		$query = array();
		wp_parse_str( (string) wp_parse_url( (string) ( $response['data']['redirect'] ?? '' ), PHP_URL_QUERY ), $query );

		return (string) ( $query[ \FP_Example_Fraud_Protection::PROVIDER_ORDER_FIELD ] ?? '' );
	}

	/**
	 * Run the plugin's skip filter for a final checkout with the example gateway.
	 *
	 * The checkout submits a new session ID, as the browser does after the provider order request.
	 *
	 * @param string $reference The provider order ID the checkout posts; empty for none.
	 * @param string $source    The source of the checkout's verification.
	 * @return mixed
	 */
	private function apply_skip_filter( string $reference, string $source = 'shortcode_checkout' ) {
		$payment_data = '' === $reference ? array() : array( \FP_Example_Fraud_Protection::PROVIDER_ORDER_FIELD => $reference );

		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Test invokes the hook.
		return apply_filters(
			'woocommerce_fraud_protection_skip_session_verify',
			false,
			$source,
			array(
				'payment_method' => \WC_Gateway_FP_Example::ID,
				'payment_data'   => $payment_data,
			),
			'checkout-session'
		);
	}
}
