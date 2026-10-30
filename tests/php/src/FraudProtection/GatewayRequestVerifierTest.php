<?php
/**
 * GatewayRequestVerifierTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\FraudProtection;

use Automattic\WooCommerce\FraudProtection\FraudProtectionFeature;
use Automattic\WooCommerce\FraudProtection\GatewayRequestVerifier;
use Automattic\WooCommerce\FraudProtection\Schemas\FraudDecision;
use Automattic\WooCommerce\FraudProtection\SessionIdNormalizer;
use Automattic\WooCommerce\FraudProtection\SessionVerifier;
use Automattic\WooCommerce\FraudProtection\SuppliedDecision;
use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;
use Automattic\WooCommerce\FraudProtection\Tests\Support\FakeNativeGateway;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Compat\GatewayNativeSupport;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Sessions\SessionIdError;
use Automattic\WooCommerce\Proxies\LegacyProxy;

/**
 * Tests for the GatewayRequestVerifier class.
 *
 * @covers \Automattic\WooCommerce\FraudProtection\GatewayRequestVerifier
 * @covers \Automattic\WooCommerce\FraudProtection\VerifiedRequest
 */
class GatewayRequestVerifierTest extends FraudProtectionUnitTestCase {

	private const GATEWAY_ID = 'my_gateway';

	private const SOURCE = 'my_gateway_create_order';

	private const REFERENCE_FIELD = 'my_gateway_order_id';

	private const COMPAT_GATEWAY_ID = 'compat_gateway';

	private const COMPAT_MAIN_GATEWAY_ID = 'compat';

	private const COMPAT_REFERENCE_FIELD = 'compat_order_id';

	/**
	 * The System Under Test.
	 *
	 * @var GatewayRequestVerifier
	 */
	private GatewayRequestVerifier $sut;

	/**
	 * Mock session verifier.
	 *
	 * @var SessionVerifier&\PHPUnit\Framework\MockObject\MockObject
	 */
	private $session_verifier;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->session_verifier = $this->createMock( SessionVerifier::class );
		$this->sut              = new GatewayRequestVerifier();
		$this->sut->init( $this->session_verifier, new SessionIdNormalizer(), wc_get_container()->get( LegacyProxy::class ), new GatewayNativeSupport() );
		$this->sut->register();
		$this->sut->register_reference_reader( self::GATEWAY_ID, static fn( array $payment_data ) => $payment_data[ self::REFERENCE_FIELD ] ?? '' );

		WC()->cart->add_to_cart( \WC_Helper_Product::create_simple_product()->get_id() );
	}

	/**
	 * Tear down after each test.
	 */
	public function tearDown(): void {
		remove_filter( 'woocommerce_fraud_protection_skip_session_verify', array( $this->sut, 'supply_reused_decision' ), 10 );
		WC()->session->set( '_wc_fraud_protection_reusable_decisions', null );
		WC()->cart->empty_cart();
		$_POST = array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		parent::tearDown();
	}

	/**
	 * @testdox The class is a shared WooCommerce container service.
	 */
	public function test_is_resolvable_from_container(): void {
		$this->assertInstanceOf( GatewayRequestVerifier::class, wc_get_container()->get( GatewayRequestVerifier::class ) );
		$this->assertSame( wc_get_container()->get( GatewayRequestVerifier::class ), wc_get_container()->get( GatewayRequestVerifier::class ) );
	}

	/*
	|--------------------------------------------------------------------------
	| verify_request() Tests
	|--------------------------------------------------------------------------
	*/

	/**
	 * @testdox verify_request() verifies the posted session ID with the gateway's payment data and forwards the reported session ID error.
	 */
	public function test_verify_request_reads_the_posted_session_id(): void {
		$_POST = array(
			SessionVerifier::SESSION_ID_FIELD => 'posted\\-session',
			SessionIdError::FIELD             => 'timeout',
		);
		$this->session_verifier->expects( $this->once() )
			->method( 'verify_session' )
			->with(
				'posted-session',
				self::SOURCE,
				42,
				array(
					'payment_method'      => self::GATEWAY_ID,
					'payment_data'        => array( 'wallet' => 'apple_pay' ),
					SessionIdError::FIELD => 'timeout',
				)
			)
			->willReturn( FraudDecision::Allow );

		$request = $this->sut->verify_request( self::SOURCE, self::GATEWAY_ID, array( 'wallet' => 'apple_pay' ), 42 );

		$this->assertSame( FraudDecision::Allow, $request->get_decision() );
		$this->assertFalse( $request->is_blocked() );
	}

	/**
	 * @testdox verify_request() verifies an explicit session ID instead of the POST body.
	 */
	public function test_verify_request_uses_an_explicit_session_id(): void {
		$_POST = array(
			SessionVerifier::SESSION_ID_FIELD => 'posted-session',
			SessionIdError::FIELD             => 'timeout',
		);
		$this->session_verifier->expects( $this->once() )
			->method( 'verify_session' )
			->with(
				'json-session',
				self::SOURCE,
				0,
				array(
					'payment_method' => self::GATEWAY_ID,
					'payment_data'   => array(),
				)
			)
			->willReturn( FraudDecision::Block );

		$request = $this->sut->verify_request( self::SOURCE, self::GATEWAY_ID, array(), 0, 'json-session' );

		$this->assertSame( FraudDecision::Block, $request->get_decision() );
		$this->assertTrue( $request->is_blocked() );
	}

	/**
	 * @testdox verify_request() reads the session ID and the session ID error from a JSON body.
	 */
	public function test_verify_request_reads_a_json_body(): void {
		$this->set_server_variables( array( 'CONTENT_TYPE' => 'application/json; charset=utf-8' ) );
		$this->mock_request_body(
			(string) wp_json_encode(
				array(
					SessionVerifier::SESSION_ID_FIELD => 'json-session',
					SessionIdError::FIELD             => 'timeout',
				)
			)
		);
		$this->session_verifier->expects( $this->once() )
			->method( 'verify_session' )
			->with(
				'json-session',
				self::SOURCE,
				0,
				array(
					'payment_method'      => self::GATEWAY_ID,
					'payment_data'        => array(),
					SessionIdError::FIELD => 'timeout',
				)
			)
			->willReturn( FraudDecision::Allow );

		$this->sut->verify_request( self::SOURCE, self::GATEWAY_ID );
	}

	/**
	 * @testdox verify_request() prefers a posted session ID field, and reads no body that is not JSON.
	 *
	 * @dataProvider body_source_provider
	 *
	 * @param array<string, string> $post         The POST fields.
	 * @param string                $content_type The request content type.
	 * @param string                $body         The raw request body.
	 * @param string                $expected     The session ID that is verified.
	 */
	public function test_verify_request_body_sources( array $post, string $content_type, string $body, string $expected ): void {
		$_POST = $post;
		$this->set_server_variables( array( 'CONTENT_TYPE' => $content_type ) );
		$this->mock_request_body( $body );
		$this->session_verifier->expects( $this->once() )
			->method( 'verify_session' )
			->with( $expected, self::SOURCE, 0, $this->anything() )
			->willReturn( FraudDecision::Allow );

		$this->sut->verify_request( self::SOURCE, self::GATEWAY_ID );
	}

	/**
	 * Request bodies and the session ID read from them.
	 *
	 * @return array<string, array{array<string, string>, string, string, string}>
	 */
	public function body_source_provider(): array {
		$json = '{"' . SessionVerifier::SESSION_ID_FIELD . '":"json-session"}';

		return array(
			'posted field with a JSON content type' => array( array( SessionVerifier::SESSION_ID_FIELD => 'posted-session' ), 'application/json', $json, 'posted-session' ),
			'body that is not JSON'                 => array( array(), 'application/x-www-form-urlencoded', $json, '' ),
			'invalid JSON'                          => array( array(), 'application/json', '{', '' ),
			'JSON without the field'                => array( array(), 'application/json', '{"other":"value"}', '' ),
		);
	}

	/*
	|--------------------------------------------------------------------------
	| Decision Reuse Tests
	|--------------------------------------------------------------------------
	*/

	/**
	 * @testdox A reusable decision is supplied once, with the resolved session ID, to a later submission that carries the reference.
	 *
	 * @dataProvider decision_provider
	 *
	 * @param FraudDecision $decision The decision for the request.
	 */
	public function test_reusable_decision_is_supplied_once( FraudDecision $decision ): void {
		$this->assertTrue( $this->verify( $decision )->reuse_decision_for( 'order-123' ) );

		$supplied = $this->supply( 'order-123' );

		$this->assertInstanceOf( SuppliedDecision::class, $supplied );
		$this->assertSame( $decision, $supplied->decision );
		$this->assertSame( 'resolved-session', $supplied->session_id_for_order );
		$this->assertFalse( $this->supply( 'order-123' ), 'The decision is used up' );
	}

	/**
	 * Actionable decisions.
	 *
	 * @return array<string, array{FraudDecision}>
	 */
	public function decision_provider(): array {
		return array(
			'allow' => array( FraudDecision::Allow ),
			'block' => array( FraudDecision::Block ),
		);
	}

	/**
	 * @testdox A reusable decision reaches every later submission type that carries the reference.
	 *
	 * @dataProvider submission_source_provider
	 *
	 * @param string $source Verification source of the submission.
	 */
	public function test_reusable_decision_reaches_submission_sources( string $source ): void {
		$this->verify( FraudDecision::Allow )->reuse_decision_for( 'order-123' );

		$this->assertInstanceOf( SuppliedDecision::class, $this->supply( 'order-123', $source ) );
	}

	/**
	 * Verification sources of the plugin's own submissions.
	 *
	 * @return array<string, array{string}>
	 */
	public function submission_source_provider(): array {
		return array(
			'blocks checkout'    => array( 'blocks_checkout' ),
			'shortcode checkout' => array( 'shortcode_checkout' ),
			'pay for order'      => array( 'pay_for_order' ),
			'add payment method' => array( 'add_payment_method' ),
		);
	}

	/**
	 * @testdox A reusable decision is not supplied to a submission that does not match the attempt.
	 *
	 * @dataProvider mismatching_submission_provider
	 *
	 * @param string $reference  The reference the submission carries.
	 * @param string $gateway_id The submission's payment method.
	 * @param string $source     The submission's verification source.
	 */
	public function test_reusable_decision_is_not_supplied_to_another_attempt( string $reference, string $gateway_id, string $source ): void {
		$this->verify( FraudDecision::Allow )->reuse_decision_for( 'order-123' );

		$this->assertFalse( $this->supply( $reference, $source, $gateway_id ) );
		$this->assertInstanceOf( SuppliedDecision::class, $this->supply( 'order-123' ), 'A mismatch does not use up the decision' );
	}

	/**
	 * Submissions that do not match the reusable decision.
	 *
	 * @return array<string, array{string, string, string}>
	 */
	public function mismatching_submission_provider(): array {
		return array(
			'another reference'          => array( 'order-456', self::GATEWAY_ID, 'shortcode_checkout' ),
			'no reference'               => array( '', self::GATEWAY_ID, 'shortcode_checkout' ),
			'another gateway'            => array( 'order-123', 'other_gateway', 'shortcode_checkout' ),
			'the earlier request source' => array( 'order-123', self::GATEWAY_ID, self::SOURCE ),
		);
	}

	/**
	 * @testdox A reusable decision is used up but not supplied when the cart changed.
	 */
	public function test_reusable_decision_is_not_supplied_after_a_cart_change(): void {
		$this->verify( FraudDecision::Allow )->reuse_decision_for( 'order-123' );
		WC()->cart->add_to_cart( \WC_Helper_Product::create_simple_product()->get_id() );

		$this->assertFalse( $this->supply( 'order-123' ) );
		WC()->cart->empty_cart();
		$this->assertFalse( $this->supply( 'order-123' ), 'The decision was used up' );
	}

	/**
	 * @testdox A reusable decision is not supplied when an item quantity changed.
	 */
	public function test_reusable_decision_is_not_supplied_after_a_quantity_change(): void {
		$this->verify( FraudDecision::Allow )->reuse_decision_for( 'order-123' );
		$cart_item_key = array_key_first( WC()->cart->get_cart() );
		WC()->cart->set_quantity( $cart_item_key, 3 );

		$this->assertFalse( $this->supply( 'order-123' ) );
	}

	/**
	 * @testdox A reusable decision is still supplied when only the cart total changed, as when shipping is calculated at checkout.
	 */
	public function test_reusable_decision_survives_a_total_change(): void {
		$this->verify( FraudDecision::Allow )->reuse_decision_for( 'order-123' );
		$cart_hash = WC()->cart->get_cart_hash();
		WC()->cart->set_total( 999 );
		$this->assertNotSame( $cart_hash, WC()->cart->get_cart_hash(), 'WooCommerce\'s cart hash includes the total' );

		$this->assertInstanceOf( SuppliedDecision::class, $this->supply( 'order-123' ) );
	}

	/**
	 * @testdox No decision is kept for reuse without a resolved session ID or a reference, and the previously kept decision is cleared.
	 *
	 * @dataProvider unusable_reuse_provider
	 *
	 * @param string $resolved_session_id The session ID the verification resolved.
	 * @param string $reference           The reference passed to reuse_decision_for().
	 */
	public function test_unusable_reuse_is_not_kept( string $resolved_session_id, string $reference ): void {
		$this->verify( FraudDecision::Allow )->reuse_decision_for( 'order-123' );

		$this->assertFalse( $this->verify( FraudDecision::Allow, $resolved_session_id )->reuse_decision_for( $reference ) );

		$this->assertFalse( $this->supply( 'order-123' ), 'The new request replaces the previously kept decision' );
		$this->assertFalse( $this->supply( $reference ) );
	}

	/**
	 * Reuses that cannot be kept.
	 *
	 * @return array<string, array{string, string}>
	 */
	public function unusable_reuse_provider(): array {
		return array(
			'no resolved session ID' => array( '', 'order-456' ),
			'no reference'           => array( 'resolved-session', '' ),
		);
	}

	/**
	 * @testdox A newly kept decision for the gateway replaces the previous one.
	 */
	public function test_new_reusable_decision_replaces_the_previous_one(): void {
		$this->verify( FraudDecision::Allow )->reuse_decision_for( 'order-123' );
		$this->verify( FraudDecision::Block )->reuse_decision_for( 'order-456' );

		$this->assertFalse( $this->supply( 'order-123' ) );
		$this->assertSame( FraudDecision::Block, $this->supply( 'order-456' )->decision );
	}

	/**
	 * @testdox A gateway without a reference reader reuses no decisions.
	 */
	public function test_gateway_without_reader_reuses_no_decisions(): void {
		$this->session_verifier->method( 'verify_session' )->willReturn( FraudDecision::Allow );
		$this->session_verifier->method( 'last_verified_session_id' )->willReturn( 'resolved-session' );

		$this->assertTrue( $this->sut->verify_request( self::SOURCE, 'other_gateway', array(), 0, 'browser-session' )->reuse_decision_for( 'order-123' ) );

		$this->assertFalse( $this->supply( 'order-123', 'shortcode_checkout', 'other_gateway' ) );
	}

	/**
	 * @testdox The gateways matched by a reference reader for a prefix share one reusable decision.
	 */
	public function test_prefix_reader_shares_the_decision_among_matching_gateways(): void {
		$this->sut->register_reference_reader( 'family_*', static fn( array $payment_data ) => $payment_data[ self::REFERENCE_FIELD ] ?? '' );
		$this->verify( FraudDecision::Block, 'resolved-session', 'family_card' )->reuse_decision_for( 'order-123' );

		$this->assertFalse( $this->supply( 'order-123', 'shortcode_checkout', 'other_gateway' ), 'A gateway outside the prefix does not reuse it' );
		$supplied = $this->supply( 'order-123', 'shortcode_checkout', 'family_sepa' );

		$this->assertInstanceOf( SuppliedDecision::class, $supplied );
		$this->assertSame( FraudDecision::Block, $supplied->decision );
		$this->assertFalse( $this->supply( 'order-123', 'shortcode_checkout', 'family_card' ), 'The decision is used up for the whole prefix' );
	}

	/**
	 * @testdox A gateway with a reference reader for its exact ID keeps its reusable decision apart from a reader for a prefix that also matches it.
	 */
	public function test_exact_reader_keeps_its_decision_apart_from_prefix_reader(): void {
		$this->sut->register_reference_reader( 'my_*', static fn( array $payment_data ) => $payment_data[ self::REFERENCE_FIELD ] ?? '' );
		$this->verify( FraudDecision::Allow )->reuse_decision_for( 'order-123' );

		$this->assertFalse( $this->supply( 'order-123', 'shortcode_checkout', 'my_other_gateway' ) );
		$this->assertInstanceOf( SuppliedDecision::class, $this->supply( 'order-123' ) );
	}

	/**
	 * @testdox An earlier supplied decision is returned unchanged and leaves the reusable decision in place.
	 */
	public function test_earlier_supplied_decision_wins(): void {
		$this->verify( FraudDecision::Allow )->reuse_decision_for( 'order-123' );
		$earlier = new SuppliedDecision( FraudDecision::Block );

		$this->assertSame( $earlier, $this->sut->supply_reused_decision( $earlier, 'shortcode_checkout', $this->request_data( 'order-123' ) ) );
		$this->assertInstanceOf( SuppliedDecision::class, $this->supply( 'order-123' ) );
	}

	/**
	 * @testdox A throwing reference reader leads to a normal verification and a forwarded warning.
	 */
	public function test_throwing_reader_verifies_normally(): void {
		$this->spy_on_controller_logging();
		$this->sut->register_reference_reader(
			self::GATEWAY_ID,
			static function () {
				throw new \RuntimeException( 'Reader failed' );
			}
		);
		$this->verify( FraudDecision::Allow )->reuse_decision_for( 'order-123' );

		$this->assertFalse( $this->supply( 'order-123' ) );
		$this->assertLogged(
			'warning',
			'Reusing a request decision failed',
			array(
				'event_source'    => 'shortcode_checkout',
				'payment_type'    => self::GATEWAY_ID,
				'exception_class' => \RuntimeException::class,
			),
			true
		);
	}

	/**
	 * @testdox An invalid stored record is discarded.
	 *
	 * @dataProvider invalid_record_provider
	 *
	 * @param mixed $record The stored record.
	 */
	public function test_invalid_record_is_discarded( $record ): void {
		WC()->session->set( '_wc_fraud_protection_reusable_decisions', array( self::GATEWAY_ID => $record ) );

		$this->assertFalse( $this->supply( 'order-123' ) );
		$this->assertNull( WC()->session->get( '_wc_fraud_protection_reusable_decisions' ) );
	}

	/**
	 * Invalid stored records.
	 *
	 * @return array<string, array{mixed}>
	 */
	public function invalid_record_provider(): array {
		$valid = array(
			'source'     => self::SOURCE,
			'session_id' => 'resolved-session',
			'decision'   => FraudDecision::Allow->value,
			'reference'  => 'order-123',
			'cart_hash'  => '',
		);

		return array(
			'not an array'         => array( 'record' ),
			'non-actionable'       => array( array_merge( $valid, array( 'decision' => FraudDecision::Challenge->value ) ) ),
			'unknown decision'     => array( array_merge( $valid, array( 'decision' => 'maybe' ) ) ),
			'empty session ID'     => array( array_merge( $valid, array( 'session_id' => '' ) ) ),
			'non-string reference' => array( array_merge( $valid, array( 'reference' => 123 ) ) ),
			'missing source'       => array( array_diff_key( $valid, array( 'source' => true ) ) ),
			'non-string cart hash' => array( array_merge( $valid, array( 'cart_hash' => null ) ) ),
		);
	}

	/*
	|--------------------------------------------------------------------------
	| Compatibility layer reference readers
	|--------------------------------------------------------------------------
	*/

	/**
	 * @testdox A compatibility layer's reference reader supplies the decision while the gateway has no reader of its own.
	 */
	public function test_compatibility_reader_supplies_without_a_gateway_reader(): void {
		$this->sut->register_compatibility_reference_reader( self::COMPAT_GATEWAY_ID, array( FraudProtectionFeature::CHECKOUT ), self::compatibility_reader() );
		$this->verify( FraudDecision::Block, 'resolved-session', self::COMPAT_GATEWAY_ID )->reuse_decision_for( 'compat-123' );

		$supplied = $this->supply_with_payment_data( array( self::COMPAT_REFERENCE_FIELD => 'compat-123' ), self::COMPAT_GATEWAY_ID );

		$this->assertInstanceOf( SuppliedDecision::class, $supplied );
		$this->assertSame( FraudDecision::Block, $supplied->decision );
	}

	/**
	 * @testdox The gateway's own reference reader replaces a compatibility layer's, whichever registers first.
	 *
	 * @testWith [true]
	 *           [false]
	 *
	 * @param bool $compatibility_first Whether the compatibility layer registers before the gateway.
	 */
	public function test_gateway_reader_replaces_compatibility_reader( bool $compatibility_first ): void {
		// setUp() registered the gateway's reader first; registering it again puts it after the compatibility layer's.
		$this->sut->register_compatibility_reference_reader( self::GATEWAY_ID, array( FraudProtectionFeature::CHECKOUT ), self::compatibility_reader() );
		if ( $compatibility_first ) {
			$this->sut->register_reference_reader( self::GATEWAY_ID, static fn( array $payment_data ) => $payment_data[ self::REFERENCE_FIELD ] ?? '' );
		}
		$this->verify( FraudDecision::Allow )->reuse_decision_for( 'order-123' );

		$this->assertFalse(
			$this->supply_with_payment_data( array( self::COMPAT_REFERENCE_FIELD => 'order-123' ) ),
			'The compatibility reader must not run'
		);
		$this->assertInstanceOf( SuppliedDecision::class, $this->supply( 'order-123' ) );
	}

	/**
	 * @testdox A compatibility layer's reference reader for a prefix serves the matching gateways until the gateway family registers a reader of its own.
	 */
	public function test_compatibility_prefix_reader_serves_matching_gateways_until_replaced(): void {
		$this->sut->register_compatibility_reference_reader( 'compat*', array( FraudProtectionFeature::CHECKOUT ), self::compatibility_reader(), self::COMPAT_MAIN_GATEWAY_ID );
		$this->verify( FraudDecision::Allow, 'resolved-session', self::COMPAT_MAIN_GATEWAY_ID )->reuse_decision_for( 'compat-123' );

		$this->assertInstanceOf( SuppliedDecision::class, $this->supply_with_payment_data( array( self::COMPAT_REFERENCE_FIELD => 'compat-123' ), self::COMPAT_GATEWAY_ID ) );

		$this->sut->register_reference_reader( 'compat_*', static fn( array $payment_data ) => $payment_data[ self::REFERENCE_FIELD ] ?? '' );
		$this->verify( FraudDecision::Allow, 'resolved-session', self::COMPAT_MAIN_GATEWAY_ID )->reuse_decision_for( 'compat-456' );

		$this->assertFalse(
			$this->supply_with_payment_data( array( self::COMPAT_REFERENCE_FIELD => 'compat-456' ), self::COMPAT_GATEWAY_ID ),
			'The compatibility reader no longer runs for the gateways the new reader matches'
		);
	}

	/**
	 * @testdox A compatibility layer's reference reader stands aside only when all the features it implements are declared by the gateway or a covering gateway.
	 *
	 * @dataProvider compatibility_declaration_provider
	 *
	 * @param string[] $implemented_features The features the compatibility layer implements.
	 * @param string   $declaring_gateway_id The gateway that declares features.
	 * @param string[] $declared_features    The features it declares.
	 * @param bool     $supplied             Whether the compatibility reader supplies the decision.
	 */
	public function test_compatibility_reader_stands_aside_when_its_features_are_declared( array $implemented_features, string $declaring_gateway_id, array $declared_features, bool $supplied ): void {
		$this->register_payment_gateways( new FakeNativeGateway( $declaring_gateway_id, $declared_features ) );
		$this->sut->register_compatibility_reference_reader( self::COMPAT_GATEWAY_ID, $implemented_features, self::compatibility_reader(), self::COMPAT_MAIN_GATEWAY_ID );
		$this->verify( FraudDecision::Allow, 'resolved-session', self::COMPAT_GATEWAY_ID )->reuse_decision_for( 'compat-123' );

		$result = $this->supply_with_payment_data( array( self::COMPAT_REFERENCE_FIELD => 'compat-123' ), self::COMPAT_GATEWAY_ID, 'add_payment_method' );

		$this->assertSame( $supplied, $result instanceof SuppliedDecision );
	}

	/**
	 * Implemented and declared features, and whether the compatibility reader still runs.
	 *
	 * @return array<string, array{string[], string, string[], bool}>
	 */
	public function compatibility_declaration_provider(): array {
		$add_payment_method = array( FraudProtectionFeature::ADD_PAYMENT_METHOD );
		$both               = array( FraudProtectionFeature::CHECKOUT, FraudProtectionFeature::ADD_PAYMENT_METHOD );

		return array(
			'the implemented feature'          => array( $add_payment_method, self::COMPAT_GATEWAY_ID, $add_payment_method, false ),
			'another feature'                  => array( $add_payment_method, self::COMPAT_GATEWAY_ID, array( FraudProtectionFeature::CHECKOUT ), true ),
			'both implemented features'        => array( $both, self::COMPAT_GATEWAY_ID, $both, false ),
			'one of two implemented features'  => array( $both, self::COMPAT_GATEWAY_ID, array( FraudProtectionFeature::CHECKOUT ), true ),
			'by the covering gateway'          => array( $add_payment_method, self::COMPAT_MAIN_GATEWAY_ID, $add_payment_method, false ),
			'by a gateway that does not cover' => array( $add_payment_method, 'unrelated_gateway', $add_payment_method, true ),
			'no implemented features'          => array( array(), self::COMPAT_GATEWAY_ID, $both, true ),
		);
	}

	/**
	 * Make the raw request body readable through the legacy proxy.
	 *
	 * @param string $body The raw request body.
	 */
	private function mock_request_body( string $body ): void {
		$this->register_legacy_proxy_function_mocks(
			array(
				'file_get_contents' => static function ( string $filename ) use ( $body ) {
					return 'php://input' === $filename ? $body : false;
				},
			)
		);
	}

	/**
	 * Verify a request with the mock verifier returning a decision and a resolved session ID.
	 *
	 * @param FraudDecision $decision            The decision.
	 * @param string        $resolved_session_id The session ID the verification resolves.
	 * @param string        $gateway_id          The request's gateway.
	 * @return \Automattic\WooCommerce\FraudProtection\VerifiedRequest
	 */
	private function verify( FraudDecision $decision, string $resolved_session_id = 'resolved-session', string $gateway_id = self::GATEWAY_ID ): \Automattic\WooCommerce\FraudProtection\VerifiedRequest {
		$session_verifier = $this->createMock( SessionVerifier::class );
		$session_verifier->method( 'verify_session' )->willReturn( $decision );
		$session_verifier->method( 'last_verified_session_id' )->willReturn( $resolved_session_id );
		$this->sut->init( $session_verifier, new SessionIdNormalizer(), wc_get_container()->get( LegacyProxy::class ), new GatewayNativeSupport() );

		return $this->sut->verify_request( self::SOURCE, $gateway_id, array(), 0, 'browser-session' );
	}

	/**
	 * Run the skip filter for a later submission with the given payment data.
	 *
	 * @param array<string, string> $payment_data The submission's payment data.
	 * @param string                $gateway_id   The submission's payment method.
	 * @param string                $source       The submission's verification source.
	 * @return mixed
	 */
	private function supply_with_payment_data( array $payment_data, string $gateway_id = self::GATEWAY_ID, string $source = 'shortcode_checkout' ) {
		$request_data = array(
			'payment_method' => $gateway_id,
			'payment_data'   => $payment_data,
		);

		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Test invokes the hook.
		return apply_filters( 'woocommerce_fraud_protection_skip_session_verify', false, $source, $request_data, 'checkout-session' );
	}

	/**
	 * Build a compatibility layer's reference reader.
	 *
	 * @return callable
	 */
	private static function compatibility_reader(): callable {
		return static fn( array $payment_data ) => $payment_data[ self::COMPAT_REFERENCE_FIELD ] ?? '';
	}

	/**
	 * Run the skip filter for a later submission.
	 *
	 * @param string $reference  The reference the submission carries; empty for none.
	 * @param string $source     The submission's verification source.
	 * @param string $gateway_id The submission's payment method.
	 * @return mixed
	 */
	private function supply( string $reference, string $source = 'shortcode_checkout', string $gateway_id = self::GATEWAY_ID ) {
		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Test invokes the hook.
		return apply_filters( 'woocommerce_fraud_protection_skip_session_verify', false, $source, $this->request_data( $reference, $gateway_id ), 'checkout-session' );
	}

	/**
	 * Build the request data of a later submission.
	 *
	 * @param string $reference  The reference the submission carries; empty for none.
	 * @param string $gateway_id The submission's payment method.
	 * @return array<string, mixed>
	 */
	private function request_data( string $reference, string $gateway_id = self::GATEWAY_ID ): array {
		return array(
			'payment_method' => $gateway_id,
			'payment_data'   => '' === $reference ? array() : array( self::REFERENCE_FIELD => $reference ),
		);
	}
}
