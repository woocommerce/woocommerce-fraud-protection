<?php
/**
 * TrustedProxyEntryTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin;

use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\TrustedProxyEntry;

/**
 * Tests for TrustedProxyEntry.
 *
 * @covers \Automattic\WooCommerce\Internal\FraudProtectionPlugin\TrustedProxyEntry
 */
class TrustedProxyEntryTest extends FraudProtectionUnitTestCase {

	/**
	 * @testdox A non-array entry is unusable and reported as a problem.
	 * @dataProvider non_array_entry_provider
	 *
	 * @param mixed $entry Entry value.
	 */
	public function test_non_array_entry_is_unusable( $entry ): void {
		$sut = TrustedProxyEntry::from_mixed( $entry );

		$this->assertFalse( $sut->valid_shape );
		$this->assertFalse( $sut->is_usable() );
		$this->assertTrue( $sut->has_problems() );
	}

	/**
	 * Non-array entry values.
	 *
	 * @return array<string, array{mixed}>
	 */
	public function non_array_entry_provider(): array {
		return array(
			'null'    => array( null ),
			'string'  => array( '10.0.0.1' ),
			'integer' => array( 1 ),
			'boolean' => array( true ),
			'object'  => array( new \stdClass() ),
		);
	}

	/**
	 * @testdox A complete entry is usable and keeps its valid addresses.
	 */
	public function test_complete_entry_is_usable(): void {
		$sut = TrustedProxyEntry::from_mixed(
			array(
				'addresses' => array( '10.0.0.0/8', '192.0.2.10', '2001:db8::/32' ),
				'header'    => 'X-Forwarded-For',
				'unknown'   => 'ignored',
			)
		);

		$this->assertTrue( $sut->valid_shape );
		$this->assertTrue( $sut->is_usable() );
		$this->assertFalse( $sut->has_problems() );
		$this->assertSame( array( '10.0.0.0/8', '192.0.2.10', '2001:db8::/32' ), $sut->addresses );
		$this->assertSame( 'X-Forwarded-For', $sut->header );
		$this->assertSame( 'HTTP_X_FORWARDED_FOR', $sut->get_header_server_key() );
	}

	/**
	 * @testdox A header name made of letters, digits, and inner dashes is kept as written and mapped to its server key.
	 * @dataProvider header_provider
	 *
	 * @param string $header     Configured header.
	 * @param string $server_key Expected `$_SERVER` key.
	 */
	public function test_header_name_is_kept_and_mapped( string $header, string $server_key ): void {
		$sut = TrustedProxyEntry::from_mixed(
			array(
				'addresses' => array( '10.0.0.1' ),
				'header'    => $header,
			)
		);

		$this->assertTrue( $sut->is_usable() );
		$this->assertSame( $header, $sut->header );
		$this->assertSame( $server_key, $sut->get_header_server_key() );
	}

	/**
	 * Supported header names and their `$_SERVER` keys.
	 *
	 * @return array<string, array{string, string}>
	 */
	public function header_provider(): array {
		return array(
			'X-Forwarded-For lower case' => array( 'x-forwarded-for', 'HTTP_X_FORWARDED_FOR' ),
			'X-Real-IP upper case'       => array( 'X-REAL-IP', 'HTTP_X_REAL_IP' ),
			'CF-Connecting-IP'           => array( 'CF-Connecting-IP', 'HTTP_CF_CONNECTING_IP' ),
			'Fastly-Client-IP'           => array( 'Fastly-Client-IP', 'HTTP_FASTLY_CLIENT_IP' ),
			'X-Sucuri-ClientIP'          => array( 'X-Sucuri-ClientIP', 'HTTP_X_SUCURI_CLIENTIP' ),
			'single word'                => array( 'ClientIP', 'HTTP_CLIENTIP' ),
			'digits'                     => array( 'X-Client-IP-2', 'HTTP_X_CLIENT_IP_2' ),
		);
	}

	/**
	 * @testdox An unsupported or missing header makes the entry unusable.
	 * @dataProvider invalid_header_provider
	 *
	 * @param mixed   $header                 Configured header.
	 * @param ?string $expected_invalid_value Expected invalid header description.
	 */
	public function test_invalid_header_makes_entry_unusable( $header, ?string $expected_invalid_value ): void {
		$entry = array( 'addresses' => array( '10.0.0.1' ) );
		if ( null !== $header ) {
			$entry['header'] = $header;
		}

		$sut = TrustedProxyEntry::from_mixed( $entry );

		$this->assertFalse( $sut->is_usable() );
		$this->assertTrue( $sut->has_problems() );
		$this->assertNull( $sut->header );
		$this->assertNull( $sut->get_header_server_key() );
		$this->assertSame( $expected_invalid_value, $sut->invalid_header );
	}

	/**
	 * Unsupported or missing header values.
	 *
	 * @return array<string, array{mixed, ?string}>
	 */
	public function invalid_header_provider(): array {
		return array(
			'missing'           => array( null, null ),
			'Forwarded header'  => array( 'Forwarded', 'Forwarded' ),
			'Forwarded lower'   => array( 'forwarded', 'forwarded' ),
			'Content-Type'      => array( 'Content-Type', 'Content-Type' ),
			'Content-Length'    => array( 'content-length', 'content-length' ),
			'underscore'        => array( 'X_Client_IP', 'X_Client_IP' ),
			'server key form'   => array( 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED_FOR' ),
			'leading dash'      => array( '-X-Client-IP', '-X-Client-IP' ),
			'trailing dash'     => array( 'X-Client-IP-', 'X-Client-IP-' ),
			'double dash'       => array( 'X--Client-IP', 'X--Client-IP' ),
			'dot'               => array( 'X.Client.IP', 'X.Client.IP' ),
			'space'             => array( 'X-Client IP', 'X-Client IP' ),
			'surrounding space' => array( ' X-Real-IP', ' X-Real-IP' ),
			'colon'             => array( 'X-Real-IP:', 'X-Real-IP:' ),
			'non-ASCII letter'  => array( 'X-Clíent-IP', 'X-Clíent-IP' ),
			'empty string'      => array( '', '' ),
			'array'             => array( array( 'X-Forwarded-For' ), 'array' ),
		);
	}

	/**
	 * @testdox Invalid address entries are ignored while valid entries are kept.
	 */
	public function test_invalid_addresses_are_ignored(): void {
		$sut = TrustedProxyEntry::from_mixed(
			array(
				'addresses' => array( '10.0.0.0/8', 'not-an-ip', '10.0.0.0/33', '2001:db8::/129', '10.0.0.0/08', '10.0.0.0/', '10.0.0.0/8/8', ' 10.0.0.1', 12, null, '192.0.2.1' ),
				'header'    => 'X-Real-IP',
			)
		);

		$this->assertTrue( $sut->is_usable() );
		$this->assertTrue( $sut->has_problems() );
		$this->assertSame( array( '10.0.0.0/8', '192.0.2.1' ), $sut->addresses );
		$this->assertSame( array( 'not-an-ip', '10.0.0.0/33', '2001:db8::/129', '10.0.0.0/08', '10.0.0.0/', '10.0.0.0/8/8', ' 10.0.0.1', 'int', 'null' ), $sut->invalid_addresses );
	}

	/**
	 * @testdox An entry without valid addresses is unusable.
	 * @dataProvider no_valid_addresses_provider
	 *
	 * @param mixed    $addresses        Configured addresses.
	 * @param string[] $expected_invalid Expected invalid address descriptions.
	 */
	public function test_entry_without_valid_addresses_is_unusable( $addresses, array $expected_invalid ): void {
		$sut = TrustedProxyEntry::from_mixed(
			array(
				'addresses' => $addresses,
				'header'    => 'X-Real-IP',
			)
		);

		$this->assertFalse( $sut->is_usable() );
		$this->assertTrue( $sut->has_problems() );
		$this->assertSame( array(), $sut->addresses );
		$this->assertSame( $expected_invalid, $sut->invalid_addresses );
	}

	/**
	 * Address values with no valid entry.
	 *
	 * @return array<string, array{mixed, string[]}>
	 */
	public function no_valid_addresses_provider(): array {
		return array(
			'missing key'  => array( null, array() ),
			'empty list'   => array( array(), array() ),
			'string value' => array( '10.0.0.1', array( '10.0.0.1' ) ),
			'invalid only' => array( array( 'nope' ), array( 'nope' ) ),
		);
	}

	/**
	 * @testdox contains() matches addresses against the entry's ranges.
	 * @dataProvider contained_address_provider
	 *
	 * @param string $ip_address IP address to check.
	 * @param bool   $expected   Whether the address is contained.
	 */
	public function test_contains_matches_ranges( string $ip_address, bool $expected ): void {
		$sut = TrustedProxyEntry::from_mixed(
			array(
				'addresses' => array( '10.0.0.0/8', '192.0.2.10', '198.51.100.64/27', '2001:db8:abcd::/48', '::1' ),
				'header'    => 'X-Forwarded-For',
			)
		);

		$this->assertSame( $expected, $sut->contains( $ip_address ) );
	}

	/**
	 * Addresses and whether they belong to the entry's ranges.
	 *
	 * @return array<string, array{string, bool}>
	 */
	public function contained_address_provider(): array {
		return array(
			'IPv4 inside /8'                 => array( '10.255.1.2', true ),
			'IPv4 outside /8'                => array( '11.0.0.1', false ),
			'IPv4 single address'            => array( '192.0.2.10', true ),
			'IPv4 next to single address'    => array( '192.0.2.11', false ),
			'IPv4 first in /27'              => array( '198.51.100.64', true ),
			'IPv4 last in /27'               => array( '198.51.100.95', true ),
			'IPv4 after /27'                 => array( '198.51.100.96', false ),
			'IPv4 before /27'                => array( '198.51.100.63', false ),
			'IPv6 inside /48'                => array( '2001:db8:abcd:12::1', true ),
			'IPv6 outside /48'               => array( '2001:db8:abce::1', false ),
			'IPv6 single address'            => array( '::1', true ),
			'IPv4-mapped IPv6 in IPv4 range' => array( '::ffff:10.1.2.3', true ),
			'IPv4-mapped IPv6 out of range'  => array( '::ffff:11.1.2.3', false ),
			'IPv4 does not match IPv6 range' => array( '32.1.13.184', false ),
		);
	}

	/**
	 * @testdox A zero-length prefix contains every address of its family.
	 */
	public function test_zero_prefix_contains_whole_family(): void {
		$sut = TrustedProxyEntry::from_mixed(
			array(
				'addresses' => array( '0.0.0.0/0' ),
				'header'    => 'X-Real-IP',
			)
		);

		$this->assertTrue( $sut->contains( '203.0.113.7' ) );
		$this->assertFalse( $sut->contains( '2001:db8::1' ) );
	}
}
