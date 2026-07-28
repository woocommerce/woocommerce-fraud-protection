<?php
/**
 * ReportContextDataTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\FraudProtection\Schemas;

use Automattic\WooCommerce\FraudProtection\Schemas\ReportContextData;
use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;

/**
 * Tests for the ReportContextData class.
 *
 * @covers \Automattic\WooCommerce\FraudProtection\Schemas\ReportContextData
 */
class ReportContextDataTest extends FraudProtectionUnitTestCase {

	/**
	 * @testdox from_array() builds a full context with normalized facts, all fields flat.
	 */
	public function test_from_array_builds_full_context(): void {
		$context = ReportContextData::from_array(
			array(
				'type'                       => 'dispute',
				'result'                     => 'lost',
				'reason'                     => 'fraud',
				'liability_shift'            => 'shifted',
				'amount_minor_units'         => 9900,
				'amount_currency'            => 'USD',
				'gateway'                    => 'woocommerce_payments',
				'correlation_order_id'       => 12345,
				'correlation_transaction_id' => 'ch_3N',
				'correlation_dispute_id'     => 'dp_1N',
			)
		);

		$this->assertInstanceOf( ReportContextData::class, $context );
		// report_id and occurred_at are no longer context fields; correlation_dispute_id stays.
		$this->assertSame(
			array(
				'schema_version'                     => 1,
				'type'                               => 'dispute',
				'result'                             => 'lost',
				'reason'                             => 'fraud',
				'liability_shift'                    => 'shifted',
				'amount_minor_units'                 => 9900,
				'amount_currency'                    => 'USD',
				'gateway'                            => 'woocommerce_payments',
				'correlation_order_id'               => 12345,
				'correlation_transaction_id'         => 'ch_3N',
				'correlation_payment_attempt_id'     => null,
				'correlation_dispute_id'             => 'dp_1N',
				'correlation_refund_id'              => null,
				'correlation_network_transaction_id' => null,
				'instrument'                         => null,
			),
			$context->to_array()
		);
		$this->assertSame( 'dp_1N', $context->get_correlation_dispute_id() );
		$this->assertArrayNotHasKey( 'report_id', $context->to_array() );
		$this->assertArrayNotHasKey( 'occurred_at', $context->to_array() );
	}

	/**
	 * @testdox to_array() emits only normalized fact fields; interpretation stays server-side.
	 */
	public function test_wire_contains_only_normalized_fact_fields(): void {
		$context = ReportContextData::from_array(
			array(
				'type'                 => 'dispute',
				'result'               => 'lost',
				'reason'               => 'fraud',
				'liability_shift'      => 'shifted',
				'amount_minor_units'   => 100,
				'amount_currency'      => 'usd',
				'gateway'              => 'woocommerce_payments',
				'correlation_order_id' => 5,
				'instrument'           => array( 'fingerprint' => 'fp_1' ),
			)
		);

		$this->assertInstanceOf( ReportContextData::class, $context );

		$allowed = array(
			'schema_version',
			'type',
			'result',
			'reason',
			'liability_shift',
			'amount_minor_units',
			'amount_currency',
			'gateway',
			'correlation_order_id',
			'correlation_transaction_id',
			'correlation_payment_attempt_id',
			'correlation_dispute_id',
			'correlation_refund_id',
			'correlation_network_transaction_id',
			'instrument',
		);
		$this->assertSame(
			array(),
			array_diff( array_keys( $context->to_array() ), $allowed ),
			'The wire must carry only normalized fact fields; no interpreted output may leak.'
		);
		// report_id and occurred_at are top-level report() parameters, not context fields.
		$this->assertArrayNotHasKey( 'report_id', $context->to_array() );
		$this->assertArrayNotHasKey( 'occurred_at', $context->to_array() );
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
		$this->assertLogged( 'error', 'Skipping report: context type is missing or unmappable.' );
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
		$this->assertLogged( 'error', 'Skipping report: context result is missing or unmappable for the given type.' );
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
		$this->assertNull( $wire['reason'], 'a blocked payment no longer falls back to suspected_fraud' );
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
		$this->assertNull( $declined->to_array()['reason'], 'a masked decline no longer falls back to generic_decline' );
		$this->assertLogged( 'warning', 'Dropped ReportContextData field "reason" with an unrecognized value.' );

		$dispute = ReportContextData::from_array(
			array(
				'type'   => 'dispute',
				'result' => 'lost',
				'reason' => 'mystery_reason',
			)
		);
		$this->assertInstanceOf( ReportContextData::class, $dispute, 'an unmapped dispute reason no longer skips the report' );
		$this->assertNull( $dispute->to_array()['reason'], 'the unmapped reason is null, never bucketed to other' );
		$this->assertLogged( 'warning', 'Dropped ReportContextData field "reason" with an unrecognized value.' );
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
		$this->assertNull( $wire['reason'] );
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
		$this->assertNull( $context->to_array()['reason'] );
	}

	/**
	 * @testdox A non-refusal payment result carries no reason even when one is supplied.
	 * @dataProvider non_refusal_payment_result_data
	 *
	 * @param string $result A payment result that is not a refusal.
	 */
	public function test_non_refusal_payment_has_no_reason( string $result ): void {
		$context = ReportContextData::from_array(
			array(
				'type'   => 'payment',
				'result' => $result,
				'reason' => 'incorrect_cvc',
			)
		);

		$this->assertInstanceOf( ReportContextData::class, $context );
		$this->assertNull( $context->to_array()['reason'], 'refusal reasons only accompany a declined or blocked payment' );
	}

	/**
	 * Payment results that are not refusals, so they never carry a refusal reason.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function non_refusal_payment_result_data(): array {
		return array(
			'captured'        => array( 'captured' ),
			'authorized'      => array( 'authorized' ),
			'voided'          => array( 'voided' ),
			'review_rejected' => array( 'review_rejected' ),
		);
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
		$this->assertNull( $invalid->to_array()['liability_shift'], 'an unknown liability_shift is dropped' );

		$absent = ReportContextData::from_array(
			array(
				'type'   => 'payment',
				'result' => 'captured',
			)
		);
		$this->assertInstanceOf( ReportContextData::class, $absent );
		$this->assertNull( $absent->to_array()['liability_shift'] );
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
	 * @testdox instrument is null when absent, empty, or carrying no recognized fields.
	 */
	public function test_instrument_null_when_empty(): void {
		$inputs = array(
			'absent'       => null,
			'empty array'  => array(),
			'unknown keys' => array( 'unknown_key' => 'x' ),
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
			$this->assertNull( $context->to_array()['instrument'], "instrument should be null ({$case})" );
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
	 * @testdox A non-array instrument field is dropped to null and logged.
	 */
	public function test_non_array_instrument_field_is_dropped_and_logged(): void {
		$context = ReportContextData::from_array(
			array(
				'type'       => 'payment',
				'result'     => 'captured',
				'instrument' => 'not-an-array',
			)
		);

		$this->assertInstanceOf( ReportContextData::class, $context );
		$this->assertNull( $context->to_array()['instrument'] );
		$this->assertLogged( 'warning', 'Dropped ReportContextData field "instrument" (expected an array).' );
	}

	/**
	 * @testdox to_array() emits a fixed shape, with null for every optional that did not resolve.
	 */
	public function test_to_array_emits_fixed_shape_with_nulls(): void {
		$context = ReportContextData::from_array(
			array(
				'type'    => 'payment',
				'result'  => 'captured',
				'gateway' => 'stripe',
			)
		);

		$this->assertInstanceOf( ReportContextData::class, $context );
		$wire = $context->to_array();

		$this->assertSame( 'stripe', $wire['gateway'] );
		// occurred_at is a top-level report() parameter now, not part of the context shape.
		$this->assertArrayNotHasKey( 'occurred_at', $wire );
		// Optionals are present as null, not omitted — the verify-side convention.
		$this->assertNull( $wire['reason'] );
		$this->assertNull( $wire['liability_shift'] );
		$this->assertNull( $wire['amount_minor_units'] );
		$this->assertNull( $wire['amount_currency'] );
		$this->assertNull( $wire['correlation_order_id'] );
		$this->assertNull( $wire['instrument'] );
		// A non-dispute event carries no dispute correlation id, present as null in the fixed shape.
		$this->assertNull( $wire['correlation_dispute_id'] );
		$this->assertNull( $context->get_correlation_dispute_id() );
	}

	/**
	 * @testdox amount_minor_units is strictly validated; amount_currency is forwarded as-is, independently.
	 */
	public function test_amount_fields_are_independent(): void {
		// minor_units without a currency is still sent.
		$units_only = $this->to_wire(
			array(
				'type'               => 'payment',
				'result'             => 'captured',
				'amount_minor_units' => 500,
			)
		);
		$this->assertSame( 500, $units_only['amount_minor_units'] );
		$this->assertNull( $units_only['amount_currency'] );

		// A non-canonical currency is forwarded as-is (sanitized, not dropped) so it stays visible server-side.
		$non_canonical = $this->to_wire(
			array(
				'type'               => 'payment',
				'result'             => 'captured',
				'amount_minor_units' => 500,
				'amount_currency'    => 'dollars',
			)
		);
		$this->assertSame( 500, $non_canonical['amount_minor_units'] );
		$this->assertSame( 'dollars', $non_canonical['amount_currency'] );

		// A currency string is sent verbatim; the plugin does not uppercase or canonicalize.
		$lowercase = $this->to_wire(
			array(
				'type'               => 'payment',
				'result'             => 'captured',
				'amount_minor_units' => 500,
				'amount_currency'    => 'eur',
			)
		);
		$this->assertSame( 500, $lowercase['amount_minor_units'] );
		$this->assertSame( 'eur', $lowercase['amount_currency'] );

		// A negative or fractional minor_units is dropped to null; the currency stays.
		foreach ( array( 'negative' => -500, 'fractional' => 99.99 ) as $case => $bad_minor ) {
			$rejected = $this->to_wire(
				array(
					'type'               => 'payment',
					'result'             => 'captured',
					'amount_minor_units' => $bad_minor,
					'amount_currency'    => 'usd',
				)
			);
			$this->assertNull( $rejected['amount_minor_units'], "a {$case} minor_units is dropped" );
			$this->assertSame( 'usd', $rejected['amount_currency'] );
		}
	}

	/**
	 * @testdox An amount with no whole-number reading the field can hold is absent, never a figure.
	 *
	 * @dataProvider provide_unrepresentable_amounts
	 *
	 * @param mixed $value Raw amount_minor_units value.
	 */
	public function test_amount_without_a_representable_reading_is_absent( mixed $value ): void {
		$wire = $this->to_wire(
			array(
				'type'               => 'payment',
				'result'             => 'captured',
				'amount_minor_units' => $value,
				'amount_currency'    => 'usd',
			)
		);

		$this->assertNull( $wire['amount_minor_units'], 'the amount must be reported as unknown' );
		$this->assertSame( 'usd', $wire['amount_currency'], 'the sibling currency is unaffected' );
		$this->assertLogged( 'error', 'Dropped ReportContextData field "amount_minor_units" with a non-integer value' );
	}

	/**
	 * Data provider for {@see test_amount_without_a_representable_reading_is_absent()}.
	 *
	 * Each value passes a whole-number test and still has no integer the field can hold, so the
	 * amount is reported as unknown rather than as whatever the cast produces.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public function provide_unrepresentable_amounts(): array {
		return array(
			'numeric string with no finite reading' => array( '1e309' ),
			'non-finite float'                      => array( INF ),
			'below the integer minimum'             => array( -1.0e19 ),
		);
	}

	/**
	 * Build a context and return its wire array, asserting it was reportable.
	 *
	 * @param array<string, mixed> $data Context input.
	 * @return array<string, mixed>
	 */
	private function to_wire( array $data ): array {
		$context = ReportContextData::from_array( $data );
		$this->assertInstanceOf( ReportContextData::class, $context );
		return $context->to_array();
	}

	/**
	 * @testdox A wrongly-typed amount or correlation field is tolerated and sanitized to null.
	 */
	public function test_garbage_amount_and_correlation_fields_are_tolerated(): void {
		$context = ReportContextData::from_array(
			array(
				'type'                       => 'payment',
				'result'                     => 'captured',
				'amount_minor_units'         => 'not-a-number',
				'amount_currency'            => array( 'usd' ),
				'correlation_order_id'       => 'not-a-number',
				'correlation_transaction_id' => array( 'x' ),
			)
		);

		$this->assertInstanceOf( ReportContextData::class, $context );
		$wire = $context->to_array();
		$this->assertNull( $wire['amount_minor_units'] );
		$this->assertNull( $wire['amount_currency'] );
		$this->assertNull( $wire['correlation_order_id'] );
		$this->assertNull( $wire['correlation_transaction_id'] );

		// Every drop is logged so a malformed integration is identifiable.
		$this->assertLogged( 'error', 'Dropped ReportContextData field "amount_currency" with unsupported type array.' );
		$this->assertLogged( 'error', 'Dropped ReportContextData field "correlation_transaction_id" with unsupported type array.' );
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
		$this->assertSame( 777, $filled['correlation_order_id'] );

		$prefilled = ReportContextData::from_array(
			array(
				'type'                 => 'payment',
				'result'               => 'captured',
				'gateway'              => 'square_credit_card',
				'correlation_order_id' => 999,
			)
		);
		$this->assertInstanceOf( ReportContextData::class, $prefilled );

		$kept = $prefilled->with_order_defaults( 777, 'stripe' )->to_array();
		$this->assertSame( 'square_credit_card', $kept['gateway'], 'caller-supplied gateway must win' );
		$this->assertSame( 999, $kept['correlation_order_id'], 'caller-supplied order_id must win' );
	}

	/**
	 * @testdox correlation IDs are sanitized and empty entries are dropped from the wire.
	 */
	public function test_correlation_sanitized_and_empty_dropped(): void {
		$context = ReportContextData::from_array(
			array(
				'type'                               => 'dispute',
				'result'                             => 'open',
				'reason'                             => 'fraud',
				'correlation_order_id'               => '54321',
				'correlation_dispute_id'             => 'dp_9',
				'correlation_transaction_id'         => '',
				'correlation_network_transaction_id' => null,
			)
		);
		$this->assertInstanceOf( ReportContextData::class, $context );

		$wire = $context->to_array();
		$this->assertSame( 54321, $wire['correlation_order_id'], 'numeric order_id is cast to int' );
		$this->assertSame( 'dp_9', $wire['correlation_dispute_id'] );
		$this->assertNull( $wire['correlation_transaction_id'], 'empty string ID is null' );
		$this->assertNull( $wire['correlation_network_transaction_id'], 'null ID is null' );
		$this->assertSame( 'dp_9', $context->get_correlation_dispute_id() );
	}

	/**
	 * @testdox A non-positive correlation order_id is treated as missing and is backfilled by with_order_defaults().
	 */
	public function test_non_positive_order_id_is_treated_as_missing(): void {
		$zero = ReportContextData::from_array(
			array(
				'type'                 => 'payment',
				'result'               => 'captured',
				'correlation_order_id' => 0,
			)
		);
		$this->assertInstanceOf( ReportContextData::class, $zero );
		$this->assertNull( $zero->to_array()['correlation_order_id'], 'order_id 0 reads as missing' );
		$this->assertLogged( 'warning', 'Dropped ReportContextData field "correlation_order_id" (non-positive value).' );

		$backfilled = $zero->with_order_defaults( 555, 'stripe' )->to_array();
		$this->assertSame( 555, $backfilled['correlation_order_id'], 'with_order_defaults must backfill when order_id was non-positive' );

		$negative = ReportContextData::from_array(
			array(
				'type'                 => 'payment',
				'result'               => 'captured',
				'correlation_order_id' => -5,
			)
		);
		$this->assertInstanceOf( ReportContextData::class, $negative );
		$this->assertNull( $negative->to_array()['correlation_order_id'], 'negative order_id is dropped' );
	}
}
