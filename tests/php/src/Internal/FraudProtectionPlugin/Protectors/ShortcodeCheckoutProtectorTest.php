<?php
/**
 * ShortcodeCheckoutProtectorTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin\Protectors;

use Automattic\WooCommerce\FraudProtection\BlackboxScriptHandler;
use Automattic\WooCommerce\FraudProtection\Schemas\FraudDecision;
use Automattic\WooCommerce\FraudProtection\BlockedSessionMessage;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\ClassicFormDataExtractionTrait;
use Automattic\WooCommerce\FraudProtection\SessionVerifier;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Protectors\ShortcodeCheckoutProtector;
use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;

/**
 * Tests for the ShortcodeCheckoutProtector class.
 *
 * @covers \Automattic\WooCommerce\Internal\FraudProtectionPlugin\Protectors\ShortcodeCheckoutProtector
 */
class ShortcodeCheckoutProtectorTest extends FraudProtectionUnitTestCase {

	/**
	 * The System Under Test.
	 *
	 * @var ShortcodeCheckoutProtector
	 */
	private ShortcodeCheckoutProtector $sut;

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
	 * Product used by checkout render tests.
	 *
	 * @var \WC_Product|null
	 */
	private $product;

	/**
	 * Checkout options before each test.
	 *
	 * @var array<string, array{exists: bool, value: mixed}>
	 */
	private array $original_checkout_options = array();

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->remember_checkout_option( 'woocommerce_enable_guest_checkout' );
		$this->remember_checkout_option( 'woocommerce_enable_signup_and_login_from_checkout' );

		$this->session_verifier       = $this->createMock( SessionVerifier::class );
		$this->blocked_session_message = $this->createMock( BlockedSessionMessage::class );
		$this->blackbox_script_handler = $this->createMock( BlackboxScriptHandler::class );

		$this->blocked_session_message
			->method( 'get_plaintext' )
			->willReturn( 'We are unable to process this request online. Please contact support (test@example.com) to complete your purchase.' );

		$this->sut = new ShortcodeCheckoutProtector();
		$this->sut->init(
			$this->session_verifier,
			$this->blocked_session_message,
			$this->blackbox_script_handler
		);

		wc_clear_notices();
	}

	/**
	 * Tear down after each test.
	 */
	public function tearDown(): void {
		$_POST = array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		WC()->cart->empty_cart();
		wc_clear_notices();

		remove_all_actions( 'woocommerce_after_checkout_validation' );
		remove_all_actions( 'woocommerce_checkout_process' );
		remove_action( 'woocommerce_checkout_before_order_review', array( $this->sut, 'enqueue_shortcode_checkout_script' ), 10 );
		remove_all_filters( 'woocommerce_add_error' );
		$this->reset_fraud_protection_scripts();
		$this->restore_checkout_options();

		if ( $this->product instanceof \WC_Product ) {
			$this->product->delete( true );
			$this->product = null;
		}

		parent::tearDown();
	}

	/**
	 * Remember a checkout option so the test can restore it.
	 *
	 * @param string $option_name Option name.
	 */
	private function remember_checkout_option( string $option_name ): void {
		$missing = new \stdClass();
		$value   = get_option( $option_name, $missing );

		$this->original_checkout_options[ $option_name ] = array(
			'exists' => $missing !== $value,
			'value'  => $value,
		);
	}

	/**
	 * Restore checkout options changed by a test.
	 */
	private function restore_checkout_options(): void {
		foreach ( $this->original_checkout_options as $option_name => $original ) {
			if ( $original['exists'] ) {
				update_option( $option_name, $original['value'] );
			} else {
				delete_option( $option_name );
			}
		}
	}

	/*
	|--------------------------------------------------------------------------
	| enqueue_shortcode_checkout_script() Tests
	|--------------------------------------------------------------------------
	*/

	/**
	 * @testdox enqueue_shortcode_checkout_script() enqueues its script when the shared scripts are available.
	 */
	public function test_enqueue_shortcode_checkout_script_when_shared_scripts_are_available(): void {
		$this->blackbox_script_handler->expects( $this->once() )->method( 'request_scripts' )->willReturn( true );

		$this->sut->enqueue_shortcode_checkout_script();

		$this->assertTrue( wp_script_is( 'wc-fraud-protection-shortcode-checkout', 'enqueued' ) );
		$script = wp_scripts()->query( 'wc-fraud-protection-shortcode-checkout', 'registered' );
		$this->assertNotFalse( $script );
		$this->assertSame(
			plugins_url( 'assets/js/shortcode-checkout.js', WC_FRAUD_PROTECTION_PLUGIN_FILE ),
			$script->src
		);
		$this->assertContains( 'wc-fraud-protection-blackbox-init', $script->deps );
	}

	/**
	 * @testdox enqueue_shortcode_checkout_script() does not enqueue when the shared scripts are unavailable.
	 */
	public function test_enqueue_shortcode_checkout_script_skips_when_shared_scripts_are_unavailable(): void {
		$this->blackbox_script_handler->expects( $this->once() )->method( 'request_scripts' )->willReturn( false );

		$this->sut->enqueue_shortcode_checkout_script();

		$this->assertFalse( wp_script_is( 'wc-fraud-protection-shortcode-checkout', 'enqueued' ) );
	}

	/**
	 * @testdox The real checkout shortcode enqueues the protector only after its form opens.
	 */
	public function test_checkout_shortcode_form_render_enqueues_protector(): void {
		$this->mock_jetpack_blog_id( 12345 );
		$this->sut->init(
			$this->session_verifier,
			$this->blocked_session_message,
			$this->make_blackbox_script_handler()
		);
		update_option( 'woocommerce_enable_guest_checkout', 'yes' );
		$this->add_product_to_cart();
		$this->sut->register();

		$this->render_checkout_shortcode();

		$this->assertTrue( wp_script_is( 'wc-fraud-protection-shortcode-checkout', 'enqueued' ) );
	}

	/**
	 * @testdox An empty checkout shortcode does not request shared or protector scripts.
	 */
	public function test_empty_cart_checkout_does_not_enqueue_scripts(): void {
		$this->blackbox_script_handler->expects( $this->never() )->method( 'request_scripts' );
		$this->sut->register();

		$this->render_checkout_shortcode();

		$this->assertFalse( wp_script_is( 'wc-fraud-protection-blackbox-init', 'enqueued' ) );
		$this->assertFalse( wp_script_is( 'wc-fraud-protection-shortcode-checkout', 'enqueued' ) );
	}

	/**
	 * @testdox Checkout cart errors return before the form hook and enqueue no scripts.
	 */
	public function test_cart_error_checkout_does_not_enqueue_scripts(): void {
		$this->blackbox_script_handler->expects( $this->never() )->method( 'request_scripts' );
		$this->add_product_to_cart();
		$add_cart_error = function (): void {
			wc_add_notice( 'Cart error.', 'error' );
		};
		add_action( 'woocommerce_check_cart_items', $add_cart_error );
		$this->sut->register();

		try {
			$this->render_checkout_shortcode();
		} finally {
			remove_action( 'woocommerce_check_cart_items', $add_cart_error );
		}

		$this->assertFalse( wp_script_is( 'wc-fraud-protection-blackbox-init', 'enqueued' ) );
		$this->assertFalse( wp_script_is( 'wc-fraud-protection-shortcode-checkout', 'enqueued' ) );
	}

	/**
	 * @testdox Registration-required anonymous checkout returns before the form hook and enqueues no scripts.
	 */
	public function test_registration_required_checkout_does_not_enqueue_scripts(): void {
		$this->blackbox_script_handler->expects( $this->never() )->method( 'request_scripts' );
		update_option( 'woocommerce_enable_guest_checkout', 'no' );
		update_option( 'woocommerce_enable_signup_and_login_from_checkout', 'no' );
		wp_set_current_user( 0 );
		$this->add_product_to_cart();
		$this->sut->register();

		$this->render_checkout_shortcode();

		$this->assertFalse( wp_script_is( 'wc-fraud-protection-blackbox-init', 'enqueued' ) );
		$this->assertFalse( wp_script_is( 'wc-fraud-protection-shortcode-checkout', 'enqueued' ) );
	}

	/**
	 * Add a simple product to the test cart.
	 */
	private function add_product_to_cart(): void {
		$this->product = \WC_Helper_Product::create_simple_product();
		WC()->cart->add_to_cart( $this->product->get_id() );
	}

	/**
	 * Render WooCommerce's checkout shortcode and discard its HTML.
	 */
	private function render_checkout_shortcode(): void {
		ob_start();
		\WC_Shortcode_Checkout::output( array() );
		ob_end_clean();
	}

	/**
	 * Run WC core's checkout validation, which fires woocommerce_after_checkout_validation
	 * (where the SUT is hooked) with whatever errors core produced.
	 *
	 * @param array $data Posted checkout data.
	 * @return \WP_Error The errors produced by core validation.
	 */
	private function run_checkout_validation( array $data ): \WP_Error {
		WC()->cart->empty_cart();

		// ship_to_different_address is always posted by the real checkout form.
		$data = array_merge( array( 'ship_to_different_address' => false ), $data );

		// phpcs:disable Generic.CodeAnalysis, Squiz.Commenting
		$checkout = new class() extends \WC_Checkout {
			public function validate_checkout( &$data, &$errors ): void {
				parent::validate_checkout( $data, $errors );
			}
		};
		// phpcs:enable Generic.CodeAnalysis, Squiz.Commenting

		$errors = new \WP_Error();
		$checkout->validate_checkout( $data, $errors );

		return $errors;
	}

	/**
	 * @testdox ShortcodeCheckoutProtector uses ClassicFormDataExtractionTrait.
	 */
	public function test_uses_classic_form_data_extraction_trait(): void {
		$this->assertContains(
			ClassicFormDataExtractionTrait::class,
			class_uses( ShortcodeCheckoutProtector::class )
		);
	}

	/*
	|--------------------------------------------------------------------------
	| register() Tests
	|--------------------------------------------------------------------------
	*/

	/**
	 * @testdox register() waits for checkout processing and hooks the checkout form render signal.
	 */
	public function test_register_hooks(): void {
		$this->sut->register();

		$this->assertSame(
			PHP_INT_MAX,
			has_action( 'woocommerce_checkout_process', array( $this->sut, 'register_checkout_validation_verifier' ) ),
			'woocommerce_checkout_process hook should be registered at PHP_INT_MAX'
		);
		$this->assertFalse(
			has_action( 'woocommerce_after_checkout_validation', array( $this->sut, 'verify_and_block' ) ),
			'Validation verification should not be registered before checkout processing starts'
		);
		$this->assertSame(
			10,
			has_action( 'woocommerce_checkout_before_order_review', array( $this->sut, 'enqueue_shortcode_checkout_script' ) )
		);
		$this->assertFalse( has_action( 'wp_enqueue_scripts', array( $this->sut, 'enqueue_shortcode_checkout_script' ) ) );
	}

	/*
	|--------------------------------------------------------------------------
	| verify_and_block() Tests
	|--------------------------------------------------------------------------
	*/

	/**
	 * @testdox verify_and_block() passes session_id and request_data to SessionVerifier, allows on ALLOW.
	 * @dataProvider checkout_data_with_expected_request_data_provider
	 *
	 * @param mixed $posted_data          Checkout data supplied to the method.
	 * @param array $expected_request_data Expected request data.
	 */
	public function test_verify_allows_on_allow_decision( mixed $posted_data, array $expected_request_data ): void {
		$_POST['wc_fraud_protection_session_id'] = 'test-session-123';
		$_POST['gateway_token']                    = 'test-token';

		$this->session_verifier
			->expects( $this->once() )
			->method( 'verify_session' )
			->with(
				'test-session-123',
				'shortcode_checkout',
				0,
				$expected_request_data
			)
			->willReturn( FraudDecision::Allow );

		$errors = new \WP_Error();
		$this->sut->verify_and_block( $posted_data, $errors );

		$this->assertEmpty( $errors->get_error_codes() );
	}

	/**
	 * @testdox verify_and_block() adds error on BLOCK decision.
	 * @dataProvider checkout_data_provider
	 *
	 * @param mixed $posted_data Checkout data supplied to the method.
	 */
	public function test_verify_adds_error_on_block_decision( mixed $posted_data ): void {
		$_POST['wc_fraud_protection_session_id'] = 'test-session-456';

		$this->session_verifier
			->expects( $this->once() )
			->method( 'verify_session' )
			->willReturn( FraudDecision::Block );

		$errors = new \WP_Error();
		$this->sut->verify_and_block( $posted_data, $errors );

		$this->assertContains( 'woocommerce_checkout_error', $errors->get_error_codes() );
		$this->assertStringContainsString(
			'We are unable to process this request online',
			$errors->get_error_message( 'woocommerce_checkout_error' )
		);
	}

	/**
	 * @testdox Direct checkout validation before checkout processing does not verify.
	 */
	public function test_direct_validation_before_checkout_process_does_not_verify(): void {
		$this->session_verifier
			->expects( $this->never() )
			->method( 'verify_session' );

		$this->sut->register();

		$errors = $this->run_checkout_validation( array( 'billing_country' => 'US' ) );

		$this->assertEmpty( $errors->get_error_codes(), 'Direct validation should remain valid without verification.' );
	}

	/**
	 * @testdox verify_and_block() skips verification when core checkout validation already failed.
	 * @dataProvider checkout_data_provider
	 *
	 * @param mixed $posted_data Checkout data supplied to the method.
	 */
	public function test_skips_verify_when_checkout_validation_already_failed( mixed $posted_data ): void {
		$this->session_verifier
			->expects( $this->never() )
			->method( 'verify_session' );

		$errors = $this->run_checkout_validation( array( 'billing_country' => 'XX' ) );
		$this->sut->verify_and_block( $posted_data, $errors );

		$this->assertNotEmpty(
			$errors->get_error_codes(),
			'Core validation should fail for an invalid country, so verification must be skipped'
		);
	}

	/**
	 * Checkout data and expected request data.
	 *
	 * @return array<string, array{mixed, array<string, mixed>}>
	 */
	public function checkout_data_with_expected_request_data_provider(): array {
		$normalized_request_data = array(
			'payment_method' => '',
			'payment_data'   => array( 'gateway_token' => 'test-token' ),
		);

		return array(
			'array'   => array(
				array(
					'billing_first_name' => 'Bob',
					'payment_method'     => 'stripe',
				),
				array(
					'payment_method'  => 'stripe',
					'payment_data'    => array( 'gateway_token' => 'test-token' ),
					'billing_address' => array( 'first_name' => 'Bob' ),
				),
			),
			'null'    => array( null, $normalized_request_data ),
			'string'  => array( 'invalid', $normalized_request_data ),
			'integer' => array( 1, $normalized_request_data ),
			'boolean' => array( true, $normalized_request_data ),
			'object'  => array( new \stdClass(), $normalized_request_data ),
		);
	}

	/**
	 * Checkout data values.
	 *
	 * @return array<string, array{mixed}>
	 */
	public function checkout_data_provider(): array {
		return array(
			'array'   => array(
				array(
					'billing_first_name' => 'Bob',
					'payment_method'     => 'stripe',
				),
			),
			'null'    => array( null ),
			'string'  => array( 'invalid' ),
			'integer' => array( 1 ),
			'boolean' => array( true ),
			'object'  => array( new \stdClass() ),
		);
	}

	/**
	 * @testdox verify_and_block() skips verification when a woocommerce_checkout_process validator added an error notice.
	 */
	public function test_skips_verify_when_checkout_process_added_error_notice(): void {
		$this->session_verifier
			->expects( $this->never() )
			->method( 'verify_session' );

		$this->sut->register();

		// Mirrors how WCPay/Subscriptions validate: an error notice, not $errors.
		add_action(
			'woocommerce_checkout_process',
			function (): void {
				wc_add_notice( 'Mobile number is required.', 'error' );
			}
		);
		do_action( 'woocommerce_checkout_process' );

		// Valid data, so the only blocking signal is the notice above.
		$this->run_checkout_validation( array( 'billing_country' => 'US' ) );

		$this->assertSame(
			1,
			wc_notice_count( 'error' ),
			'The checkout_process error notice should be present and block verification'
		);
	}

	/**
	 * @testdox verify_and_block() still verifies when a pre-existing error has an empty message (non-blocking in core).
	 */
	public function test_runs_verify_when_pre_existing_error_has_empty_message(): void {
		$this->session_verifier
			->expects( $this->once() )
			->method( 'verify_session' )
			->willReturn( FraudDecision::Allow );

		// A message-less WP_Error entry does not block order creation in core
		// (wc_add_notice drops empty messages), so verification must still run.
		add_action(
			'woocommerce_after_checkout_validation',
			function ( $data, \WP_Error $errors ): void {
				$errors->add( 'soft_signal', '' );
			},
			10,
			2
		);

		$this->sut->register();
		do_action( 'woocommerce_checkout_process' );

		$errors = $this->run_checkout_validation( array( 'billing_country' => 'US' ) );

		$this->assertNotEmpty(
			$errors->get_error_codes(),
			'The empty-message error should be present but must not block verification'
		);
	}

	/**
	 * @testdox verify_and_block() still verifies when an error message is filtered to empty (non-blocking in core).
	 */
	public function test_runs_verify_when_error_message_filtered_to_empty(): void {
		$this->session_verifier
			->expects( $this->once() )
			->method( 'verify_session' )
			->willReturn( FraudDecision::Allow );

		// Core runs each error message through woocommerce_add_error before deciding
		// whether to store an error notice. A filter that blanks the message means the
		// order is still created, so verification must run despite the raw $errors entry.
		add_filter( 'woocommerce_add_error', '__return_empty_string' );

		$this->sut->register();
		do_action( 'woocommerce_checkout_process' );

		$errors = $this->run_checkout_validation( array( 'billing_country' => 'XX' ) );

		$this->assertNotEmpty(
			$errors->get_error_messages(),
			'The raw error message is present, but the filter blanks it so it must not block verification'
		);
	}

	/**
	 * @testdox Checkout processing followed by core validation verifies once.
	 */
	public function test_checkout_process_then_validation_verifies_once(): void {
		$_POST['wc_fraud_protection_session_id'] = 'checkout-session';

		$this->session_verifier
			->expects( $this->once() )
			->method( 'verify_session' )
			->with(
				'checkout-session',
				'shortcode_checkout',
				0,
				$this->isType( 'array' )
			)
			->willReturn( FraudDecision::Allow );

		$this->sut->register();
		do_action( 'woocommerce_checkout_process' );

		$this->assertSame(
			PHP_INT_MAX,
			has_action( 'woocommerce_after_checkout_validation', array( $this->sut, 'verify_and_block' ) ),
			'Validation verification should be registered at PHP_INT_MAX'
		);

		$errors = $this->run_checkout_validation( array( 'billing_country' => 'US' ) );

		$this->assertEmpty(
			$errors->get_error_codes(),
			'Core validation should pass for a valid country, so verification must run'
		);
	}

	/**
	 * @testdox Checkout processing skips an integer form key and verifies with valid request data.
	 */
	public function test_checkout_process_skips_integer_key_and_verifies_with_valid_data(): void {
		parse_str( '0=bad&wc_fraud_protection_session_id=checkout-session&gateway_token=valid', $_POST );

		$this->session_verifier
			->expects( $this->once() )
			->method( 'verify_session' )
			->with(
				'checkout-session',
				'shortcode_checkout',
				0,
				array(
					'payment_method'  => '',
					'payment_data'    => array( 'gateway_token' => 'valid' ),
					'billing_address' => array( 'country' => 'US' ),
				)
			)
			->willReturn( FraudDecision::Allow );

		$this->sut->register();
		do_action( 'woocommerce_checkout_process' );

		$errors = $this->run_checkout_validation( array( 'billing_country' => 'US' ) );

		$this->assertEmpty( $errors->get_error_codes() );
	}

}
