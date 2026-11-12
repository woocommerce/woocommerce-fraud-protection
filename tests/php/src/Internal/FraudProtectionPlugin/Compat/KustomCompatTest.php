<?php
/**
 * KustomCompatTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin\Compat;

use Automattic\WooCommerce\FraudProtection\BlockedSessionMessage;
use Automattic\WooCommerce\FraudProtection\SessionVerifier;
use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Compat\KustomCompat;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Protectors\ShortcodeCheckoutProtector;

/**
 * Tests for the KustomCompat class.
 *
 * @covers \Automattic\WooCommerce\Internal\FraudProtectionPlugin\Compat\KustomCompat
 */
class KustomCompatTest extends FraudProtectionUnitTestCase {

	private const SCRIPT_HANDLE = 'wc-fraud-protection-kustom-blocks-checkout';
	private const NAMESPACE     = 'woocommerce/fraud-protection';
	private const CHECKOUT_HOOK = 'woocommerce_store_api_checkout_update_order_from_request';
	private const PROCESS_HOOK  = 'woocommerce_store_api_checkout_order_processed';

	/**
	 * The System Under Test.
	 *
	 * @var KustomCompat
	 */
	private $sut;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$handler   = $this->make_blackbox_script_handler();
		$protector = new ShortcodeCheckoutProtector();
		$protector->init(
			$this->createMock( SessionVerifier::class ),
			$this->createMock( BlockedSessionMessage::class ),
			$handler
		);

		$this->sut = new KustomCompat();
		$this->sut->init( $protector, $handler );

		// The instance that the plugin registered at bootstrap must not handle the test's hooks.
		remove_all_actions( self::CHECKOUT_HOOK );
		remove_all_actions( self::PROCESS_HOOK );
		$this->sut->register();
		$this->mock_jetpack_blog_id( 12345 );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		remove_all_actions( 'kco_wc_before_snippet' );
		remove_action( 'woocommerce_blocks_enqueue_checkout_block_scripts_before', array( $this->sut, 'enqueue_blocks_checkout_script' ) );
		remove_action( self::CHECKOUT_HOOK, array( $this->sut, 'send_session_id_only_once' ), 5 );
		remove_action( self::PROCESS_HOOK, array( $this->sut, 'record_sent_session_id' ), 5 );
		remove_filter( 'woocommerce_available_payment_gateways', array( $this, 'add_kustom_gateway' ) );
		wp_dequeue_script( self::SCRIPT_HANDLE );
		wp_deregister_script( self::SCRIPT_HANDLE );
		$this->reset_fraud_protection_scripts();
		WC()->session->__unset( '_wc_fraud_protection_kustom_sent_session_id' );

		global $wp;
		unset( $wp->query_vars['order-received'] );

		parent::tearDown();
	}

	/**
	 * Add Kustom to the available gateways.
	 *
	 * @param array<string, mixed> $gateways Available gateways.
	 * @return array<string, mixed>
	 */
	public function add_kustom_gateway( array $gateways ): array {
		$gateways['kco'] = new \WC_Mock_Payment_Gateway();

		return $gateways;
	}

	/**
	 * Fire the Store API checkout hooks for a request, and return its session ID data.
	 *
	 * @param string $session_id      Session ID the request carries.
	 * @param string $payment_method  Payment method of the request.
	 * @param bool   $reaches_verification Whether WooCommerce validates the order, so that it reaches verification.
	 * @return mixed The Fraud Protection extension data after the hooks.
	 */
	private function submit_checkout( string $session_id, string $payment_method = 'kco', bool $reaches_verification = true ): mixed {
		$order   = new \WC_Order();
		$request = new \WP_REST_Request( 'POST', '/wc/store/v1/checkout' );
		$request->set_param( 'payment_method', $payment_method );
		$request->set_param( 'extensions', array( self::NAMESPACE => array( 'blackbox_session_id' => $session_id ) ) );

		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Test invokes the hook.
		do_action( self::CHECKOUT_HOOK, $order, $request );

		if ( $reaches_verification ) {
			// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Test invokes the hook.
			do_action( self::PROCESS_HOOK, $order );
		}

		return $request->get_param( 'extensions' )[ self::NAMESPACE ];
	}

	/**
	 * @testdox Rendering Kustom's embedded classic checkout loads the classic checkout script.
	 */
	public function test_kustom_checkout_render_enqueues_classic_checkout_script(): void {
		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Test invokes the hook.
		do_action( 'kco_wc_before_snippet' );

		$this->assertTrue( wp_script_is( 'wc-fraud-protection-blackbox-init', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'wc-fraud-protection-shortcode-checkout', 'enqueued' ) );
	}

	/**
	 * @testdox Loads the Checkout block script when Kustom is available.
	 */
	public function test_enqueues_blocks_script_when_kustom_is_available(): void {
		add_filter( 'woocommerce_available_payment_gateways', array( $this, 'add_kustom_gateway' ) );

		$this->sut->enqueue_blocks_checkout_script();

		$this->assertTrue( wp_script_is( self::SCRIPT_HANDLE, 'enqueued' ) );
		$this->assertContains(
			'wc-fraud-protection-blackbox-init',
			wp_scripts()->query( self::SCRIPT_HANDLE, 'registered' )->deps
		);
	}

	/**
	 * @testdox Does not load the Checkout block script when Kustom is not available.
	 */
	public function test_does_not_enqueue_blocks_script_without_kustom(): void {
		$this->sut->enqueue_blocks_checkout_script();

		$this->assertFalse( wp_script_is( self::SCRIPT_HANDLE, 'enqueued' ) );
	}

	/**
	 * @testdox Does not load the Checkout block script on the order received page.
	 */
	public function test_does_not_enqueue_blocks_script_on_order_received(): void {
		global $wp;
		$wp->query_vars['order-received'] = 1;
		add_filter( 'woocommerce_available_payment_gateways', array( $this, 'add_kustom_gateway' ) );

		$this->sut->enqueue_blocks_checkout_script();

		$this->assertFalse( wp_script_is( self::SCRIPT_HANDLE, 'enqueued' ) );
	}

	/**
	 * @testdox The first Kustom request for a session ID keeps it.
	 */
	public function test_first_request_keeps_session_id(): void {
		$this->assertSame( array( 'blackbox_session_id' => 'session-1' ), $this->submit_checkout( 'session-1' ) );
	}

	/**
	 * @testdox A retry with the same session ID reports that it has none for this attempt.
	 */
	public function test_retry_does_not_send_session_id_again(): void {
		$this->submit_checkout( 'session-1' );

		$this->assertSame(
			array(
				'blackbox_session_id'       => '',
				'blackbox_session_id_error' => 'not_ready',
			),
			$this->submit_checkout( 'session-1' )
		);
	}

	/**
	 * @testdox A retry after WooCommerce rejected the order before verification keeps the session ID.
	 */
	public function test_retry_after_rejection_before_verification_keeps_session_id(): void {
		$this->submit_checkout( 'session-1', 'kco', false );

		$this->assertSame( array( 'blackbox_session_id' => 'session-1' ), $this->submit_checkout( 'session-1' ) );
	}

	/**
	 * @testdox A new session ID after an earlier attempt is kept.
	 */
	public function test_new_session_id_is_kept(): void {
		$this->submit_checkout( 'session-1' );

		$this->assertSame( array( 'blackbox_session_id' => 'session-2' ), $this->submit_checkout( 'session-2' ) );
	}

	/**
	 * @testdox Requests for other payment methods are left unchanged.
	 */
	public function test_other_payment_methods_are_unchanged(): void {
		$this->submit_checkout( 'session-1', 'stripe' );

		$this->assertSame(
			array( 'blackbox_session_id' => 'session-1' ),
			$this->submit_checkout( 'session-1', 'stripe' )
		);
	}

	/**
	 * @testdox Invalid hook arguments are ignored.
	 */
	public function test_invalid_request_is_ignored(): void {
		$this->sut->send_session_id_only_once( null, 'not-a-request' );

		$this->assertNull( WC()->session->get( '_wc_fraud_protection_kustom_sent_session_id' ) );
	}
}
