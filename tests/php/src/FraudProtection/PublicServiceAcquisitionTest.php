<?php
/**
 * PublicServiceAcquisitionTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\FraudProtection;

use Automattic\WooCommerce\FraudProtection\BlockedSessionMessage;
use Automattic\WooCommerce\FraudProtection\FraudProtectionReporter;
use Automattic\WooCommerce\FraudProtection\SessionVerifier;
use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;

/**
 * Pins the supported way for third-party code — payment-gateway compat layers
 * that will eventually live outside this plugin — to obtain the plugin's public
 * services: resolving them from the WooCommerce container. Once this plugin is
 * merged into WooCommerce core these become ordinary core classes resolved the
 * same way, so this contract is intentionally just "the container resolves them".
 *
 * @covers \Automattic\WooCommerce\FraudProtection\SessionVerifier
 * @covers \Automattic\WooCommerce\FraudProtection\FraudProtectionReporter
 * @covers \Automattic\WooCommerce\FraudProtection\BlockedSessionMessage
 */
class PublicServiceAcquisitionTest extends FraudProtectionUnitTestCase {

	/**
	 * @testdox SessionVerifier is resolvable from the WooCommerce container with its dependencies wired.
	 */
	public function test_session_verifier_is_resolvable_from_container(): void {
		$this->assertInstanceOf(
			SessionVerifier::class,
			wc_get_container()->get( SessionVerifier::class )
		);
	}

	/**
	 * @testdox FraudProtectionReporter is resolvable from the WooCommerce container with its dependencies wired.
	 */
	public function test_reporter_is_resolvable_from_container(): void {
		$this->assertInstanceOf(
			FraudProtectionReporter::class,
			wc_get_container()->get( FraudProtectionReporter::class )
		);
	}

	/**
	 * @testdox BlockedSessionMessage is resolvable from the WooCommerce container.
	 */
	public function test_blocked_session_message_is_resolvable_from_container(): void {
		$this->assertInstanceOf(
			BlockedSessionMessage::class,
			wc_get_container()->get( BlockedSessionMessage::class )
		);
	}
}
