<?php
/**
 * SuppliedDecision tests.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\FraudProtection;

use Automattic\WooCommerce\FraudProtection\Schemas\FraudDecision;
use Automattic\WooCommerce\FraudProtection\SuppliedDecision;
use WC_Unit_Test_Case;

/**
 * Tests for the SuppliedDecision class.
 */
class SuppliedDecisionTest extends WC_Unit_Test_Case {

	/**
	 * @testdox A new carrier is empty.
	 */
	public function test_new_carrier_is_empty(): void {
		$sut = new SuppliedDecision();

		$this->assertNull( $sut->get_decision() );
		$this->assertNull( $sut->get_session_id_for_order() );
	}

	/**
	 * @testdox An actionable decision replaces the complete pair.
	 *
	 * @dataProvider actionable_decision_provider
	 *
	 * @param FraudDecision $decision The actionable decision.
	 */
	public function test_actionable_decision_replaces_pair( FraudDecision $decision ): void {
		$sut = new SuppliedDecision();
		$sut->supply( FraudDecision::Block, 'first-session-id' );

		$sut->supply( $decision, 'replacement-session-id' );

		$this->assertSame( $decision, $sut->get_decision() );
		$this->assertSame( 'replacement-session-id', $sut->get_session_id_for_order() );
	}

	/**
	 * Actionable decisions.
	 *
	 * @return array<string, array{FraudDecision}>
	 */
	public function actionable_decision_provider(): array {
		return array(
			'allow' => array( FraudDecision::Allow ),
			'block' => array( FraudDecision::Block ),
		);
	}

	/**
	 * @testdox A non-actionable decision leaves the current pair unchanged.
	 *
	 * @dataProvider invalid_decision_provider
	 *
	 * @param mixed $decision The invalid decision.
	 */
	public function test_invalid_decision_preserves_pair( $decision ): void {
		$sut = new SuppliedDecision();
		$sut->supply( FraudDecision::Block, 'response-session-id' );

		$sut->supply( $decision, 'replacement-session-id' );

		$this->assertSame( FraudDecision::Block, $sut->get_decision() );
		$this->assertSame( 'response-session-id', $sut->get_session_id_for_order() );
	}

	/**
	 * Invalid decisions.
	 *
	 * @return array<string, array{mixed}>
	 */
	public function invalid_decision_provider(): array {
		return array(
			'non-actionable decision' => array( FraudDecision::Challenge ),
			'string'                  => array( 'block' ),
		);
	}

	/**
	 * @testdox An invalid order session ID applies the decision with no association.
	 *
	 * @dataProvider invalid_session_id_provider
	 *
	 * @param mixed $session_id The invalid session ID.
	 */
	public function test_invalid_session_id_clears_association( $session_id ): void {
		$sut = new SuppliedDecision();
		$sut->supply( FraudDecision::Allow, 'first-session-id' );

		$sut->supply( FraudDecision::Block, $session_id );

		$this->assertSame( FraudDecision::Block, $sut->get_decision() );
		$this->assertNull( $sut->get_session_id_for_order() );
	}

	/**
	 * Invalid order session IDs.
	 *
	 * @return array<string, array{mixed}>
	 */
	public function invalid_session_id_provider(): array {
		return array(
			'empty string' => array( '' ),
			'null'         => array( null ),
		);
	}
}
