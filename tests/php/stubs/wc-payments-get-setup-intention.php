<?php
/**
 * Test stub for the WooPayments request that reads a setup intent.
 *
 * Defines WCPay\Core\Server\Request\Get_Setup_Intention, unless the real class
 * is loaded, so that the WooPayments compatibility classes can read setup intents.
 */

declare( strict_types=1 );

namespace WCPay\Core\Server\Request;

// This fixture keeps the name of the external API that it models.
// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound, Squiz.Classes.ClassFileName.NoMatch, Squiz.Classes.ValidClassName.NotCamelCaps

if ( class_exists( Get_Setup_Intention::class, false ) ) {
	return;
}

/** WooPayments setup intention model test stub. */
class WC_Payments_API_Setup_Intention_Stub {

	/**
	 * Payment method ID of the setup intent.
	 *
	 * @var ?string
	 */
	private ?string $payment_method_id;

	/**
	 * Constructor.
	 *
	 * @param ?string $payment_method_id Payment method ID of the setup intent.
	 */
	public function __construct( ?string $payment_method_id ) {
		$this->payment_method_id = $payment_method_id;
	}

	/**
	 * Get the payment method ID, as the real model does.
	 *
	 * @return ?string
	 */
	public function get_payment_method_id() {
		return $this->payment_method_id;
	}
}

/** WooPayments Get_Setup_Intention request test stub. */
class Get_Setup_Intention_Stub {

	/**
	 * Mock responses of send(), by setup intent ID.
	 *
	 * @var array<string, mixed>
	 */
	private static array $mock_responses = array();

	/**
	 * Setup intent IDs that were sent.
	 *
	 * @var string[]
	 */
	private static array $sent = array();

	/**
	 * Setup intent ID of this request.
	 *
	 * @var string
	 */
	private string $id;

	/**
	 * Constructor.
	 *
	 * @param string $id Setup intent ID.
	 */
	private function __construct( string $id ) {
		$this->id = $id;
	}

	/**
	 * Reset mock state.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$mock_responses = array();
		self::$sent           = array();
	}

	/**
	 * Set the response of send() for a setup intent ID.
	 *
	 * A Throwable response is thrown.
	 *
	 * @param string $id       Setup intent ID.
	 * @param mixed  $response The response to return.
	 */
	public static function set_mock_response( string $id, $response ): void {
		self::$mock_responses[ $id ] = $response;
	}

	/**
	 * Set a setup intent that holds the given payment method as the response for an ID.
	 *
	 * @param string  $id                Setup intent ID.
	 * @param ?string $payment_method_id Payment method ID that the setup intent holds.
	 */
	public static function set_mock_setup_intent( string $id, ?string $payment_method_id ): void {
		self::$mock_responses[ $id ] = new WC_Payments_API_Setup_Intention_Stub( $payment_method_id );
	}

	/**
	 * Get the setup intent IDs that were sent.
	 *
	 * @return string[]
	 */
	public static function get_sent(): array {
		return self::$sent;
	}

	/**
	 * Create the request, as the real class does.
	 *
	 * @param mixed $id Setup intent ID.
	 * @return self
	 */
	public static function create( $id = null ): self {
		return new self( (string) $id );
	}

	/**
	 * Send the request. IDs without a mock response return null.
	 *
	 * @return mixed
	 * @throws \Throwable The mock response, when it is a Throwable.
	 */
	public function send() {
		self::$sent[] = $this->id;
		$response     = self::$mock_responses[ $this->id ] ?? null;
		if ( $response instanceof \Throwable ) {
			throw $response;
		}

		return $response;
	}
}

class_alias( Get_Setup_Intention_Stub::class, Get_Setup_Intention::class );
