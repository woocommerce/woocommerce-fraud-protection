<?php
/**
 * VisitorIpResolverTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin;

use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\VisitorIpResolver;

/**
 * Tests for VisitorIpResolver.
 *
 * @covers \Automattic\WooCommerce\Internal\FraudProtectionPlugin\VisitorIpResolver
 */
class VisitorIpResolverTest extends FraudProtectionUnitTestCase {

	/**
	 * The System Under Test.
	 *
	 * @var VisitorIpResolver
	 */
	private VisitorIpResolver $sut;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->unset_server_variables( array( 'REMOTE_ADDR' ) );
		$this->sut = new VisitorIpResolver();
	}

	/**
	 * @testdox get_ip_address() accepts one complete IP literal.
	 * @dataProvider valid_ip_provider
	 *
	 * @param string $ip_address Valid IP address.
	 */
	public function test_get_ip_address_accepts_one_complete_ip_literal( string $ip_address ): void {
		$this->set_server_variables( array( 'REMOTE_ADDR' => $ip_address ) );

		$this->assertSame( $ip_address, $this->sut->get_ip_address() );
	}

	/**
	 * Valid visitor IP addresses.
	 *
	 * @return array<string, array{string}>
	 */
	public function valid_ip_provider(): array {
		return array(
			'public IPv4'       => array( '8.8.8.8' ),
			'private IPv4'      => array( '10.0.0.1' ),
			'loopback IPv4'     => array( '127.0.0.1' ),
			'reserved IPv4'     => array( '203.0.113.7' ),
			'IPv6'              => array( '2001:db8::1' ),
			'loopback IPv6'     => array( '::1' ),
			'IPv4-mapped IPv6' => array( '::ffff:192.0.2.128' ),
		);
	}

	/**
	 * @testdox get_ip_address() rejects non-string values.
	 * @dataProvider invalid_type_provider
	 *
	 * @param mixed $value Invalid server value.
	 */
	public function test_get_ip_address_rejects_non_string_values( $value ): void {
		$this->set_server_variables( array( 'REMOTE_ADDR' => $value ) );

		$this->assertNull( $this->sut->get_ip_address() );
	}

	/**
	 * Non-string server values.
	 *
	 * @return array<string, array{mixed}>
	 */
	public function invalid_type_provider(): array {
		return array(
			'null'    => array( null ),
			'array'   => array( array( '203.0.113.7' ) ),
			'integer' => array( 203001137 ),
			'boolean' => array( true ),
			'object'  => array( new \stdClass() ),
		);
	}

	/**
	 * @testdox get_ip_address() returns null when REMOTE_ADDR is absent.
	 */
	public function test_get_ip_address_returns_null_when_remote_addr_is_absent(): void {
		$this->assertArrayNotHasKey( 'REMOTE_ADDR', $_SERVER );
		$this->assertNull( $this->sut->get_ip_address() );
	}

	/**
	 * @testdox get_ip_address() rejects values that are not one complete IP literal.
	 * @dataProvider invalid_ip_provider
	 *
	 * @param string $value Invalid IP value.
	 */
	public function test_get_ip_address_rejects_values_that_are_not_one_complete_ip_literal( string $value ): void {
		$this->set_server_variables( array( 'REMOTE_ADDR' => $value ) );

		$this->assertNull( $this->sut->get_ip_address() );
	}

	/**
	 * Invalid visitor IP strings.
	 *
	 * @return array<string, array{string}>
	 */
	public function invalid_ip_provider(): array {
		return array(
			'empty'                  => array( '' ),
			'leading space'          => array( ' 203.0.113.7' ),
			'trailing space'         => array( '203.0.113.7 ' ),
			'leading tab'            => array( "\t203.0.113.7" ),
			'trailing line break'    => array( "203.0.113.7\n" ),
			'leading vertical tab'   => array( "\v203.0.113.7" ),
			'leading NUL byte'       => array( "\0" . '203.0.113.7' ),
			'embedded whitespace'    => array( '203.0. 113.7' ),
			'comma-separated chain'  => array( '203.0.113.7, 198.51.100.4' ),
			'IPv4 with port'         => array( '203.0.113.7:443' ),
			'bracketed IPv6'         => array( '[2001:db8::1]' ),
			'IPv6 with port'         => array( '[2001:db8::1]:443' ),
			'IPv6 zone identifier'   => array( 'fe80::1%eth0' ),
			'leading-zero IPv4'      => array( '01.2.3.4' ),
			'backslash'              => array( '192.0.2.\1' ),
			'multiple line literals' => array( "203.0.113.7\n198.51.100.4" ),
			'invalid text'           => array( 'not-an-ip' ),
		);
	}

	/**
	 * @testdox Forwarding headers cannot replace a valid REMOTE_ADDR.
	 */
	public function test_forwarding_headers_cannot_replace_valid_remote_addr(): void {
		$this->set_server_variables(
			array(
				'REMOTE_ADDR'           => '203.0.113.7',
				'HTTP_X_REAL_IP'        => '198.51.100.1',
				'HTTP_X_FORWARDED_FOR'  => '198.51.100.2',
				'HTTP_CLIENT_IP'        => '198.51.100.3',
				'HTTP_FORWARDED'        => 'for=198.51.100.4',
				'HTTP_CF_CONNECTING_IP' => '198.51.100.5',
			)
		);

		$this->assertSame( '203.0.113.7', $this->sut->get_ip_address() );
	}

	/**
	 * @testdox Forwarding headers cannot supply an IP when REMOTE_ADDR is absent.
	 */
	public function test_forwarding_headers_cannot_supply_ip_without_remote_addr(): void {
		$this->set_server_variables(
			array(
				'HTTP_X_REAL_IP'        => '198.51.100.1',
				'HTTP_X_FORWARDED_FOR'  => '198.51.100.2',
				'HTTP_CLIENT_IP'        => '198.51.100.3',
				'HTTP_FORWARDED'        => 'for=198.51.100.4',
				'HTTP_CF_CONNECTING_IP' => '198.51.100.5',
			)
		);

		$this->assertNull( $this->sut->get_ip_address() );
	}

	/**
	 * @testdox get_ip_country() ignores country headers without a selected visitor IP.
	 */
	public function test_get_ip_country_ignores_country_headers_without_selected_ip(): void {
		$this->set_server_variables(
			array(
				'MM_COUNTRY_CODE'     => 'US',
				'GEOIP_COUNTRY_CODE'  => 'CA',
				'HTTP_CF_IPCOUNTRY'   => 'GB',
				'HTTP_X_COUNTRY_CODE' => 'DE',
			)
		);

		$this->assertSame( '', $this->sut->get_ip_country( null ) );
		$this->assertSame( '', $this->sut->get_ip_country( '' ) );
	}

	/**
	 * @testdox get_ip_country() geolocates the selected visitor IP without fallback.
	 */
	public function test_get_ip_country_geolocates_selected_ip_without_fallback(): void {
		$ip_address = '203.0.113.7';
		$filter     = function ( $country_code, $received_ip, $fallback, $api_fallback ) use ( $ip_address ) {
			$this->assertFalse( $country_code );
			$this->assertSame( $ip_address, $received_ip );
			$this->assertFalse( $fallback );
			$this->assertFalse( $api_fallback );

			return 'US';
		};

		add_filter( 'woocommerce_geolocate_ip', $filter, 10, 4 );

		try {
			$this->assertSame( 'US', $this->sut->get_ip_country( $ip_address ) );
		} finally {
			remove_filter( 'woocommerce_geolocate_ip', $filter, 10 );
		}
	}
}
