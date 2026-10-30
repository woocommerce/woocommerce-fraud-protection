<?php
/**
 * GatewayRequestVerifier class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\FraudProtection;

use Automattic\WooCommerce\FraudProtection\Schemas\FraudDecision;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Compat\GatewayNativeSupport;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\FraudProtectionController;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Sessions\SessionIdError;
use Automattic\WooCommerce\Proxies\LegacyProxy;

defined( 'ABSPATH' ) || exit;

/**
 * Verifies a payment gateway's own requests and reuses their decisions for the submissions that complete them.
 *
 * For a request that the gateway handles itself before the WooCommerce submission, such as
 * a create-order or setup-intent call:
 *
 * - {@see verify_request()} verifies the request with {@see SessionVerifier} and returns a
 *   {@see VerifiedRequest}.
 * - {@see VerifiedRequest::reuse_decision_for()} keeps the decision for reuse, with a reference
 *   the gateway's server created in that request.
 * - {@see register_reference_reader()} tells the plugin how to read that reference from the
 *   gateway's later submissions. The submission that carries it gets the kept decision instead of a new
 *   verification, so the attempt is scored once.
 *
 * A gateway's reference reader always takes precedence over the plugin's built-in
 * compatibility layer for that gateway, whatever the order of registration.
 *
 * Resolve it from the WooCommerce container with
 * `wc_get_container()->get( GatewayRequestVerifier::class )` rather than constructing it:
 * the container supplies its dependencies through `init()`.
 *
 * @since 0.3.1
 */
class GatewayRequestVerifier {

	/**
	 * WooCommerce session key of the reusable decision records, keyed by gateway ID.
	 */
	private const REUSABLE_DECISIONS_SESSION_KEY = '_wc_fraud_protection_reusable_decisions';

	/**
	 * Session verifier instance.
	 *
	 * @var SessionVerifier
	 */
	private SessionVerifier $session_verifier;

	/**
	 * Session ID normalizer.
	 *
	 * @var SessionIdNormalizer
	 */
	private SessionIdNormalizer $session_id_normalizer;

	/**
	 * Legacy proxy instance.
	 *
	 * @var LegacyProxy
	 */
	private LegacyProxy $legacy_proxy;

	/**
	 * Reference readers registered through {@see register_reference_reader()}, keyed by gateway ID.
	 *
	 * @var array<string, callable>
	 */
	private array $reference_readers = array();

	/**
	 * Reference readers of the plugin's compatibility layers, keyed by gateway ID, with the features
	 * each one implements and the gateways whose declarations cover it.
	 *
	 * @var array<string, array{features: string[], covering_gateway_ids: string[], reader: callable}>
	 */
	private array $compatibility_reference_readers = array();

	/**
	 * Native gateway support checker.
	 *
	 * @var GatewayNativeSupport
	 */
	private GatewayNativeSupport $native_support;

	/**
	 * Initialize with dependencies.
	 *
	 * @internal
	 *
	 * @param SessionVerifier      $session_verifier      The session verifier instance.
	 * @param SessionIdNormalizer  $session_id_normalizer The session ID normalizer.
	 * @param LegacyProxy          $legacy_proxy          The legacy proxy instance.
	 * @param GatewayNativeSupport $native_support        Native gateway support checker.
	 */
	final public function init( SessionVerifier $session_verifier, SessionIdNormalizer $session_id_normalizer, LegacyProxy $legacy_proxy, GatewayNativeSupport $native_support ): void {
		$this->session_verifier      = $session_verifier;
		$this->session_id_normalizer = $session_id_normalizer;
		$this->legacy_proxy          = $legacy_proxy;
		$this->native_support        = $native_support;
	}

	/**
	 * Register the filter that supplies reusable decisions.
	 *
	 * @internal
	 */
	public function register(): void {
		add_filter( 'woocommerce_fraud_protection_skip_session_verify', array( $this, 'supply_reused_decision' ), 10, 3 );
	}

	/**
	 * Verify a request that the gateway handles itself.
	 *
	 * Call it before acting on the request, after your own nonce and permission checks. Stop the
	 * request when {@see VerifiedRequest::is_blocked()} is true, with the message from
	 * {@see BlockedSessionMessage}. Fail-open: like {@see SessionVerifier::verify_session()}, it
	 * never throws, and errors produce an Allow decision.
	 *
	 * @param string $source       Stable ASCII identifier of the request, 32 characters at most, such as `my_gateway_create_order`.
	 * @param string $gateway_id   ID of the gateway the request pays with.
	 * @param array  $payment_data Gateway fields that describe the payment instrument, passed to the resolved-payment-data filter.
	 * @param int    $order_id     The WooCommerce order the request pays for, or 0 before the order exists.
	 * @param mixed  $session_id   The session ID the browser sent. Null reads it from the request body under {@see SessionVerifier::SESSION_ID_FIELD}: a form field, or a top-level property of a JSON body.
	 * @return VerifiedRequest The decision, which the submission that completes the attempt can reuse.
	 *
	 * @since 0.3.1
	 */
	public function verify_request( string $source, string $gateway_id, array $payment_data = array(), int $order_id = 0, mixed $session_id = null ): VerifiedRequest {
		$request_data = array(
			'payment_method' => $gateway_id,
			'payment_data'   => $payment_data,
		);

		if ( null === $session_id ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verification is the caller's responsibility.
			if ( array_key_exists( SessionVerifier::SESSION_ID_FIELD, $_POST ) ) {
				$session_id = ( new ClassicFormRequestData() )->get_submitted_session_id();
				// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verification is the caller's responsibility. Strings are unslashed and sanitized below.
				$session_id_error = is_string( $_POST[ SessionIdError::FIELD ] ?? null ) ? wp_unslash( $_POST[ SessionIdError::FIELD ] ) : null;
			} else {
				$body             = $this->get_json_body();
				$session_id       = $body[ SessionVerifier::SESSION_ID_FIELD ] ?? '';
				$session_id_error = $body[ SessionIdError::FIELD ] ?? null;
			}

			if ( is_string( $session_id_error ) ) {
				$request_data[ SessionIdError::FIELD ] = sanitize_key( $session_id_error );
			}
		}

		$decision            = $this->session_verifier->verify_session( $session_id, $source, $order_id, $request_data );
		$verified_session_id = $this->session_verifier->last_verified_session_id();
		// The skip filter receives the bounded source, so the record keeps the same value.
		$source = mb_substr( $source, 0, 32 );

		return new VerifiedRequest(
			$decision,
			fn( string $reference ): bool => $this->store_reusable_decision( $gateway_id, $source, $decision, $verified_session_id, $reference )
		);
	}

	/**
	 * Decode the current request's body when it is JSON.
	 *
	 * @return array The decoded top-level object, or an empty array.
	 */
	private function get_json_body(): array {
		$content_type = isset( $_SERVER['CONTENT_TYPE'] ) && is_string( $_SERVER['CONTENT_TYPE'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['CONTENT_TYPE'] ) ) ) : '';
		if ( ! str_contains( $content_type, 'application/json' ) ) {
			return array();
		}

		$raw  = $this->legacy_proxy->call_function( 'file_get_contents', 'php://input' );
		$body = is_string( $raw ) ? json_decode( $raw, true ) : null;

		return is_array( $body ) ? $body : array();
	}

	/**
	 * Register how to read the reference of a reusable decision from the gateway's later submissions.
	 *
	 * Call it once while your plugin loads. Before the plugin verifies a checkout, Pay for order
	 * or payment-method submission paid with `$gateway_id`, it calls `$reference_reader` with the
	 * submission's payment data, its full request data and its verification source. Return the
	 * reference that the submission carries, or an empty string. When it matches the reference
	 * passed to the gateway's last {@see VerifiedRequest::reuse_decision_for()} call, and the cart
	 * items have not changed since, the submission reuses that decision instead of being verified, and the
	 * decision is used up. A submission with the same source as the earlier request never reuses it.
	 *
	 * A later call for the same gateway replaces the reader.
	 *
	 * @param string   $gateway_id       ID of the gateway, as in the submission's payment method.
	 * @param callable $reference_reader Receives `array $payment_data, array $request_data, string $source` and returns the reference as a string.
	 *
	 * @since 0.3.1
	 */
	public function register_reference_reader( string $gateway_id, callable $reference_reader ): void {
		$this->reference_readers[ $gateway_id ] = $reference_reader;
	}

	/**
	 * Register the reference reader of one of the plugin's compatibility layers.
	 *
	 * It runs only while the gateway has no reader of its own from
	 * {@see register_reference_reader()} and not all of `$features` are declared, each by the
	 * gateway or a covering gateway, so a gateway's native integration always replaces it,
	 * whichever registers first. A layer that stands aside for some of its flows on fewer
	 * declarations checks them in its reader, which receives the submission's source.
	 *
	 * @internal
	 *
	 * @param string   $gateway_id              ID of the gateway, as in the submission's payment method.
	 * @param string[] $features                The {@see FraudProtectionFeature} values the compatibility layer implements for the gateway. Without any, the reader never stands aside.
	 * @param callable $reference_reader        Same contract as for {@see register_reference_reader()}.
	 * @param string   ...$covering_gateway_ids Gateways whose declarations also cover `$gateway_id`, such as the main gateway of its family.
	 */
	public function register_compatibility_reference_reader( string $gateway_id, array $features, callable $reference_reader, string ...$covering_gateway_ids ): void {
		$this->compatibility_reference_readers[ $gateway_id ] = array(
			'features'             => array_values( array_filter( $features, 'is_string' ) ),
			'covering_gateway_ids' => $covering_gateway_ids,
			'reader'               => $reference_reader,
		);
	}

	/**
	 * Get the reference reader for a gateway's submissions.
	 *
	 * @param string $gateway_id The submission's gateway.
	 * @return callable|null The gateway's own reader, else a compatibility layer's that the gateway has not replaced.
	 */
	private function get_reference_reader( string $gateway_id ): ?callable {
		if ( isset( $this->reference_readers[ $gateway_id ] ) ) {
			return $this->reference_readers[ $gateway_id ];
		}

		$compatibility = $this->compatibility_reference_readers[ $gateway_id ] ?? null;
		if ( null === $compatibility ) {
			return null;
		}

		if ( array() === $compatibility['features'] ) {
			return $compatibility['reader'];
		}

		foreach ( $compatibility['features'] as $feature ) {
			if ( ! $this->native_support->declared( $feature, $gateway_id, ...$compatibility['covering_gateway_ids'] ) ) {
				return $compatibility['reader'];
			}
		}

		return null;
	}

	/**
	 * Supply a reusable decision to the submission that carries its reference.
	 *
	 * @internal
	 *
	 * @param mixed $supplied_decision The filter's default (false), or what an earlier callback returned.
	 * @param mixed $source            Verification source of the submission.
	 * @param mixed $request_data      Request data with payment_method and payment_data.
	 * @return mixed The reused decision, or the incoming value to verify normally.
	 */
	public function supply_reused_decision( $supplied_decision, $source, $request_data ) {
		if ( false !== $supplied_decision || ! is_string( $source ) || ! is_array( $request_data ) ) {
			return $supplied_decision;
		}

		$gateway_id = $request_data['payment_method'] ?? null;
		if ( ! is_string( $gateway_id ) || ! function_exists( 'WC' ) || ! WC()->session ) {
			return $supplied_decision;
		}

		$reference_reader = $this->get_reference_reader( $gateway_id );
		if ( null === $reference_reader ) {
			return $supplied_decision;
		}

		try {
			$records = $this->get_reusable_decisions();
			$record  = $this->validate_record( $records[ $gateway_id ] ?? null );
			if ( null === $record ) {
				if ( isset( $records[ $gateway_id ] ) ) {
					unset( $records[ $gateway_id ] );
					$this->set_reusable_decisions( $records );
				}
				return $supplied_decision;
			}

			if ( $record['source'] === $source ) {
				return $supplied_decision;
			}

			$payment_data = is_array( $request_data['payment_data'] ?? null ) ? $request_data['payment_data'] : array();
			$reference    = $reference_reader( $payment_data, $request_data, $source );
			if ( ! is_string( $reference ) || '' === $reference || ! hash_equals( $record['reference'], $reference ) ) {
				return $supplied_decision;
			}

			// One use only: the decision belongs to this attempt alone.
			unset( $records[ $gateway_id ] );
			$this->set_reusable_decisions( $records );

			// Changed cart items make a new attempt, which is verified normally.
			if ( self::get_cart_items_hash() !== $record['cart_hash'] ) {
				return $supplied_decision;
			}

			return new SuppliedDecision( $record['decision'], $record['session_id'] );
		} catch ( \Throwable $e ) {
			FraudProtectionController::log(
				'warning',
				'Reusing a request decision failed; the submission will be verified',
				array(
					'event_source'      => $source,
					'payment_type'      => $gateway_id,
					'exception_class'   => $e::class,
					'exception_message' => $e->getMessage(),
				),
				true
			);

			return $supplied_decision;
		}
	}

	/**
	 * Store or clear the gateway's reusable decision record.
	 *
	 * @param string        $gateway_id The gateway ID.
	 * @param string        $source     Verification source of the request.
	 * @param FraudDecision $decision   The decision for the request.
	 * @param string        $session_id The session ID the verification resolved.
	 * @param string        $reference  The reference the gateway's server created.
	 * @return bool Whether a record was stored.
	 */
	private function store_reusable_decision( string $gateway_id, string $source, FraudDecision $decision, string $session_id, string $reference ): bool {
		try {
			if ( ! function_exists( 'WC' ) || ! WC()->session ) {
				return false;
			}

			$records = $this->get_reusable_decisions();
			unset( $records[ $gateway_id ] );

			$stored = '' !== $session_id && '' !== $reference && in_array( $decision, FraudDecision::ACTIONABLE, true );
			if ( $stored ) {
				$records[ $gateway_id ] = array(
					'source'     => $source,
					'session_id' => $session_id,
					'decision'   => $decision->value,
					'reference'  => $reference,
					'cart_hash'  => self::get_cart_items_hash(),
				);
			}

			$this->set_reusable_decisions( $records );

			if ( $stored ) {
				FraudProtectionController::log(
					'info',
					'Kept a request decision for the submission that completes the attempt',
					array(
						'event_source' => $source,
						'session_id'   => $session_id,
						'payment_type' => $gateway_id,
						'decision'     => $decision->value,
					)
				);
			}

			return $stored;
		} catch ( \Throwable $e ) {
			FraudProtectionController::log(
				'warning',
				'Keeping a request decision failed; the submission will be verified',
				array(
					'event_source'      => $source,
					'payment_type'      => $gateway_id,
					'exception_class'   => $e::class,
					'exception_message' => $e->getMessage(),
				),
				true
			);

			return false;
		}
	}

	/**
	 * Validate a stored reusable decision record.
	 *
	 * @param mixed $stored The stored record.
	 * @return ?array{source: string, session_id: string, decision: FraudDecision, reference: string, cart_hash: string} The record, or null when it is unusable.
	 */
	private function validate_record( mixed $stored ): ?array {
		if ( ! is_array( $stored ) ) {
			return null;
		}

		$session_id = $this->session_id_normalizer->normalize_stored( $stored['session_id'] ?? null );
		$decision   = is_string( $stored['decision'] ?? null ) ? FraudDecision::tryFrom( $stored['decision'] ) : null;

		if (
			! is_string( $stored['source'] ?? null )
			|| '' === $session_id
			|| ! in_array( $decision, FraudDecision::ACTIONABLE, true )
			|| ! is_string( $stored['reference'] ?? null )
			|| '' === $stored['reference']
			|| ! is_string( $stored['cart_hash'] ?? null )
		) {
			return null;
		}

		return array(
			'source'     => $stored['source'],
			'session_id' => $session_id,
			'decision'   => $decision,
			'reference'  => $stored['reference'],
			'cart_hash'  => $stored['cart_hash'],
		);
	}

	/**
	 * Read the reusable decision records from the WooCommerce session.
	 *
	 * @return array<string, mixed>
	 */
	private function get_reusable_decisions(): array {
		$records = WC()->session->get( self::REUSABLE_DECISIONS_SESSION_KEY );

		return is_array( $records ) ? $records : array();
	}

	/**
	 * Write the reusable decision records to the WooCommerce session.
	 *
	 * @param array<string, mixed> $records The records, keyed by gateway ID.
	 */
	private function set_reusable_decisions( array $records ): void {
		WC()->session->set( self::REUSABLE_DECISIONS_SESSION_KEY, array() === $records ? null : $records );
	}

	/**
	 * Get a hash of the current cart items and their quantities.
	 *
	 * Unlike `WC_Cart::get_cart_hash()`, it leaves out the total, which changes when shipping
	 * or taxes are calculated after the gateway's request. Each cart item key already identifies
	 * the product, its variation and its item data.
	 *
	 * @return string The hash, or an empty string without cart items.
	 */
	private static function get_cart_items_hash(): string {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return '';
		}

		$quantities = array();
		foreach ( WC()->cart->get_cart() as $key => $item ) {
			$quantities[ (string) $key ] = is_array( $item ) && is_numeric( $item['quantity'] ?? null ) ? (float) $item['quantity'] : 0;
		}
		ksort( $quantities );

		return array() === $quantities ? '' : md5( (string) wp_json_encode( $quantities ) );
	}
}
