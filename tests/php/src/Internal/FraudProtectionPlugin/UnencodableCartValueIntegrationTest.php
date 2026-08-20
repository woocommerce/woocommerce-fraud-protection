<?php
/**
 * UnencodableCartValueIntegrationTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin;

use Automattic\WooCommerce\FraudProtection\Schemas\FraudDecision;
use Automattic\WooCommerce\FraudProtection\SessionIdNormalizer;
use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\ApiClient;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Sessions\SessionDataCollector;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Trackers\CartEventTracker;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\VisitorIpResolver;

/**
 * End-to-end cover for a cart value the JSON encoder cannot carry.
 *
 * The invariant: a verify request reaches Blackbox and carries its decision, whatever the cart
 * happens to hold. The unit tests pin each guard against a value handed straight to it; this one
 * drives the whole path instead — the classic form handler, the real tracker registration, the
 * real collector and the real {@see ApiClient}, with only the transport seam replaced.
 *
 * Three fixture properties are what make the path produce such a value, and none is incidental.
 * The store must interpret quantities as decimals, which is the supported pattern of replacing
 * WooCommerce's default `intval` stock-amount callback with `floatval`. The product must not
 * manage stock, or the quantity would be clamped against a finite stock level first. And the
 * cases run with tax both off and on, because that decides which derived fields the value
 * reaches: `WC_Cart::set_cart_contents_tax()` stores its total raw, where its neighbours pass
 * theirs through `wc_format_decimal()`.
 *
 * Each case asserts its own precondition — that the cart really holds the value in question —
 * before asserting anything about the outcome. Without that, a future WooCommerce that
 * normalizes earlier would leave these tests passing while testing nothing.
 */
class UnencodableCartValueIntegrationTest extends FraudProtectionUnitTestCase {

	/**
	 * The cart hooks CartEventTracker::register() listens on.
	 */
	private const TRACKER_HOOKS = array(
		'internal_woocommerce_cart_item_added_from_user_request',
		'internal_woocommerce_cart_item_updated_from_user_request',
		'internal_woocommerce_cart_item_removed_from_user_request',
		'woocommerce_cart_item_restored',
	);

	/**
	 * The priority CartEventTracker::register() uses for all of them.
	 */
	private const TRACKER_PRIORITY = 10;

	/**
	 * The product added to the cart by each case.
	 *
	 * @var \WC_Product|null
	 */
	private $product;

	/**
	 * The tax rate created by a tax-enabled case, deleted in tearDown().
	 *
	 * @var int|null
	 */
	private $tax_rate_id;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( version_compare( WC_VERSION, '10.6.0', '<' ) ) {
			$this->markTestSkipped( 'The internal cart event hooks used by this test require WooCommerce 10.6.0 or later.' );
		}

		wc_load_cart();
		WC()->cart->empty_cart();
		WC()->session->set( 'fraud_protection_collected_data', null );

		update_option( 'jetpack_options', array( 'id' => 12345 ) );
		update_option( 'jetpack_private_options', array( 'blog_token' => 'IAM.AJETPACKBLOGTOKEN' ) );

		// The supported decimal-quantity store pattern: core's default callback is intval.
		remove_filter( 'woocommerce_stock_amount', 'intval' );
		add_filter( 'woocommerce_stock_amount', 'floatval' );

		// These cases assert on what the tracker collected, so exactly one tracker has to be
		// listening. Registration is ambient here and sibling test classes strip these hooks in
		// their own teardown, so neither inheriting it nor simply adding to it is safe: the
		// container hands back a different instance than the ambient one, which add_action()
		// cannot deduplicate, and two listeners would report every event twice. Clear first,
		// then register exactly once.
		foreach ( self::TRACKER_HOOKS as $hook ) {
			remove_all_actions( $hook );
		}
		wc_get_container()->get( CartEventTracker::class )->register();

		foreach ( self::TRACKER_HOOKS as $hook ) {
			$this->assertSame(
				1,
				$this->listener_count( $hook ),
				sprintf( 'Exactly one tracker must be listening on %s', $hook )
			);
		}
	}

	/**
	 * Count the callbacks registered on a hook at the tracker's priority.
	 *
	 * @param string $hook Hook name.
	 * @return int
	 */
	private function listener_count( string $hook ): int {
		global $wp_filter;

		if ( ! isset( $wp_filter[ $hook ] ) ) {
			return 0;
		}

		return count( $wp_filter[ $hook ]->callbacks[ self::TRACKER_PRIORITY ] ?? array() );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		remove_filter( 'woocommerce_stock_amount', 'floatval' );
		add_filter( 'woocommerce_stock_amount', 'intval' );

		$_REQUEST = array();
		$_GET     = array();
		wc_clear_notices();

		WC()->cart->empty_cart();
		WC()->session->set( 'fraud_protection_collected_data', null );

		if ( $this->product instanceof \WC_Product ) {
			$this->product->delete( true );
			$this->product = null;
		}

		if ( null !== $this->tax_rate_id ) {
			\WC_Tax::_delete_tax_rate( $this->tax_rate_id );
			$this->tax_rate_id = null;
		}
		update_option( 'woocommerce_calc_taxes', 'no' );
		delete_option( 'woocommerce_prices_include_tax' );
		delete_option( 'woocommerce_default_customer_address' );

		delete_option( 'jetpack_options' );
		delete_option( 'jetpack_private_options' );

		parent::tearDown();
	}

	/**
	 * @testdox A cart value the encoder cannot carry costs its own field, not the verify request.
	 *
	 * Parameterized over store tax configuration: with tax off the cart totals reach the payload
	 * as strings, and with tax on WC_Cart::set_cart_contents_tax() carries the raw value into
	 * order.tax_total. Both routes must end the same way.
	 *
	 * @dataProvider provide_tax_configurations
	 *
	 * @param bool $taxes_enabled Whether the store charges tax.
	 */
	public function test_unencodable_cart_value_still_reaches_the_blackbox_transport( bool $taxes_enabled ): void {
		if ( $taxes_enabled ) {
			$this->enable_taxes();
		}

		$this->add_to_cart_via_classic_form( '1e400' );
		WC()->cart->calculate_totals();

		// Precondition: the state under test is genuinely in the cart, not simulated.
		$cart_contents = WC()->cart->get_cart();
		$cart_item     = reset( $cart_contents );
		$this->assertSame( 0, wc_notice_count( 'error' ), 'The classic add-to-cart request should succeed' );
		$this->assertIsFloat( $cart_item['quantity'], 'The cart should hold the quantity as a float' );
		$this->assertFalse( is_finite( $cart_item['quantity'] ), 'The cart quantity should be non-finite' );
		$this->assertFalse(
			is_finite( WC()->cart->get_cart_contents_count() ),
			'WooCommerce should sum the cart to a non-finite count, so the derived field is covered too'
		);

		// The event itself must still be reported; dropping it is not the intended outcome.
		// Asserted by count as well as by type, so a second listener reporting the same add
		// twice cannot pass as a production-shaped payload.
		$payload = wc_get_container()->get( SessionDataCollector::class )->get_collected_data();
		$this->assertSame(
			array( 'cart_item_added' ),
			array_column( $payload['collected_events'] ?? array(), 'event_type' ),
			'The add event should be collected exactly once'
		);

		// The two fields this branch guards at their producers.
		$this->assertFiniteOrAbsent(
			$payload['collected_events'][0]['event_data']['cart_item_count'] ?? null,
			'cart_item_count'
		);
		$this->assertFiniteOrAbsent( $payload['order']['tax_total'] ?? null, 'order.tax_total' );

		// The decisive assertion: Blackbox is actually called, and the body really encoded.
		$transport_calls = 0;
		$captured_body   = null;
		$result          = $this->verify_through_stub_transport( $payload, $transport_calls, $captured_body );

		$this->assertSame( 1, $transport_calls, 'The verify request must reach the Blackbox transport' );
		$this->assertIsArray( $captured_body, 'The transport must receive a body that encoded' );
		$this->assertFalse( $result->fail_open, 'Verification must reach a real verdict, not a fallback' );
		$this->assertSame( FraudDecision::Block, $result->decision, 'The real Blackbox verdict must be honoured' );

		// The unencodable value costs its own field — not the event, and not the request.
		// Asserted per field on the transmitted payload: "nothing non-finite survives on the
		// wire" would be vacuous, because JSON cannot carry a non-finite number whatever the
		// code does.
		$sent_event = $captured_body['context']['collected_events'][0]['event_data'] ?? array();
		$this->assertSame( 'item_added', $sent_event['action'] ?? null, 'The event itself must still reach Blackbox' );
		$this->assertArrayNotHasKey(
			'quantity',
			$sent_event,
			'An unencodable quantity must be omitted, never substituted with a number'
		);
	}

	/**
	 * Data provider for {@see test_unencodable_cart_value_still_reaches_the_blackbox_transport()}.
	 *
	 * @return array<string, array{0: bool}>
	 */
	public function provide_tax_configurations(): array {
		return array(
			'taxes disabled' => array( false ),
			'taxes enabled'  => array( true ),
		);
	}

	/**
	 * @testdox A quantity that overflows the line amounts it derives costs no more than its own fields.
	 *
	 * The companion to the case above, and the reason a guard on the divisor alone is not enough.
	 * The quantity here stays finite and is reported as supplied; it is the amounts WooCommerce
	 * derives from it that overflow, so the fields at risk are the plugin's own per-unit
	 * arithmetic rather than the value that came in on the request.
	 */
	public function test_quantity_that_overflows_the_derived_line_amounts_still_reaches_the_transport(): void {
		$this->add_to_cart_via_classic_form( '1e308' );
		WC()->cart->calculate_totals();

		// Precondition: a finite quantity really did produce non-finite line amounts.
		$cart_contents = WC()->cart->get_cart();
		$cart_item     = reset( $cart_contents );
		$this->assertSame( 0, wc_notice_count( 'error' ), 'The classic add-to-cart request should succeed' );
		$this->assertTrue( is_finite( $cart_item['quantity'] ), 'The cart quantity itself should be finite' );
		$this->assertFalse( is_finite( (float) $cart_item['line_subtotal'] ), 'The derived line subtotal should overflow' );

		$payload = wc_get_container()->get( SessionDataCollector::class )->get_collected_data();
		$item    = $payload['order']['items'][0] ?? array();
		$this->assertFiniteOrAbsent( $item['unit_tax_amount'] ?? null, 'order.items[].unit_tax_amount' );
		$this->assertFiniteOrAbsent( $item['unit_discount_amount'] ?? null, 'order.items[].unit_discount_amount' );

		$transport_calls = 0;
		$captured_body   = null;
		$result          = $this->verify_through_stub_transport( $payload, $transport_calls, $captured_body );

		$this->assertSame( 1, $transport_calls, 'The verify request must reach the Blackbox transport' );
		$this->assertFalse( $result->fail_open, 'Verification must reach a real verdict, not a fallback' );

		$sent_item = $captured_body['context']['order']['items'][0] ?? array();
		$this->assertSame( 1.0e308, $sent_item['quantity'] ?? null, 'The quantity itself is representable, so it is relayed' );
	}

	/**
	 * @testdox The conversion-warning suppression hides only that conversion.
	 *
	 * The suppression exists so WooCommerce's notice formatting cannot decide these tests. It
	 * would be a poor trade if it also hid a diagnostic raised by the code under test, and
	 * nothing else here would notice: the cases above pass whether the handler is selective or
	 * swallows everything.
	 */
	public function test_conversion_warning_suppression_still_surfaces_other_diagnostics(): void {
		$seen = array();

		// Stand in for the harness's own handler, so this asserts on delegation rather than on
		// PHPUnit's warning conversion.
		$spy = static function ( int $errno, string $errstr ) use ( &$seen ) {
			$seen[] = $errstr;
			return true;
		};
		set_error_handler( $spy );

		try {
			$returned = self::without_float_to_int_cast_warning(
				static function () {
					trigger_error( 'unrelated diagnostic', E_USER_WARNING );
					trigger_error( 'The float INF is not representable as an int, cast occurred', E_USER_WARNING );
					return 'done';
				}
			);

			// Asserted by identity, not by delegation. A missing restore leaves the helper's
			// own handler installed for the rest of the run, and that handler forwards anything
			// it does not recognise — so triggering an error here would still reach the spy and
			// prove nothing. Who is installed is the only thing that separates the two.
			$installed = set_error_handler( static fn() => true );
			restore_error_handler();
		} finally {
			restore_error_handler();
		}

		$this->assertSame( $spy, $installed, 'the helper must restore the handler it replaced' );

		$this->assertSame( 'done', $returned, 'the callback must still run to completion' );
		$this->assertContains(
			'unrelated diagnostic',
			$seen,
			'a diagnostic the suppression does not name must reach the handler underneath it'
		);
		// Asserted here rather than left to the PHP version under test: the conversion only
		// warns on 8.5, so without this the suppression is unpinned everywhere else and can be
		// removed without anything noticing until CI.
		$this->assertNotContains(
			'The float INF is not representable as an int, cast occurred',
			$seen,
			'the named conversion warning must be swallowed, not delegated'
		);
	}

	/**
	 * @testdox An ordinary decimal quantity reaches Blackbox unchanged.
	 *
	 * The negative case above pins that an unencodable value is omitted. On its own that is
	 * satisfied by dropping far too much, so this pins the other edge on the same route: an
	 * ordinary quantity and the count derived from it arrive on the wire exactly as recorded.
	 */
	public function test_ordinary_decimal_quantity_reaches_blackbox_unchanged(): void {
		$this->add_to_cart_via_classic_form( '2.5' );

		$payload = wc_get_container()->get( SessionDataCollector::class )->get_collected_data();

		$transport_calls = 0;
		$captured_body   = null;
		$this->verify_through_stub_transport( $payload, $transport_calls, $captured_body );

		$this->assertSame( 1, $transport_calls, 'The verify request must reach the Blackbox transport' );
		$sent_event = $captured_body['context']['collected_events'][0]['event_data'] ?? array();
		$this->assertSame( 2.5, $sent_event['quantity'] ?? null, 'A representable quantity is relayed unchanged' );
		$this->assertSame( 2.5, $sent_event['cart_item_count'] ?? null, 'A finite count is relayed unchanged, not rounded or dropped' );
	}

	/**
	 * Run a real verify() against a stubbed transport, capturing what the transport received.
	 *
	 * Only `jetpack_remote_request()` is replaced, so the payload really is merged, filtered and
	 * encoded by {@see ApiClient} before it is captured. Assertions on `$captured_body` are
	 * therefore assertions about what Blackbox would have received, not about what the collector
	 * built.
	 *
	 * @param array<string, mixed>      $payload         Collected payload to verify.
	 * @param int                       $transport_calls Receives the number of transport calls.
	 * @param array<string, mixed>|null $captured_body   Receives the decoded request body.
	 * @return \Automattic\WooCommerce\Internal\FraudProtectionPlugin\Schemas\VerifyResult
	 */
	private function verify_through_stub_transport( array $payload, int &$transport_calls, &$captured_body ) {
		$api_client = $this->getMockBuilder( ApiClient::class )
			->onlyMethods( array( 'jetpack_remote_request' ) )
			->getMock();
		$api_client->init( wc_get_container()->get( VisitorIpResolver::class ), new SessionIdNormalizer() );
		$api_client->method( 'jetpack_remote_request' )->willReturnCallback(
			function ( array $request_args, string $body ) use ( &$transport_calls, &$captured_body ) {
				++$transport_calls;
				$captured_body = json_decode( $body, true );
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( array( 'data' => array( 'decision' => 'block' ) ) ),
				);
			}
		);

		return $api_client->verify( 'blackbox-session-id', $payload );
	}

	/**
	 * Assert a payload value is either a representable number or absent, never a non-finite float.
	 *
	 * @param mixed  $value The collected value.
	 * @param string $field Field name, for the failure message.
	 */
	private function assertFiniteOrAbsent( $value, string $field ): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- PHPUnit style.
		if ( null === $value ) {
			$this->addToAssertionCount( 1 );
			return;
		}

		// Deliberately not "anything that is not a non-finite float", which the string 'INF'
		// would satisfy. What this does and does not guarantee: the field is a real finite
		// number or it is gone. It cannot tell a substituted 0 from a genuine one — the unit
		// tests own that distinction, per field, where the intended value is known.
		$this->assertTrue(
			is_int( $value ) || ( is_float( $value ) && is_finite( $value ) ),
			sprintf(
				'%s must be a finite number or absent, never a stand-in for one (got %s)',
				$field,
				var_export( $value, true )
			)
		);
	}

	/**
	 * Turn the store into a tax-charging store with one standard rate.
	 */
	private function enable_taxes(): void {
		update_option( 'woocommerce_calc_taxes', 'yes' );
		update_option( 'woocommerce_prices_include_tax', 'no' );
		update_option( 'woocommerce_default_customer_address', 'base' );

		$this->tax_rate_id = \WC_Tax::_insert_tax_rate(
			array(
				'tax_rate_country'  => '',
				'tax_rate_state'    => '',
				'tax_rate'          => '20.0000',
				'tax_rate_name'     => 'VAT',
				'tax_rate_priority' => '1',
				'tax_rate_compound' => '0',
				'tax_rate_shipping' => '1',
				'tax_rate_order'    => '1',
				'tax_rate_class'    => '',
			)
		);

		WC()->customer->set_billing_country( 'GB' );
		WC()->customer->set_shipping_country( 'GB' );
	}

	/**
	 * Submit the classic add-to-cart form for a fresh unmanaged-stock product.
	 *
	 * Unmanaged stock is what lets core accept the overflowed quantity: a managed-stock
	 * product would be rejected against its finite stock level first.
	 *
	 * @param string $quantity The raw quantity as it arrives in the request.
	 */
	private function add_to_cart_via_classic_form( string $quantity ): void {
		$this->product = \WC_Helper_Product::create_simple_product();
		$this->product->set_regular_price( '10' );
		$this->product->set_manage_stock( false );
		$this->product->save();

		$_REQUEST = array(
			'add-to-cart' => (string) $this->product->get_id(),
			'quantity'    => $quantity,
		);
		$_GET     = $_REQUEST;

		self::without_float_to_int_cast_warning(
			static function () {
				\WC_Form_Handler::add_to_cart_action( false );
			}
		);
	}

	/**
	 * Run a callback with WooCommerce's own float-to-int conversion warnings suppressed.
	 *
	 * After a successful add, WooCommerce builds a cart notice through wc_add_to_cart_message(),
	 * whose quantity label calls wc_stock_amount(). That function passes intval( $amount ) as a
	 * fallback argument, which PHP evaluates eagerly, and PHP 8.5 warns when a float that cannot
	 * be represented as an int is cast. The harness turns warnings into exceptions, so the
	 * notice-formatting code would otherwise decide the outcome of a test about the payload.
	 *
	 * The match is deliberately the whole family of float-to-int conversion warnings rather than
	 * one wording, since the quantity may be infinite or merely too large and PHP words those
	 * differently. It is scoped to this one call, and every other diagnostic still reaches the
	 * handler that was installed before this one.
	 *
	 * @param callable $callback Callback to run.
	 * @return mixed Whatever the callback returned.
	 */
	private static function without_float_to_int_cast_warning( callable $callback ): mixed {
		$previous = set_error_handler(
			static function ( int $errno, string $errstr, ...$rest ) use ( &$previous ) {
				if ( false !== strpos( $errstr, 'is not representable as an int' ) ) {
					return true;
				}

				if ( is_callable( $previous ) ) {
					return ( $previous )( $errno, $errstr, ...$rest );
				}

				return false;
			}
		);

		try {
			return $callback();
		} finally {
			restore_error_handler();
		}
	}
}
