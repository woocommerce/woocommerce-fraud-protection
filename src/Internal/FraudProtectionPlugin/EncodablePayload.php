<?php
/**
 * EncodablePayload class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin;

defined( 'ABSPATH' ) || exit;

/**
 * Reduces a payload to the values PHP's JSON encoder can carry.
 *
 * The encoder rejects an entire document over a single value it cannot carry, and both places
 * this plugin encodes one pay that price whole: a request body fails as a unit, and WooCommerce's
 * log handler interpolates a failed context encode as an empty string. Reducing the payload first
 * means such a value costs its own field instead of the document around it.
 *
 * That holds at the encoder, and only there. `serialize()` runs upstream of it on the collector's
 * path and is stricter than the encoder these rules model, so it can still refuse a value this
 * walk permits — before the walk ever sees it.
 *
 * The rules, an allowlist so an unanticipated type is rejected rather than sailing through:
 *
 * | Value                                       | Result                                     |
 * |---------------------------------------------|--------------------------------------------|
 * | `null`, `bool`, `int`                       | kept                                       |
 * | `float`                                     | kept when finite, rejected otherwise       |
 * | `string`                                    | kept as-is — see below                     |
 * | `array`                                     | walked; the array itself is always kept    |
 * | `object`                                    | kept when it encodes, once the walk permits it |
 * | `object` that iterates, serializes, or hooks itself | rejected — see guard_against_self_encoding() |
 * | anything else (resource, ...)               | rejected                                   |
 *
 * Strings are never tested, deliberately: invalid UTF-8 also makes `json_encode()` fail, but
 * `wp_json_encode()` repairs it through `_wp_json_sanity_check()`, and testing here would
 * discard data WordPress was going to salvage. The repair needs `mb_convert_encoding()`;
 * without mbstring on a site whose charset is not UTF-8 it is a no-op and the document still
 * fails — a host limitation, not a value this class could have rejected.
 *
 * Known limits, all shared with `ApiClient::filter_empty_values()` and none introduced here:
 * nesting deeper than the encoder's 512-level budget still fails, because depth is a property of
 * the whole document rather than any one value; a self-referential array would recurse until the
 * stack gives out, and PHP request parsing cannot construct one; rejecting an element of a JSON
 * list leaves a gap in the keys, so the field encodes as an object rather than an array — no
 * payload field is a list of scalars today.
 */
final class EncodablePayload {

	/**
	 * Depth budget for inspecting an object, matching the encoder's own default.
	 */
	private const MAX_DEPTH = 512;

	/**
	 * This class only exposes static helpers.
	 */
	private function __construct() {}

	/**
	 * Filter a payload for transmission, omitting anything that cannot travel.
	 *
	 * The key is dropped rather than nulled, matching {@see ApiClient::filter_empty_values()} and
	 * the documented wire contract, where an unset optional is absent rather than `null`.
	 *
	 * @template TKey of array-key
	 * @param array<TKey, mixed> $data     Payload to filter.
	 * @param array<int, string> $rejected Collects the path of every rejected value.
	 * @return array<TKey, mixed>
	 */
	public static function for_wire( array $data, array &$rejected = array() ): array {
		return self::filter( $data, false, $rejected, '' );
	}

	/**
	 * Filter a payload for logging, replacing anything that cannot travel with a readable marker.
	 *
	 * In a log an absent key reads as a field that was never set; `[unencodable: resource]`
	 * says what happened.
	 *
	 * @template TKey of array-key
	 * @param array<TKey, mixed> $data     Payload to filter.
	 * @param array<int, string> $rejected Collects the path of every rejected value.
	 * @return array<TKey, mixed>
	 */
	public static function for_log( array $data, array &$rejected = array() ): array {
		return self::filter( $data, true, $rejected, '' );
	}

	/**
	 * Walk a payload, keeping only encodable values.
	 *
	 * @template TKey of array-key
	 * @param array<TKey, mixed> $data       Payload to filter.
	 * @param bool               $substitute Replace a rejected value with a marker instead of dropping its key.
	 * @param array<int, string> $rejected   Collects the path of every rejected value.
	 * @param string             $path       Path accumulated so far.
	 * @return array<TKey, mixed>
	 */
	private static function filter( array $data, bool $substitute, array &$rejected, string $path ): array {
		$filtered = array();

		foreach ( $data as $key => $value ) {
			$key_path = '' === $path ? (string) $key : $path . '.' . $key;

			if ( is_array( $value ) ) {
				$filtered[ $key ] = self::filter( $value, $substitute, $rejected, $key_path );
				continue;
			}

			if ( self::is_encodable( $value ) ) {
				$filtered[ $key ] = $value;
				continue;
			}

			$rejected[] = $key_path;

			if ( $substitute ) {
				$filtered[ $key ] = self::describe( $value );
			}
		}

		return $filtered;
	}

	/**
	 * Whether the JSON encoder can carry this value, or WordPress can repair it into something it
	 * can carry.
	 *
	 * @param mixed $value Value to test. Arrays are handled by the walker and never reach here.
	 * @return bool
	 */
	private static function is_encodable( mixed $value ): bool {
		if ( null === $value || is_bool( $value ) || is_int( $value ) || is_string( $value ) ) {
			return true;
		}

		if ( is_float( $value ) ) {
			return is_finite( $value );
		}

		if ( is_object( $value ) ) {
			// json_encode() propagates an exception thrown by JsonSerializable::jsonSerialize(),
			// and wp_json_encode()'s own try/catch covers only its repair pass — so the test
			// itself could throw, on the logging path this class keeps quiet. The throwable is
			// not logged: reporting from inside the class that makes log contexts encodable
			// would re-enter the very call it is serving.
			try {
				// The walk must finish before the encode: encoding runs the object's own code
				// anywhere in the graph, and a refusal after that is too late. One encode then
				// settles it — wp_json_encode() runs the repair pass itself, so its return is
				// the encoder's final verdict, and a second encode would add nothing.
				self::guard_against_self_encoding( $value, self::MAX_DEPTH );

				return false !== \wp_json_encode( $value );
			} catch ( \Throwable $e ) {
				return false;
			}
		}

		return false;
	}

	/**
	 * Walk a value and throw if encoding it would run any object's own code.
	 *
	 * The encoder describes most objects by reading their public properties, but three kinds it
	 * asks to describe themselves: a `Traversable` through the repair pass a failed encode
	 * triggers, a `JsonSerializable` directly, and an object with a hooked public property on
	 * either path. None of that code is guaranteed to answer the same way twice — one that
	 * consumes something answers once and throws afterwards, on the real encode, where nothing
	 * catches it — so running it to decide whether the value can travel is exactly what stops it
	 * travelling. Refused at any depth, because the encoder recurses too. This costs repeatable
	 * serializers as well (`WC_Shipping_Rate` is dropped whole): telling a repeatable one from a
	 * single-use one means running it.
	 *
	 * The depth budget terminates a cyclic graph: `json_encode()` detects a cycle on its own,
	 * but this walk reaches the graph first, and unbounded recursion ends the process rather
	 * than the call. One gap: `get_object_vars()` materializes a lazy object, running its
	 * initializer — once, here, rather than again on the encode.
	 *
	 * @param mixed $value Value to inspect.
	 * @param int   $depth Remaining depth budget.
	 * @return void
	 * @throws \RuntimeException When encoding the value would run its own code, or the depth budget runs out.
	 */
	private static function guard_against_self_encoding( mixed $value, int $depth ): void {
		if ( $depth < 0 ) {
			throw new \RuntimeException( 'Reached depth limit' );
		}

		if ( is_object( $value ) ) {
			if ( $value instanceof \Traversable || $value instanceof \JsonSerializable || self::has_hooked_properties( $value ) ) {
				throw new \RuntimeException( 'Cannot inspect this object without running its own code' );
			}

			$items = get_object_vars( $value );
		} elseif ( is_array( $value ) ) {
			$items = $value;
		} else {
			return;
		}

		foreach ( $items as $item ) {
			self::guard_against_self_encoding( $item, $depth - 1 );
		}
	}

	/**
	 * Whether any public property carries a PHP 8.4 hook.
	 *
	 * A `get` hook is a getter the encoder runs behind the property access — the same "testing
	 * the value runs its code" hazard as `Traversable` and `JsonSerializable`. The test is
	 * deliberately coarse: `hasHooks()` does not distinguish `get` from `set`, and refusing an
	 * object for a set-only hook the encoder would never run is cheaper than reflecting each
	 * hook's kind, and safe. `method_exists()` guards the call because `hasHooks()` does not
	 * exist below PHP 8.4, where no property can be hooked. Decided by the class, so cached per
	 * class.
	 *
	 * @param object $value Value to inspect.
	 * @return bool
	 */
	private static function has_hooked_properties( object $value ): bool {
		static $cache = array();

		$class = $value::class;

		if ( ! isset( $cache[ $class ] ) ) {
			// Computed into a local and assigned once, so a throw mid-loop memoizes nothing
			// rather than a wrong "not hooked" for the life of the request.
			$hooked = false;

			foreach ( ( new \ReflectionClass( $class ) )->getProperties( \ReflectionProperty::IS_PUBLIC ) as $property ) {
				if ( method_exists( $property, 'hasHooks' ) && $property->hasHooks() ) {
					$hooked = true;
					break;
				}
			}

			$cache[ $class ] = $hooked;
		}

		return $cache[ $class ];
	}

	/**
	 * A short, encodable description of a value that cannot travel.
	 *
	 * Names the shape, never the contents: this goes into a log entry, and the values reaching
	 * it came from the request.
	 *
	 * @param mixed $value Rejected value.
	 * @return string
	 */
	private static function describe( mixed $value ): string {
		if ( is_float( $value ) ) {
			$name = is_nan( $value ) ? 'NAN' : ( $value > 0 ? 'INF' : '-INF' );

			return sprintf( '[unencodable: %s]', $name );
		}

		if ( is_object( $value ) ) {
			return sprintf( '[unencodable: object %s]', $value::class );
		}

		return sprintf( '[unencodable: %s]', gettype( $value ) );
	}
}
