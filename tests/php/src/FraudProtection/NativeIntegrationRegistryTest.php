<?php
/**
 * NativeIntegrationRegistryTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\FraudProtection;

use Automattic\WooCommerce\FraudProtection\NativeIntegrationRegistry;
use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;

/**
 * Tests for NativeIntegrationRegistry.
 *
 * @covers \Automattic\WooCommerce\FraudProtection\NativeIntegrationRegistry
 */
class NativeIntegrationRegistryTest extends FraudProtectionUnitTestCase {

	/**
	 * @testdox register() keeps each integration once per plugin slug.
	 */
	public function test_register_keeps_each_integration_once(): void {
		$registry = new NativeIntegrationRegistry();

		$registry->register( 'my-extension', 'change_payment_method' );
		$registry->register( 'my-extension', 'change_payment_method' );
		$registry->register( 'other-extension', 'change_payment_method' );

		$this->assertSame(
			array(
				'my-extension'    => array( 'change_payment_method' ),
				'other-extension' => array( 'change_payment_method' ),
			),
			$registry->get_registered()
		);
	}

	/**
	 * @testdox register() ignores and logs an empty plugin slug or integration.
	 *
	 * @dataProvider empty_registration_provider
	 *
	 * @param string $extension   Plugin slug.
	 * @param string $integration Integration.
	 */
	public function test_register_ignores_empty_values( string $extension, string $integration ): void {
		$registry = new NativeIntegrationRegistry();

		$registry->register( $extension, $integration );

		$this->assertSame( array(), $registry->get_registered() );
		$this->assertLogged(
			'warning',
			'empty plugin slug or integration',
			array(
				'extension'   => $extension,
				'integration' => $integration,
			)
		);
	}

	/**
	 * Registrations with an empty value.
	 *
	 * @return array<string, array{string, string}>
	 */
	public function empty_registration_provider(): array {
		return array(
			'empty slug'        => array( '', 'change_payment_method' ),
			'empty integration' => array( 'my-extension', '' ),
		);
	}

	/**
	 * @testdox register() ignores and logs registrations after the registry is closed.
	 */
	public function test_register_ignores_registrations_after_close(): void {
		$registry = new NativeIntegrationRegistry();
		$registry->register( 'my-extension', 'change_payment_method' );
		$registry->close();

		$registry->register( 'late-extension', 'change_payment_method' );

		$this->assertSame( array( 'my-extension' => array( 'change_payment_method' ) ), $registry->get_registered() );
		$this->assertLogged( 'warning', 'outside the registration action', array( 'extension' => 'late-extension' ) );
	}
}
