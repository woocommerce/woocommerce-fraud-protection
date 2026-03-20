<?php
/**
 * PayPalPaymentDataCompatTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\FraudProtection\Compat;

use Automattic\WooCommerce\FraudProtection\Compat\PayPalPaymentDataCompat;
use Automattic\WooCommerce\FraudProtection\Schemas\PaymentMethodData;
use WC_Unit_Test_Case;

/**
 * Tests for the PayPalPaymentDataCompat class.
 *
 * @covers \Automattic\WooCommerce\FraudProtection\Compat\PayPalPaymentDataCompat
 */
class PayPalPaymentDataCompatTest extends WC_Unit_Test_Case {

	/**
	 * The System Under Test.
	 *
	 * @var PayPalPaymentDataCompat
	 */
	private PayPalPaymentDataCompat $sut;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->sut = new PayPalPaymentDataCompat();
	}

	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		delete_option( 'woocommerce-ppcp-settings' );
		delete_option( 'woocommerce-ppcp-data-common' );
		parent::tearDown();
	}

	/**
	 * @testdox Returns resolved for non-PayPal payment methods.
	 */
	public function test_returns_resolved_for_non_paypal(): void {
		$resolved = new PaymentMethodData( 'stripe', 'card' );

		$result = $this->sut->resolve( $resolved );

		$this->assertSame( $resolved, $result );
	}

	/**
	 * @testdox Includes test mode when PayPal sandbox_on setting is enabled.
	 */
	public function test_includes_test_mode_from_settings(): void {
		update_option(
			'woocommerce-ppcp-settings',
			array( 'sandbox_on' => '1' )
		);

		$result = $this->sut->resolve(
			new PaymentMethodData( 'ppcp-gateway' )
		);

		$this->assertSame( PaymentMethodData::MODE_TEST, $result->to_array()['transaction_mode'] );
	}

	/**
	 * @testdox Includes live mode when PayPal sandbox_on setting is empty.
	 */
	public function test_includes_live_mode_from_settings(): void {
		update_option(
			'woocommerce-ppcp-settings',
			array( 'sandbox_on' => '' )
		);

		$result = $this->sut->resolve(
			new PaymentMethodData( 'ppcp-gateway' )
		);

		$this->assertSame( PaymentMethodData::MODE_LIVE, $result->to_array()['transaction_mode'] );
	}

	/**
	 * @testdox Resolves test mode from new settings format (use_sandbox boolean).
	 */
	public function test_includes_test_mode_from_new_settings_format(): void {
		update_option(
			'woocommerce-ppcp-data-common',
			array( 'use_sandbox' => true )
		);

		$result = $this->sut->resolve(
			new PaymentMethodData( 'ppcp-gateway' )
		);

		$this->assertSame( PaymentMethodData::MODE_TEST, $result->to_array()['transaction_mode'] );
	}

	/**
	 * @testdox Resolves live mode from new settings format (use_sandbox false).
	 */
	public function test_includes_live_mode_from_new_settings_format(): void {
		update_option(
			'woocommerce-ppcp-data-common',
			array( 'use_sandbox' => false )
		);

		$result = $this->sut->resolve(
			new PaymentMethodData( 'ppcp-gateway' )
		);

		$this->assertSame( PaymentMethodData::MODE_LIVE, $result->to_array()['transaction_mode'] );
	}

	/**
	 * @testdox New settings format takes precedence over legacy.
	 */
	public function test_new_settings_format_takes_precedence(): void {
		update_option(
			'woocommerce-ppcp-data-common',
			array( 'use_sandbox' => true )
		);
		update_option(
			'woocommerce-ppcp-settings',
			array( 'sandbox_on' => '' )
		);

		$result = $this->sut->resolve(
			new PaymentMethodData( 'ppcp-gateway' )
		);

		$this->assertSame( PaymentMethodData::MODE_TEST, $result->to_array()['transaction_mode'] );
	}

	/**
	 * @testdox Transaction mode is unknown when PayPal settings are absent.
	 */
	public function test_transaction_mode_unknown_without_settings(): void {
		$result = $this->sut->resolve(
			new PaymentMethodData( 'ppcp-gateway' )
		);

		$this->assertSame( PaymentMethodData::MODE_UNKNOWN, $result->to_array()['transaction_mode'] );
	}

	/**
	 * @testdox Matches ppcp-card-button-gateway as a PayPal gateway.
	 */
	public function test_matches_ppcp_card_button_gateway(): void {
		update_option(
			'woocommerce-ppcp-settings',
			array( 'sandbox_on' => '1' )
		);

		$result = $this->sut->resolve(
			new PaymentMethodData( 'ppcp-card-button-gateway' )
		);

		$this->assertSame( PaymentMethodData::MODE_TEST, $result->to_array()['transaction_mode'] );
	}

	/**
	 * @testdox Augments pre-resolved data with transaction mode.
	 */
	public function test_augments_preresolved_with_mode(): void {
		update_option(
			'woocommerce-ppcp-settings',
			array( 'sandbox_on' => '1' )
		);

		$resolved = new PaymentMethodData( 'ppcp-gateway', 'paypal', true );

		$result = $this->sut->resolve( $resolved );

		$this->assertNotSame( $resolved, $result );
		$array = $result->to_array();
		$this->assertSame( PaymentMethodData::MODE_TEST, $array['transaction_mode'] );
		$this->assertSame( 'paypal', $array['payment_type'] );
		$this->assertTrue( $array['is_saved_payment_method'] );
	}
}
