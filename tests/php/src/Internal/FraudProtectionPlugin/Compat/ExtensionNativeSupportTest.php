<?php
/**
 * ExtensionNativeSupportTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin\Compat;

use Automattic\WooCommerce\FraudProtection\FraudProtectionIntegration;
use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Compat\ExtensionNativeSupport;

/**
 * Tests for the ExtensionNativeSupport class.
 *
 * @covers \Automattic\WooCommerce\Internal\FraudProtectionPlugin\Compat\ExtensionNativeSupport
 */
class ExtensionNativeSupportTest extends FraudProtectionUnitTestCase {

	/**
	 * The filter extensions use to declare their integrations.
	 */
	private const FILTER = 'woocommerce_fraud_protection_native_integrations';

	/**
	 * The System Under Test.
	 *
	 * @var ExtensionNativeSupport
	 */
	private $sut;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->sut = new ExtensionNativeSupport();
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		remove_all_filters( self::FILTER );

		parent::tearDown();
	}

	/**
	 * Declare integrations for an extension through the filter, the way an extension does.
	 *
	 * @param string   $extension    Plugin slug.
	 * @param string[] $integrations Integrations to add.
	 */
	private function declare_for( string $extension, array $integrations ): void {
		add_filter(
			self::FILTER,
			static function ( $declared ) use ( $extension, $integrations ) {
				$declared[ $extension ] = array_merge( $declared[ $extension ] ?? array(), $integrations );
				return $declared;
			}
		);
	}

	/**
	 * @testdox Nothing is declared when no extension uses the filter.
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
	 * @testdox Declarations from several callbacks for the same extension are combined without duplicates.
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
	 * @testdox Invalid entries are skipped while valid ones still count.
	 */
	public function test_invalid_entries_are_skipped(): void {
		add_filter(
			self::FILTER,
			static fn() => array(
				'my-extension'      => array( 'change_payment_method', 42, null ),
				7                   => array( 'change_payment_method' ),
				''                  => array( 'change_payment_method' ),
				'string-extension'  => 'change_payment_method',
				'empty-extension'   => array(),
				'invalid-extension' => array( array( 'change_payment_method' ) ),
			)
		);

		$this->assertSame( array( 'my-extension' => array( 'change_payment_method' ) ), $this->sut->get_declarations() );
		$this->assertFalse( $this->sut->declared( 'string-extension', FraudProtectionIntegration::CHANGE_PAYMENT_METHOD ) );
	}

	/**
	 * @testdox A non-array filter result declares nothing and is logged.
	 */
	public function test_non_array_result_declares_nothing(): void {
		add_filter( self::FILTER, static fn() => 'change_payment_method' );

		$this->assertFalse( $this->sut->declared( 'my-extension', FraudProtectionIntegration::CHANGE_PAYMENT_METHOD ) );
		$this->assertLogged( 'warning', 'returned a non-array', array( 'filter' => self::FILTER ) );
	}

	/**
	 * @testdox A throwing callback declares nothing, and the failure is forwarded and logged once per request.
	 */
	public function test_throwing_callback_declares_nothing(): void {
		$spy = $this->spy_on_controller_logging();
		add_filter(
			self::FILTER,
			static function () {
				throw new \RuntimeException( 'broken extension' );
			}
		);

		$this->assertFalse( $this->sut->declared( 'my-extension', FraudProtectionIntegration::CHANGE_PAYMENT_METHOD ) );
		$this->assertSame( array(), $this->sut->get_declarations() );
		$this->assertLogged( 'warning', 'filter threw', array( 'exception_message' => 'broken extension' ), true );
		$failure_logs = array_filter(
			$spy->entries,
			static fn( array $entry ): bool => str_contains( $entry['message'], 'filter threw' )
		);
		$this->assertCount( 1, $failure_logs, 'The failure is logged once per request' );
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
