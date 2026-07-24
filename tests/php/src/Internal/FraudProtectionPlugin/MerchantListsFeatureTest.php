<?php
/**
 * MerchantListsFeatureTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin;

use Automattic\WooCommerce\Internal\FraudProtectionPlugin\MerchantListsFeature;
use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;

/**
 * Tests for the MerchantListsFeature class.
 */
class MerchantListsFeatureTest extends FraudProtectionUnitTestCase {

	/**
	 * @testdox Should be enabled, with the gate hardcoded rather than site-configurable.
	 */
	public function test_is_enabled(): void {
		$this->assertTrue( ( new MerchantListsFeature() )->is_enabled() );
	}
}
