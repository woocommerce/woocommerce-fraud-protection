<?php
/**
 * ExampleSubscriptionsIntegrationTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Examples;

require_once dirname( __DIR__, 3 ) . '/examples/fraud-protection-example-subscriptions/includes/class-fp-example-change-payment.php';

use Automattic\WooCommerce\FraudProtection\BlockedSessionMessage;
use Automattic\WooCommerce\FraudProtection\Schemas\FraudDecision;
use Automattic\WooCommerce\FraudProtection\SessionVerifier;
use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Compat\ExtensionNativeSupport;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Compat\SubscriptionsChangePaymentCompat;

// phpcs:disable Squiz.Classes.ClassFileName.NoMatch
/**
 * Exception thrown when wp_redirect is intercepted in tests.
 */
class ExampleRedirectInterceptedException extends \Exception {}
// phpcs:enable Squiz.Classes.ClassFileName.NoMatch

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound
/**
 * Exercises the example change-payment flow next to the plugin's Subscriptions compatibility layer.
 */
class ExampleSubscriptionsIntegrationTest extends FraudProtectionUnitTestCase {

	/**
	 * The example flow.
	 *
	 * @var \FP_Example_Change_Payment
	 */
	private \FP_Example_Change_Payment $flow;

	/**
	 * The plugin's compatibility layer, registered as in production.
	 *
	 * @var SubscriptionsChangePaymentCompat
	 */
	private SubscriptionsChangePaymentCompat $compat;

	/**
	 * Mock session verifier shared by the flow (through the container) and the compat layer.
	 *
	 * @var SessionVerifier&\PHPUnit\Framework\MockObject\MockObject
	 */
	private $session_verifier;

	/**
	 * The customer's order.
	 *
	 * @var \WC_Order
	 */
	private \WC_Order $order;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->session_verifier = $this->createMock( SessionVerifier::class );
		$GLOBALS['wc_container']->replace( SessionVerifier::class, $this->session_verifier );

		$this->compat = new SubscriptionsChangePaymentCompat();
		$this->compat->init( $this->session_verifier, new BlockedSessionMessage(), $this->make_blackbox_script_handler(), new ExtensionNativeSupport() );
		$this->compat->register();

		$this->flow = new \FP_Example_Change_Payment();
		$this->flow->register();

		$customer_id = $this->factory()->user->create( array( 'role' => 'customer' ) );
		wp_set_current_user( $customer_id );
		$this->order = \WC_Helper_Order::create_order( $customer_id );
		$this->order->set_payment_method( 'bacs' );
		$this->order->save();
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		$GLOBALS['wc_container']->reset_replacement( SessionVerifier::class );
		remove_shortcode( 'fp_example_change_payment' );
		remove_action( 'wp', array( $this->flow, 'handle_submission' ), 20 );
		remove_all_filters( 'woocommerce_fraud_protection_native_integrations' );
		remove_all_actions( 'woocommerce_subscription_change_payment_method_via_pay_shortcode' );
		remove_all_actions( 'woocommerce_subscriptions_change_payment_after_submit' );
		remove_all_filters( 'wp_redirect' );
		wc_clear_notices();
		$_POST = array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		\WC_Helper_Order::delete_order( $this->order->get_id() );
		wp_set_current_user( 0 );
		$this->reset_fraud_protection_scripts();

		parent::tearDown();
	}

	/**
	 * @testdox The form loads the pay-form protector through the public script request.
	 */
	public function test_form_render_loads_pay_for_order_script(): void {
		$this->mock_jetpack_blog_id( 12345 );

		$html = do_shortcode( '[fp_example_change_payment]' );

		$this->assertStringContainsString( 'id="order_review"', $html );
		$this->assertTrue( wp_script_is( 'wc-fraud-protection-blackbox-init', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'wc-fraud-protection-pay-for-order', 'enqueued' ) );
	}

	/**
	 * @testdox An allowed submission is verified exactly once, by the flow itself, and changes the payment method.
	 */
	public function test_allowed_submission_is_verified_once_by_the_flow(): void {
		$this->intercept_redirect();
		$_POST = array(
			'payment_method'                  => 'cod',
			SessionVerifier::SESSION_ID_FIELD => 'browser-session',
		);
		$this->session_verifier->expects( $this->once() )
			->method( 'verify_session' )
			->with( 'browser-session', \FP_Example_Change_Payment::SOURCE, $this->order->get_id(), $this->callback( fn( array $data ): bool => 'cod' === $data['payment_method'] ) )
			->willReturn( FraudDecision::Allow );

		$redirect = $this->run_change_payment();

		$this->assertSame( $this->order->get_view_order_url(), $redirect );
		$this->assertSame( 'cod', wc_get_order( $this->order->get_id() )->get_payment_method() );
		$this->assertSame( 0, wc_notice_count( 'error' ) );
		$this->assertLogged( 'info', 'An extension declares native Fraud Protection support' );
	}

	/**
	 * @testdox A blocked submission shows the blocked notice and leaves the payment method unchanged.
	 */
	public function test_blocked_submission_is_stopped(): void {
		$this->intercept_redirect();
		$_POST = array(
			'payment_method'                  => 'cod',
			SessionVerifier::SESSION_ID_FIELD => 'browser-session',
		);
		$this->session_verifier->expects( $this->once() )->method( 'verify_session' )->willReturn( FraudDecision::Block );

		$redirect = $this->run_change_payment();

		$this->assertSame( $this->order->get_view_order_url(), $redirect );
		$this->assertSame( 'bacs', wc_get_order( $this->order->get_id() )->get_payment_method() );
		$this->assertSame( 1, wc_notice_count( 'error' ) );
	}

	/**
	 * Run the flow's change-payment handler and return the redirect it attempts.
	 *
	 * @return string
	 */
	private function run_change_payment(): string {
		try {
			$this->flow->change_payment_method( $this->order );
		} catch ( ExampleRedirectInterceptedException $e ) {
			return $e->getMessage();
		}

		$this->fail( 'The flow should redirect' );
	}

	/**
	 * Intercept wp_redirect so the exit after it is never reached.
	 */
	private function intercept_redirect(): void {
		add_filter( // @phpstan-ignore return.missing
			'wp_redirect',
			static function ( string $location ): string {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The test inspects this redirect value through the exception.
				throw new ExampleRedirectInterceptedException( $location );
			}
		);
	}
}
// phpcs:enable Generic.Files.OneObjectStructurePerFile.MultipleFound
