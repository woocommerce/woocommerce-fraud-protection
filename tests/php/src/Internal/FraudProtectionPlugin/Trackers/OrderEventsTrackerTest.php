<?php
/**
 * OrderEventsTrackerTest class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin\Trackers;

use Automattic\WooCommerce\Internal\FraudProtectionPlugin\ApiClient;
use Automattic\WooCommerce\FraudProtection\Schemas\ReportSource;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Trackers\OrderEventsTracker;
use Automattic\WooCommerce\FraudProtection\SessionVerifier;
use Automattic\WooCommerce\FraudProtection\SessionIdNormalizer;
use Automattic\WooCommerce\FraudProtection\Schemas\ReportContextData;
use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;

/**
 * Tests for the OrderEventsTracker class.
 *
 * @covers \Automattic\WooCommerce\Internal\FraudProtectionPlugin\Trackers\OrderEventsTracker
 */
class OrderEventsTrackerTest extends FraudProtectionUnitTestCase {

	/**
	 * The System Under Test.
	 *
	 * @var OrderEventsTracker
	 */
	private OrderEventsTracker $sut;

	/**
	 * Mock API client.
	 *
	 * @var ApiClient&\PHPUnit\Framework\MockObject\MockObject
	 */
	private $api_client;

	/**
	 * Session ID normalizer.
	 *
	 * @var SessionIdNormalizer
	 */
	private $session_id_normalizer;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->api_client           = $this->createMock( ApiClient::class );
		$this->session_id_normalizer = new SessionIdNormalizer();

		$this->sut = new OrderEventsTracker();
		$this->sut->init( $this->api_client, $this->session_id_normalizer );
	}

	/**
	 * Build a minimal valid report context.
	 *
	 * @param array<string, mixed> $overrides Fields merged over the defaults.
	 * @return ReportContextData
	 */
	private function make_context( array $overrides = array() ): ReportContextData {
		$context = ReportContextData::from_array(
			array_merge(
				array(
					'type'   => 'payment',
					'result' => 'captured',
				),
				$overrides
			)
		);

		$this->assertInstanceOf( ReportContextData::class, $context );
		return $context;
	}

	/*
	|--------------------------------------------------------------------------
	| Fraud Protection Report Tests
	|--------------------------------------------------------------------------
	*/

	/**
	 * @testdox fraud_protection_report() sends report_id, source, notes, context, and top-level occurred_at + correlation_dispute_id.
	 */
	public function test_fraud_protection_report_sends_context_payload(): void {
		$order = \WC_Helper_Order::create_order();
		$order->update_meta_data( SessionVerifier::ORDER_BLACKBOX_SESSION_ID_KEY, 'bb-session-123' );
		$order->save_meta_data();

		$context = $this->make_context(
			array(
				'type'                   => 'dispute',
				'result'                 => 'lost',
				'reason'                 => 'fraud',
				'gateway'                => 'woocommerce_payments',
				'correlation_order_id'   => 555,
				'correlation_dispute_id' => 'dp_1',
			)
		);

		$this->api_client
			->expects( $this->once() )
			->method( 'report' )
			->with(
				'bb-session-123',
				array(
					'report_id'              => 'evt_dp_1',
					'source'                 => ReportSource::Chargeback->value,
					'notes'                  => 'Visa CB 10.4 fraud.',
					'occurred_at'            => '2026-06-03T12:00:00+00:00',
					'correlation_dispute_id' => 'dp_1',
					'context'                => $context->to_array(),
				)
			);

		$this->sut->fraud_protection_report(
			$order,
			ReportSource::Chargeback,
			'evt_dp_1',
			$context,
			new \DateTimeImmutable( '2026-06-03T12:00:00Z' ),
			'Visa CB 10.4 fraud.'
		);
	}

	/**
	 * @testdox fraud_protection_report() sends the normalized stored session ID to the API
	 */
	public function test_fraud_protection_report_uses_normalized_stored_session_id(): void {
		$stored     = 'legacy-stored-session-id';
		$normalized = 'normalized-by-helper';
		$normalizer = $this->createMock( SessionIdNormalizer::class );
		$order      = \WC_Helper_Order::create_order();
		$order->update_meta_data( SessionVerifier::ORDER_BLACKBOX_SESSION_ID_KEY, $stored );
		$order->save_meta_data();

		$normalizer
			->expects( $this->once() )
			->method( 'normalize_stored' )
			->with( $stored )
			->willReturn( $normalized );
		$this->sut->init( $this->api_client, $normalizer );

		$this->api_client
			->expects( $this->once() )
			->method( 'report' )
			->with( $normalized, $this->anything() );

		$this->sut->fraud_protection_report( $order, ReportSource::Api, 'rep_legacy', $this->make_context() );
	}

	/**
	 * @testdox fraud_protection_report() sends report_id and occurred_at top-level only; correlation_dispute_id top-level and in the context.
	 */
	public function test_fraud_protection_report_field_placement(): void {
		$order = \WC_Helper_Order::create_order();
		$order->update_meta_data( SessionVerifier::ORDER_BLACKBOX_SESSION_ID_KEY, 'bb-session-123' );
		$order->save_meta_data();

		$context = $this->make_context(
			array(
				'type'                   => 'dispute',
				'result'                 => 'lost',
				'correlation_dispute_id' => 'dp_1',
			)
		);

		$captured = array();
		$this->api_client
			->expects( $this->once() )
			->method( 'report' )
			->willReturnCallback(
				function ( string $session_id, array $payload ) use ( &$captured ) {
					$captured = $payload;
					return true;
				}
			);

		$this->sut->fraud_protection_report(
			$order,
			ReportSource::Chargeback,
			'rep_top',
			$context,
			new \DateTimeImmutable( '2026-06-03T12:00:00Z' )
		);

		// Top-level report fields.
		$this->assertSame( 'rep_top', $captured['report_id'] );
		$this->assertSame( '2026-06-03T12:00:00+00:00', $captured['occurred_at'] );
		$this->assertSame( 'dp_1', $captured['correlation_dispute_id'] );
		// report_id and occurred_at are top-level only, not in the context.
		$this->assertArrayNotHasKey( 'report_id', $captured['context'] );
		$this->assertArrayNotHasKey( 'occurred_at', $captured['context'] );
		// correlation_dispute_id is kept in the context too.
		$this->assertSame( 'dp_1', $captured['context']['correlation_dispute_id'] );
	}

	/**
	 * @testdox fraud_protection_report() sends a null top-level correlation_dispute_id for a non-dispute event.
	 */
	public function test_fraud_protection_report_null_dispute_id_for_non_dispute(): void {
		$order = \WC_Helper_Order::create_order();
		$order->update_meta_data( SessionVerifier::ORDER_BLACKBOX_SESSION_ID_KEY, 'bb-session-123' );
		$order->save_meta_data();

		$context = $this->make_context(
			array(
				'type'   => 'payment',
				'result' => 'captured',
			)
		);

		$captured = array();
		$this->api_client
			->expects( $this->once() )
			->method( 'report' )
			->willReturnCallback(
				function ( string $session_id, array $payload ) use ( &$captured ) {
					$captured = $payload;
					return true;
				}
			);

		$this->sut->fraud_protection_report(
			$order,
			ReportSource::Api,
			'rep_np',
			$context,
			new \DateTimeImmutable( '2026-06-03T12:00:00Z' )
		);

		$this->assertSame( '2026-06-03T12:00:00+00:00', $captured['occurred_at'] );
		// A non-dispute has no dispute id; the top-level field is not pruned, so it goes out as null.
		$this->assertArrayHasKey( 'correlation_dispute_id', $captured );
		$this->assertNull( $captured['correlation_dispute_id'] );
	}

	/**
	 * @testdox fraud_protection_report() renders a non-UTC occurred_at to UTC and defaults a null one to now.
	 */
	public function test_fraud_protection_report_normalizes_occurred_at(): void {
		$order = \WC_Helper_Order::create_order();
		$order->update_meta_data( SessionVerifier::ORDER_BLACKBOX_SESSION_ID_KEY, 'bb-session-123' );
		$order->save_meta_data();

		$captured = array();
		$this->api_client
			->method( 'report' )
			->willReturnCallback(
				function ( string $session_id, array $payload ) use ( &$captured ) {
					$captured = $payload;
					return true;
				}
			);

		// A non-UTC instant renders to its UTC equivalent at the top level.
		$this->sut->fraud_protection_report(
			$order,
			ReportSource::Api,
			'rep_tz',
			$this->make_context(),
			new \DateTimeImmutable( '2026-06-03T12:00:00+02:00' )
		);
		$this->assertSame( '2026-06-03T10:00:00+00:00', $captured['occurred_at'] );
		$this->assertArrayNotHasKey( 'occurred_at', $captured['context'] );

		// A null occurred_at falls back to the current time as a UTC ISO 8601 string.
		$this->sut->fraud_protection_report( $order, ReportSource::Api, 'rep_now', $this->make_context() );
		$fallback = \DateTimeImmutable::createFromFormat( \DateTimeInterface::RFC3339, $captured['occurred_at'] );
		$this->assertInstanceOf( \DateTimeImmutable::class, $fallback, 'the fallback is a parseable RFC3339 timestamp' );
		$this->assertSame( $captured['occurred_at'], $fallback->format( \DateTimeInterface::RFC3339 ), 'the fallback is a canonical RFC3339 string' );
		$this->assertSame( 0, $fallback->getOffset(), 'the fallback occurred_at is UTC' );
	}

	/**
	 * @testdox fraud_protection_report() backfills gateway and order_id from the order.
	 */
	public function test_fraud_protection_report_enriches_context_from_order(): void {
		$order = \WC_Helper_Order::create_order();
		$order->set_payment_method( 'stripe' );
		$order->update_meta_data( SessionVerifier::ORDER_BLACKBOX_SESSION_ID_KEY, 'bb-session-789' );
		$order->save();

		$captured = array();
		$this->api_client
			->expects( $this->once() )
			->method( 'report' )
			->willReturnCallback(
				function ( string $session_id, array $payload ) use ( &$captured ) {
					$captured = $payload;
					return true;
				}
			);

		$this->sut->fraud_protection_report( $order, ReportSource::Api, 'rep_enrich', $this->make_context() );

		$this->assertSame( 'stripe', $captured['context']['gateway'], 'gateway should be backfilled from the order' );
		$this->assertSame( $order->get_id(), $captured['context']['correlation_order_id'], 'order_id should be backfilled from the order' );
	}

	/**
	 * @testdox fraud_protection_report() skips reporting when the stored session ID normalizes to empty.
	 *
	 * @dataProvider unusable_stored_session_id_provider
	 *
	 * @param string $stored_session_id Stored session ID.
	 */
	public function test_fraud_protection_report_skips_when_session_id_normalizes_to_empty( string $stored_session_id ): void {
		$order = \WC_Helper_Order::create_order();
		$order->update_meta_data( SessionVerifier::ORDER_BLACKBOX_SESSION_ID_KEY, $stored_session_id );
		$order->save_meta_data();

		$this->api_client
			->expects( $this->never() )
			->method( 'report' );

		$this->sut->fraud_protection_report( $order, ReportSource::Api, 'rep_nosession', $this->make_context() );

		$this->assertLogged( 'warning', 'Missing session ID in order meta, skipping Blackbox API report.' );
	}

	/**
	 * Stored session IDs that cannot be reported.
	 *
	 * @return array<string, array{string}>
	 */
	public function unusable_stored_session_id_provider(): array {
		return array(
			'missing'    => array( '' ),
			'single dot' => array( '.' ),
			'double dot' => array( '..' ),
		);
	}

	/**
	 * @testdox fraud_protection_report() catches exceptions and logs an error.
	 */
	public function test_fraud_protection_report_catches_exceptions(): void {
		$order = \WC_Helper_Order::create_order();
		$order->update_meta_data( SessionVerifier::ORDER_BLACKBOX_SESSION_ID_KEY, 'bb-session-123' );
		$order->save_meta_data();

		$this->api_client
			->method( 'report' )
			->willThrowException( new \RuntimeException( 'API connection failed' ) );

		$this->sut->fraud_protection_report( $order, ReportSource::Api, 'rep_exc', $this->make_context() );

		$this->assertLogged( 'error', 'Failed to report 3rd party event to Blackbox API' );
	}
}
