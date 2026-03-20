<?php
/**
 * WooPaymentsPaymentDataCompatTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\FraudProtection\Compat;

use Automattic\WooCommerce\FraudProtection\Compat\WooPaymentsPaymentDataCompat;
use Automattic\WooCommerce\FraudProtection\Schemas\PaymentMethodData;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsPaymentDataCompat class.
 *
 * Note: the WCPAY_DEV_MODE constant override path is not tested here
 * because PHP constants cannot be undefined after definition, requiring
 * @runInSeparateProcess which adds ~2.5s per test. The path is a trivial
 * one-liner and covered by code review.
 *
 * @covers \Automattic\WooCommerce\FraudProtection\Compat\WooPaymentsPaymentDataCompat
 */
class WooPaymentsPaymentDataCompatTest extends WC_Unit_Test_Case {

	/**
	 * The System Under Test.
	 *
	 * @var WooPaymentsPaymentDataCompat
	 */
	private WooPaymentsPaymentDataCompat $sut;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->sut = new WooPaymentsPaymentDataCompat();
	}

	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		delete_option( 'woocommerce_woocommerce_payments_settings' );
		parent::tearDown();
	}

	/**
	 * @testdox Returns resolved for non-WooPayments payment methods.
	 */
	public function test_returns_resolved_for_non_woopayments(): void {
		$resolved = new PaymentMethodData( 'stripe', 'card' );

		$result = $this->sut->resolve( $resolved );

		$this->assertSame( $resolved, $result );
	}

	/**
	 * @testdox Includes test mode when WooPayments test_mode setting is yes.
	 */
	public function test_includes_test_mode_from_settings(): void {
		update_option(
			'woocommerce_woocommerce_payments_settings',
			array( 'test_mode' => 'yes' )
		);

		$result = $this->sut->resolve(
			new PaymentMethodData( 'woocommerce_payments' )
		);

		$this->assertSame( PaymentMethodData::MODE_TEST, $result->to_array()['transaction_mode'] );
	}

	/**
	 * @testdox Includes live mode when WooPayments test_mode setting is no.
	 */
	public function test_includes_live_mode_from_settings(): void {
		update_option(
			'woocommerce_woocommerce_payments_settings',
			array( 'test_mode' => 'no' )
		);

		$result = $this->sut->resolve(
			new PaymentMethodData( 'woocommerce_payments' )
		);

		$this->assertSame( PaymentMethodData::MODE_LIVE, $result->to_array()['transaction_mode'] );
	}

	/**
	 * @testdox Transaction mode is unknown when WooPayments settings are absent.
	 */
	public function test_transaction_mode_unknown_without_settings(): void {
		$result = $this->sut->resolve(
			new PaymentMethodData( 'woocommerce_payments' )
		);

		$this->assertSame( PaymentMethodData::MODE_UNKNOWN, $result->to_array()['transaction_mode'] );
	}

	/**
	 * @testdox Matches APM gateways like woocommerce_payments_bancontact.
	 */
	public function test_matches_apm_gateway(): void {
		update_option(
			'woocommerce_woocommerce_payments_settings',
			array( 'test_mode' => 'yes' )
		);

		$result = $this->sut->resolve(
			new PaymentMethodData( 'woocommerce_payments_bancontact' )
		);

		$this->assertSame( PaymentMethodData::MODE_TEST, $result->to_array()['transaction_mode'] );
	}

	/**
	 * @testdox Does not match unrelated gateways with similar prefix.
	 */
	public function test_does_not_match_unrelated_gateway(): void {
		$resolved = new PaymentMethodData( 'woocommerce_paymentsx' );

		$result = $this->sut->resolve( $resolved );

		$this->assertSame( $resolved, $result );
	}

	/**
	 * @testdox Augments pre-resolved data with transaction mode.
	 */
	public function test_augments_preresolved_with_mode(): void {
		update_option(
			'woocommerce_woocommerce_payments_settings',
			array( 'test_mode' => 'no' )
		);

		$resolved = new PaymentMethodData( 'woocommerce_payments', 'card', true );

		$result = $this->sut->resolve( $resolved );

		$this->assertNotSame( $resolved, $result );
		$array = $result->to_array();
		$this->assertSame( PaymentMethodData::MODE_LIVE, $array['transaction_mode'] );
		$this->assertSame( 'card', $array['payment_type'] );
		$this->assertTrue( $array['is_saved_payment_method'] );
	}
}
