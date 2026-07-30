<?php
/**
 * A hooked-property fixture object for EncodablePayloadTest.
 *
 * Lives in its own file because property-hook syntax does not parse before PHP 8.4, while every
 * *Test.php file is loaded on every supported version. Nothing discovers or analyses this file on
 * its own: it runs only when required, from a test gated on PHP 8.4. Each require returns a fresh
 * instance.
 *
 * The hook reads finite until its third call and then non-finite, so the object encodes
 * standalone, survives an inspection that reads it, and fails only the real encode. The counter
 * it drives is {@see \Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin\HookProbe},
 * a plain class defined normally in EncodablePayloadTest.php.
 *
 * @package WooCommerce_Fraud_Protection\Tests
 */

declare( strict_types=1 );

use Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin\HookProbe;

return new class() {
	/**
	 * A hooked public property: reading it runs the hook.
	 *
	 * @var float
	 */
	public float $amount {
		get {
			HookProbe::$reads++;
			return HookProbe::$reads >= 3 ? INF : 1.5;
		}
	}
};
