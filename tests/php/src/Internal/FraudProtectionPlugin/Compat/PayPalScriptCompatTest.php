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

	/** @var bool Whether the smart-button handle changed. */
	private bool $touched_smart_button_handle = false;

	/** @var bool Whether the block handle was registered. */
	private bool $registered_block_handle = false;

	/** @var bool Whether the add-payment-method handle changed. */
	private bool $touched_add_payment_method_handle = false;

	/** @var string[] SDK v6 handles changed by the test. */
	private array $touched_sdk_v6_handles = array();

	/** @var array<string, mixed> Original WooCommerce page options. */
	private array $original_page_options = array();

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->sut                   = $this->make_compat_with_script_handler( $this->make_blackbox_script_handler() );
		$this->original_page_options = array(
			'woocommerce_cart_page_id'     => get_option( 'woocommerce_cart_page_id', null ),
			'woocommerce_checkout_page_id' => get_option( 'woocommerce_checkout_page_id', null ),
			'woocommerce_shop_page_id'     => get_option( 'woocommerce_shop_page_id', null ),
		);
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		global $wp;

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
		foreach ( $this->touched_sdk_v6_handles as $handle ) {
			wp_dequeue_script( $handle );
			wp_deregister_script( $handle );
		}
		foreach ( $this->original_page_options as $option => $value ) {
			if ( null === $value ) {
				delete_option( $option );
			} else {
				update_option( $option, $value );
			}
		}
		unset( $wp->query_vars['order-pay'], $wp->query_vars['order-received'] );
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
		$this->assertFalse( has_action( 'wp_enqueue_scripts', array( $this->sut, 'enqueue_paypal_script_for_add_payment_method' ) ) );
		$this->assertSame( PHP_INT_MAX, has_action( 'wp_enqueue_scripts', array( $this->sut, 'enqueue_paypal_script_for_sdk_v6' ) ) );
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

		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Test invokes the hook.
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

	/** @testdox The block follower supports the registered SDK v6 Blocks script. */
	public function test_block_follower_supports_sdk_v6(): void {
		$this->register_sdk_v6_handle( 'wc-ppcp-sdk-v6-blocks', false );
		$sut = $this->make_sut_expecting_script_request( true );

		$sut->enqueue_paypal_block_script_if_registered();

		$this->assertTrue( wp_script_is( 'wc-fraud-protection-paypal-express', 'enqueued' ) );
	}

	/** @testdox The Cart block follower loads its carrier for the SDK v6 Blocks script. */
	public function test_cart_block_follower_supports_sdk_v6(): void {
		$this->register_sdk_v6_handle( 'wc-ppcp-sdk-v6-blocks', false );
		$sut = $this->make_sut_expecting_script_request( true );

		$sut->enqueue_paypal_cart_block_scripts_if_registered();

		$this->assertTrue( wp_script_is( 'wc-fraud-protection-blocks-checkout', 'enqueued' ) );
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
		$sut         = $this->make_sut_expecting_no_script_request();
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
		$this->assertFalse( wp_script_is( 'wc-fraud-protection-paypal-express', 'enqueued' ) );
		$this->run_wp_enqueue_scripts();

		$this->assertTrue( wp_script_is( 'wc-fraud-protection-paypal-express', 'enqueued' ) );
	}

	/** @testdox A form rendered after script enqueueing loads the PayPal interceptor immediately. */
	public function test_standard_form_follower_runs_after_wp_enqueue_scripts(): void {
		$this->run_wp_enqueue_scripts();
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
		$this->run_wp_enqueue_scripts();

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
	 * @testdox A payment-surface hook waits for PayPal to enqueue its script before loading the interceptor.
	 *
	 * @dataProvider early_payment_surface_provider
	 * @param string $hook PayPal payment-surface hook.
	 * @param string $handle PayPal script handle.
	 */
	public function test_early_payment_surface_follows_later_paypal_script( string $hook, string $handle ): void {
		$this->go_to( home_url( '/' ) );
		if ( 'woocommerce_widget_cart_is_hidden' === $hook ) {
			$this->configure_paypal_mini_cart( true, false, false );
		}
		$sut = $this->make_sut_expecting_script_request( true );
		$sut->register();
		$enqueue_paypal_script = function () use ( $handle ): void {
			wp_register_script( $handle, 'https://example.com/paypal.js', array(), '1.0', true );
			wp_enqueue_script( $handle );
		};
		add_action( 'wp_enqueue_scripts', $enqueue_paypal_script, 10 );

		try {
			if ( 'woocommerce_widget_cart_is_hidden' === $hook ) {
				// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Test invokes the hook.
				$this->assertFalse( apply_filters( 'woocommerce_widget_cart_is_hidden', false ) );
			} else {
				// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Test invokes the hook.
				do_action( $hook );
			}
			$this->assertFalse( wp_script_is( 'wc-fraud-protection-paypal-express', 'enqueued' ) );

			$this->run_wp_enqueue_scripts();

			$this->assertTrue( wp_script_is( 'wc-fraud-protection-paypal-express', 'enqueued' ) );
			$this->assertSame( 1, array_count_values( wp_scripts()->queue )['wc-fraud-protection-paypal-express'] ?? 0 );
		} finally {
			remove_action( 'wp_enqueue_scripts', $enqueue_paypal_script, 10 );
			wp_dequeue_script( $handle );
			wp_deregister_script( $handle );
		}
	}

	/**
	 * Payment surfaces that can render before PayPal enqueues its script.
	 *
	 * @return array<string, array{string, string}>
	 */
	public function early_payment_surface_provider(): array {
		return array(
			'classic checkout'         => array( 'woocommerce_checkout_before_order_review', 'ppcp-smart-button' ),
			'classic mini-cart widget' => array( 'woocommerce_widget_cart_is_hidden', 'ppcp-smart-button' ),
			'SDK v6 mini-cart widget'  => array( 'woocommerce_widget_cart_is_hidden', 'wc-ppcp-sdk-v6-boot' ),
			'subscription change'      => array( 'woocommerce_subscriptions_change_payment_after_submit', 'ppcp-add-payment-method' ),
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

		$sut                         = $this->make_sut_expecting_no_script_request();
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
		$this->assertFalse( wp_script_is( 'wc-fraud-protection-paypal-express', 'enqueued' ) );
		$this->run_wp_enqueue_scripts();

		$this->assertTrue( wp_script_is( 'wc-fraud-protection-paypal-express', 'enqueued' ) );
	}

	/** @testdox The mini-cart follower supports the active SDK v6 boot script. */
	public function test_mini_cart_follower_supports_sdk_v6(): void {
		$this->configure_paypal_mini_cart( true, false, false );
		$this->register_sdk_v6_handle( 'wc-ppcp-sdk-v6-boot' );
		$sut = $this->make_sut_expecting_script_request( true );

		$sut->enqueue_paypal_mini_cart_script_if_enabled();
		$this->run_wp_enqueue_scripts();

		$this->assertTrue( wp_script_is( 'wc-fraud-protection-paypal-express', 'enqueued' ) );
	}

	/**
	 * @testdox A visible classic cart widget prepares the page for PayPal fragments without changing visibility.
	 */
	public function test_visible_cart_widget_prepares_paypal_fragments(): void {
		$this->run_wp_enqueue_scripts();
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
		$sut    = $this->make_sut_expecting_no_script_request();
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
			'array'   => array( array() ),
			'object'  => array( new \stdClass() ),
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
		$this->run_wp_enqueue_scripts();

		ob_start();
		woocommerce_mini_cart();
		ob_end_clean();
		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Test invokes the hook.
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
		$this->run_wp_enqueue_scripts();

		$this->assertFalse( wp_script_is( 'wc-fraud-protection-paypal-express', 'enqueued' ) );
	}

	/**
	 * Unavailable classic mini-cart cases.
	 *
	 * @return array<string, array{bool, bool, bool}>
	 */
	public function unavailable_mini_cart_provider(): array {
		return array(
			'location disabled'     => array( false, true, true ),
			'script not registered' => array( true, false, true ),
			'script not enqueued'   => array( true, true, false ),
		);
	}

	/**
	 * @testdox PayPal version and setting precedence controls the mini-cart follower.
	 *
	 * @dataProvider mini_cart_setting_precedence_provider
	 * @param string $version Test value.
	 * @param ?bool  $current_enabled Test value.
	 * @param bool   $legacy_enabled Test value.
	 * @param bool   $expected Test value.
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
		$this->run_wp_enqueue_scripts();

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
	 * @testdox The late SDK v6 follower loads the interceptor only on payment-capable pages.
	 *
	 * @dataProvider sdk_v6_page_context_provider
	 *
	 * @param string $context Page context.
	 * @param bool   $expected Whether the SDK v6 payment script should be followed.
	 */
	public function test_sdk_v6_boot_follower_uses_payment_page_context( string $context, bool $expected ): void {
		$this->go_to_sdk_v6_page_context( $context );
		if ( in_array( $context, array( 'product', 'cart' ), true ) ) {
			$this->configure_paypal_styling_location( $context, true );
		}
		$this->register_sdk_v6_handle( 'wc-ppcp-sdk-v6-boot' );
		$sut = $expected ? $this->make_sut_expecting_script_request( true ) : $this->make_sut_expecting_no_script_request();

		$sut->enqueue_paypal_script_for_sdk_v6();

		$this->assertSame( $expected, wp_script_is( 'wc-fraud-protection-paypal-express', 'enqueued' ) );
	}

	/** @return array<string, array{string, bool}> */
	public function sdk_v6_page_context_provider(): array {
		return array(
			'product'        => array( 'product', true ),
			'cart'           => array( 'cart', true ),
			'checkout'       => array( 'checkout', true ),
			'pay for order'  => array( 'order-pay', true ),
			'order received' => array( 'order-received', false ),
			'home'           => array( 'home', false ),
			'shop'           => array( 'shop', false ),
		);
	}

	/**
	 * @testdox The late SDK v6 follower ignores message-only product and cart pages.
	 *
	 * @dataProvider sdk_v6_message_only_context_provider
	 *
	 * @param string $context Page context.
	 */
	public function test_sdk_v6_boot_follower_ignores_message_only_pages( string $context ): void {
		$this->go_to_sdk_v6_page_context( $context );
		$this->configure_paypal_styling_location( $context, false );
		$this->register_sdk_v6_handle( 'wc-ppcp-sdk-v6-boot' );
		$sut = $this->make_sut_expecting_no_script_request();

		$sut->enqueue_paypal_script_for_sdk_v6();

		$this->assertFalse( wp_script_is( 'wc-fraud-protection-paypal-express', 'enqueued' ) );
	}

	/** @return array<string, array{string}> */
	public function sdk_v6_message_only_context_provider(): array {
		return array(
			'product' => array( 'product' ),
			'cart'    => array( 'cart' ),
		);
	}

	/**
	 * @testdox The late SDK v6 follower supports auxiliary payment scripts.
	 *
	 * @dataProvider sdk_v6_auxiliary_handle_provider
	 *
	 * @param string $handle SDK v6 script handle.
	 */
	public function test_sdk_v6_follower_supports_auxiliary_payment_scripts( string $handle ): void {
		$this->register_sdk_v6_handle( $handle );
		$sut = $this->make_sut_expecting_script_request( true );

		$sut->enqueue_paypal_script_for_sdk_v6();

		$this->assertTrue( wp_script_is( 'wc-fraud-protection-paypal-express', 'enqueued' ) );
	}

	/** @return array<string, array{string}> */
	public function sdk_v6_auxiliary_handle_provider(): array {
		return array(
			'add payment method' => array( 'wc-ppcp-sdk-v6-add-payment-method' ),
			'standalone vault'   => array( 'ppcp-vault-component' ),
		);
	}

	/** @testdox The late SDK v6 follower leaves PayPal script dependencies unchanged. */
	public function test_sdk_v6_follower_does_not_change_paypal_dependencies(): void {
		$this->go_to_sdk_v6_page_context( 'checkout' );
		$handles = array(
			'wc-ppcp-sdk-v6-boot',
			'wc-ppcp-sdk-v6-add-payment-method',
			'ppcp-vault-component',
		);
		foreach ( $handles as $handle ) {
			$this->register_sdk_v6_handle( $handle, true, array( 'jquery' ) );
		}
		$sut = $this->make_sut_expecting_script_request( true );

		$sut->enqueue_paypal_script_for_sdk_v6();

		foreach ( $handles as $handle ) {
			$script = wp_scripts()->query( $handle, 'registered' );
			$this->assertNotFalse( $script );
			$this->assertSame( array( 'jquery' ), $script->deps );
		}
	}

	/** @testdox The SDK v6 follower does not enqueue the interceptor when shared scripts are unavailable. */
	public function test_sdk_v6_follower_skips_when_shared_scripts_are_unavailable(): void {
		$this->go_to_sdk_v6_page_context( 'checkout' );
		$this->register_sdk_v6_handle( 'wc-ppcp-sdk-v6-boot' );
		$sut = $this->make_sut_expecting_script_request( false );

		$sut->enqueue_paypal_script_for_sdk_v6();

		$this->assertFalse( wp_script_is( 'wc-fraud-protection-paypal-express', 'enqueued' ) );
	}

	/**
	 * Build a PayPal compatibility layer with a controlled script handler.
	 *
	 * @param BlackboxScriptHandler $handler Test value.
	 * @return PayPalScriptCompat
	 */
	private function make_compat_with_script_handler( BlackboxScriptHandler $handler ): PayPalScriptCompat {
		$sut = new PayPalScriptCompat();
		$sut->init( $handler );

		return $sut;
	}

	/**
	 * Build a compatibility layer that must request the shared scripts.
	 *
	 * @param bool $result Test value.
	 */
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

	/** Run the script-enqueue action for a page load. */
	private function run_wp_enqueue_scripts(): void {
		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Test invokes the hook.
		do_action( 'wp_enqueue_scripts' );
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
		$this->configure_paypal_styling_location( 'mini_cart', $location_enabled );

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
	 * Configure a PayPal Payments location in its current styling settings.
	 *
	 * @param string $location Location key.
	 * @param bool   $enabled Whether the payment location is enabled.
	 */
	private function configure_paypal_styling_location( string $location, bool $enabled ): void {
		$location_styling          = new \stdClass();
		$location_styling->enabled = $enabled;
		update_option( 'woocommerce-ppcp-data-styling', array( $location => $location_styling ) );
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
	 * Register an SDK v6 handle and optionally enqueue it.
	 *
	 * @param string   $handle       Script handle.
	 * @param bool     $enqueue      Whether to enqueue the script.
	 * @param string[] $dependencies Script dependencies.
	 */
	private function register_sdk_v6_handle( string $handle, bool $enqueue = true, array $dependencies = array() ): void {
		wp_register_script( $handle, 'https://example.com/' . $handle . '.js', $dependencies, '1.0', true );
		if ( $enqueue ) {
			wp_enqueue_script( $handle );
		}

		$this->touched_sdk_v6_handles[] = $handle;
	}

	/**
	 * Set the frontend context for an SDK v6 boot check.
	 *
	 * @param string $context Page context.
	 */
	private function go_to_sdk_v6_page_context( string $context ): void {
		global $wp;
		unset( $wp->query_vars['order-pay'], $wp->query_vars['order-received'] );
		$this->reset_woocommerce_cart_checkout_page_cache();

		$other_page_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);
		update_option( 'woocommerce_cart_page_id', $other_page_id );
		update_option( 'woocommerce_checkout_page_id', $other_page_id );
		update_option( 'woocommerce_shop_page_id', $other_page_id );

		if ( 'product' === $context ) {
			$product = \WC_Helper_Product::create_simple_product();
			$this->go_to( get_permalink( $product->get_id() ) );
			return;
		}

		if ( 'home' === $context ) {
			$this->go_to( home_url( '/' ) );
			return;
		}

		$page_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);

		if ( 'shop' === $context ) {
			update_option( 'woocommerce_shop_page_id', $page_id );
			$this->go_to( get_permalink( $page_id ) );
			return;
		}

		if ( 'cart' === $context ) {
			wp_update_post(
				array(
					'ID'           => $page_id,
					'post_content' => '[woocommerce_cart]',
				)
			);
			update_option( 'woocommerce_cart_page_id', $page_id );
			$this->go_to( get_permalink( $page_id ) );
			return;
		}

		wp_update_post(
			array(
				'ID'           => $page_id,
				'post_content' => '[woocommerce_checkout]',
			)
		);
		update_option( 'woocommerce_checkout_page_id', $page_id );
		$this->go_to( get_permalink( $page_id ) );
		if ( 'order-pay' === $context ) {
			$wp->query_vars['order-pay'] = '123';
		} elseif ( 'order-received' === $context ) {
			$wp->query_vars['order-received'] = '123';
		}
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

	/** @testdox A subscription form rendered after script enqueueing requires an active PayPal script. */
	public function test_subscriptions_render_requires_active_paypal_script(): void {
		$sut = $this->make_sut_expecting_script_request( true );
		$sut->register();
		$this->run_wp_enqueue_scripts();
		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Test invokes the hook.
		do_action( 'woocommerce_subscriptions_change_payment_after_submit' );
		wp_register_script( 'ppcp-add-payment-method', 'https://example.com/add.js', array(), '1.0', true );
		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Test invokes the hook.
		do_action( 'woocommerce_subscriptions_change_payment_after_submit' );
		wp_enqueue_script( 'ppcp-add-payment-method' );
		$this->touched_add_payment_method_handle = true;

		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Test invokes the hook.
		do_action( 'woocommerce_subscriptions_change_payment_after_submit' );

		$this->assertTrue( wp_script_is( 'wc-fraud-protection-paypal-express', 'enqueued' ) );
	}

	/** @testdox My Account render requires the page and add-payment-method query variable. */
	public function test_my_account_render_requires_both_page_checks(): void {
		global $wp;

		$handler = $this->createMock( BlackboxScriptHandler::class );
		$handler->expects( $this->once() )->method( 'request_scripts' )->willReturn( true );
		$sut                    = $this->make_compat_with_script_handler( $handler );
		$page_id                = self::factory()->post->create( array( 'post_type' => 'page' ) );
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

			$this->assertFalse( wp_script_is( 'wc-fraud-protection-paypal-express', 'enqueued' ) );
			$this->run_wp_enqueue_scripts();
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

	/** @testdox The add-payment-method interceptor loads when PayPal enqueues after the form hook. */
	public function test_add_payment_method_script_loads_during_wp_enqueue_scripts(): void {
		global $wp;

		$handler = $this->createMock( BlackboxScriptHandler::class );
		$handler->expects( $this->once() )->method( 'request_scripts' )->willReturn( true );
		$sut                    = $this->make_compat_with_script_handler( $handler );
		$page_id                = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$previous_page_id       = get_option( 'woocommerce_myaccount_page_id', null );
		$had_endpoint_query_var = array_key_exists( 'add-payment-method', $wp->query_vars );
		$previous_query_var     = $wp->query_vars['add-payment-method'] ?? null;
		$enqueue_paypal_script  = function (): void {
			wp_register_script( 'ppcp-add-payment-method', 'https://example.com/add.js', array(), '1.0', true );
			wp_enqueue_script( 'ppcp-add-payment-method' );
			$this->touched_add_payment_method_handle = true;
		};

		try {
			update_option( 'woocommerce_myaccount_page_id', $page_id );
			$this->go_to( get_permalink( $page_id ) );
			$wp->query_vars['add-payment-method'] = '';
			add_action( 'wp_enqueue_scripts', $enqueue_paypal_script, 10 );
			$sut->register();

			// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Test invokes the hook.
			do_action( 'woocommerce_add_payment_method_form_bottom' );
			$this->assertFalse( wp_script_is( 'wc-fraud-protection-paypal-express', 'enqueued' ) );
			$this->run_wp_enqueue_scripts();

			$this->assertTrue( wp_script_is( 'wc-fraud-protection-paypal-express', 'enqueued' ) );
		} finally {
			remove_action( 'wp_enqueue_scripts', $enqueue_paypal_script, 10 );
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
