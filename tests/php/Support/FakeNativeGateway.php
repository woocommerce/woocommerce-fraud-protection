<?php
/**
 * FakeNativeGateway class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\FraudProtection\Tests\Support;

/**
 * A registered payment gateway with a chosen ID and `$supports` list.
 *
 * Register it with {@see \Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase::register_payment_gateways()}
 * to test how the plugin reacts to a gateway's native Fraud Protection declarations.
 */
class FakeNativeGateway extends \WC_Payment_Gateway {

	/**
	 * Constructor.
	 *
	 * @param string   $id       Gateway ID.
	 * @param string[] $supports Features the gateway declares.
	 */
	public function __construct( string $id, array $supports ) {
		$this->id           = $id;
		$this->title        = $id;
		$this->method_title = $id;
		$this->enabled      = 'yes';
		$this->supports     = $supports;
	}
}
