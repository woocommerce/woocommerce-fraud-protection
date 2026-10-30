<?php
/**
 * Test stub for the WooCommerce Stripe Gateway API class.
 *
 * Defines WC_Stripe_API in the global namespace, unless the real class is
 * loaded, so that the Stripe compatibility classes can call the Stripe API.
 */

declare( strict_types=1 );

// This fixture keeps the name of the external API that it models.
// phpcs:disable Squiz.Classes.ClassFileName.NoMatch, Squiz.Classes.ValidClassName.NotCamelCaps

if ( class_exists( 'WC_Stripe_API', false ) ) {
	return;
}

/** Stripe API test stub. */
class WC_Stripe_API_Stub {

	/**
	 * Mock response to return from get_payment_method().
	 *
	 * @var mixed
	 */
	private static $mock_response;

	/**
	 * Mock responses to return from retrieve(), by API path.
	 *
	 * @var array<string, mixed>
	 */
	private static array $mock_retrieve_responses = array();

	/**
	 * API paths passed to retrieve().
	 *
	 * @var string[]
	 */
	private static array $retrieved = array();

	/**
	 * Payment method IDs passed to get_payment_method().
	 *
	 * @var string[]
	 */
	private static array $requested_payment_methods = array();

	/**
	 * Reset mock state.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$mock_response             = null;
		self::$mock_retrieve_responses   = array();
		self::$retrieved                 = array();
		self::$requested_payment_methods = array();
	}

	/**
	 * Set the mock response.
	 *
	 * @param mixed $response The response to return.
	 */
	public static function set_mock_response( $response ): void {
		self::$mock_response = $response;
	}

	/**
	 * Set the mock response of retrieve() for an API path.
	 *
	 * @param string $api      The API path.
	 * @param mixed  $response The response to return.
	 */
	public static function set_mock_retrieve_response( string $api, $response ): void {
		self::$mock_retrieve_responses[ $api ] = $response;
	}

	/**
	 * Get the API paths passed to retrieve().
	 *
	 * @return string[]
	 */
	public static function get_retrieved(): array {
		return self::$retrieved;
	}

	/**
	 * Get the payment method IDs passed to get_payment_method().
	 *
	 * @return string[]
	 */
	public static function get_requested_payment_methods(): array {
		return self::$requested_payment_methods;
	}

	/**
	 * Retrieve a payment method by ID.
	 *
	 * Mirrors the real WC_Stripe_API static method signature.
	 *
	 * @param string $payment_method_id The payment method ID.
	 * @return mixed
	 */
	public static function get_payment_method( string $payment_method_id ) {
		self::$requested_payment_methods[] = $payment_method_id;
		return self::$mock_response;
	}

	/**
	 * Retrieve an API object.
	 *
	 * Mirrors the real WC_Stripe_API static method signature. Paths without a
	 * mock response return null, as the real method does with invalid API keys.
	 * A Throwable mock response is thrown.
	 *
	 * @param string $api The API path.
	 * @return mixed
	 * @throws \Throwable The mock response, when it is a Throwable.
	 */
	public static function retrieve( $api ) {
		self::$retrieved[] = $api;
		$response          = self::$mock_retrieve_responses[ $api ] ?? null;
		if ( $response instanceof \Throwable ) {
			throw $response;
		}

		return $response;
	}
}

class_alias( 'WC_Stripe_API_Stub', 'WC_Stripe_API' );
