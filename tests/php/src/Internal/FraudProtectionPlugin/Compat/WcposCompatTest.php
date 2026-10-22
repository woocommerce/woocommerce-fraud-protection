<?php
/**
 * WcposCompatTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin\Compat;

use Automattic\WooCommerce\FraudProtection\Schemas\FraudDecision;
use Automattic\WooCommerce\FraudProtection\SuppliedDecision;
use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Compat\WcposCompat;
use WCPOS\WooCommercePOS\Services\Auth;

// Stub wcpos_request() and the WCPOS gateway base class if not loaded.
require_once __DIR__ . '/../../../../stubs/wcpos.php';

/**
 * Tests for the WcposCompat class.
 *
 * @covers \Automattic\WooCommerce\Internal\FraudProtectionPlugin\Compat\WcposCompat
 */
class WcposCompatTest extends FraudProtectionUnitTestCase {

	/**
	 * The System Under Test.
	 *
	 * @var WcposCompat
	 */
	private WcposCompat $sut;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		add_filter( 'woocommerce_payment_gateways', array( $this, 'add_mock_gateway' ) );
		add_filter( 'woocommerce_available_payment_gateways', array( $this, 'add_pos_gateway' ) );
		WC()->payment_gateways()->init();
		\WCPOS_Request_Stub::$is_pos_request = true;

		$cashier = self::factory()->user->create_and_get( array( 'role' => 'shop_manager' ) );
		$cashier->add_cap( 'access_woocommerce_pos' );
		$customer           = self::factory()->user->create( array( 'role' => 'customer' ) );
		Auth::$valid_tokens = array(
			'cashier-token'  => $cashier->ID,
			'customer-token' => $customer,
		);
		$_GET['wcpos_jwt']  = 'cashier-token';

		$this->sut = new WcposCompat();
		$this->sut->register();
	}

	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		remove_filter( 'woocommerce_fraud_protection_skip_session_verify', array( $this->sut, 'supply_decision_for_pos_tender' ) );
		remove_filter( 'woocommerce_payment_gateways', array( $this, 'add_mock_gateway' ) );
		remove_filter( 'woocommerce_available_payment_gateways', array( $this, 'add_pos_gateway' ) );
		WC()->payment_gateways()->init();
		\WCPOS_Request_Stub::$is_pos_request = false;
		Auth::$valid_tokens                  = array();
		unset( $_GET['wcpos_jwt'], $_GET['token'] );

		parent::tearDown();
	}

	/**
	 * Register a regular gateway.
	 *
	 * @param array<int, mixed> $gateways Registered gateways.
	 * @return array<int, mixed>
	 */
	public function add_mock_gateway( array $gateways ): array {
		$gateways[] = \WC_Mock_Payment_Gateway::class;

		return $gateways;
	}

	/**
	 * Add the WCPOS cash gateway to the available gateways, as WCPOS does on its requests.
	 *
	 * @param array<string, mixed> $gateways Available gateways.
	 * @return array<string, mixed>
	 */
	public function add_pos_gateway( array $gateways ): array {
		$gateways['pos_cash'] = new \WCPOS_Cash_Gateway_Stub();

		return $gateways;
	}

	/**
	 * Create an order with the given `created_via` value.
	 *
	 * @param string $created_via The `created_via` value.
	 * @return int Order ID.
	 */
	private function create_order( string $created_via ): int {
		$order = \WC_Helper_Order::create_order();
		$order->set_created_via( $created_via );
		$order->save();

		return $order->get_id();
	}

	/**
	 * Run the filter as SessionVerifier does.
	 *
	 * @param mixed  $supplied_decision The value entering the filter.
	 * @param string $source            Source identifier.
	 * @param array  $request_data      Request data.
	 * @param int    $order_id          Order ID.
	 * @return mixed The filtered value.
	 */
	private function apply_filter( $supplied_decision, string $source, array $request_data, int $order_id ) {
		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Test invokes the hook.
		return apply_filters( 'woocommerce_fraud_protection_skip_session_verify', $supplied_decision, $source, $request_data, '', $order_id );
	}

	/**
	 * @testdox Supplies an allow for a WCPOS tender on a WCPOS order in a WCPOS request.
	 */
	public function test_supplies_allow_for_pos_tender(): void {
		$order_id = $this->create_order( 'woocommerce-pos' );

		$result = $this->apply_filter( false, 'pay_for_order', array( 'payment_method' => 'pos_cash' ), $order_id );

		$this->assertInstanceOf( SuppliedDecision::class, $result );
		$this->assertSame( FraudDecision::Allow, $result->decision );
		$this->assertNull( $result->session_id_for_order );
	}

	/**
	 * Cases that are verified as before.
	 *
	 * @return array<string, array{0: bool, 1: string, 2: array, 3: string|null}>
	 */
	public function verified_case_provider(): array {
		return array(
			'not a WCPOS request'           => array( false, 'pay_for_order', array( 'payment_method' => 'pos_cash' ), 'woocommerce-pos' ),
			'another source'                => array( true, 'shortcode_checkout', array( 'payment_method' => 'pos_cash' ), 'woocommerce-pos' ),
			'another gateway'               => array( true, 'pay_for_order', array( 'payment_method' => 'mock' ), 'woocommerce-pos' ),
			'unregistered gateway'          => array( true, 'pay_for_order', array( 'payment_method' => 'pos_unknown' ), 'woocommerce-pos' ),
			'no payment method'             => array( true, 'pay_for_order', array(), 'woocommerce-pos' ),
			'non-string payment method'     => array( true, 'pay_for_order', array( 'payment_method' => array( 'pos_cash' ) ), 'woocommerce-pos' ),
			'order created by the checkout' => array( true, 'pay_for_order', array( 'payment_method' => 'pos_cash' ), 'checkout' ),
			'no order'                      => array( true, 'pay_for_order', array( 'payment_method' => 'pos_cash' ), null ),
		);
	}

	/**
	 * @testdox Defers to verification when $_dataName.
	 * @dataProvider verified_case_provider
	 *
	 * @param bool        $is_pos_request Whether the request is a WCPOS request.
	 * @param string      $source         Source identifier.
	 * @param array       $request_data   Request data.
	 * @param string|null $created_via    The order's `created_via` value, or null for no order.
	 */
	public function test_defers_to_verification( bool $is_pos_request, string $source, array $request_data, ?string $created_via ): void {
		\WCPOS_Request_Stub::$is_pos_request = $is_pos_request;
		$order_id                            = null === $created_via ? 0 : $this->create_order( $created_via );

		$this->assertFalse( $this->apply_filter( false, $source, $request_data, $order_id ) );
	}

	/**
	 * Token parameters that are not a valid token of a user who can access the POS.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public function rejected_token_provider(): array {
		return array(
			'no token'                            => array( array() ),
			'an empty token'                      => array( array( 'wcpos_jwt' => '' ) ),
			'a non-string token'                  => array( array( 'wcpos_jwt' => array( 'cashier-token' ) ) ),
			'an invalid token'                    => array( array( 'wcpos_jwt' => 'forged-token' ) ),
			'the token of a non-POS user'         => array( array( 'wcpos_jwt' => 'customer-token' ) ),
			'an invalid token before a valid one' => array(
				array(
					'wcpos_jwt' => 'forged-token',
					'token'     => 'cashier-token',
				),
			),
		);
	}

	/**
	 * @testdox Defers to verification on a WCPOS request with $_dataName.
	 * @dataProvider rejected_token_provider
	 *
	 * @param array<string, mixed> $token_params Token query parameters.
	 */
	public function test_defers_without_a_valid_cashier_token( array $token_params ): void {
		unset( $_GET['wcpos_jwt'] );
		foreach ( $token_params as $name => $value ) {
			$_GET[ $name ] = $value;
		}
		$order_id = $this->create_order( 'woocommerce-pos' );

		$this->assertFalse( $this->apply_filter( false, 'pay_for_order', array( 'payment_method' => 'pos_cash' ), $order_id ) );
	}

	/**
	 * @testdox Accepts the cashier token in the legacy token parameter, like WCPOS.
	 */
	public function test_accepts_the_legacy_token_parameter(): void {
		unset( $_GET['wcpos_jwt'] );
		$_GET['token'] = 'cashier-token';
		$order_id      = $this->create_order( 'woocommerce-pos' );

		$result = $this->apply_filter( false, 'pay_for_order', array( 'payment_method' => 'pos_cash' ), $order_id );

		$this->assertInstanceOf( SuppliedDecision::class, $result );
		$this->assertSame( FraudDecision::Allow, $result->decision );
	}

	/**
	 * @testdox Keeps a decision that an earlier callback supplied for the attempt.
	 */
	public function test_keeps_an_earlier_supplied_decision(): void {
		$order_id = $this->create_order( 'woocommerce-pos' );
		$earlier  = new SuppliedDecision( FraudDecision::Block );

		$result = $this->apply_filter( $earlier, 'pay_for_order', array( 'payment_method' => 'pos_cash' ), $order_id );

		$this->assertSame( $earlier, $result );
	}

	/**
	 * @testdox Defers to verification when a caller does not pass the order ID.
	 */
	public function test_defers_without_order_id(): void {
		$this->create_order( 'woocommerce-pos' );

		$result = $this->sut->supply_decision_for_pos_tender( false, 'pay_for_order', array( 'payment_method' => 'pos_cash' ), '' );

		$this->assertFalse( $result );
	}
}
