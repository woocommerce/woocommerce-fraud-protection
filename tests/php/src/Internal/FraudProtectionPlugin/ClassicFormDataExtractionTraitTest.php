<?php
/**
 * ClassicFormDataExtractionTraitTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin;

use Automattic\WooCommerce\FraudProtection\ClassicFormRequestData;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\ClassicFormDataExtractionTrait;
use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;

// phpcs:disable Squiz.Classes.ClassFileName.NoMatch
/**
 * Test double that exposes the trait's private methods for testing.
 */
class ClassicFormDataExtractionTraitTestDouble {
	use ClassicFormDataExtractionTrait;

	/**
	 * Public proxy for the private get_submitted_session_id() method.
	 *
	 * @return mixed Submitted session ID.
	 */
	public function test_get_submitted_session_id(): mixed {
		return $this->get_submitted_session_id();
	}

	/**
	 * Public proxy for the private build_request_data() method.
	 *
	 * @param array $form_data Form data array.
	 * @return array Structured request data.
	 */
	public function test_build_request_data( array $form_data ): array {
		return $this->build_request_data( $form_data );
	}
}
// phpcs:enable Squiz.Classes.ClassFileName.NoMatch

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound
/**
 * Tests for the ClassicFormDataExtractionTrait.
 *
 * The extraction rules themselves are covered by ClassicFormRequestDataTest; these
 * tests pin that the trait delegates to the public class.
 *
 * @covers \Automattic\WooCommerce\Internal\FraudProtectionPlugin\ClassicFormDataExtractionTrait
 */
class ClassicFormDataExtractionTraitTest extends FraudProtectionUnitTestCase {

	/**
	 * The System Under Test.
	 *
	 * @var ClassicFormDataExtractionTraitTestDouble
	 */
	private ClassicFormDataExtractionTraitTestDouble $sut;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->sut = new ClassicFormDataExtractionTraitTestDouble();
	}

	/**
	 * Tear down after each test.
	 */
	public function tearDown(): void {
		$_POST = array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		parent::tearDown();
	}

	/**
	 * @testdox get_submitted_session_id() returns what the public class reads from the POST body.
	 */
	public function test_get_submitted_session_id_delegates_to_public_class(): void {
		$_POST['wc_fraud_protection_session_id'] = "a\\\\b/<tag>?";

		$this->assertSame( ( new ClassicFormRequestData() )->get_submitted_session_id(), $this->sut->test_get_submitted_session_id() );
		$this->assertSame( 'a\b/<tag>?', $this->sut->test_get_submitted_session_id() );
	}

	/**
	 * @testdox build_request_data() returns what the public class builds.
	 */
	public function test_build_request_data_delegates_to_public_class(): void {
		$_POST     = array(
			'payment_method'           => 'stripe',
			'billing_city'             => 'Paris',
			'wc-stripe-payment-method' => 'pm_123',
		);
		$form_data = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Test supplies the form data.

		$result = $this->sut->test_build_request_data( $form_data );

		$this->assertSame( ( new ClassicFormRequestData() )->build_request_data( $form_data ), $result );
		$this->assertSame( 'stripe', $result['payment_method'] );
		$this->assertSame( array( 'city' => 'Paris' ), $result['billing_address'] );
		$this->assertSame( 'pm_123', $result['payment_data']['wc-stripe-payment-method'] );
	}
}
// phpcs:enable Generic.Files.OneObjectStructurePerFile.MultipleFound
