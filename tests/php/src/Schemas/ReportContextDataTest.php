<?php
/**
 * ReportContextDataTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\FraudProtection\Schemas;

use Automattic\WooCommerce\FraudProtection\Schemas\ReportContextData;
use Automattic\WooCommerce\RestApi\UnitTests\LoggerSpyTrait;
use WC_Unit_Test_Case;

/**
 * Tests for the ReportContextData class.
 *
 * @covers \Automattic\WooCommerce\FraudProtection\Schemas\ReportContextData
 */
class ReportContextDataTest extends WC_Unit_Test_Case {

	use LoggerSpyTrait;

	/**
	 * @testdox from_array() builds a full dispute context with derived fields and nested amount/correlation.
	 */
	public function test_from_array_builds_full_dispute_context(): void {
		$context = ReportContextData::from_array(
			array(
				'type'        => 'dispute',
				'result'      => 'lost',
				'reason'      => 'fraud',
				'amount'      => array(
					'minor_units' => 9900,
					'currency'    => 'usd',
				),
				'occurred_at' => '2026-06-03T12:00:00Z',
				'gateway'     => 'woocommerce_payments',
				'correlation' => array(
					'order_id'       => 12345,
					'transaction_id' => 'ch_3N',
					'dispute_id'     => 'dp_1N',
				),
			)
		);

		$this->assertInstanceOf( ReportContextData::class, $context );
		$this->assertSame(
			array(
				'schema_version'    => 1,
				'type'              => 'dispute',
				'result'            => 'lost',
				'reason'            => 'fraud',
				'classification'    => 'fraud_flagged',
				'evidence_strength' => 'confirmed',
				'amount'            => array(
					'minor_units' => 9900,
					'currency'    => 'USD',
				),
				'occurred_at'       => '2026-06-03T12:00:00Z',
				'gateway'           => 'woocommerce_payments',
				'correlation'       => array(
					'order_id'       => 12345,
					'transaction_id' => 'ch_3N',
					'dispute_id'     => 'dp_1N',
				),
			),
			$context->to_array()
		);
	}

	/**
	 * @testdox from_array() derives classification from reason.
	 * @dataProvider classification_data
	 *
	 * @param string $type                   Event phase.
	 * @param string $result                 Outcome.
	 * @param string $reason                 Caller reason.
	 * @param string $expected_reason        Normalized reason.
	 * @param string $expected_classification Derived classification.
	 */
	public function test_from_array_derives_classification(
		string $type,
		string $result,
		string $reason,
		string $expected_reason,
		string $expected_classification
	): void {
		$context = ReportContextData::from_array(
			array(
				'type'   => $type,
				'result' => $result,
				'reason' => $reason,
			)
		);

		$this->assertInstanceOf( ReportContextData::class, $context );
		$wire = $context->to_array();
		$this->assertSame( $expected_reason, $wire['reason'], 'reason should be normalized' );
		$this->assertSame( $expected_classification, $wire['classification'], 'classification should be derived from reason' );
	}

	/**
	 * Data provider covering each classification bucket.
	 *
	 * @return array<string, array{0:string,1:string,2:string,3:string,4:string}>
	 */
	public function classification_data(): array {
		return array(
			// Every dispute reason (appendix dispute-reason table).
			'dispute fraud'                    => array( 'dispute', 'lost', 'fraud', 'fraud', 'fraud_flagged' ),
			'dispute unrecognized'             => array( 'dispute', 'lost', 'unrecognized', 'unrecognized', 'fraud_flagged' ),
			'dispute subscription_canceled'    => array( 'dispute', 'lost', 'subscription_canceled', 'subscription_canceled', 'service_dispute' ),
			'dispute canceled_or_returned'     => array( 'dispute', 'open', 'canceled_or_returned', 'canceled_or_returned', 'service_dispute' ),
			'dispute product_not_received'     => array( 'dispute', 'open', 'product_not_received', 'product_not_received', 'service_dispute' ),
			'dispute product_not_as_described' => array( 'dispute', 'open', 'product_not_as_described', 'product_not_as_described', 'service_dispute' ),
			'dispute credit_not_processed'     => array( 'dispute', 'open', 'credit_not_processed', 'credit_not_processed', 'service_dispute' ),
			'dispute duplicate'                => array( 'dispute', 'open', 'duplicate', 'duplicate', 'no_risk' ),
			'dispute bank'                     => array( 'dispute', 'open', 'bank', 'bank', 'no_risk' ),
			'dispute other'                    => array( 'dispute', 'open', 'other', 'other', 'no_risk' ),
			// Every payment refusal reason (appendix refusal-reason table).
			'declined lost_or_stolen'          => array( 'payment', 'declined', 'lost_or_stolen', 'lost_or_stolen', 'fraud_flagged' ),
			'declined suspected_fraud'         => array( 'payment', 'declined', 'suspected_fraud', 'suspected_fraud', 'fraud_flagged' ),
			'declined restricted_card'         => array( 'payment', 'declined', 'restricted_card', 'restricted_card', 'fraud_flagged' ),
			'declined security_violation'      => array( 'payment', 'declined', 'security_violation', 'security_violation', 'fraud_flagged' ),
			'declined incorrect_cvc'           => array( 'payment', 'declined', 'incorrect_cvc', 'incorrect_cvc', 'verification_mismatch' ),
			'declined incorrect_avs'           => array( 'payment', 'declined', 'incorrect_avs', 'incorrect_avs', 'verification_mismatch' ),
			'declined incorrect_number'        => array( 'payment', 'declined', 'incorrect_number', 'incorrect_number', 'verification_mismatch' ),
			'declined incorrect_expiry'        => array( 'payment', 'declined', 'incorrect_expiry', 'incorrect_expiry', 'verification_mismatch' ),
			'declined do_not_honor'            => array( 'payment', 'declined', 'do_not_honor', 'do_not_honor', 'hard_decline' ),
			'declined generic_decline'         => array( 'payment', 'declined', 'generic_decline', 'generic_decline', 'hard_decline' ),
			'declined compliance'              => array( 'payment', 'declined', 'compliance', 'compliance', 'hard_decline' ),
			'declined card_not_supported'      => array( 'payment', 'declined', 'card_not_supported', 'card_not_supported', 'hard_decline' ),
			'declined unsupported_currency'    => array( 'payment', 'declined', 'unsupported_currency', 'unsupported_currency', 'hard_decline' ),
			'declined expired_card'            => array( 'payment', 'declined', 'expired_card', 'expired_card', 'hard_decline' ),
			'declined invalid_account'         => array( 'payment', 'declined', 'invalid_account', 'invalid_account', 'hard_decline' ),
			'declined not_permitted'           => array( 'payment', 'declined', 'not_permitted', 'not_permitted', 'hard_decline' ),
			'declined insufficient_funds'      => array( 'payment', 'declined', 'insufficient_funds', 'insufficient_funds', 'soft_decline' ),
			'declined limit_exceeded'          => array( 'payment', 'declined', 'limit_exceeded', 'limit_exceeded', 'soft_decline' ),
			'declined velocity_exceeded'       => array( 'payment', 'declined', 'velocity_exceeded', 'velocity_exceeded', 'soft_decline' ),
			'declined authentication_required' => array( 'payment', 'declined', 'authentication_required', 'authentication_required', 'soft_decline' ),
			'declined issuer_unavailable'      => array( 'payment', 'declined', 'issuer_unavailable', 'issuer_unavailable', 'soft_decline' ),
			'declined processing_error'        => array( 'payment', 'declined', 'processing_error', 'processing_error', 'soft_decline' ),
			'declined test_mode'               => array( 'payment', 'declined', 'test_mode', 'test_mode', 'no_risk' ),
			'declined duplicate'               => array( 'payment', 'declined', 'duplicate', 'duplicate', 'no_risk' ),
			'declined request_error'           => array( 'payment', 'declined', 'request_error', 'request_error', 'no_risk' ),
			'declined operational'             => array( 'payment', 'declined', 'operational', 'operational', 'no_risk' ),
			'blocked suspected_fraud'          => array( 'payment', 'blocked', 'suspected_fraud', 'suspected_fraud', 'fraud_flagged' ),
		);
	}

	/**
	 * @testdox from_array() derives evidence_strength from the outcome.
	 * @dataProvider evidence_data
	 *
	 * @param string $type     Event phase.
	 * @param string $result   Outcome.
	 * @param string $reason   Caller reason (empty when not applicable).
	 * @param string $expected Derived evidence tier.
	 */
	public function test_from_array_derives_evidence_strength( string $type, string $result, string $reason, string $expected ): void {
		$data = array(
			'type'   => $type,
			'result' => $result,
		);
		if ( '' !== $reason ) {
			$data['reason'] = $reason;
		}

		$context = ReportContextData::from_array( $data );

		$this->assertInstanceOf( ReportContextData::class, $context );
		$this->assertSame( $expected, $context->to_array()['evidence_strength'] );
	}

	/**
	 * Data provider covering the evidence_strength table.
	 *
	 * @return array<string, array{0:string,1:string,2:string,3:string}>
	 */
	public function evidence_data(): array {
		return array(
			'dispute lost confirmed'        => array( 'dispute', 'lost', 'fraud', 'confirmed' ),
			'dispute accepted confirmed'    => array( 'dispute', 'accepted', 'fraud', 'confirmed' ),
			'dispute won confirmed'         => array( 'dispute', 'won', 'fraud', 'confirmed' ),
			'dispute open correlated'       => array( 'dispute', 'open', 'fraud', 'correlated' ),
			'dispute inquiry correlated'    => array( 'dispute', 'inquiry', 'fraud', 'correlated' ),
			'dispute prevented correlated'  => array( 'dispute', 'prevented', 'fraud', 'correlated' ),
			'dispute withdrawn neutral'     => array( 'dispute', 'withdrawn', 'fraud', 'neutral' ),
			'payment blocked confirmed'     => array( 'payment', 'blocked', 'suspected_fraud', 'confirmed' ),
			'review_rejected confirmed'     => array( 'payment', 'review_rejected', '', 'confirmed' ),
			'review_approved confirmed'     => array( 'payment', 'review_approved', '', 'confirmed' ),
			'declined risky correlated'     => array( 'payment', 'declined', 'incorrect_cvc', 'correlated' ),
			'declined no_risk neutral'      => array( 'payment', 'declined', 'test_mode', 'neutral' ),
			'review_pending correlated'     => array( 'payment', 'review_pending', '', 'correlated' ),
			'review_expired correlated'     => array( 'payment', 'review_expired', '', 'correlated' ),
			'captured neutral'              => array( 'payment', 'captured', '', 'neutral' ),
			'authorized neutral'            => array( 'payment', 'authorized', '', 'neutral' ),
			'pending neutral'               => array( 'payment', 'pending', '', 'neutral' ),
			'voided neutral'                => array( 'payment', 'voided', '', 'neutral' ),
			'canceled neutral'              => array( 'payment', 'canceled', '', 'neutral' ),
			'refund correlated'             => array( 'refund', 'refunded', '', 'correlated' ),
			'partial refund correlated'     => array( 'refund', 'partially_refunded', '', 'correlated' ),
		);
	}

	/**
	 * @testdox from_array() returns null and logs when type cannot be mapped.
	 */
	public function test_from_array_returns_null_for_unmappable_type(): void {
		$context = ReportContextData::from_array(
			array(
				'type'   => 'chargeback',
				'result' => 'lost',
			)
		);

		$this->assertNull( $context );
		$this->assertLogged( 'warning', 'Unmappable report context type "chargeback"' );
	}

	/**
	 * @testdox from_array() returns null and logs when result is invalid for the type.
	 */
	public function test_from_array_returns_null_for_invalid_result_for_type(): void {
		$context = ReportContextData::from_array(
			array(
				'type'   => 'payment',
				'result' => 'lost',
			)
		);

		$this->assertNull( $context );
		$this->assertLogged( 'warning', 'Unmappable report context result "lost" for type "payment"' );
	}

	/**
	 * @testdox A blocked payment without a reason falls back to suspected_fraud.
	 */
	public function test_blocked_payment_reason_falls_back_to_suspected_fraud(): void {
		$context = ReportContextData::from_array(
			array(
				'type'   => 'payment',
				'result' => 'blocked',
			)
		);

		$this->assertInstanceOf( ReportContextData::class, $context );
		$wire = $context->to_array();
		$this->assertSame( 'suspected_fraud', $wire['reason'] );
		$this->assertSame( 'fraud_flagged', $wire['classification'] );
	}

	/**
	 * @testdox A declined payment with an unmappable reason falls back to generic_decline.
	 */
	public function test_declined_payment_reason_falls_back_to_generic_decline(): void {
		$context = ReportContextData::from_array(
			array(
				'type'   => 'payment',
				'result' => 'declined',
				'reason' => 'totally_unknown_code',
			)
		);

		$this->assertInstanceOf( ReportContextData::class, $context );
		$wire = $context->to_array();
		$this->assertSame( 'generic_decline', $wire['reason'] );
		$this->assertSame( 'hard_decline', $wire['classification'] );
	}

	/**
	 * @testdox A dispute with an unmappable reason skips the report and logs.
	 */
	public function test_dispute_unmappable_reason_skips(): void {
		$context = ReportContextData::from_array(
			array(
				'type'   => 'dispute',
				'result' => 'lost',
				'reason' => 'mystery_reason',
			)
		);

		$this->assertNull( $context );
		$this->assertLogged( 'warning', 'Unmappable required dispute reason "mystery_reason", skipping report.' );
	}

	/**
	 * @testdox A dispute with no reason skips the report.
	 */
	public function test_dispute_without_reason_skips(): void {
		$context = ReportContextData::from_array(
			array(
				'type'   => 'dispute',
				'result' => 'lost',
			)
		);

		$this->assertNull( $context );
		$this->assertLogged( 'warning', 'Unmappable required dispute reason' );
	}

	/**
	 * @testdox A refund carries no reason or classification.
	 */
	public function test_refund_has_no_reason(): void {
		$context = ReportContextData::from_array(
			array(
				'type'   => 'refund',
				'result' => 'refunded',
				'reason' => 'fraud',
			)
		);

		$this->assertInstanceOf( ReportContextData::class, $context );
		$wire = $context->to_array();
		$this->assertArrayNotHasKey( 'reason', $wire );
		$this->assertArrayNotHasKey( 'classification', $wire );
	}

	/**
	 * @testdox An unmappable optional reason is dropped rather than defaulted.
	 */
	public function test_optional_reason_unmapped_is_dropped(): void {
		$context = ReportContextData::from_array(
			array(
				'type'   => 'payment',
				'result' => 'captured',
				'reason' => 'nonsense',
			)
		);

		$this->assertInstanceOf( ReportContextData::class, $context );
		$wire = $context->to_array();
		$this->assertArrayNotHasKey( 'reason', $wire );
		$this->assertArrayNotHasKey( 'classification', $wire );
	}

	/**
	 * @testdox to_array() omits empty optionals and always emits required fields.
	 */
	public function test_to_array_omits_empty_optionals(): void {
		$context = ReportContextData::from_array(
			array(
				'type'    => 'payment',
				'result'  => 'captured',
				'gateway' => 'stripe',
			)
		);

		$this->assertInstanceOf( ReportContextData::class, $context );
		$wire = $context->to_array();

		$this->assertArrayHasKey( 'schema_version', $wire );
		$this->assertArrayHasKey( 'evidence_strength', $wire );
		$this->assertArrayHasKey( 'occurred_at', $wire );
		$this->assertSame( 'stripe', $wire['gateway'] );
		$this->assertArrayNotHasKey( 'reason', $wire );
		$this->assertArrayNotHasKey( 'classification', $wire );
		$this->assertArrayNotHasKey( 'amount', $wire );
		$this->assertArrayNotHasKey( 'correlation', $wire );
	}

	/**
	 * @testdox amount is emitted only when both minor_units and a valid currency resolve.
	 */
	public function test_amount_requires_both_fields(): void {
		$missing_currency = ReportContextData::from_array(
			array(
				'type'   => 'payment',
				'result' => 'captured',
				'amount' => array( 'minor_units' => 500 ),
			)
		);
		$this->assertInstanceOf( ReportContextData::class, $missing_currency );
		$this->assertArrayNotHasKey( 'amount', $missing_currency->to_array() );

		$invalid_currency = ReportContextData::from_array(
			array(
				'type'   => 'payment',
				'result' => 'captured',
				'amount' => array(
					'minor_units' => 500,
					'currency'    => 'dollars',
				),
			)
		);
		$this->assertInstanceOf( ReportContextData::class, $invalid_currency );
		$this->assertArrayNotHasKey( 'amount', $invalid_currency->to_array() );

		$valid = ReportContextData::from_array(
			array(
				'type'   => 'payment',
				'result' => 'captured',
				'amount' => array(
					'minor_units' => 500,
					'currency'    => 'eur',
				),
			)
		);
		$this->assertInstanceOf( ReportContextData::class, $valid );
		$this->assertSame(
			array(
				'minor_units' => 500,
				'currency'    => 'EUR',
			),
			$valid->to_array()['amount']
		);
	}

	/**
	 * @testdox occurred_at is normalized to UTC; a missing value falls back to now.
	 */
	public function test_occurred_at_normalized_and_falls_back(): void {
		$with_offset = ReportContextData::from_array(
			array(
				'type'        => 'payment',
				'result'      => 'captured',
				'occurred_at' => '2026-06-03T12:00:00+02:00',
			)
		);
		$this->assertInstanceOf( ReportContextData::class, $with_offset );
		$this->assertSame( '2026-06-03T10:00:00Z', $with_offset->to_array()['occurred_at'] );

		$without = ReportContextData::from_array(
			array(
				'type'   => 'payment',
				'result' => 'captured',
			)
		);
		$this->assertInstanceOf( ReportContextData::class, $without );
		$this->assertMatchesRegularExpression(
			'/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/',
			$without->to_array()['occurred_at'],
			'A missing occurred_at should fall back to a UTC ISO 8601 timestamp'
		);

		$non_iso = ReportContextData::from_array(
			array(
				'type'        => 'payment',
				'result'      => 'captured',
				'occurred_at' => '@1700000000',
			)
		);
		$this->assertInstanceOf( ReportContextData::class, $non_iso );
		$this->assertStringStartsNotWith(
			'2023-',
			$non_iso->to_array()['occurred_at'],
			'A non-ISO "@unix" string should fall back to now, not parse to its 2023 value'
		);
	}

	/**
	 * @testdox with_order_defaults() fills only the empty gateway and missing order ID.
	 */
	public function test_with_order_defaults_fills_only_missing(): void {
		$bare = ReportContextData::from_array(
			array(
				'type'   => 'payment',
				'result' => 'captured',
			)
		);
		$this->assertInstanceOf( ReportContextData::class, $bare );

		$filled = $bare->with_order_defaults( 777, 'stripe' )->to_array();
		$this->assertSame( 'stripe', $filled['gateway'] );
		$this->assertSame( 777, $filled['correlation']['order_id'] );

		$prefilled = ReportContextData::from_array(
			array(
				'type'        => 'payment',
				'result'      => 'captured',
				'gateway'     => 'square_credit_card',
				'correlation' => array( 'order_id' => 999 ),
			)
		);
		$this->assertInstanceOf( ReportContextData::class, $prefilled );

		$kept = $prefilled->with_order_defaults( 777, 'stripe' )->to_array();
		$this->assertSame( 'square_credit_card', $kept['gateway'], 'caller-supplied gateway must win' );
		$this->assertSame( 999, $kept['correlation']['order_id'], 'caller-supplied order_id must win' );
	}

	/**
	 * @testdox correlation IDs are sanitized and empty entries are dropped from the wire.
	 */
	public function test_correlation_sanitized_and_empty_dropped(): void {
		$context = ReportContextData::from_array(
			array(
				'type'        => 'dispute',
				'result'      => 'open',
				'reason'      => 'fraud',
				'correlation' => array(
					'order_id'               => '54321',
					'dispute_id'             => 'dp_9',
					'transaction_id'         => '',
					'network_transaction_id' => null,
				),
			)
		);
		$this->assertInstanceOf( ReportContextData::class, $context );

		$correlation = $context->to_array()['correlation'];
		$this->assertSame( 54321, $correlation['order_id'], 'numeric order_id is cast to int' );
		$this->assertSame( 'dp_9', $correlation['dispute_id'] );
		$this->assertArrayNotHasKey( 'transaction_id', $correlation, 'empty string ID is dropped' );
		$this->assertArrayNotHasKey( 'network_transaction_id', $correlation, 'null ID is dropped' );
	}

	/**
	 * @testdox A non-positive correlation order_id is treated as missing and is backfilled by with_order_defaults().
	 */
	public function test_non_positive_order_id_is_treated_as_missing(): void {
		$zero = ReportContextData::from_array(
			array(
				'type'        => 'payment',
				'result'      => 'captured',
				'correlation' => array( 'order_id' => 0 ),
			)
		);
		$this->assertInstanceOf( ReportContextData::class, $zero );
		$this->assertArrayNotHasKey( 'correlation', $zero->to_array(), 'order_id 0 must not produce a correlation block' );

		$backfilled = $zero->with_order_defaults( 555, 'stripe' )->to_array();
		$this->assertSame( 555, $backfilled['correlation']['order_id'], 'with_order_defaults must backfill when order_id was non-positive' );

		$negative = ReportContextData::from_array(
			array(
				'type'        => 'payment',
				'result'      => 'captured',
				'correlation' => array( 'order_id' => -5 ),
			)
		);
		$this->assertInstanceOf( ReportContextData::class, $negative );
		$this->assertArrayNotHasKey( 'correlation', $negative->to_array(), 'negative order_id must be dropped' );
	}
}
