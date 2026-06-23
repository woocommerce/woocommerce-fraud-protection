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
	 * @testdox from_array() builds a full context with normalized facts and nested amount/correlation.
	 */
	public function test_from_array_builds_full_context(): void {
		$context = ReportContextData::from_array(
			array(
				'type'            => 'dispute',
				'result'          => 'lost',
				'reason'          => 'fraud',
				'liability_shift' => 'shifted',
				'amount'          => array(
					'minor_units' => 9900,
					'currency'    => 'usd',
				),
				'occurred_at'     => '2026-06-03T12:00:00Z',
				'gateway'         => 'woocommerce_payments',
				'correlation'     => array(
					'order_id'       => 12345,
					'transaction_id' => 'ch_3N',
					'dispute_id'     => 'dp_1N',
				),
			)
		);

		$this->assertInstanceOf( ReportContextData::class, $context );
		$this->assertSame(
			array(
				'schema_version'  => 1,
				'type'            => 'dispute',
				'result'          => 'lost',
				'reason'          => 'fraud',
				'liability_shift' => 'shifted',
				'amount'          => array(
					'minor_units' => 9900,
					'currency'    => 'USD',
				),
				'occurred_at'     => '2026-06-03T12:00:00Z',
				'gateway'         => 'woocommerce_payments',
				'correlation'     => array(
					'order_id'       => 12345,
					'transaction_id' => 'ch_3N',
					'dispute_id'     => 'dp_1N',
				),
			),
			$context->to_array()
		);
	}

	/**
	 * @testdox to_array() emits only normalized fact fields; interpretation stays server-side.
	 */
	public function test_wire_contains_only_normalized_fact_fields(): void {
		$context = ReportContextData::from_array(
			array(
				'type'            => 'dispute',
				'result'          => 'lost',
				'reason'          => 'fraud',
				'liability_shift' => 'shifted',
				'amount'          => array(
					'minor_units' => 100,
					'currency'    => 'usd',
				),
				'gateway'         => 'woocommerce_payments',
				'correlation'     => array( 'order_id' => 5 ),
				'instrument'      => array( 'fingerprint' => 'fp_1' ),
			)
		);

		$this->assertInstanceOf( ReportContextData::class, $context );

		$allowed = array(
			'schema_version',
			'type',
			'result',
			'reason',
			'liability_shift',
			'amount',
			'occurred_at',
			'gateway',
			'correlation',
			'instrument',
		);
		$this->assertSame(
			array(),
			array_diff( array_keys( $context->to_array() ), $allowed ),
			'The wire must carry only normalized fact fields; no interpreted output may leak.'
		);
	}

	/**
	 * @testdox from_array() accepts every normalized reason and sends it unchanged.
	 * @dataProvider reason_data
	 *
	 * @param string $type   Event phase.
	 * @param string $result Outcome.
	 * @param string $reason Normalized reason.
	 */
	public function test_valid_reasons_round_trip( string $type, string $result, string $reason ): void {
		$context = ReportContextData::from_array(
			array(
				'type'   => $type,
				'result' => $result,
				'reason' => $reason,
			)
		);

		$this->assertInstanceOf( ReportContextData::class, $context );
		$this->assertSame( $reason, $context->to_array()['reason'] );
	}

	/**
	 * Data provider covering the full normalized reason vocabulary.
	 *
	 * @return array<string, array{0:string,1:string,2:string}>
	 */
	public function reason_data(): array {
		return array(
			// Every dispute reason (appendix dispute-reason table).
			'dispute fraud'                    => array( 'dispute', 'lost', 'fraud' ),
			'dispute unrecognized'             => array( 'dispute', 'lost', 'unrecognized' ),
			'dispute subscription_canceled'    => array( 'dispute', 'lost', 'subscription_canceled' ),
			'dispute canceled_or_returned'     => array( 'dispute', 'open', 'canceled_or_returned' ),
			'dispute product_not_received'     => array( 'dispute', 'open', 'product_not_received' ),
			'dispute product_not_as_described' => array( 'dispute', 'open', 'product_not_as_described' ),
			'dispute credit_not_processed'     => array( 'dispute', 'open', 'credit_not_processed' ),
			'dispute duplicate'                => array( 'dispute', 'open', 'duplicate' ),
			'dispute bank'                     => array( 'dispute', 'open', 'bank' ),
			'dispute other'                    => array( 'dispute', 'open', 'other' ),
			// Every payment refusal reason (appendix refusal-reason table).
			'declined lost_or_stolen'          => array( 'payment', 'declined', 'lost_or_stolen' ),
			'declined suspected_fraud'         => array( 'payment', 'declined', 'suspected_fraud' ),
			'declined restricted_card'         => array( 'payment', 'declined', 'restricted_card' ),
			'declined security_violation'      => array( 'payment', 'declined', 'security_violation' ),
			'declined incorrect_cvc'           => array( 'payment', 'declined', 'incorrect_cvc' ),
			'declined incorrect_avs'           => array( 'payment', 'declined', 'incorrect_avs' ),
			'declined incorrect_number'        => array( 'payment', 'declined', 'incorrect_number' ),
			'declined incorrect_expiry'        => array( 'payment', 'declined', 'incorrect_expiry' ),
			'declined do_not_honor'            => array( 'payment', 'declined', 'do_not_honor' ),
			'declined generic_decline'         => array( 'payment', 'declined', 'generic_decline' ),
			'declined compliance'              => array( 'payment', 'declined', 'compliance' ),
			'declined card_not_supported'      => array( 'payment', 'declined', 'card_not_supported' ),
			'declined unsupported_currency'    => array( 'payment', 'declined', 'unsupported_currency' ),
			'declined expired_card'            => array( 'payment', 'declined', 'expired_card' ),
			'declined invalid_account'         => array( 'payment', 'declined', 'invalid_account' ),
			'declined not_permitted'           => array( 'payment', 'declined', 'not_permitted' ),
			'declined insufficient_funds'      => array( 'payment', 'declined', 'insufficient_funds' ),
			'declined limit_exceeded'          => array( 'payment', 'declined', 'limit_exceeded' ),
			'declined velocity_exceeded'       => array( 'payment', 'declined', 'velocity_exceeded' ),
			'declined authentication_required' => array( 'payment', 'declined', 'authentication_required' ),
			'declined issuer_unavailable'      => array( 'payment', 'declined', 'issuer_unavailable' ),
			'declined processing_error'        => array( 'payment', 'declined', 'processing_error' ),
			'declined test_mode'               => array( 'payment', 'declined', 'test_mode' ),
			'declined duplicate'               => array( 'payment', 'declined', 'duplicate' ),
			'declined request_error'           => array( 'payment', 'declined', 'request_error' ),
			'declined operational'             => array( 'payment', 'declined', 'operational' ),
			'blocked suspected_fraud'          => array( 'payment', 'blocked', 'suspected_fraud' ),
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
	 * @testdox A blocked payment with no mappable reason omits it — there is no fallback.
	 */
	public function test_blocked_payment_has_no_reason_fallback(): void {
		$context = ReportContextData::from_array(
			array(
				'type'   => 'payment',
				'result' => 'blocked',
			)
		);

		$this->assertInstanceOf( ReportContextData::class, $context );
		$wire = $context->to_array();
		$this->assertArrayNotHasKey( 'reason', $wire, 'a blocked payment no longer falls back to suspected_fraud' );
		$this->assertSame( 'blocked', $wire['result'], 'result still carries the outcome' );
	}

	/**
	 * @testdox A provided reason that cannot be mapped is dropped and logged, for any type.
	 */
	public function test_unmapped_reason_is_dropped_and_logged(): void {
		$declined = ReportContextData::from_array(
			array(
				'type'   => 'payment',
				'result' => 'declined',
				'reason' => 'totally_unknown_code',
			)
		);
		$this->assertInstanceOf( ReportContextData::class, $declined );
		$this->assertArrayNotHasKey( 'reason', $declined->to_array(), 'a masked decline no longer falls back to generic_decline' );
		$this->assertLogged( 'warning', 'Unmapped report reason "totally_unknown_code" for type "payment"' );

		$dispute = ReportContextData::from_array(
			array(
				'type'   => 'dispute',
				'result' => 'lost',
				'reason' => 'mystery_reason',
			)
		);
		$this->assertInstanceOf( ReportContextData::class, $dispute, 'an unmapped dispute reason no longer skips the report' );
		$this->assertArrayNotHasKey( 'reason', $dispute->to_array(), 'the unmapped reason is omitted, never bucketed to other' );
		$this->assertLogged( 'warning', 'Unmapped report reason "mystery_reason" for type "dispute"' );
	}

	/**
	 * @testdox A dispute with no reason at all is still reported, with the reason omitted.
	 */
	public function test_dispute_without_reason_is_reported(): void {
		$context = ReportContextData::from_array(
			array(
				'type'   => 'dispute',
				'result' => 'lost',
			)
		);

		$this->assertInstanceOf( ReportContextData::class, $context );
		$wire = $context->to_array();
		$this->assertArrayNotHasKey( 'reason', $wire );
		$this->assertSame( 'dispute', $wire['type'] );
		$this->assertSame( 'lost', $wire['result'] );
	}

	/**
	 * @testdox A refund carries no reason even when one is supplied.
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
		$this->assertArrayNotHasKey( 'reason', $context->to_array() );
	}

	/**
	 * @testdox liability_shift round-trips for valid values and is omitted otherwise.
	 */
	public function test_liability_shift_round_trips_and_rejects_invalid(): void {
		foreach ( array( 'shifted', 'attempted', 'not_shifted' ) as $value ) {
			$context = ReportContextData::from_array(
				array(
					'type'            => 'payment',
					'result'          => 'captured',
					'liability_shift' => $value,
				)
			);
			$this->assertInstanceOf( ReportContextData::class, $context );
			$this->assertSame( $value, $context->to_array()['liability_shift'] );
		}

		$invalid = ReportContextData::from_array(
			array(
				'type'            => 'payment',
				'result'          => 'captured',
				'liability_shift' => 'maybe',
			)
		);
		$this->assertInstanceOf( ReportContextData::class, $invalid );
		$this->assertArrayNotHasKey( 'liability_shift', $invalid->to_array(), 'an unknown liability_shift is dropped' );

		$absent = ReportContextData::from_array(
			array(
				'type'   => 'payment',
				'result' => 'captured',
			)
		);
		$this->assertInstanceOf( ReportContextData::class, $absent );
		$this->assertArrayNotHasKey( 'liability_shift', $absent->to_array() );
	}

	/**
	 * @testdox instrument reuses the verify-side shape and nests the full set of keys when present.
	 */
	public function test_instrument_round_trips_when_present(): void {
		$context = ReportContextData::from_array(
			array(
				'type'       => 'payment',
				'result'     => 'declined',
				'reason'     => 'incorrect_cvc',
				'instrument' => array(
					'brand'       => 'visa',
					'fingerprint' => 'Xt5EWLLDS7FJjR1c',
					'bin'         => '424242',
					'cvc_check'   => 'fail',
				),
			)
		);

		$this->assertInstanceOf( ReportContextData::class, $context );
		$wire = $context->to_array();
		$this->assertArrayHasKey( 'instrument', $wire );
		$this->assertSame( 'visa', $wire['instrument']['brand'] );
		$this->assertSame( 'Xt5EWLLDS7FJjR1c', $wire['instrument']['fingerprint'] );
		$this->assertSame( '424242', $wire['instrument']['bin'] );
		$this->assertSame( 'fail', $wire['instrument']['cvc_check'] );
		// Full verify-side shape: an unset field is still present as null.
		$this->assertArrayHasKey( 'last4', $wire['instrument'] );
		$this->assertNull( $wire['instrument']['last4'] );
	}

	/**
	 * @testdox instrument is omitted when absent, empty, or carrying no recognized fields.
	 */
	public function test_instrument_omitted_when_empty(): void {
		$inputs = array(
			'absent'        => null,
			'empty array'   => array(),
			'unknown keys'  => array( 'unknown_key' => 'x' ),
		);

		foreach ( $inputs as $case => $instrument_input ) {
			$data = array(
				'type'   => 'payment',
				'result' => 'captured',
			);
			if ( null !== $instrument_input ) {
				$data['instrument'] = $instrument_input;
			}

			$context = ReportContextData::from_array( $data );
			$this->assertInstanceOf( ReportContextData::class, $context );
			$this->assertArrayNotHasKey( 'instrument', $context->to_array(), "instrument should be omitted ({$case})" );
		}
	}

	/**
	 * @testdox A malformed instrument field never throws out of from_array(); valid siblings survive.
	 */
	public function test_malformed_instrument_field_does_not_throw(): void {
		$context = ReportContextData::from_array(
			array(
				'type'       => 'payment',
				'result'     => 'declined',
				'reason'     => 'incorrect_cvc',
				'instrument' => array(
					'fingerprint' => array( 'unexpected' ),
					'bin'         => '424242',
				),
			)
		);

		$this->assertInstanceOf( ReportContextData::class, $context );
		$wire = $context->to_array();
		$this->assertArrayHasKey( 'instrument', $wire );
		$this->assertNull( $wire['instrument']['fingerprint'], 'the malformed fingerprint is dropped, not thrown on' );
		$this->assertSame( '424242', $wire['instrument']['bin'], 'the valid bin survives' );
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
		$this->assertArrayHasKey( 'occurred_at', $wire );
		$this->assertSame( 'stripe', $wire['gateway'] );
		$this->assertArrayNotHasKey( 'reason', $wire );
		$this->assertArrayNotHasKey( 'liability_shift', $wire );
		$this->assertArrayNotHasKey( 'amount', $wire );
		$this->assertArrayNotHasKey( 'correlation', $wire );
		$this->assertArrayNotHasKey( 'instrument', $wire );
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

		foreach ( array( 'negative' => -500, 'fractional' => 99.99 ) as $case => $bad_minor ) {
			$rejected = ReportContextData::from_array(
				array(
					'type'   => 'payment',
					'result' => 'captured',
					'amount' => array(
						'minor_units' => $bad_minor,
						'currency'    => 'usd',
					),
				)
			);
			$this->assertInstanceOf( ReportContextData::class, $rejected );
			$this->assertArrayNotHasKey( 'amount', $rejected->to_array(), "amount with a {$case} minor_units is dropped" );
		}
	}

	/**
	 * @testdox A non-array amount or correlation is tolerated and simply omitted.
	 */
	public function test_non_array_amount_and_correlation_are_tolerated(): void {
		$context = ReportContextData::from_array(
			array(
				'type'        => 'payment',
				'result'      => 'captured',
				'amount'      => 'not-an-array',
				'correlation' => 'also-not-an-array',
			)
		);

		$this->assertInstanceOf( ReportContextData::class, $context );
		$wire = $context->to_array();
		$this->assertArrayNotHasKey( 'amount', $wire );
		$this->assertArrayNotHasKey( 'correlation', $wire );
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

		// Date-shaped but unparseable: matches the regex, then DateTimeImmutable throws,
		// exercising the catch / fall-back-to-now branch.
		$unparseable = ReportContextData::from_array(
			array(
				'type'        => 'payment',
				'result'      => 'captured',
				'occurred_at' => '2026-13-45T99:99:99',
			)
		);
		$this->assertInstanceOf( ReportContextData::class, $unparseable );
		$this->assertMatchesRegularExpression(
			'/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/',
			$unparseable->to_array()['occurred_at'],
			'A date-shaped but unparseable string should fall back to a UTC timestamp via the catch branch'
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
