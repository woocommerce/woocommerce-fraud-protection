<?php
/**
 * Shared PayPal Payments test stubs.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\FraudProtection\Tests\Support;

/** PayPal connection-state stub. */
class PayPalConnectionStateStub {

	/** @var ?bool */
	private static ?bool $sandbox = null;

	/** Set the sandbox state. */
	public static function set_sandbox( ?bool $sandbox ): void {
		self::$sandbox = $sandbox;
	}

	/** Whether the connection uses sandbox mode. */
	public function is_sandbox(): bool {
		return true === self::$sandbox;
	}

	/** Whether the connection uses production mode. */
	public function is_production(): bool {
		return false === self::$sandbox;
	}
}

/** PayPal container stub. */
class PayPalContainerStub {

	/** @var mixed */
	private static $merchant_id;

	/** @var bool */
	private static bool $merchant_id_throws = false;

	/** @var array<string, mixed> */
	private static array $services = array();

	/** Set the merchant identifier. */
	public static function set_merchant_id( $merchant_id ): void {
		self::$merchant_id = $merchant_id;
	}

	/** Control merchant identifier failures. */
	public static function set_merchant_id_throws( bool $throws ): void {
		self::$merchant_id_throws = $throws;
	}

	/** Set a container service. */
	public static function set_service( string $id, $service ): void {
		self::$services[ $id ] = $service;
	}

	/** Reset controlled values. */
	public static function reset(): void {
		self::$merchant_id        = null;
		self::$merchant_id_throws = false;
		self::$services           = array();
	}

	/** Get a service. */
	public function get( string $id ) {
		if ( array_key_exists( $id, self::$services ) ) {
			$service = self::$services[ $id ];
			if ( $service instanceof \Throwable ) {
				throw $service;
			}

			return $service;
		}

		if ( 'api.merchant_id' === $id ) {
			if ( self::$merchant_id_throws ) {
				throw new \RuntimeException( 'Merchant ID lookup failed' );
			}

			return self::$merchant_id;
		}

		return new PayPalConnectionStateStub();
	}
}

/** PayPal service locator stub. */
class PayPalPPCPStub {

	/** @var ?\Throwable */
	private static ?\Throwable $error = null;

	/** Control container failures. */
	public static function set_error( ?\Throwable $error ): void {
		self::$error = $error;
	}

	/** Get the container. */
	public static function container(): PayPalContainerStub {
		if ( null !== self::$error ) {
			throw self::$error;
		}

		return new PayPalContainerStub();
	}
}

/** Captured PayPal JSON response. */
class PayPalJsonResponseCapture {

	/** @var bool */
	public static bool $enabled = false;

	/** @var mixed */
	public static $data;

	/** @var ?int */
	public static ?int $status_code = null;

	/** Reset captured values. */
	public static function reset(): void {
		self::$enabled     = false;
		self::$data        = null;
		self::$status_code = null;
	}
}

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\Compat;

use Automattic\WooCommerce\FraudProtection\Tests\Support\PayPalJsonResponseCapture;

/**
 * Capture a test response or delegate to WordPress.
 *
 * @param mixed $data        Response data.
 * @param int   $status_code HTTP status.
 * @return never
 */
function wp_send_json_error( $data = null, $status_code = null ): never {
	if ( PayPalJsonResponseCapture::$enabled ) {
		PayPalJsonResponseCapture::$data        = $data;
		PayPalJsonResponseCapture::$status_code = $status_code;
		throw new \WPDieException();
	}

	\wp_send_json_error( $data, $status_code );
}
