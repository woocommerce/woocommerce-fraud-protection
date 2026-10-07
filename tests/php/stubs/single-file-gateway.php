<?php
/**
 * Plugin Name: Fraud Protection Test Single-File Gateway
 * Version: 1.2.3
 *
 * Test stub for a payment gateway declared by a single-file plugin.
 * Tests copy this file directly into the plugins directory before loading it.
 */

declare( strict_types=1 );

/**
 * Payment gateway declared by a single-file plugin.
 */
class WC_Fraud_Protection_Test_Single_File_Gateway extends WC_Payment_Gateway {

	/**
	 * Set the gateway ID.
	 */
	public function __construct() {
		$this->id = 'wfp_test_single_file';
	}
}
