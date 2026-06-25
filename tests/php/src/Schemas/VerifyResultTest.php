<?php
/**
 * VerifyResultTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\FraudProtection\Schemas;

use Automattic\WooCommerce\FraudProtection\ApiClient;
use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;

/**
 * Tests for the VerifyResult value object.
 *
 * @covers \Automattic\WooCommerce\FraudProtection\Schemas\VerifyResult
 */
class VerifyResultTest extends FraudProtectionUnitTestCase {

	/**
	 * @testdox create() exposes the decision and session ID via getters
	 */
	public function test_create_exposes_decision_and_session_id(): void {
		$result = VerifyResult::create( ApiClient::DECISION_BLOCK, '82vHd2iPY4JvJZQE-A6jHg' );

		$this->assertSame( ApiClient::DECISION_BLOCK, $result->get_decision() );
		$this->assertSame( '82vHd2iPY4JvJZQE-A6jHg', $result->get_session_id() );
	}

	/**
	 * @testdox create() preserves an empty session ID
	 */
	public function test_create_preserves_empty_session_id(): void {
		$result = VerifyResult::create( ApiClient::DECISION_ALLOW, '' );

		$this->assertSame( ApiClient::DECISION_ALLOW, $result->get_decision() );
		$this->assertSame( '', $result->get_session_id() );
	}

	/**
	 * @testdox create() sanitizes the session ID
	 */
	public function test_create_sanitizes_session_id(): void {
		// sanitize_text_field strips tags and trims surrounding whitespace.
		$result = VerifyResult::create( ApiClient::DECISION_ALLOW, ' <b>abc</b>123 ' );

		$this->assertSame( 'abc123', $result->get_session_id() );
	}

	/**
	 * @testdox create() exposes the risk score and defaults it to null
	 */
	public function test_create_exposes_risk_score(): void {
		$with_score = VerifyResult::create( ApiClient::DECISION_ALLOW, 'sid', 0.4033 );
		$this->assertSame( 0.4033, $with_score->get_risk_score() );

		$without_score = VerifyResult::create( ApiClient::DECISION_ALLOW, 'sid' );
		$this->assertNull( $without_score->get_risk_score() );
	}
}
