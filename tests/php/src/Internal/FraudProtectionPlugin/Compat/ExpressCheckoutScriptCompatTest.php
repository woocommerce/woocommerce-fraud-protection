<?php
/**
 * ExpressCheckoutScriptCompatTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin\Compat;

use Automattic\WooCommerce\Blocks\BlockTypes\AbstractBlock;
use Automattic\WooCommerce\FraudProtection\BlackboxScriptHandler;
use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Compat\ExpressCheckoutScriptCompat;

/**
 * Tests for the ExpressCheckoutScriptCompat class.
 *
 * @covers \Automattic\WooCommerce\Internal\FraudProtectionPlugin\Compat\ExpressCheckoutScriptCompat
 */
class ExpressCheckoutScriptCompatTest extends FraudProtectionUnitTestCase {

	/**
	 * The System Under Test.
	 *
	 * @var ExpressCheckoutScriptCompat
	 */
	private $sut;

	/**
	 * Gateway script handles registered by the test.
	 *
	 * @var string[]
	 */
	private array $gateway_handles = array();

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->sut = new ExpressCheckoutScriptCompat();
		$this->sut->init( $this->make_blackbox_script_handler() );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		global $wp;

		foreach ( $this->gateway_handles as $handle ) {
			wp_dequeue_script( $handle );
			wp_deregister_script( $handle );
		}
		unset( $wp->query_vars['order-pay'], $wp->query_vars['order-received'] );
		remove_action( 'wp_enqueue_scripts', array( $this->sut, 'enqueue_express_checkout_script_if_buttons_enqueued' ), PHP_INT_MAX );
		remove_action( 'woocommerce_blocks_enqueue_checkout_block_scripts_before', array( $this->sut, 'enqueue_express_checkout_block_script_if_registered' ), 20 );
		remove_action( 'woocommerce_blocks_enqueue_cart_block_scripts_before', array( $this->sut, 'enqueue_express_checkout_block_script_if_registered' ), 20 );
		$this->reset_fraud_protection_scripts();

		parent::tearDown();
	}

	/**
	 * @testdox Registers the express payment surface hooks.
	 */
	public function test_register_hooks(): void {
		$this->sut->register();

		$this->assertSame( PHP_INT_MAX, has_action( 'wp_enqueue_scripts', array( $this->sut, 'enqueue_express_checkout_script_if_buttons_enqueued' ) ) );
		$this->assertSame( 20, has_action( 'woocommerce_blocks_enqueue_checkout_block_scripts_before', array( $this->sut, 'enqueue_express_checkout_block_script_if_registered' ) ) );
		$this->assertSame( 20, has_action( 'woocommerce_blocks_enqueue_cart_block_scripts_before', array( $this->sut, 'enqueue_express_checkout_block_script_if_registered' ) ) );
	}

	/**
	 * @testdox Loads the express checkout script after a gateway enqueues its express button script.
	 * @dataProvider classic_handle_provider
	 *
	 * @param string $handle Gateway express button script handle.
	 */
	public function test_enqueues_script_for_enqueued_express_button_script( string $handle ): void {
		$this->mock_jetpack_blog_id( 12345 );
		$this->sut->register();
		$this->register_gateway_handle( $handle, true );

		$this->run_wp_enqueue_scripts();

		$this->assertTrue( wp_script_is( 'wc-fraud-protection-blackbox-init', 'enqueued' ), 'The shared scripts should be requested' );
		$this->assertTrue( wp_script_is( 'wc-fraud-protection-express-checkout', 'enqueued' ), 'The express checkout script should be enqueued' );
		$this->assertSame(
			array( 'wc-fraud-protection-blackbox-init', 'wp-hooks' ),
			wp_scripts()->query( 'wc-fraud-protection-express-checkout', 'registered' )->deps,
			'The express checkout script should run after the shared API and the hooks library'
		);
	}

	/**
	 * Express button script handles for product, cart, checkout, and pay-for-order pages.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function classic_handle_provider(): array {
		return array(
			'WooPayments' => array( 'WCPAY_EXPRESS_CHECKOUT_ECE' ),
			'Stripe'      => array( 'wc_stripe_express_checkout' ),
		);
	}

	/**
	 * @testdox Does not load scripts when an express button script is only registered.
	 */
	public function test_skips_registered_but_not_enqueued_express_button_script(): void {
		$this->mock_jetpack_blog_id( 12345 );
		$this->sut->register();
		$this->register_gateway_handle( 'wc_stripe_express_checkout', false );

		$this->run_wp_enqueue_scripts();

		$this->assertFalse( wp_script_is( 'wc-fraud-protection-blackbox-init', 'enqueued' ) );
		$this->assertFalse( wp_script_is( 'wc-fraud-protection-express-checkout', 'enqueued' ) );
	}

	/**
	 * @testdox Does not load scripts on pages without express buttons.
	 */
	public function test_skips_pages_without_express_buttons(): void {
		$this->mock_jetpack_blog_id( 12345 );
		$this->sut->register();

		$this->run_wp_enqueue_scripts();

		$this->assertFalse( wp_script_is( 'wc-fraud-protection-blackbox-init', 'enqueued' ) );
		$this->assertFalse( wp_script_is( 'wc-fraud-protection-express-checkout', 'enqueued' ) );
	}

	/**
	 * @testdox Does not enqueue the express checkout script when the shared scripts are unavailable.
	 */
	public function test_skips_when_shared_scripts_are_unavailable(): void {
		$handler = $this->createMock( BlackboxScriptHandler::class );
		$handler->expects( $this->once() )->method( 'request_scripts' )->willReturn( false );
		$this->sut->init( $handler );
		$this->register_gateway_handle( 'WCPAY_EXPRESS_CHECKOUT_ECE', true );

		$this->sut->enqueue_express_checkout_script_if_buttons_enqueued();

		$this->assertFalse( wp_script_is( 'wc-fraud-protection-express-checkout', 'enqueued' ) );
	}

	/**
	 * @testdox Loads the express checkout script for a Cart or Checkout block with a registered gateway block integration.
	 * @dataProvider blocks_handle_provider
	 *
	 * @param string $handle Gateway block integration script handle.
	 */
	public function test_block_follower_enqueues_for_registered_block_integration( string $handle ): void {
		$this->mock_jetpack_blog_id( 12345 );
		$this->register_gateway_handle( $handle, false );

		$this->sut->enqueue_express_checkout_block_script_if_registered();

		$this->assertTrue( wp_script_is( 'wc-fraud-protection-express-checkout', 'enqueued' ) );
	}

	/**
	 * Gateway block integration script handles.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function blocks_handle_provider(): array {
		return array(
			'WooPayments' => array( 'WCPAY_BLOCKS_CHECKOUT' ),
			'Stripe'      => array( 'wc-stripe-blocks-integration' ),
		);
	}

	/**
	 * @testdox The block follower leaves the order-pay and order-received endpoints to the classic handles.
	 * @dataProvider checkout_endpoint_provider
	 *
	 * @param string $endpoint Checkout endpoint query variable.
	 */
	public function test_block_follower_skips_checkout_endpoints( string $endpoint ): void {
		global $wp;

		$handler = $this->createMock( BlackboxScriptHandler::class );
		$handler->expects( $this->never() )->method( 'request_scripts' );
		$this->sut->init( $handler );
		$this->register_gateway_handle( 'WCPAY_BLOCKS_CHECKOUT', false );
		$wp->query_vars[ $endpoint ] = '123';

		$this->sut->enqueue_express_checkout_block_script_if_registered();

		$this->assertFalse( wp_script_is( 'wc-fraud-protection-express-checkout', 'enqueued' ) );
	}

	/**
	 * Checkout endpoints rendered with classic views.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function checkout_endpoint_provider(): array {
		return array(
			'order pay'      => array( 'order-pay' ),
			'order received' => array( 'order-received' ),
		);
	}

	/**
	 * @testdox A Cart block rendered with a gateway block integration loads the express checkout script.
	 */
	public function test_cart_block_render_enqueues_script(): void {
		$this->mock_jetpack_blog_id( 12345 );
		$this->register_gateway_handle( 'wc-stripe-blocks-integration', false );
		$this->sut->register();
		$previous_enqueue_state = $this->set_cart_block_enqueue_state( false );

		try {
			do_blocks( '<!-- wp:woocommerce/cart --><div class="wp-block-woocommerce-cart"></div><!-- /wp:woocommerce/cart -->' );

			$this->assertTrue( wp_script_is( 'wc-fraud-protection-blackbox-init', 'enqueued' ) );
			$this->assertTrue( wp_script_is( 'wc-fraud-protection-express-checkout', 'enqueued' ) );
		} finally {
			$this->set_cart_block_enqueue_state( $previous_enqueue_state );
		}
	}

	/**
	 * @testdox A Cart block rendered without a gateway block integration loads no fraud scripts.
	 */
	public function test_cart_block_render_without_gateway_skips_scripts(): void {
		$this->mock_jetpack_blog_id( 12345 );
		$this->sut->register();
		$previous_enqueue_state = $this->set_cart_block_enqueue_state( false );

		try {
			do_blocks( '<!-- wp:woocommerce/cart --><div class="wp-block-woocommerce-cart"></div><!-- /wp:woocommerce/cart -->' );

			$this->assertFalse( wp_script_is( 'wc-fraud-protection-blackbox-init', 'enqueued' ) );
			$this->assertFalse( wp_script_is( 'wc-fraud-protection-express-checkout', 'enqueued' ) );
		} finally {
			$this->set_cart_block_enqueue_state( $previous_enqueue_state );
		}
	}

	/**
	 * Register a gateway script handle and optionally enqueue it.
	 *
	 * @param string $handle  Script handle.
	 * @param bool   $enqueue Whether to enqueue the script.
	 */
	private function register_gateway_handle( string $handle, bool $enqueue ): void {
		wp_register_script( $handle, 'https://example.com/' . $handle . '.js', array(), '1.0', true );
		if ( $enqueue ) {
			wp_enqueue_script( $handle );
		}
		$this->gateway_handles[] = $handle;
	}

	/** Run the script-enqueue action for a page load. */
	private function run_wp_enqueue_scripts(): void {
		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Test invokes the hook.
		do_action( 'wp_enqueue_scripts' );
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
		$this->assertIsArray( $block_type->render_callback );
		$this->assertInstanceOf( AbstractBlock::class, $block_type->render_callback[0] );

		$property = new \ReflectionProperty( AbstractBlock::class, 'enqueued_assets' );
		$property->setAccessible( true );
		$previous = (bool) $property->getValue( $block_type->render_callback[0] );
		$property->setValue( $block_type->render_callback[0], $state );

		return $previous;
	}
}
