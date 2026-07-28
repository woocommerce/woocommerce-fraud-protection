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
 * This works as an allowlist rather than a list of known-bad types, and that is the point: a
 * reject-list covers only the shapes someone thought to name, so the type nobody anticipated
 * sails through it. Here a value travels only if its type is known to survive the encoder, and
 * everything else is rejected by construction.
 *
 * The rules:
 *
 * | Value                                       | Result                                     |
 * |---------------------------------------------|--------------------------------------------|
 * | `null`, `bool`, `int`                       | kept                                       |
 * | `float`                                     | kept when finite, rejected otherwise       |
 * | `string`                                    | kept as-is — see below                     |
 * | `array`                                     | walked; the array itself is always kept    |
 * | `object`                                    | kept when it encodes, once the walk permits it |
 * | `object` that iterates, serializes, or hooks itself | rejected — see below               |
 * | anything else (resource, ...)               | rejected                                   |
 *
 * **Strings are never tested, deliberately.** Invalid UTF-8 also makes `json_encode()` fail, but
 * `wp_json_encode()` retries through `_wp_json_sanity_check()`, which repairs the string and
 * succeeds — `"\xB1\x31"` reaches the wire as `"?1"`. Testing each string here would pre-empt that
 * repair and discard data WordPress was going to salvage. The rule is "reject what cannot be
 * encoded *and* cannot be repaired", not "reject what cannot be encoded".
 *
 * Known limits, all shared with `ApiClient::filter_empty_values()` and none introduced here.
 * Nesting deeper than the encoder's 512-level budget still fails, because the depth is a property
 * of the whole document rather than any one value. A self-referential array would recurse until
 * the stack gives out; PHP request parsing cannot construct one. Rejecting an element of a JSON
 * list leaves a gap in the keys, so the field encodes as an object rather than an array — no
 * payload field is a list of scalars today, but one would need reindexing.
 *
 * An object is refused outright, at any depth, when testing it would run its own code: when it
 * is `Traversable`, `JsonSerializable`, or carries a hook on a public property. All three are
 * user code the encoder itself dispatches, and none is guaranteed to
 * answer the same way twice — a serializer or a hook that consumes something answers once and
 * throws afterwards, on the real encode, where nothing catches it. So asking whether such a value
 * can travel is what stops it travelling, and it takes the document with it. `WC_Shipping_Rate`
 * is a concrete example: it implements `JsonSerializable`, so it is dropped whole even though its
 * serializer happens to be repeatable. Refusing is accepted because the guard cannot tell a
 * repeatable one from a single-use one without running it, and running it is the risk.
 *
 * What remains is read by plain property access. The one gap is a lazy object: `get_object_vars()`
 * materializes an uninitialized one, running its initializer — but exactly once, here, rather than
 * a second time on the encode, so it is not a repeatability hazard the way a hook is. No payload
 * field uses any of these types today.
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
	 * A log entry is read by a person, so an absent key is worse than a visible one: it looks
	 * like the field was never set. `[unencodable: resource]` says what happened.
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
			// and wp_json_encode()'s own try/catch covers only its repair pass. A test that can
			// throw instead of answering is not a test, and this one runs on the logging path
			// that the plugin's error handling depends on staying quiet.
			//
			// The throwable is deliberately not logged: this class is what makes a log context
			// safe to encode, so reporting from inside it would re-enter the very call it is
			// serving. A value that throws is reported the same way as one that simply cannot
			// encode — by its path on the wire, and by a marker in the log.
			try {
				// Walked before it is encoded, and the ordering is the whole refusal. Encoding
				// runs the object's own code — jsonSerialize() directly, iteration through the
				// repair pass a failed encode triggers, a property hook on either — anywhere in
				// the graph, not just at the top. The walk throws on any such object, so it has
				// to finish before the encode; a refusal after it could only ever be too late.
				//
				// One encode settles it. wp_json_encode() is the whole pipeline — it runs the
				// repair pass itself when the first attempt fails — so its return is the
				// encoder's final verdict on this value, not just the first pass. The repair
				// pass can change what a value encodes to (a non-backed enum, unencodable on
				// its own, becomes its {name} array; a bad-UTF-8 string is repaired) and can
				// change an object's shape, but wp_json_encode() has already accounted for
				// both, so there is nothing a separate second encode would add.
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
	 * either path. None of that code is guaranteed to answer the same way twice — a serializer,
	 * an iterator or a hook that consumes something answers once and throws afterwards — so
	 * running it to decide whether the value can travel is exactly what stops it travelling. Any
	 * such object is refused, at any depth, and this walk is what finds it.
	 *
	 * It recurses because the encoder does, so a refused object nested inside a permitted one is
	 * still caught. It reads nothing but type and, for a plain object, its public properties —
	 * which by then are known to be hook-free. It runs before the encode, so a refusal lands
	 * before the object's own code could.
	 *
	 * The walk is bounded by a depth budget so a cyclic graph terminates. `json_encode()` detects
	 * a cycle on its own, but this walk reaches the graph first, and unbounded recursion offers
	 * nothing to catch — it ends the process rather than the call.
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
	 * A `get` hook is a getter the encoder runs behind the property access, so `get_object_vars()`
	 * here and `json_encode()` later both run it — the same "testing the value runs its code"
	 * hazard as `Traversable` and `JsonSerializable`, and the same lack of a guarantee that it
	 * answers twice the same way. The test is deliberately coarse: `hasHooks()` does not
	 * distinguish `get` from `set`, and refusing an object for a set-only hook that the encoder
	 * would never run is cheaper than reflecting each hook's kind, and safe. The result is decided
	 * by the class, so it is cached per class rather than reflected on every value.
	 *
	 * `method_exists()` guards the reflection call because `ReflectionProperty::hasHooks()` does
	 * not exist below 8.4; on those versions no property can be hooked, so the answer is false.
	 *
	 * @param object $value Value to inspect.
	 * @return bool
	 */
	private static function has_hooked_properties( object $value ): bool {
		static $cache = array();

		$class = $value::class;

		if ( ! isset( $cache[ $class ] ) ) {
			// Computed into a local and assigned once, so a throw inside the loop leaves nothing
			// memoized rather than caching a wrong "not hooked" for the life of the request.
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
