<?php
/**
 * EncodablePayloadTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin;

use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\EncodablePayload;

/**
 * Unit coverage for the payload allowlist.
 *
 * Two halves that have to be tested together: it must reject everything the encoder cannot carry,
 * and it must keep everything it can. A guard that dropped the entire payload would satisfy the
 * first half alone, which is why the passthrough cases carry as much weight here as the rejections.
 */
class EncodablePayloadTest extends FraudProtectionUnitTestCase {

	/**
	 * @testdox for_wire() keeps a value the encoder can carry.
	 *
	 * @dataProvider provide_encodable_values
	 *
	 * @param mixed $value Value under test.
	 */
	public function test_for_wire_keeps_encodable_values( mixed $value ): void {
		$result = EncodablePayload::for_wire( array( 'field' => $value ) );

		$this->assertArrayHasKey( 'field', $result, 'the value must survive' );
		$this->assertSame( $value, $result['field'] );
	}

	/**
	 * Data provider for {@see test_for_wire_keeps_encodable_values()}.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public function provide_encodable_values(): array {
		return array(
			'null'                 => array( null ),
			'true'                 => array( true ),
			'false'                => array( false ),
			'int'                  => array( 2 ),
			'zero'                 => array( 0 ),
			'finite float'         => array( 2.5 ),
			'negative float'       => array( -2.5 ),
			'tiny denormal float'  => array( 1.0E-320 ),
			'string'               => array( 'not-a-number' ),
			'empty string'         => array( '' ),
			'numeric string'       => array( '1e400' ),
			// Kept deliberately: json_encode() rejects invalid UTF-8, but wp_json_encode()
			// repairs it. Testing strings here would destroy what WordPress would salvage.
			'invalid UTF-8 string' => array( "\xB1\x31" ),
		);
	}

	/**
	 * @testdox for_wire() drops a value the encoder cannot carry, and records its path.
	 *
	 * @dataProvider provide_unencodable_values
	 *
	 * @param mixed $value Value under test.
	 */
	public function test_for_wire_drops_unencodable_values( mixed $value ): void {
		$rejected = array();

		$result = EncodablePayload::for_wire( array( 'outer' => array( 'field' => $value ) ), $rejected );

		$this->assertSame( array( 'outer' => array() ), $result, 'the key is dropped, its container kept' );
		$this->assertSame( array( 'outer.field' ), $rejected, 'the path is recorded for the diagnostic' );
	}

	/**
	 * @testdox for_log() replaces a value the encoder cannot carry with a readable marker.
	 *
	 * @dataProvider provide_unencodable_values
	 *
	 * @param mixed  $value    Value under test.
	 * @param string $expected Expected marker.
	 */
	public function test_for_log_substitutes_a_marker( mixed $value, string $expected ): void {
		$rejected = array();

		$result = EncodablePayload::for_log( array( 'field' => $value ), $rejected );

		$this->assertSame( $expected, $result['field'], 'a human needs to see that something was there' );
		$this->assertSame( array( 'field' ), $rejected );
		$this->assertNotFalse( wp_json_encode( $result ), 'the marker itself must be encodable' );
	}

	/**
	 * Data provider for the rejection tests.
	 *
	 * @return array<string, array{0: mixed, 1: string}>
	 */
	public function provide_unencodable_values(): array {
		return array(
			'positive infinity' => array( INF, '[unencodable: INF]' ),
			'negative infinity' => array( -INF, '[unencodable: -INF]' ),
			'not a number'      => array( NAN, '[unencodable: NAN]' ),
			// The shape an enumerate-the-bad-types guard misses: not a float, not an object.
			'resource'          => array( STDERR, '[unencodable: resource]' ),
			// Pinned exactly, because the marker names the class and must never carry its
			// contents: this goes into a log, and the values reaching it came from the request.
			'object'            => array(
				new SingleUseSerializer(),
				'[unencodable: object Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin\SingleUseSerializer]',
			),
		);
	}

	/**
	 * @testdox An object is judged by whether it encodes, not by being an object.
	 */
	public function test_objects_are_kept_or_dropped_by_encodability(): void {
		$safe     = new \stdClass();
		$safe->ok = 1;

		$unsafe        = new \stdClass();
		$unsafe->ratio = INF;

		$rejected = array();
		$result   = EncodablePayload::for_wire(
			array(
				'safe'   => $safe,
				'unsafe' => $unsafe,
			),
			$rejected
		);

		$this->assertSame( $safe, $result['safe'], 'an object that encodes is left alone' );
		$this->assertArrayNotHasKey( 'unsafe', $result, 'an object that cannot encode is dropped' );
		$this->assertSame( array( 'unsafe' ), $rejected );
	}

	/**
	 * @testdox An object with an ordinary declared public property is kept.
	 *
	 * The other "kept" cases use stdClass, whose dynamic properties reflect to nothing, so the
	 * hooked-property check never sees a real declared property on the keep side. This one does:
	 * a plain public property with no hook must pass the check and be kept, not refused.
	 */
	public function test_object_with_a_plain_declared_property_is_kept(): void {
		$rejected = array();

		$result = EncodablePayload::for_wire( array( 'v' => new PlainDeclaredProperty() ), $rejected );

		$this->assertSame( array(), $rejected, 'a plain declared property must not trigger the hook refusal' );
		$this->assertArrayHasKey( 'v', $result );
		$this->assertNotFalse( wp_json_encode( $result ), 'the reduced payload must encode' );
	}

	/**
	 * @testdox Nested structures are walked, and the whole result encodes.
	 */
	public function test_nested_structures_are_walked(): void {
		$rejected = array();

		$payload = array(
			'order'  => array(
				'tax_total' => INF,
				'total'     => 10.0,
			),
			'events' => array(
				array( 'quantity' => NAN ),
				array( 'quantity' => '2' ),
			),
		);

		$result = EncodablePayload::for_wire( $payload, $rejected );

		$this->assertSame(
			array(
				'order'  => array( 'total' => 10.0 ),
				'events' => array( array(), array( 'quantity' => '2' ) ),
			),
			$result
		);
		$this->assertSame( array( 'order.tax_total', 'events.0.quantity' ), $rejected );
		$this->assertNotFalse( wp_json_encode( $result ), 'the filtered payload must encode' );
	}

	/**
	 * @testdox A payload of only encodable values is returned unchanged.
	 */
	public function test_clean_payload_is_untouched(): void {
		$rejected = array();

		$payload = array(
			'session' => array( 'id' => 'abc', 'count' => 3 ),
			'order'   => array( 'total' => 10.5, 'currency' => 'GBP', 'note' => null ),
		);

		$this->assertSame( $payload, EncodablePayload::for_wire( $payload, $rejected ) );
		$this->assertSame( array(), $rejected, 'nothing may be reported as rejected' );
	}

	/**
	 * @testdox An object whose serialization throws is rejected, not allowed to escape.
	 *
	 * json_encode() propagates whatever jsonSerialize() throws, and wp_json_encode()'s own
	 * try/catch covers only its repair pass. A test that can throw instead of answering would
	 * turn one bad value back into the loss of the whole payload — and on the logging path it
	 * would throw from inside the handlers that exist to keep a failure contained.
	 *
	 * @dataProvider provide_throwing_serializers
	 *
	 * @param object $value Object whose jsonSerialize() throws.
	 */
	public function test_object_that_throws_while_serializing_is_rejected( object $value ): void {
		$rejected = array();

		$result = EncodablePayload::for_wire( array( 'ok' => 1, 'bad' => $value ), $rejected );

		$this->assertSame( array( 'ok' => 1 ), $result, 'the throwing value must cost only its own key' );
		$this->assertSame( array( 'bad' ), $rejected );

		$logged = EncodablePayload::for_log( array( 'bad' => $value ) );
		$this->assertIsString( $logged['bad'], 'the log must still get a readable stand-in' );
		$this->assertNotFalse( wp_json_encode( $logged ), 'the filtered context must encode' );
	}

	/**
	 * @testdox A serializer that looks clean is still refused, and the document around it survives.
	 *
	 * DivergentSerializer returns something encodable but holds a non-finite property, so judging
	 * it by its serializer would keep it and lose the document the moment the repair pass rebuilt
	 * it from properties instead. It is refused for defining its own serialization at all, which
	 * makes the clean-looking serializer irrelevant. The invalid-UTF-8 sibling is kept and travels.
	 */
	public function test_object_defining_serialization_is_refused_regardless_of_what_it_returns(): void {
		$rejected = array();

		$result = EncodablePayload::for_wire(
			array(
				'attr' => new DivergentSerializer(),
				'note' => "\xB1\x31",
			),
			$rejected
		);

		$this->assertSame( array( 'attr' ), $rejected );
		$this->assertSame( "\xB1\x31", $result['note'], 'the repairable string is kept untouched here' );
		$this->assertSame(
			'{"note":"?1"}',
			wp_json_encode( $result ),
			'the reduced payload must encode, with the string repaired by the encoder rather than by this class'
		);
	}

	/**
	 * @testdox A refused object nested inside a plain one is still caught.
	 *
	 * The guard walk recurses because the encoder does, so checking only the outer object would
	 * approve a document that a nested self-encoding object still breaks.
	 */
	public function test_nested_self_encoding_object_is_refused(): void {
		$outer       = new \stdClass();
		$outer->attr = new DivergentSerializer();

		$rejected = array();
		$result   = EncodablePayload::for_wire(
			array(
				'outer' => $outer,
				'note'  => "\xB1\x31",
			),
			$rejected
		);

		$this->assertSame( array( 'outer' ), $rejected );
		$this->assertNotFalse( wp_json_encode( $result ), 'the reduced payload must encode' );
	}

	/**
	 * @testdox A traversable is refused rather than spent inspecting it.
	 *
	 * Reading one to check it can leave WordPress's repair pass walking a drained object, turning
	 * a document that encoded into one that does not. Whether a walk repeats is not knowable from
	 * the type — getIterator() may hand back a memoized generator — so both a bare generator and
	 * an IteratorAggregate are refused, and neither is touched on the way.
	 *
	 * @dataProvider provide_traversables
	 *
	 * @param object   $value    The traversable under test.
	 * @param callable $is_intact Asserts the value can still be walked afterwards.
	 */
	public function test_traversable_is_refused_without_being_consumed( object $value, callable $is_intact ): void {
		$rejected = array();

		$result = EncodablePayload::for_wire(
			array(
				'iter' => $value,
				'note' => "\xB1\x31",
			),
			$rejected
		);

		$this->assertSame( array( 'iter' ), $rejected );
		$this->assertSame(
			array( 'a' => 1, 'b' => 2 ),
			$is_intact( $value ),
			'the traversable must not have been consumed by the check'
		);
		$this->assertNotFalse( wp_json_encode( $result ), 'the reduced payload must encode' );
	}

	/**
	 * Data provider for {@see test_traversable_is_refused_without_being_consumed()}.
	 *
	 * @return array<string, array{0: object, 1: callable}>
	 */
	public function provide_traversables(): array {
		$walk = static function ( $value ) {
			return iterator_to_array( $value );
		};

		return array(
			'generator'          => array(
				( function () {
					yield 'a' => 1;
					yield 'b' => 2;
				} )(),
				$walk,
			),
			'iterator aggregate' => array( new IterableHolder( array( 'a' => 1, 'b' => 2 ) ), $walk ),
			// The case that makes the type alone useless as a signal: a perfectly ordinary
			// IteratorAggregate whose getIterator() memoizes, so the second walk throws.
			'memoizing aggregate' => array( new MemoizingIterable( array( 'a' => 1, 'b' => 2 ) ), $walk ),
		);
	}

	/**
	 * @testdox A traversable nested inside another object is refused without being consumed.
	 *
	 * The outer object is not itself traversable, so nothing about its type says to stop. What
	 * matters is that nothing encodes before the whole graph has been inspected: the outer
	 * object carries invalid UTF-8, so the first encode fails, and the repair pass that follows
	 * walks straight into the nested iterator. Inspecting first is what keeps that from
	 * happening — a refusal placed after any encode would already be too late.
	 *
	 * @dataProvider provide_nested_traversables
	 *
	 * @param callable $build Returns [ the payload, the nested traversable ].
	 */
	public function test_nested_traversable_is_refused_without_being_consumed( callable $build ): void {
		list( $payload, $inner ) = $build();

		$rejected = array();
		$result   = EncodablePayload::for_wire( $payload, $rejected );

		$this->assertSame( array( 'outer' ), $rejected, 'the object holding it must be rejected' );
		$this->assertSame(
			array( 'a' => 1 ),
			iterator_to_array( $inner ),
			'the nested traversable must not have been consumed by the check'
		);
		$this->assertNotFalse( wp_json_encode( $result ), 'the reduced payload must encode' );
	}

	/**
	 * Data provider for {@see test_nested_traversable_is_refused_without_being_consumed()}.
	 *
	 * @return array<string, array{0: callable}>
	 */
	public function provide_nested_traversables(): array {
		return array(
			'directly on a property' => array(
				static function () {
					$inner        = new OneShotIterable();
					$outer        = new \stdClass();
					$outer->note  = "\xB1\x31";
					$outer->inner = $inner;

					return array( array( 'outer' => $outer ), $inner );
				},
			),
			'inside a nested array'  => array(
				static function () {
					$inner       = new OneShotIterable();
					$outer       = new \stdClass();
					$outer->note = "\xB1\x31";
					$outer->bag  = array( 'deep' => $inner );

					return array( array( 'outer' => $outer ), $inner );
				},
			),
		);
	}

	/**
	 * @testdox An object that serializes itself is refused rather than asked twice.
	 *
	 * The check is an encode, so for these objects it runs their own code — and nothing says
	 * running it twice gives the same answer. A serializer that consumes something answers once
	 * and throws on the second call, which is the real encode, where nothing catches it. The
	 * payload here encodes perfectly well untouched; asking whether it does is what would break
	 * it.
	 */
	public function test_object_defining_its_own_serialization_is_refused(): void {
		$thing = new SingleUseSerializer();

		$rejected = array();
		$result   = EncodablePayload::for_wire( array( 'thing' => $thing ), $rejected );

		$this->assertSame( array( 'thing' ), $rejected );
		$this->assertSame( '[]', wp_json_encode( $result ), 'the reduced payload must still encode' );
		// The refusal must not have run its serializer — a check that spends the value is the
		// bug this refusal exists for. Asserted on the same instance that was reduced, so a
		// reduction that quietly encoded it would be caught here rather than hidden by a fresh
		// object.
		$this->assertSame(
			array( 'a' => 1 ),
			$thing->jsonSerialize(),
			'the refusal must not have spent the object it refused'
		);
	}

	/**
	 * @testdox An object with a hooked public property is refused rather than read twice.
	 *
	 * A property hook is a getter the encoder runs on the property access, and reading properties
	 * to inspect the object would run it too. Nothing says a hook answers the same way each time —
	 * this one reads finite until its third call and then non-finite, so the object encodes
	 * standalone, survives an inspection that reads it, and then fails the real encode. Refusing
	 * it for having a hook means its code never runs at all.
	 *
	 * The hooked class lives in its own fixture file because its syntax does not parse before
	 * PHP 8.4, where this file is still loaded; the require below runs only once the version
	 * requirement has passed. The counter the hook drives is a plain class defined normally.
	 *
	 * @requires PHP 8.4
	 */
	public function test_object_with_a_hooked_property_is_refused(): void {
		HookProbe::$reads = 0;

		$hooked = require dirname( __DIR__, 3 ) . '/Support/hooked-property-object.php';

		$rejected = array();
		$result   = EncodablePayload::for_wire( array( 'v' => $hooked ), $rejected );

		$this->assertSame( array( 'v' ), $rejected, 'the hooked object must be refused' );
		$this->assertSame( 0, HookProbe::$reads, 'the refusal must not have run the hook at all' );
		$this->assertNotFalse( wp_json_encode( $result ), 'the reduced payload must encode' );
	}

	/**
	 * @testdox An object cycle is rejected rather than followed forever.
	 *
	 * The guard walk reaches the graph before the encode, so it is the first thing to meet the
	 * cycle and cannot rely on the encoder's own recursion check. Without a depth budget there is
	 * no error to catch — the request never returns.
	 */
	public function test_object_cycle_is_rejected(): void {
		$a = new CycleNode();
		$b = new CycleNode();
		$a->next = $b;
		$b->next = $a;

		$rejected = array();
		$result   = EncodablePayload::for_wire( array( 'node' => $a ), $rejected );

		$this->assertSame( array( 'node' ), $rejected );
		$this->assertSame( array(), $result );
	}

	/**
	 * @testdox An object nested within the encoder's budget is still kept.
	 *
	 * The companion to the cycle case: the budget must reject runaway recursion without
	 * rejecting depth the encoder would have accepted. Nested close to the budget on purpose, so
	 * this pins roughly the value the constant claims to mirror rather than merely "more than a
	 * handful".
	 */
	public function test_deeply_nested_object_within_budget_is_kept(): void {
		$root = new CycleNode();
		$node = $root;
		for ( $i = 0; $i < 480; $i++ ) {
			$node->next = new CycleNode();
			$node       = $node->next;
		}

		$rejected = array();
		$result   = EncodablePayload::for_wire( array( 'node' => $root ), $rejected );

		$this->assertSame( array(), $rejected, 'nesting the encoder accepts must not be rejected' );
		$this->assertArrayHasKey( 'node', $result );
	}

	/**
	 * Data provider for {@see test_object_that_throws_while_serializing_is_rejected()}.
	 *
	 * Errors as well as exceptions: a TypeError raised inside a third-party jsonSerialize() is
	 * at least as likely as a deliberate throw, and only \Throwable covers both.
	 *
	 * @return array<string, array{0: object}>
	 */
	public function provide_throwing_serializers(): array {
		return array(
			'throws an exception' => array( new ThrowingSerializer( new \RuntimeException( 'nope' ) ) ),
			'throws an error'     => array( new ThrowingSerializer( new \Error( 'nope' ) ) ),
		);
	}
}

/**
 * A JsonSerializable whose serializer is clean but whose properties are not.
 *
 * The serializer returns something encodable while a public property holds a non-finite float, so
 * judging it by its serializer alone would keep it and then lose the document the moment the
 * repair pass rebuilt it from properties. It is refused for being a JsonSerializable at all, which
 * makes the clean-looking serializer beside the point.
 */
class DivergentSerializer implements \JsonSerializable {

	/**
	 * A value the JSON encoder cannot represent, visible only by iteration.
	 *
	 * @var float
	 */
	public $ratio = INF;

	/**
	 * Returns something perfectly encodable.
	 *
	 * @return mixed
	 */
	public function jsonSerialize(): mixed {
		return array( 'ok' => 1 );
	}
}

/**
 * An object with a single ordinary declared public property, no hook.
 *
 * Exercises the keep side of the hooked-property check against a real ReflectionProperty, which
 * stdClass with dynamic properties does not.
 */
class PlainDeclaredProperty {

	/**
	 * An encodable value.
	 *
	 * @var int
	 */
	public $amount = 5;
}

/**
 * A plain counter a property hook can drive, defined without hook syntax so it parses on every
 * supported PHP version.
 */
class HookProbe {

	/**
	 * How many times the hook has run.
	 *
	 * @var int
	 */
	public static $reads = 0;
}

/**
 * An object whose contents exist only as iteration, not as public properties.
 */
class IterableHolder implements \IteratorAggregate {

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $items What iterating this object yields.
	 */
	public function __construct( private readonly array $items ) {}

	/**
	 * A fresh walk every time, as the repair pass will get.
	 *
	 * @return \Traversable
	 */
	public function getIterator(): \Traversable {
		return new \ArrayIterator( $this->items );
	}
}

/**
 * An IteratorAggregate whose getIterator() hands back the same one-shot walk every time.
 *
 * Legal, and enough to show the interface promises nothing about repeatability. It also carries
 * invalid UTF-8 in a public property, so the first encode fails and the repair pass runs — the
 * path that actually reaches for the iterator.
 */
class MemoizingIterable implements \IteratorAggregate {

	/**
	 * A property that makes the first encode fail, sending the document through the repair pass.
	 *
	 * @var string
	 */
	public $note = "\xB1\x31";

	/**
	 * The single generator handed to every caller.
	 *
	 * @var \Generator|null
	 */
	private $iterator;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $items What the one walk yields.
	 */
	public function __construct( private readonly array $items ) {}

	/**
	 * The same generator every time, so only the first caller gets anything.
	 *
	 * A generator rather than an ArrayIterator on purpose: foreach rewinds the latter, so it
	 * would not be one-shot and would not demonstrate anything.
	 *
	 * @return \Traversable
	 */
	public function getIterator(): \Traversable {
		if ( null === $this->iterator ) {
			$items          = $this->items;
			$this->iterator = ( static function () use ( $items ) {
				yield from $items;
			} )();
		}

		return $this->iterator;
	}
}

/**
 * An IteratorAggregate that can only be walked once.
 *
 * A generator rather than an ArrayIterator, because foreach rewinds the latter and it would not
 * demonstrate anything.
 */
class OneShotIterable implements \IteratorAggregate {

	/**
	 * The single generator handed to every caller.
	 *
	 * @var \Generator|null
	 */
	private $iterator;

	/**
	 * The same generator every time.
	 *
	 * @return \Traversable
	 */
	public function getIterator(): \Traversable {
		if ( null === $this->iterator ) {
			$this->iterator = ( static function () {
				yield 'a' => 1;
			} )();
		}

		return $this->iterator;
	}
}

/**
 * A node that can be pointed at another, used to build depth and cycles.
 *
 * Plain on purpose: an object defining its own serialization is refused before depth is ever
 * considered, so it could not exercise the budget.
 */
class CycleNode {

	/**
	 * The next node, if any.
	 *
	 * @var self|null
	 */
	public $next;
}

/**
 * A JsonSerializable that can only be asked once.
 *
 * Answers correctly the first time and throws thereafter, which is what makes an encode-based
 * check unsafe for objects that serialize themselves.
 */
class SingleUseSerializer implements \JsonSerializable {

	/**
	 * The single walk backing the answer.
	 *
	 * @var \Generator
	 */
	private $generator;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->generator = ( static function () {
			yield 'a' => 1;
		} )();
	}

	/**
	 * Correct once, fatal afterwards.
	 *
	 * @return mixed
	 */
	public function jsonSerialize(): mixed {
		return iterator_to_array( $this->generator );
	}
}

/**
 * An object whose JSON serialization always throws.
 */
class ThrowingSerializer implements \JsonSerializable {

	/**
	 * Constructor.
	 *
	 * @param \Throwable $throwable What jsonSerialize() should throw.
	 */
	public function __construct( private readonly \Throwable $throwable ) {}

	/**
	 * Always throws.
	 *
	 * @return mixed
	 * @throws \Throwable Always.
	 */
	public function jsonSerialize(): mixed {
		throw $this->throwable;
	}
}
