<?php
/**
 * PublicServiceAcquisitionTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\FraudProtection;

use Automattic\WooCommerce\FraudProtection\BlackboxScriptHandler;
use Automattic\WooCommerce\FraudProtection\BlockedSessionMessage;
use Automattic\WooCommerce\FraudProtection\FraudProtectionReporter;
use Automattic\WooCommerce\FraudProtection\SessionVerifier;
use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Sessions\SessionIdentityManager;

/**
 * Pins the supported way for third-party code — payment-gateway compat layers
 * that will eventually live outside this plugin — to obtain the plugin's public
 * services: resolving them from the WooCommerce container. The handler check
 * also proves that the container initializes its dependencies.
 *
 * @covers \Automattic\WooCommerce\FraudProtection\SessionVerifier
 * @covers \Automattic\WooCommerce\FraudProtection\FraudProtectionReporter
 * @covers \Automattic\WooCommerce\FraudProtection\BlockedSessionMessage
 * @covers \Automattic\WooCommerce\FraudProtection\BlackboxScriptHandler
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

	/**
	 * @testdox BlackboxScriptHandler is a shared, initialized WooCommerce container service.
	 */
	public function test_blackbox_script_handler_is_resolvable_from_container(): void {
		$this->reset_fraud_protection_scripts();
		$this->mock_jetpack_blog_id( 12345 );
		$previous_identity_id = WC()->session->get( SessionIdentityManager::CUSTOMER_IDENTITY_ID_KEY );
		$first_resolution     = wc_get_container()->get( BlackboxScriptHandler::class );
		$second_resolution    = wc_get_container()->get( BlackboxScriptHandler::class );

		try {
			$this->assertInstanceOf( BlackboxScriptHandler::class, $first_resolution );
			$this->assertSame( $first_resolution, $second_resolution );
			$this->assertTrue( $first_resolution->request_scripts() );
		} finally {
			WC()->session->set( SessionIdentityManager::CUSTOMER_IDENTITY_ID_KEY, $previous_identity_id );
			$this->reset_fraud_protection_scripts();
		}
	}
}
