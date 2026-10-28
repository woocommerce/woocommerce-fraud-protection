<?php
/**
 * ExampleGatewayIntegrationTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Examples;

require_once dirname( __DIR__, 3 ) . '/examples/fraud-protection-example-gateway/includes/class-wc-gateway-fp-example.php';
require_once dirname( __DIR__, 3 ) . '/examples/fraud-protection-example-gateway/includes/class-fp-example-fraud-protection.php';

use Automattic\WooCommerce\FraudProtection\ClassicFormRequestData;
use Automattic\WooCommerce\FraudProtection\FraudProtectionFeature;
use Automattic\WooCommerce\FraudProtection\Schemas\FraudDecision;
use Automattic\WooCommerce\FraudProtection\Schemas\PaymentMode;
use Automattic\WooCommerce\FraudProtection\SessionVerifier;
use Automattic\WooCommerce\FraudProtection\SuppliedDecision;
use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Compat\GatewayNativeSupport;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\PaymentDataResolver;

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
	 * Mock session verifier installed in the container.
	 *
	 * @var SessionVerifier&\PHPUnit\Framework\MockObject\MockObject
	 */
	private $session_verifier;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! defined( 'FP_EXAMPLE_GATEWAY_FILE' ) ) {
			define( 'FP_EXAMPLE_GATEWAY_FILE', dirname( __DIR__, 3 ) . '/examples/fraud-protection-example-gateway/fraud-protection-example-gateway.php' );
		}

		$this->session_verifier = $this->createMock( SessionVerifier::class );
		$GLOBALS['wc_container']->replace( SessionVerifier::class, $this->session_verifier );

		$this->integration = new \FP_Example_Fraud_Protection();
		$this->integration->register();
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		$GLOBALS['wc_container']->reset_replacement( SessionVerifier::class );
		remove_filter( 'woocommerce_fraud_protection_resolved_payment_data', array( $this->integration, 'resolve_payment_data' ), 10 );
		remove_filter( 'woocommerce_fraud_protection_skip_session_verify', array( $this->integration, 'supply_express_decision' ), 10 );
		remove_action( 'woocommerce_proceed_to_checkout', array( $this->integration, 'render_express_button' ), 30 );
		remove_action( 'woocommerce_checkout_before_order_review', array( $this->integration, 'render_express_reference' ) );
		remove_action( 'wc_ajax_' . \FP_Example_Fraud_Protection::AJAX_ACTION, array( $this->integration, 'handle_express_pay' ) );
		remove_filter( 'wp_doing_ajax', '__return_true' );
		remove_all_filters( 'wp_die_ajax_handler' );
		WC()->session->set( 'fp_example_fraud_verification', null );
		WC()->cart->empty_cart();
		$_POST = array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		unset( $_GET[ \FP_Example_Fraud_Protection::EXPRESS_REFERENCE_FIELD ] );
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
	 * @testdox The express request is verified with the submitted session ID, and the checkout that posts its reference reuses the decision once.
	 */
	public function test_express_request_is_verified_and_its_decision_reused_once(): void {
		$this->add_product_to_cart();
		$this->prepare_ajax_request( 'browser-session' );
		$this->session_verifier->expects( $this->once() )
			->method( 'verify_session' )
			->with( 'browser-session', \FP_Example_Fraud_Protection::EXPRESS_SOURCE, 0, $this->callback( fn( array $data ): bool => \WC_Gateway_FP_Example::ID === $data['payment_method'] && 'apple_pay' === $data['payment_data']['fp_example_wallet'] ) )
			->willReturn( FraudDecision::Allow );
		$this->session_verifier->method( 'last_verified_session_id' )->willReturn( 'resolved-session' );

		$response = $this->dispatch_express_request();

		$this->assertTrue( $response['success'] );
		$reference = $this->get_reference( $response );
		$this->assertNotSame( '', $reference, 'The redirect carries the payment reference' );
		$this->assertStringStartsWith( wc_get_checkout_url(), $response['data']['redirect'] );

		// The browser started a new session after the express request, so the checkout submits another session ID.
		$supplied = $this->apply_skip_filter( $reference );
		$this->assertInstanceOf( SuppliedDecision::class, $supplied, 'The checkout that posts the reference reuses the decision' );
		$this->assertSame( FraudDecision::Allow, $supplied->decision );
		$this->assertSame( 'resolved-session', $supplied->session_id_for_order, 'The order gets the session ID the verification resolved' );
		$this->assertFalse( $this->apply_skip_filter( $reference ), 'The decision is consumed on first use' );
	}

	/**
	 * @testdox The checkout form carries the express reference from the redirect URL.
	 */
	public function test_checkout_form_carries_the_express_reference(): void {
		$_GET[ \FP_Example_Fraud_Protection::EXPRESS_REFERENCE_FIELD ] = 'abc-123';

		ob_start();
		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Test invokes the hook.
		do_action( 'woocommerce_checkout_before_order_review' );
		$output = (string) ob_get_clean();

		$this->assertSame( '<input type="hidden" name="fp_example_express_reference" value="abc-123" />', $output );

		unset( $_GET[ \FP_Example_Fraud_Protection::EXPRESS_REFERENCE_FIELD ] );
		ob_start();
		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Test invokes the hook.
		do_action( 'woocommerce_checkout_before_order_review' );
		$this->assertSame( '', (string) ob_get_clean(), 'A regular checkout has no reference' );
	}

	/**
	 * @testdox The posted reference reaches the payment data the plugin builds for a classic checkout.
	 */
	public function test_posted_reference_reaches_the_request_data(): void {
		$_POST = array(
			'payment_method' => \WC_Gateway_FP_Example::ID,
			\FP_Example_Fraud_Protection::EXPRESS_REFERENCE_FIELD => 'abc-123',
		);

		$request_data = ( new ClassicFormRequestData() )->build_request_data( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$this->assertSame( 'abc-123', $request_data['payment_data'][ \FP_Example_Fraud_Protection::EXPRESS_REFERENCE_FIELD ] );
	}

	/**
	 * @testdox A blocked express request is answered with a 403 and the blocked message.
	 */
	public function test_blocked_express_request_is_stopped(): void {
		$this->add_product_to_cart();
		$this->prepare_ajax_request( 'browser-session' );
		$this->session_verifier->method( 'verify_session' )->willReturn( FraudDecision::Block );

		$response = $this->dispatch_express_request();

		$this->assertFalse( $response['success'] );
		$this->assertStringContainsString( 'unable to process this request', $response['data']['message'] );
		$this->assertArrayNotHasKey( 'redirect', $response['data'], 'A block creates no payment reference' );
		$this->assertNull( WC()->session->get( 'fp_example_fraud_verification' ), 'A block leaves nothing to reuse' );
	}

	/**
	 * @testdox A remembered decision is not reused for a checkout without the reference, with another reference, or with another cart.
	 *
	 * @dataProvider mismatch_provider
	 *
	 * @param ?string $reference   The reference the final checkout posts; null for the one the express request created.
	 * @param bool    $change_cart Whether the cart changes before the final checkout.
	 */
	public function test_remembered_decision_is_not_reused_for_another_attempt( ?string $reference, bool $change_cart ): void {
		$this->add_product_to_cart();
		$this->prepare_ajax_request( 'browser-session' );
		$this->session_verifier->method( 'verify_session' )->willReturn( FraudDecision::Allow );
		$this->session_verifier->method( 'last_verified_session_id' )->willReturn( 'resolved-session' );
		$created = $this->get_reference( $this->dispatch_express_request() );

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
			'another reference' => array( 'another-reference', false ),
			'no reference'      => array( '', false ),
			'changed cart'      => array( null, true ),
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
	 * Set up the POST body and the AJAX context of an express request.
	 *
	 * @param string $session_id The session ID the browser adds.
	 */
	private function prepare_ajax_request( string $session_id ): void {
		$nonce                = wp_create_nonce( 'fp_example_express' );
		$_POST                = array(
			'security'                        => $nonce,
			'fp_example_wallet'               => 'apple_pay',
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
	 * Dispatch the express request through its WooCommerce AJAX hook and decode the JSON answer.
	 *
	 * @return array{success: bool, data: array<string, mixed>}
	 */
	private function dispatch_express_request(): array {
		ob_start();
		try {
			// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Test invokes the hook.
			do_action( 'wc_ajax_' . \FP_Example_Fraud_Protection::AJAX_ACTION );
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
	 * Read the payment reference from an express response's redirect URL.
	 *
	 * @param array{success: bool, data: array<string, mixed>} $response The express response.
	 * @return string
	 */
	private function get_reference( array $response ): string {
		$query = array();
		wp_parse_str( (string) wp_parse_url( (string) ( $response['data']['redirect'] ?? '' ), PHP_URL_QUERY ), $query );

		return (string) ( $query[ \FP_Example_Fraud_Protection::EXPRESS_REFERENCE_FIELD ] ?? '' );
	}

	/**
	 * Run the plugin's skip filter for a final checkout with the example gateway.
	 *
	 * The checkout submits a new session ID, as the browser does after the express request.
	 *
	 * @param string $reference The express reference the checkout posts; empty for none.
	 * @return mixed
	 */
	private function apply_skip_filter( string $reference ) {
		$payment_data = '' === $reference ? array() : array( \FP_Example_Fraud_Protection::EXPRESS_REFERENCE_FIELD => $reference );

		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Test invokes the hook.
		return apply_filters(
			'woocommerce_fraud_protection_skip_session_verify',
			false,
			'shortcode_checkout',
			array(
				'payment_method' => \WC_Gateway_FP_Example::ID,
				'payment_data'   => $payment_data,
			),
			'checkout-session'
		);
	}
}
