<?php
/**
 * VerifyResultTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin\Schemas;

use Automattic\WooCommerce\FraudProtection\Schemas\FraudDecision;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Schemas\VerifyResult;
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

		$this->assertSame( FraudDecision::Block, $result->get_decision() );
		$this->assertSame( '82vHd2iPY4JvJZQE-A6jHg', $result->get_session_id() );
	}

	/**
	 * @testdox create() preserves an empty session ID
	 */
	public function test_create_preserves_empty_session_id(): void {
		$result = VerifyResult::create( FraudDecision::Allow, '' );

		$this->assertSame( FraudDecision::Allow, $result->get_decision() );
		$this->assertSame( '', $result->get_session_id() );
	}

	/**
	 * @testdox create() sanitizes the session ID
	 */
	public function test_create_sanitizes_session_id(): void {
		// sanitize_text_field strips tags and trims surrounding whitespace.
		$result = VerifyResult::create( FraudDecision::Allow, ' <b>abc</b>123 ' );

		$this->assertSame( 'abc123', $result->get_session_id() );
	}

	/**
	 * @testdox create() exposes the risk score and defaults it to null
	 */
	public function test_create_exposes_risk_score(): void {
		$with_score = VerifyResult::create( FraudDecision::Allow, 'sid', 0.4033 );
		$this->assertSame( 0.4033, $with_score->get_risk_score() );

		$without_score = VerifyResult::create( FraudDecision::Allow, 'sid' );
		$this->assertNull( $without_score->get_risk_score() );
	}
}
