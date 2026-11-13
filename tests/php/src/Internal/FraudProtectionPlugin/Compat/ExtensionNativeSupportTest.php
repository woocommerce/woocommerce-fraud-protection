<?php
/**
 * ExtensionNativeSupportTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin\Compat;

use Automattic\WooCommerce\FraudProtection\FraudProtectionIntegration;
use Automattic\WooCommerce\FraudProtection\NativeIntegrationRegistry;
use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Compat\ExtensionNativeSupport;

/**
 * Tests for the ExtensionNativeSupport class.
 *
 * @covers \Automattic\WooCommerce\Internal\FraudProtectionPlugin\Compat\ExtensionNativeSupport
 */
class ExtensionNativeSupportTest extends FraudProtectionUnitTestCase {

	/**
	 * The action extensions use to declare their integrations.
	 */
	private const ACTION = 'woocommerce_fraud_protection_register_native_integrations';

	/**
	 * The System Under Test.
	 *
	 * @var ExtensionNativeSupport
	 */
	private $sut;

	/**
	 * How many times the action had fired before the test.
	 *
	 * @var int
	 */
	private int $actions_before = 0;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->sut            = new ExtensionNativeSupport();
		$this->actions_before = did_action( self::ACTION );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		remove_all_actions( self::ACTION );

		parent::tearDown();
	}

	/**
	 * Declare integrations for an extension through the action, the way an extension does.
	 *
	 * @param string   $extension    Plugin slug.
	 * @param string[] $integrations Integrations to register.
	 */
	private function declare_for( string $extension, array $integrations ): void {
		add_action(
			self::ACTION,
			static function ( NativeIntegrationRegistry $registry ) use ( $extension, $integrations ): void {
				foreach ( $integrations as $integration ) {
					$registry->register( $extension, $integration );
				}
			}
		);
	}

	/**
	 * @testdox Nothing is declared when no extension registers.
	 */
	public function test_nothing_is_declared_by_default(): void {
		$this->assertFalse( $this->sut->declared( 'my-extension', FraudProtectionIntegration::CHANGE_PAYMENT_METHOD ) );
		$this->assertSame( array(), $this->sut->get_declarations() );
	}

	/**
	 * @testdox A declaration counts only for the extension and integration it names.
	 */
	public function test_declaration_counts_only_for_its_extension_and_integration(): void {
		$this->declare_for( 'my-extension', array( 'change_payment_method' ) );

		$this->assertTrue( $this->sut->declared( 'my-extension', FraudProtectionIntegration::CHANGE_PAYMENT_METHOD ) );
		$this->assertFalse( $this->sut->declared( 'other-extension', FraudProtectionIntegration::CHANGE_PAYMENT_METHOD ), 'Another extension declared nothing' );
		$this->assertFalse( $this->sut->declared( 'my-extension', 'another_integration' ), 'The extension declared only one integration' );
	}

	/**
	 * @testdox Registrations from several callbacks are combined without duplicates, and unknown integrations are kept.
	 */
	public function test_declarations_for_an_extension_are_combined(): void {
		$this->declare_for( 'my-extension', array( 'change_payment_method' ) );
		$this->declare_for( 'my-extension', array( 'change_payment_method', 'misspelled_integration' ) );
		$this->declare_for( 'other-extension', array( 'change_payment_method' ) );

		$this->assertSame(
			array(
				'my-extension'    => array( 'change_payment_method', 'misspelled_integration' ),
				'other-extension' => array( 'change_payment_method' ),
			),
			$this->sut->get_declarations()
		);
	}

	/**
	 * @testdox The action fires once per request, and a registration made after it is ignored and logged.
	 */
	public function test_action_fires_once_and_late_registrations_are_ignored(): void {
		$kept_registry = null;
		add_action(
			self::ACTION,
			static function ( NativeIntegrationRegistry $registry ) use ( &$kept_registry ): void {
				$kept_registry = $registry;
				$registry->register( 'my-extension', 'change_payment_method' );
			}
		);

		$this->sut->get_declarations();
		$this->sut->declared( 'my-extension', FraudProtectionIntegration::CHANGE_PAYMENT_METHOD );
		$kept_registry->register( 'late-extension', 'change_payment_method' );

		$this->assertSame( 1, did_action( self::ACTION ) - $this->actions_before );
		$this->assertSame( array( 'my-extension' => array( 'change_payment_method' ) ), $this->sut->get_declarations() );
		$this->assertLogged( 'warning', 'outside the registration action', array( 'extension' => 'late-extension' ) );
	}

	/**
	 * @testdox A throwing callback keeps the registrations made before it, skips the later ones, and is forwarded.
	 */
	public function test_throwing_callback_keeps_earlier_registrations(): void {
		$this->declare_for( 'early-extension', array( 'change_payment_method' ) );
		add_action(
			self::ACTION,
			static function (): void {
				throw new \RuntimeException( 'broken extension' );
			}
		);
		$this->declare_for( 'late-extension', array( 'change_payment_method' ) );

		$this->assertSame( array( 'early-extension' => array( 'change_payment_method' ) ), $this->sut->get_declarations() );
		$this->assertLogged( 'warning', 'callback threw', array( 'exception_message' => 'broken extension' ), true );
	}

	/**
	 * @testdox A callback that asks for the declarations while the action runs gets none, without firing the action again.
	 */
	public function test_nested_request_declares_nothing(): void {
		$nested = null;
		add_action(
			self::ACTION,
			function ( NativeIntegrationRegistry $registry ) use ( &$nested ): void {
				$registry->register( 'my-extension', 'change_payment_method' );
				$nested = $this->sut->get_declarations();
			}
		);

		$this->assertSame( array( 'my-extension' => array( 'change_payment_method' ) ), $this->sut->get_declarations() );
		$this->assertSame( array(), $nested );
		$this->assertSame( 1, did_action( self::ACTION ) - $this->actions_before );
	}

	/**
	 * @testdox Before WordPress init nothing is declared and the action does not fire, so it can still fire later.
	 */
	public function test_nothing_is_declared_before_init(): void {
		global $wp_actions;

		$this->declare_for( 'my-extension', array( 'change_payment_method' ) );
		$init_count = $wp_actions['init'];
		unset( $wp_actions['init'] );

		try {
			$this->assertSame( array(), $this->sut->get_declarations() );
			$this->assertSame( 0, did_action( self::ACTION ) - $this->actions_before );
		} finally {
			$wp_actions['init'] = $init_count; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restores the init count changed above.
		}

		$this->assertTrue( $this->sut->declared( 'my-extension', FraudProtectionIntegration::CHANGE_PAYMENT_METHOD ) );
	}

	/**
	 * @testdox A declaration is logged once per extension and integration per request.
	 */
	public function test_declaration_is_logged_once(): void {
		$spy = $this->spy_on_controller_logging();
		$this->declare_for( 'my-extension', array( 'change_payment_method' ) );

		$this->sut->declared( 'my-extension', FraudProtectionIntegration::CHANGE_PAYMENT_METHOD );
		$this->sut->declared( 'my-extension', FraudProtectionIntegration::CHANGE_PAYMENT_METHOD );

		$declaration_logs = array_filter(
			$spy->entries,
			static fn( array $entry ): bool => str_contains( $entry['message'], 'declares native Fraud Protection support' )
		);
		$this->assertCount( 1, $declaration_logs );
		$this->assertLogged(
			'info',
			'declares native Fraud Protection support',
			array(
				'extension'   => 'my-extension',
				'integration' => 'change_payment_method',
			)
		);
	}
}
