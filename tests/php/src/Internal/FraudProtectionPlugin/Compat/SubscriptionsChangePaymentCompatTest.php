<?php
/**
 * SubscriptionsChangePaymentCompatTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin\Compat;

use Automattic\WooCommerce\FraudProtection\Schemas\FraudDecision;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\BlockedSessionNotice;
use Automattic\WooCommerce\FraudProtection\MessageContext;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\ClassicFormDataExtractionTrait;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Compat\SubscriptionsChangePaymentCompat;
use Automattic\WooCommerce\FraudProtection\SessionVerifier;
use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;

/**
 * Tests for the SubscriptionsChangePaymentCompat class.
 *
 * @covers \Automattic\WooCommerce\Internal\FraudProtectionPlugin\Compat\SubscriptionsChangePaymentCompat
 */
class SubscriptionsChangePaymentCompatTest extends FraudProtectionUnitTestCase {

	/**
	 * The System Under Test.
	 *
	 * @var SubscriptionsChangePaymentCompat
	 */
	private SubscriptionsChangePaymentCompat $sut;

	/**
	 * Mock session verifier.
	 *
	 * @var SessionVerifier&\PHPUnit\Framework\MockObject\MockObject
	 */
	private $session_verifier;

	/**
	 * Mock blocked session notice.
	 *
	 * @var BlockedSessionNotice&\PHPUnit\Framework\MockObject\MockObject
	 */
	private $blocked_session_notice;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->session_verifier       = $this->createMock( SessionVerifier::class );
		$this->blocked_session_notice = $this->createMock( BlockedSessionNotice::class );

		$this->sut = new SubscriptionsChangePaymentCompat();
		$this->sut->init(
			$this->session_verifier,
			$this->blocked_session_notice
		);
	}

	/**
	 * Tear down after each test.
	 */
	public function tearDown(): void {
		$_POST = array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		wc_clear_notices();
		remove_all_actions( 'woocommerce_subscription_change_payment_method_via_pay_shortcode' );
		remove_all_filters( 'wp_redirect' );

		parent::tearDown();
	}

	/**
	 * Intercept wp_redirect to prevent headers-already-sent errors in tests.
	 * Throws RedirectInterceptedException so the `exit` after wp_safe_redirect
	 * is never reached.
	 *
	 * @return void
	 */
	private function intercept_redirect(): void {
		add_filter( // @phpstan-ignore return.missing
			'wp_redirect',
			function ( string $location ): string {
				throw new RedirectInterceptedException( $location );
			}
		);
	}

	/**
	 * @testdox SubscriptionsChangePaymentCompat uses ClassicFormDataExtractionTrait.
	 */
	public function test_uses_classic_form_data_extraction_trait(): void {
		$this->assertContains(
			ClassicFormDataExtractionTrait::class,
			class_uses( SubscriptionsChangePaymentCompat::class )
		);
	}

	/*
	|--------------------------------------------------------------------------
	| register() Tests
	|--------------------------------------------------------------------------
	*/

	/**
	 * @testdox register() hooks woocommerce_subscription_change_payment_method_via_pay_shortcode.
	 */
	public function test_register_hooks(): void {
		$this->sut->register();

		$this->assertNotFalse(
			has_action( 'woocommerce_subscription_change_payment_method_via_pay_shortcode', array( $this->sut, 'verify_and_block' ) ),
			'woocommerce_subscription_change_payment_method_via_pay_shortcode action should be registered'
		);
	}

	/*
	|--------------------------------------------------------------------------
	| verify_and_block() Tests
	|--------------------------------------------------------------------------
	*/

	/**
	 * @testdox verify_and_block() passes session_id, source, subscription ID, and request_data to SessionVerifier on ALLOW.
	 */
	public function test_verify_allows_on_allow_decision(): void {
		$subscription = $this->create_mock_subscription( 42 );

		$_POST['wc_fraud_protection_session_id'] = 'test-session-123';
		$_POST['payment_method']                 = 'stripe';

		$this->session_verifier
			->expects( $this->once() )
			->method( 'verify_session' )
			->with( 'test-session-123', 'subscriptions_change_payment_method', 42, $this->isType( 'array' ) )
			->willReturn( FraudDecision::Allow );

		$this->sut->verify_and_block( $subscription );

		$this->assertSame( 0, wc_notice_count( 'error' ), 'No error notice should be added on ALLOW' );
	}

	/**
	 * @testdox verify_and_block() adds error notice with generic context and redirects to view-subscription on BLOCK.
	 */
	public function test_verify_blocks_on_block_decision(): void {
		$this->intercept_redirect();
		$subscription = $this->create_mock_subscription( 99 );

		$_POST['wc_fraud_protection_session_id'] = 'test-session-456';
		$_POST['payment_method']                 = 'woocommerce_payments';

		$this->blocked_session_notice
			->expects( $this->once() )
			->method( 'get_message_html' )
			->with( MessageContext::Generic )
			->willReturn( 'We are unable to process this request online. Please <a href="mailto:test@example.com">contact support (test@example.com)</a> for assistance.' );

		$this->session_verifier
			->expects( $this->once() )
			->method( 'verify_session' )
			->willReturn( FraudDecision::Block );

		try {
			$this->sut->verify_and_block( $subscription );
			$this->fail( 'Expected RedirectInterceptedException' );
		} catch ( RedirectInterceptedException $e ) {
			$this->assertMatchesRegularExpression(
				'#^http://example\.org/my-account/view-subscription/99/$#',
				$e->getMessage(),
				'Should redirect to view-subscription page'
			);
		}

		$this->assertTrue(
			wc_has_notice(
				'We are unable to process this request online. Please <a href="mailto:test@example.com">contact support (test@example.com)</a> for assistance.',
				'error'
			),
			'Error notice should be added on BLOCK'
		);
	}

	/**
	 * @testdox verify_and_block() extracts payment_method from POST data.
	 */
	public function test_verify_extracts_payment_method_from_post(): void {
		$subscription = $this->create_mock_subscription( 1 );

		$_POST['payment_method']                 = 'woocommerce_payments';
		$_POST['wc_fraud_protection_session_id'] = 'sess-abc';

		$this->session_verifier
			->expects( $this->once() )
			->method( 'verify_session' )
			->with(
				'sess-abc',
				'subscriptions_change_payment_method',
				1,
				$this->callback(
					function ( array $request_data ): bool {
						return 'woocommerce_payments' === ( $request_data['payment_method'] ?? '' );
					}
				)
			)
			->willReturn( FraudDecision::Allow );

		$this->sut->verify_and_block( $subscription );
	}

	/**
	 * @testdox verify_and_block() does not duplicate the blocked notice if already present.
	 */
	public function test_verify_deduplicates_blocked_notice(): void {
		$this->intercept_redirect();
		$subscription = $this->create_mock_subscription( 1 );

		$_POST['wc_fraud_protection_session_id'] = 'test-session-dedup';
		$_POST['payment_method']                 = 'stripe';

		$this->blocked_session_notice
			->method( 'get_message_html' )
			->with( MessageContext::Generic )
			->willReturn( 'Blocked message.' );

		$this->session_verifier
			->method( 'verify_session' )
			->willReturn( FraudDecision::Block );

		// Pre-add the same notice.
		wc_add_notice( 'Blocked message.', 'error' );

		try {
			$this->sut->verify_and_block( $subscription );
			$this->fail( 'Expected RedirectInterceptedException' );
		} catch ( RedirectInterceptedException $e ) {
			// Expected.
		}

		// Should still be just 1 notice, not 2.
		$this->assertSame( 1, wc_notice_count( 'error' ) );
	}

	/**
	 * Create a mock WC_Order representing a subscription.
	 *
	 * @param int $id The subscription/order ID.
	 * @return \WC_Order&\PHPUnit\Framework\MockObject\MockObject
	 */
	private function create_mock_subscription( int $id ) {
		$subscription = $this->createMock( \WC_Order::class );
		$subscription->method( 'get_id' )->willReturn( $id );
		$subscription->method( 'get_view_order_url' )->willReturn(
			'http://example.org/my-account/view-subscription/' . $id . '/'
		);
		return $subscription;
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
/**
 * Exception thrown when wp_redirect is intercepted in tests.
 */
class RedirectInterceptedException extends \Exception {
}
