<?php
/**
 * SessionIdNormalizerTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\FraudProtection;

use Automattic\WooCommerce\FraudProtection\SessionIdNormalizer;
use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;

/**
 * Tests for session ID normalization.
 *
 * @covers \Automattic\WooCommerce\FraudProtection\SessionIdNormalizer
 */
class SessionIdNormalizerTest extends FraudProtectionUnitTestCase {

	/**
	 * The System Under Test.
	 *
	 * @var SessionIdNormalizer
	 */
	private SessionIdNormalizer $sut;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->sut = new SessionIdNormalizer();
	}

	/**
	 * @testdox normalize() casts scalar values and bounds supported strings to 255 bytes
	 *
	 * @dataProvider scalar_value_provider
	 *
	 * @param mixed  $value    Scalar input value.
	 * @param string $expected Expected normalized value.
	 */
	public function test_normalize_casts_and_bounds_scalar_values( $value, string $expected ): void {
		$this->assertSame( $expected, $this->sut->normalize( $value ) );
	}

	/**
	 * Scalar values and their normalized forms.
	 *
	 * @return array<string, array{mixed, string}>
	 */
	public function scalar_value_provider(): array {
		return array(
			'empty string'       => array( '', '' ),
			'string zero'        => array( '0', '0' ),
			'valid 22-byte ID'   => array( '82vHd2iPY4JvJZQE-A6jHg', '82vHd2iPY4JvJZQE-A6jHg' ),
			'letters'            => array( 'sessionid', 'sessionid' ),
			'hyphen'             => array( 'session-id', 'session-id' ),
			'underscore'         => array( 'session_id', 'session_id' ),
			'integer zero'       => array( 0, '0' ),
			'integer'            => array( 42, '42' ),
			'float zero'         => array( 0.0, '0' ),
		);
	}

	/**
	 * @testdox normalize() replaces scalars containing unsupported characters with a fixed invalid marker
	 *
	 * @dataProvider unsupported_scalar_provider
	 *
	 * @param mixed $value Submitted scalar value.
	 */
	public function test_normalize_replaces_unsupported_characters( mixed $value ): void {
		$this->assertSame( 'wcfp-invalid-characters', $this->sut->normalize( $value ) );
	}

	/**
	 * Scalar values outside the Base64URL alphabet after string conversion.
	 *
	 * @return array<string, array{mixed}>
	 */
	public function unsupported_scalar_provider(): array {
		$values = array(
			'positive float'     => array( 1.5 ),
			'negative float'     => array( -1.5 ),
			'single dot'        => array( '.' ),
			'double dot'        => array( '..' ),
			'three dots'         => array( '...' ),
			'leading dot'        => array( '.session' ),
			'trailing dot'       => array( 'session.' ),
			'dot inside string'  => array( 'session.id' ),
			'double dot inside'  => array( 'session..id' ),
			'dot before slash'   => array( './x' ),
			'dots before slash'  => array( '../x' ),
			'dot after slash'    => array( 'x/.' ),
			'dots after slash'   => array( 'x/..' ),
			'dotted components'  => array( './../x' ),
			'slash'              => array( 'a/b' ),
			'backslash'          => array( 'a\b' ),
			'query delimiter'    => array( 'a?b' ),
			'fragment delimiter' => array( 'a#b' ),
			'percent'            => array( 'a%b' ),
			'non-ASCII'          => array( 'café' ),
		);

		foreach ( array_merge( range( 0, 31 ), array( 127 ) ) as $byte ) {
			$values[ 'control byte ' . $byte ] = array( 'a' . chr( $byte ) . 'b' );
		}

		return $values;
	}

	/**
	 * @testdox normalize() uses a fixed invalid-number marker for non-finite floats
	 *
	 * @dataProvider non_finite_number_provider
	 *
	 * @param float $value Non-finite float.
	 */
	public function test_normalize_uses_invalid_number_marker( float $value ): void {
		$this->assertSame( 'wcfp-invalid-number', $this->sut->normalize( $value ) );
	}

	/**
	 * Non-finite float values.
	 *
	 * @return array<string, array{float}>
	 */
	public function non_finite_number_provider(): array {
		return array(
			'not a number'      => array( NAN ),
			'positive infinity' => array( INF ),
			'negative infinity' => array( -INF ),
		);
	}

	/**
	 * @testdox normalize() keeps 255 bytes and cuts a 256-byte string
	 */
	public function test_normalize_applies_exact_ascii_boundary(): void {
		$at_limit   = str_repeat( 'a', 255 );
		$over_limit = $at_limit . 'b';

		$this->assertSame( $at_limit, $this->sut->normalize( $at_limit ) );
		$this->assertSame( $at_limit, $this->sut->normalize( $over_limit ) );
		$this->assertSame( 'wcfp-invalid-characters', $this->sut->normalize( $at_limit . '.' ) );
	}

	/**
	 * @testdox normalize() uses fixed markers for booleans and non-scalar values
	 */
	public function test_normalize_uses_invalid_type_markers(): void {
		$resource = fopen( 'php://memory', 'r' );
		$this->assertIsResource( $resource );

		$this->assertSame( 'wcfp-invalid-boolean', $this->sut->normalize( true ) );
		$this->assertSame( 'wcfp-invalid-boolean', $this->sut->normalize( false ) );
		$this->assertSame( 'wcfp-invalid-null', $this->sut->normalize( null ) );
		$this->assertSame( 'wcfp-invalid-array', $this->sut->normalize( array( 'value' ) ) );
		$this->assertSame( 'wcfp-invalid-object', $this->sut->normalize( new \stdClass() ) );
		$this->assertSame( 'wcfp-invalid-resource', $this->sut->normalize( $resource ) );

		fclose( $resource );
		$this->assertSame( 'wcfp-invalid-resource', $this->sut->normalize( $resource ) );
	}

	/**
	 * @testdox normalize() applies the limit by bytes to supported characters
	 */
	public function test_normalize_applies_a_byte_limit(): void {
		$value    = str_repeat( 'ab_', 86 );
		$expected = substr( $value, 0, 255 );

		$this->assertSame( 255, strlen( $this->sut->normalize( $value ) ) );
		$this->assertSame( $expected, $this->sut->normalize( $value ) );
	}

	/**
	 * @testdox normalize_stored() bounds valid values and discards invalid mappings
	 */
	public function test_normalize_stored_discards_invalid_mappings(): void {
		$this->assertSame( 'stored-session', $this->sut->normalize_stored( 'stored-session' ) );
		$this->assertSame( str_repeat( 'a', 255 ), $this->sut->normalize_stored( str_repeat( 'a', 256 ) ) );
		$this->assertSame( '', $this->sut->normalize_stored( '..' ) );
		$this->assertSame( '', $this->sut->normalize_stored( 'opaque.response/id' ) );
		$this->assertSame( '', $this->sut->normalize_stored( 'wcfp-invalid-characters' ) );
	}

	/**
	 * @testdox is_invalid_marker() recognizes only the seven exact reserved markers
	 */
	public function test_is_invalid_marker_recognizes_only_exact_markers(): void {
		foreach (
			array(
				'wcfp-invalid-boolean',
				'wcfp-invalid-null',
				'wcfp-invalid-array',
				'wcfp-invalid-object',
				'wcfp-invalid-resource',
				'wcfp-invalid-characters',
				'wcfp-invalid-number',
			) as $marker
		) {
			$this->assertTrue( $this->sut->is_invalid_marker( $marker ) );
			$this->assertSame( $marker, $this->sut->normalize( $marker ) );
		}

		$this->assertFalse( $this->sut->is_invalid_marker( 'WCFP-invalid-null' ) );
		$this->assertFalse( $this->sut->is_invalid_marker( 'wcfp-invalid-null-extra' ) );
		$this->assertFalse( $this->sut->is_invalid_marker( '' ) );
	}
}
