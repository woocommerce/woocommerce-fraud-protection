<?php
/**
 * VerifyResultTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin\Schemas;

use Automattic\WooCommerce\FraudProtection\Schemas\FraudDecision;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Schemas\VerifyResult;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Schemas\VerifyResultOrigin;
use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;

/**
 * Tests for the VerifyResult value object.
 *
 * @covers \Automattic\WooCommerce\Internal\FraudProtectionPlugin\Schemas\VerifyResult
 */
class VerifyResultTest extends FraudProtectionUnitTestCase {

	/**
	 * @testdox create() exposes the decision and session ID via getters
	 */
	public function test_create_exposes_decision_and_session_id(): void {
		$result = VerifyResult::create( FraudDecision::Block, '82vHd2iPY4JvJZQE-A6jHg' );

		$this->assertSame( FraudDecision::Block, $result->decision );
		$this->assertSame( '82vHd2iPY4JvJZQE-A6jHg', $result->session_id );
	}

	/**
	 * @testdox create() preserves an empty session ID
	 */
	public function test_create_preserves_empty_session_id(): void {
		$result = VerifyResult::create( FraudDecision::Allow, '' );

		$this->assertSame( FraudDecision::Allow, $result->decision );
		$this->assertSame( '', $result->session_id );
	}

	/**
	 * @testdox create() preserves the response session ID byte for byte
	 */
	public function test_create_preserves_session_id(): void {
		$session_id = ' <b>opaque-response-id</b> ';
		$result     = VerifyResult::create( FraudDecision::Allow, $session_id );

		$this->assertSame( $session_id, $result->session_id );
	}

	/**
	 * @testdox create() exposes the risk score and defaults it to null
	 */
	public function test_create_exposes_risk_score(): void {
		$with_score = VerifyResult::create( FraudDecision::Allow, 'sid', 0.4033 );
		$this->assertSame( 0.4033, $with_score->risk_score );

		$without_score = VerifyResult::create( FraudDecision::Allow, 'sid' );
		$this->assertNull( $without_score->risk_score );
	}

	/**
	 * @testdox create() records a response origin.
	 */
	public function test_create_records_response_origin(): void {
		$result = VerifyResult::create( FraudDecision::Allow, 'sid' );

		$this->assertSame( VerifyResultOrigin::Response, $result->origin );
	}

	/**
	 * @testdox fail_open() produces a synthetic allow with a fail-open origin.
	 */
	public function test_fail_open_produces_synthetic_allow(): void {
		$result = VerifyResult::fail_open();

		$this->assertSame( VerifyResultOrigin::FailOpen, $result->origin );
		$this->assertSame( FraudDecision::Allow, $result->decision );
		$this->assertSame( '', $result->session_id );
		$this->assertNull( $result->risk_score );
	}

	/**
	 * @testdox request_rejected() produces a marked block without association data.
	 */
	public function test_request_rejected_produces_marked_block(): void {
		$result = VerifyResult::request_rejected();

		$this->assertSame( FraudDecision::Block, $result->decision );
		$this->assertSame( '', $result->session_id );
		$this->assertNull( $result->risk_score );
		$this->assertSame( VerifyResultOrigin::RequestRejected, $result->origin );
	}
}
