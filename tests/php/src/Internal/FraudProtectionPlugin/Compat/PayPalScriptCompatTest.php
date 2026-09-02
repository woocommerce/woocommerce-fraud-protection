<?php
/**
 * PayPalScriptCompatTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin\Compat;

use Automattic\WooCommerce\Blocks\BlockTypes\AbstractBlock;
use Automattic\WooCommerce\FraudProtection\BlackboxScriptHandler;
use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Compat\PayPalScriptCompat;

/**
 * Tests for the PayPalScriptCompat class.
 *
 * @covers \Automattic\WooCommerce\Internal\FraudProtectionPlugin\Compat\PayPalScriptCompat
 */
class PayPalScriptCompatTest extends FraudProtectionUnitTestCase {

	/** @var PayPalScriptCompat */
	private PayPalScriptCompat $sut;

	private bool $touched_smart_button_handle = false;

	private bool $registered_block_handle = false;

	private bool $touched_add_payment_method_handle = false;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->sut = $this->make_compat_with_script_handler( $this->make_blackbox_script_handler() );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		if ( $this->touched_smart_button_handle ) {
			wp_dequeue_script( 'ppcp-smart-button' );
			wp_deregister_script( 'ppcp-smart-button' );
		}
		if ( $this->registered_block_handle ) {
			wp_dequeue_script( 'ppcp-checkout-block' );
			wp_deregister_script( 'ppcp-checkout-block' );
		}
		if ( $this->touched_add_payment_method_handle ) {
			wp_dequeue_script( 'ppcp-add-payment-method' );
			wp_deregister_script( 'ppcp-add-payment-method' );
		}
		$this->reset_fraud_protection_scripts();

		parent::tearDown();
	}

	/**
	 * Register PayPal script hooks.
	 */
	public function test_register_hooks(): void {
		$this->sut->register();

		foreach ( $this->paypal_button_render_hook_provider() as $case ) {
			$this->assertSame( 10, has_action( $case[0], array( $this->sut, 'enqueue_paypal_script' ) ) );
		}
		$this->assertSame( 20, has_action( 'woocommerce_blocks_enqueue_checkout_block_scripts_before', array( $this->sut, 'enqueue_paypal_block_script_if_registered' ) ) );
		$this->assertSame( 20, has_action( 'woocommerce_blocks_enqueue_cart_block_scripts_before', array( $this->sut, 'enqueue_paypal_cart_block_scripts_if_registered' ) ) );
		$this->assertSame( 10, has_action( 'woocommerce_before_mini_cart', array( $this->sut, 'enqueue_paypal_mini_cart_script_if_enabled' ) ) );
		$this->assertSame( 20, has_filter( 'woocommerce_widget_cart_is_hidden', array( $this->sut, 'enqueue_paypal_script_for_visible_mini_cart_widget' ) ) );
		$this->assertSame( 20, has_action( 'woocommerce_checkout_before_order_review', array( $this->sut, 'enqueue_paypal_script_if_smart_button_enqueued' ) ) );
		$this->assertSame( 20, has_action( 'before_woocommerce_pay_form', array( $this->sut, 'enqueue_paypal_script_if_smart_button_enqueued' ) ) );
		$this->assertSame( 20, has_action( 'woocommerce_add_payment_method_form_bottom', array( $this->sut, 'enqueue_paypal_script_for_add_payment_method' ) ) );
		$this->assertSame( 20, has_action( 'woocommerce_subscriptions_change_payment_after_submit', array( $this->sut, 'enqueue_paypal_script_if_add_payment_method_enqueued' ) ) );
	}
	/*
	|--------------------------------------------------------------------------
	| enqueue_paypal_script() Tests
	|--------------------------------------------------------------------------
	*/

	/**
	 * @testdox Each PayPal button render action requests the shared scripts and enqueues the interceptor.
	 *
	 * @dataProvider paypal_button_render_hook_provider
	 *
	 * @param string $hook PayPal button render action.
	 */
	public function test_button_render_hooks_enqueue_paypal_script( string $hook ): void {
		$this->mock_jetpack_blog_id( 12345 );
		$this->sut->register();

		do_action( $hook );

		$this->assertTrue( wp_script_is( 'wc-fraud-protection-blackbox-init', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'wc-fraud-protection-paypal-express', 'enqueued' ) );
	}

	/**
	 * @testdox A Cart block rendered from template content reaches the PayPal block follower.
	 */
	public function test_cart_block_template_render_enqueues_paypal_script(): void {
		$this->mock_jetpack_blog_id( 12345 );
		$this->register_paypal_block_handle();
		$this->sut->register();
		$previous_enqueue_state = $this->set_cart_block_enqueue_state( false );

		try {
			do_blocks( '<!-- wp:woocommerce/cart --><div class="wp-block-woocommerce-cart"></div><!-- /wp:woocommerce/cart -->' );

			$this->assertTrue( wp_script_is( 'wc-fraud-protection-blackbox-init', 'enqueued' ) );
			$this->assertTrue( wp_script_is( 'wc-fraud-protection-paypal-express', 'enqueued' ) );
			$this->assertTrue( wp_script_is( 'wc-fraud-protection-blocks-checkout', 'enqueued' ) );
		} finally {
			$this->set_cart_block_enqueue_state( $previous_enqueue_state );
		}
	}

	/**
	 * @testdox A Cart block without the PayPal block integration queues no fraud scripts.
	 */
	public function test_cart_block_template_render_without_paypal_skips_scripts(): void {
		$this->mock_jetpack_blog_id( 12345 );
		$this->sut->register();
		$previous_enqueue_state = $this->set_cart_block_enqueue_state( false );

		try {
			do_blocks( '<!-- wp:woocommerce/cart --><div class="wp-block-woocommerce-cart"></div><!-- /wp:woocommerce/cart -->' );

			$this->assertFalse( wp_script_is( 'wc-fraud-protection-blackbox-init', 'enqueued' ) );
			$this->assertFalse( wp_script_is( 'wc-fraud-protection-paypal-express', 'enqueued' ) );
			$this->assertFalse( wp_script_is( 'wc-fraud-protection-blocks-checkout', 'enqueued' ) );
		} finally {
			$this->set_cart_block_enqueue_state( $previous_enqueue_state );
		}
	}

	/**
	 * PayPal button actions that prove the wrapper rendered.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function paypal_button_render_hook_provider(): array {
		return array(
			'single product' => array( 'woocommerce_paypal_payments_single_product_button_render' ),
			'cart'           => array( 'woocommerce_paypal_payments_cart_button_render' ),
			'checkout'       => array( 'woocommerce_paypal_payments_checkout_button_render' ),
			'pay for order'  => array( 'woocommerce_paypal_payments_payorder_button_render' ),
			'mini-cart'      => array( 'woocommerce_paypal_payments_minicart_button_render' ),
		);
	}

	/**
	 * @testdox The block follower uses PayPal's registered block integration as its early signal.
	 */
	public function test_block_follower_enqueues_when_paypal_block_integration_is_registered(): void {
		$this->register_paypal_block_handle();
		$sut = $this->make_sut_expecting_script_request( true );

		$sut->enqueue_paypal_block_script_if_registered();

		$this->assertTrue( wp_script_is( 'wc-fraud-protection-paypal-express', 'enqueued' ) );
	}

	/**
	 * @testdox The block follower ignores another PPCP gateway without the PayPal block integration.
	 */
	public function test_block_follower_skips_other_ppcp_gateway_without_paypal_block_integration(): void {
		$gateway     = $this->getMockBuilder( \WC_Payment_Gateway::class )
			->disableOriginalConstructor()
			->getMock();
		$gateway->id = 'ppcp-applepay';
		$add_gateway = function ( array $gateways ) use ( $gateway ): array {
			$gateways[ $gateway->id ] = $gateway;
			return $gateways;
		};
		$sut = $this->make_sut_expecting_no_script_request();
		add_filter( 'woocommerce_available_payment_gateways', $add_gateway );

		try {
			$sut->enqueue_paypal_block_script_if_registered();
		} finally {
			remove_filter( 'woocommerce_available_payment_gateways', $add_gateway );
		}

		$this->assertFalse( wp_script_is( 'wc-fraud-protection-paypal-express', 'enqueued' ) );
		$this->assertFalse( wp_script_is( 'wc-fraud-protection-blocks-checkout', 'enqueued' ) );
	}

	/**
	 * @testdox The standard-form follower prepares for a PayPal button that can become eligible later.
	 */
	public function test_standard_form_follower_uses_enqueued_smart_button_without_gateway_snapshot(): void {
		$this->register_and_enqueue_paypal_smart_button();
		$sut = $this->make_sut_expecting_script_request( true );

		$sut->enqueue_paypal_script_if_smart_button_enqueued();

		$this->assertTrue( wp_script_is( 'wc-fraud-protection-paypal-express', 'enqueued' ) );
	}

	/**
	 * @testdox The standard-form follower requires PayPal's smart-button handle to be registered and enqueued.
	 *
	 * @dataProvider unavailable_smart_button_provider
	 *
	 * @param bool $registered Whether the handle is registered.
	 * @param bool $enqueued   Whether the handle is enqueued.
	 */
	public function test_standard_form_follower_requires_registered_and_enqueued_smart_button( bool $registered, bool $enqueued ): void {
		$this->configure_paypal_smart_button( $registered, $enqueued );
		$sut = $this->make_sut_expecting_no_script_request();

		$sut->enqueue_paypal_script_if_smart_button_enqueued();

		$this->assertFalse( wp_script_is( 'wc-fraud-protection-paypal-express', 'enqueued' ) );
	}

	/**
	 * Unavailable smart-button handle states.
	 *
	 * @return array<string, array{bool, bool}>
	 */
	public function unavailable_smart_button_provider(): array {
		return array(
			'not registered' => array( false, true ),
			'not enqueued'   => array( true, false ),
		);
	}

	/**
	 * @testdox The block follower skips Checkout endpoint fallbacks.
	 *
	 * @dataProvider checkout_endpoint_provider
	 *
	 * @param string $endpoint Endpoint query variable.
	 */
	public function test_paypal_block_follower_skips_checkout_endpoint( string $endpoint ): void {
		global $wp;

		$sut = $this->make_sut_expecting_no_script_request();
		$wp->query_vars[ $endpoint ] = '123';

		$sut->enqueue_paypal_block_script_if_registered();

		$this->assertFalse( wp_script_is( 'wc-fraud-protection-paypal-express', 'enqueued' ) );
	}

	/**
	 * @testdox The mini-cart follower loads when PayPal enables and enqueues its fragment-aware script.
	 */
	public function test_mini_cart_follower_enqueues_for_paypal_fragment_script(): void {
		$this->configure_paypal_mini_cart( true, true, true );
		$sut = $this->make_sut_expecting_script_request( true );

		$sut->enqueue_paypal_mini_cart_script_if_enabled();

		$this->assertTrue( wp_script_is( 'wc-fraud-protection-paypal-express', 'enqueued' ) );
	}

	/**
	 * @testdox A visible classic cart widget prepares the page for PayPal fragments without changing visibility.
	 */
	public function test_visible_cart_widget_prepares_paypal_fragments(): void {
		$this->configure_paypal_mini_cart( true, true, true );
		$sut = $this->make_sut_expecting_script_request( true );

		$this->assertFalse( $sut->enqueue_paypal_script_for_visible_mini_cart_widget( false ) );
		$this->assertTrue( wp_script_is( 'wc-fraud-protection-paypal-express', 'enqueued' ) );
	}

	/**
	 * @testdox A hidden classic cart widget stays hidden and requests no scripts.
	 */
	public function test_hidden_cart_widget_requests_nothing(): void {
		$sut = $this->make_sut_expecting_no_script_request();

		$this->assertTrue( $sut->enqueue_paypal_script_for_visible_mini_cart_widget( true ) );
		$this->assertFalse( wp_script_is( 'wc-fraud-protection-paypal-express', 'enqueued' ) );
	}

	/**
	 * @testdox Malformed cart widget visibility values pass through without requesting scripts.
	 *
	 * This filter accepts mixed extension input, so invalid values must remain
	 * unchanged instead of being coerced into widget visibility.
	 *
	 * @dataProvider malformed_cart_widget_visibility_provider
	 *
	 * @param mixed $value Malformed filter value.
	 */
	public function test_malformed_cart_widget_visibility_passes_through_without_scripts( $value ): void {
		$sut = $this->make_sut_expecting_no_script_request();
		$result = $sut->enqueue_paypal_script_for_visible_mini_cart_widget( $value );

		$this->assertSame( $value, $result );
		$this->assertFalse( wp_script_is( 'wc-fraud-protection-paypal-express', 'enqueued' ) );
	}

	/**
	 * Malformed cart widget visibility values.
	 *
	 * @return array<string, array{mixed}>
	 */
	public function malformed_cart_widget_visibility_provider(): array {
		return array(
			'integer' => array( 0 ),
			'array'  => array( array() ),
			'object' => array( new \stdClass() ),
		);
	}

	/**
	 * @testdox An empty real mini-cart requests one idempotent script stack before later PayPal fragments.
	 */
	public function test_empty_mini_cart_render_prepares_for_later_paypal_fragment(): void {
		WC()->cart->empty_cart();
		$this->mock_jetpack_blog_id( 12345 );
		$this->configure_paypal_mini_cart( true, true, true );
		$this->sut->register();

		ob_start();
		woocommerce_mini_cart();
		ob_end_clean();
		do_action( 'woocommerce_paypal_payments_minicart_button_render' );

		$this->assertSame( 1, array_count_values( wp_scripts()->queue )['wc-fraud-protection-blackbox'] );
		$this->assertSame( 1, array_count_values( wp_scripts()->queue )['wc-fraud-protection-blackbox-init'] );
		$this->assertSame( 1, array_count_values( wp_scripts()->queue )['wc-fraud-protection-paypal-express'] );
	}

	/**
	 * @testdox The mini-cart follower skips when its PayPal location or executable script is absent.
	 *
	 * @dataProvider unavailable_mini_cart_provider
	 *
	 * @param bool $location_enabled Whether the PayPal mini-cart setting is enabled.
	 * @param bool $script_registered Whether the PayPal smart-button script is registered.
	 * @param bool $script_enqueued   Whether the PayPal smart-button script is enqueued.
	 */
	public function test_mini_cart_follower_requires_paypal_location_and_script( bool $location_enabled, bool $script_registered, bool $script_enqueued ): void {
		$this->configure_paypal_mini_cart( $location_enabled, $script_registered, $script_enqueued );
		$sut = $this->make_sut_expecting_no_script_request();

		$sut->enqueue_paypal_mini_cart_script_if_enabled();

		$this->assertFalse( wp_script_is( 'wc-fraud-protection-paypal-express', 'enqueued' ) );
	}

	/**
	 * Unavailable classic mini-cart cases.
	 *
	 * @return array<string, array{bool, bool, bool}>
	 */
	public function unavailable_mini_cart_provider(): array {
		return array(
			'location disabled'    => array( false, true, true ),
			'script not registered' => array( true, false, true ),
			'script not enqueued'   => array( true, true, false ),
		);
	}

	/**
	 * @testdox PayPal version and setting precedence controls the mini-cart follower.
	 *
	 * @dataProvider mini_cart_setting_precedence_provider
	 */
	public function test_mini_cart_setting_precedence( string $version, ?bool $current_enabled, bool $legacy_enabled, bool $expected ): void {
		update_option( 'woocommerce-ppcp-version', $version );
		update_option( 'woocommerce-ppcp-settings', array( 'smart_button_locations' => $legacy_enabled ? array( 'mini-cart' ) : array() ) );
		if ( null === $current_enabled ) {
			delete_option( 'woocommerce-ppcp-data-styling' );
			$this->register_and_enqueue_paypal_smart_button();
		} else {
			$this->configure_paypal_mini_cart( $current_enabled, true, true );
			update_option( 'woocommerce-ppcp-version', $version );
		}
		$sut = $expected ? $this->make_sut_expecting_script_request( true ) : $this->make_sut_expecting_no_script_request();

		$sut->enqueue_paypal_mini_cart_script_if_enabled();

		$this->assertSame( $expected, wp_script_is( 'wc-fraud-protection-paypal-express', 'enqueued' ) );
	}

	/** @return array<string, array{string, ?bool, bool, bool}> */
	public function mini_cart_setting_precedence_provider(): array {
		return array(
			'current fallback to enabled legacy' => array( '4.0.0', null, true, true ),
			'current styling overrides legacy'   => array( '4.0.0', false, true, false ),
			'3.4 uses enabled legacy location'   => array( '3.4.1', false, true, true ),
			'3.4 uses disabled legacy location'  => array( '3.4.1', true, false, false ),
		);
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

	/**
	 * @testdox A named PayPal render action still skips the interceptor when shared scripts are unavailable.
	 */
	public function test_named_paypal_render_skips_when_shared_scripts_are_unavailable(): void {
		$sut = $this->make_sut_expecting_script_request( false );

		$sut->enqueue_paypal_script();

		$this->assertFalse( wp_script_is( 'wc-fraud-protection-paypal-express', 'enqueued' ) );
	}

	/**
	 * @testdox enqueue_paypal_script() uses the injected handler before it enqueues the interceptor.
	 */
	public function test_enqueue_paypal_script_uses_injected_handler(): void {
		$sut = $this->make_sut_expecting_script_request( true );

		$sut->enqueue_paypal_script();

		$this->assertTrue( wp_script_is( 'wc-fraud-protection-paypal-express', 'enqueued' ) );
		$script = wp_scripts()->query( 'wc-fraud-protection-paypal-express', 'registered' );
		$this->assertNotFalse( $script );
		$this->assertSame(
			plugins_url( 'assets/js/paypal-express.js', WC_FRAUD_PROTECTION_PLUGIN_FILE ),
			$script->src
		);
		$this->assertContains( 'wc-fraud-protection-blackbox-init', $script->deps );
	}

	/**
	 * Build a PayPal compatibility layer with a controlled script handler.
	 *
	 * @return PayPalScriptCompat
	 */
	private function make_compat_with_script_handler( BlackboxScriptHandler $handler ): PayPalScriptCompat {
		$sut = new PayPalScriptCompat();
		$sut->init( $handler );

		return $sut;
	}

	/** Build a compatibility layer that must request the shared scripts. */
	private function make_sut_expecting_script_request( bool $result ): PayPalScriptCompat {
		$handler = $this->createMock( BlackboxScriptHandler::class );
		$handler->expects( $this->once() )->method( 'request_scripts' )->willReturn( $result );

		return $this->make_compat_with_script_handler( $handler );
	}

	/** Build a compatibility layer that must not request the shared scripts. */
	private function make_sut_expecting_no_script_request(): PayPalScriptCompat {
		$handler = $this->createMock( BlackboxScriptHandler::class );
		$handler->expects( $this->never() )->method( 'request_scripts' );

		return $this->make_compat_with_script_handler( $handler );
	}

	/**
	 * Configure PayPal's current mini-cart setting and smart-button handle.
	 *
	 * @param bool $location_enabled Whether the mini-cart location is enabled.
	 * @param bool $script_registered Whether the smart-button handle is registered.
	 * @param bool $script_enqueued   Whether the smart-button handle is enqueued.
	 */
	private function configure_paypal_mini_cart( bool $location_enabled, bool $script_registered, bool $script_enqueued ): void {
		update_option( 'woocommerce-ppcp-version', '4.0.0' );
		$mini_cart          = new \stdClass();
		$mini_cart->enabled = $location_enabled;
		update_option( 'woocommerce-ppcp-data-styling', array( 'mini_cart' => $mini_cart ) );

		if ( $script_registered ) {
			wp_register_script( 'ppcp-smart-button', 'https://example.com/paypal-button.js', array(), '1.0', true );
			$this->touched_smart_button_handle = true;
		}

		if ( $script_enqueued ) {
			wp_enqueue_script( 'ppcp-smart-button' );
			$this->touched_smart_button_handle = true;
		}
	}

	/**
	 * Register and enqueue PayPal's smart-button handle.
	 */
	private function register_and_enqueue_paypal_smart_button(): void {
		$this->configure_paypal_smart_button( true, true );
	}

	/**
	 * Configure PayPal's smart-button handle state.
	 *
	 * @param bool $registered Whether the handle is registered.
	 * @param bool $enqueued   Whether the handle is enqueued.
	 */
	private function configure_paypal_smart_button( bool $registered, bool $enqueued ): void {
		if ( $registered ) {
			wp_register_script( 'ppcp-smart-button', 'https://example.com/paypal-button.js', array(), '1.0', true );
		}

		if ( $enqueued ) {
			wp_enqueue_script( 'ppcp-smart-button' );
		}

		$this->touched_smart_button_handle = $registered || $enqueued;
	}

	/**
	 * Register PayPal's Checkout block integration handle.
	 */
	private function register_paypal_block_handle(): void {
		wp_register_script( 'ppcp-checkout-block', 'https://example.com/paypal-block.js', array(), '1.0', true );
		$this->registered_block_handle = true;
	}

	/**
	 * Set WooCommerce's shared Cart block enqueue state.
	 *
	 * @param bool $state New enqueue state.
	 * @return bool Previous enqueue state.
	 */
	private function set_cart_block_enqueue_state( bool $state ): bool {
		$block_type = \WP_Block_Type_Registry::get_instance()->get_registered( 'woocommerce/cart' );
		$this->assertInstanceOf( \WP_Block_Type::class, $block_type );
		$this->assertIsCallable( $block_type->render_callback );
		$this->assertIsArray( $block_type->render_callback );
		$this->assertInstanceOf( AbstractBlock::class, $block_type->render_callback[0] );

		$property = new \ReflectionProperty( AbstractBlock::class, 'enqueued_assets' );
		$property->setAccessible( true );
		$previous = (bool) $property->getValue( $block_type->render_callback[0] );
		$property->setValue( $block_type->render_callback[0], $state );

		return $previous;
	}

	/** @testdox Subscriptions render requests the interceptor only for the active PayPal script. */
	public function test_subscriptions_render_requires_active_paypal_script(): void {
		$sut = $this->make_sut_expecting_script_request( true );
		$sut->register();
		do_action( 'woocommerce_subscriptions_change_payment_after_submit' );
		wp_register_script( 'ppcp-add-payment-method', 'https://example.com/add.js', array(), '1.0', true );
		do_action( 'woocommerce_subscriptions_change_payment_after_submit' );
		wp_enqueue_script( 'ppcp-add-payment-method' );
		$this->touched_add_payment_method_handle = true;

		do_action( 'woocommerce_subscriptions_change_payment_after_submit' );

		$this->assertTrue( wp_script_is( 'wc-fraud-protection-paypal-express', 'enqueued' ) );
	}

	/** @testdox My Account render requires the page and add-payment-method query variable. */
	public function test_my_account_render_requires_both_page_checks(): void {
		global $wp;

		$handler = $this->createMock( BlackboxScriptHandler::class );
		$handler->expects( $this->once() )->method( 'request_scripts' )->willReturn( true );
		$sut = $this->make_compat_with_script_handler( $handler );
		$page_id = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$previous_page_id       = get_option( 'woocommerce_myaccount_page_id', null );
		$had_endpoint_query_var = array_key_exists( 'add-payment-method', $wp->query_vars );
		$previous_query_var     = $wp->query_vars['add-payment-method'] ?? null;
		try {
			update_option( 'woocommerce_myaccount_page_id', $page_id );
			wp_register_script( 'ppcp-add-payment-method', 'https://example.com/add.js', array(), '1.0', true );
			wp_enqueue_script( 'ppcp-add-payment-method' );
			$this->touched_add_payment_method_handle = true;

			$this->go_to( home_url( '/' ) );
			$wp->query_vars['add-payment-method'] = '';
			$sut->enqueue_paypal_script_for_add_payment_method();

			$this->go_to( get_permalink( $page_id ) );
			unset( $wp->query_vars['add-payment-method'] );
			$sut->enqueue_paypal_script_for_add_payment_method();
			$wp->query_vars['add-payment-method'] = '';
			$sut->enqueue_paypal_script_for_add_payment_method();

			$this->assertTrue( wp_script_is( 'wc-fraud-protection-paypal-express', 'enqueued' ) );
		} finally {
			if ( null === $previous_page_id ) {
				delete_option( 'woocommerce_myaccount_page_id' );
			} else {
				update_option( 'woocommerce_myaccount_page_id', $previous_page_id );
			}
			if ( $had_endpoint_query_var ) {
				$wp->query_vars['add-payment-method'] = $previous_query_var;
			} else {
				unset( $wp->query_vars['add-payment-method'] );
			}
		}
	}


}
