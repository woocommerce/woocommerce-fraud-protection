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
	 * Transient that limits the configuration problem log.
	 */
	private const CONFIG_PROBLEM_LOG_TRANSIENT = 'wc_fraud_protection_trusted_proxy_config_log';

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

		$this->unset_server_variables( array( 'REMOTE_ADDR', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CF_CONNECTING_IP', 'HTTP_TRUE_CLIENT_IP', 'HTTP_FASTLY_CLIENT_IP' ) );
		$this->sut = new VisitorIpResolver();
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		remove_all_filters( VisitorIpResolver::TRUSTED_PROXY_CONFIG_FILTER );
		delete_transient( self::CONFIG_PROBLEM_LOG_TRANSIENT );
		parent::tearDown();
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
			'public IPv4'      => array( '8.8.8.8' ),
			'private IPv4'     => array( '10.0.0.1' ),
			'loopback IPv4'    => array( '127.0.0.1' ),
			'reserved IPv4'    => array( '203.0.113.7' ),
			'IPv6'             => array( '2001:db8::1' ),
			'loopback IPv6'    => array( '::1' ),
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
	 * @testdox The trusted-proxy constant is read when it is defined.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_trusted_proxy_constant_is_read(): void {
		define(
			'WC_FRAUD_PROTECTION_TRUSTED_PROXY_CONFIG',
			array(
				'addresses' => array( '10.0.0.0/8' ),
				'header'    => 'X-Forwarded-For',
			)
		);
		$this->set_server_variables(
			array(
				'REMOTE_ADDR'          => '10.0.0.1',
				'HTTP_X_FORWARDED_FOR' => '203.0.113.7',
			)
		);

		$this->assertSame( '203.0.113.7', $this->sut->get_ip_address() );
	}

	/**
	 * @testdox The trusted-proxy filter receives the constant value, or an empty array when it is undefined.
	 */
	public function test_trusted_proxy_filter_receives_empty_array_without_constant(): void {
		$received = 'not called';
		add_filter(
			VisitorIpResolver::TRUSTED_PROXY_CONFIG_FILTER,
			function ( $config ) use ( &$received ) {
				$received = $config;
				return $config;
			}
		);

		$config = $this->sut->get_trusted_proxy_config();

		$this->assertSame( array(), $received );
		$this->assertFalse( $config->provided );
	}

	/**
	 * @testdox A forwarded header replaces REMOTE_ADDR only when the peer is a trusted proxy.
	 * @dataProvider forwarded_header_provider
	 *
	 * @param string $header       Configured header.
	 * @param string $server_key   `$_SERVER` key of the header.
	 * @param string $header_value Header value.
	 * @param string $peer_address Direct peer address.
	 * @param string $expected     Expected visitor IP.
	 */
	public function test_forwarded_header_replaces_trusted_peer_only( string $header, string $server_key, string $header_value, string $peer_address, string $expected ): void {
		$this->configure_trusted_proxies( array( '10.0.0.0/8', '2001:db8::/32' ), $header );
		$this->set_server_variables(
			array(
				'REMOTE_ADDR' => $peer_address,
				$server_key   => $header_value,
			)
		);

		$this->assertSame( $expected, $this->sut->get_ip_address() );
	}

	/**
	 * Header configurations, header values, peers, and the expected visitor IP.
	 *
	 * @return array<string, array{string, string, string, string, string}>
	 */
	public function forwarded_header_provider(): array {
		return array(
			'XFF from trusted peer'                      => array( 'X-Forwarded-For', 'HTTP_X_FORWARDED_FOR', '203.0.113.7', '10.0.0.1', '203.0.113.7' ),
			'XFF from untrusted peer'                    => array( 'X-Forwarded-For', 'HTTP_X_FORWARDED_FOR', '203.0.113.7', '198.51.100.1', '198.51.100.1' ),
			'XFF skips trusted hops from the right'      => array( 'X-Forwarded-For', 'HTTP_X_FORWARDED_FOR', '203.0.113.7, 10.0.0.3, 10.0.0.2', '10.0.0.1', '203.0.113.7' ),
			'XFF ignores spoofed entries on the left'    => array( 'X-Forwarded-For', 'HTTP_X_FORWARDED_FOR', 'spoofed, 192.0.2.1, 203.0.113.7', '10.0.0.1', '203.0.113.7' ),
			'XFF with every entry trusted uses leftmost' => array( 'X-Forwarded-For', 'HTTP_X_FORWARDED_FOR', '10.0.0.9,10.0.0.8', '10.0.0.1', '10.0.0.9' ),
			'XFF with tabs and spaces'                   => array( 'X-Forwarded-For', 'HTTP_X_FORWARDED_FOR', "203.0.113.7 ,\t10.0.0.2", '10.0.0.1', '203.0.113.7' ),
			'XFF with IPv6 client and proxy'             => array( 'X-Forwarded-For', 'HTTP_X_FORWARDED_FOR', '2001:db9::7', '2001:db8::1', '2001:db9::7' ),
			'XFF with invalid entry keeps peer'          => array( 'X-Forwarded-For', 'HTTP_X_FORWARDED_FOR', '203.0.113.7, unknown', '10.0.0.1', '10.0.0.1' ),
			'XFF with port keeps peer'                   => array( 'X-Forwarded-For', 'HTTP_X_FORWARDED_FOR', '203.0.113.7:443', '10.0.0.1', '10.0.0.1' ),
			'XFF empty keeps peer'                       => array( 'X-Forwarded-For', 'HTTP_X_FORWARDED_FOR', '', '10.0.0.1', '10.0.0.1' ),
			'XFF with empty entry keeps peer'            => array( 'X-Forwarded-For', 'HTTP_X_FORWARDED_FOR', '203.0.113.7,,10.0.0.2', '10.0.0.1', '10.0.0.1' ),
			'IPv4-mapped trusted peer'                   => array( 'X-Forwarded-For', 'HTTP_X_FORWARDED_FOR', '203.0.113.7', '::ffff:10.0.0.1', '203.0.113.7' ),
			'X-Real-IP from trusted peer'                => array( 'X-Real-IP', 'HTTP_X_REAL_IP', '203.0.113.7', '10.0.0.1', '203.0.113.7' ),
			'X-Real-IP from untrusted peer'              => array( 'X-Real-IP', 'HTTP_X_REAL_IP', '203.0.113.7', '198.51.100.1', '198.51.100.1' ),
			'X-Real-IP is read as a chain'               => array( 'X-Real-IP', 'HTTP_X_REAL_IP', '203.0.113.7, 198.51.100.9', '10.0.0.1', '198.51.100.9' ),
			'X-Real-IP chain skips trusted addresses'    => array( 'X-Real-IP', 'HTTP_X_REAL_IP', '203.0.113.7, 10.0.0.2', '10.0.0.1', '203.0.113.7' ),
			'X-Real-IP invalid keeps peer'               => array( 'X-Real-IP', 'HTTP_X_REAL_IP', 'not-an-ip', '10.0.0.1', '10.0.0.1' ),
			'CF-Connecting-IP from trusted peer'         => array( 'CF-Connecting-IP', 'HTTP_CF_CONNECTING_IP', ' 203.0.113.7 ', '10.0.0.1', '203.0.113.7' ),
			'True-Client-IP from trusted peer'           => array( 'True-Client-IP', 'HTTP_TRUE_CLIENT_IP', '2001:db9::7', '10.0.0.1', '2001:db9::7' ),
			'custom header from trusted peer'            => array( 'Fastly-Client-IP', 'HTTP_FASTLY_CLIENT_IP', '203.0.113.7', '10.0.0.1', '203.0.113.7' ),
			'lower-case custom header'                   => array( 'fastly-client-ip', 'HTTP_FASTLY_CLIENT_IP', '203.0.113.7', '10.0.0.1', '203.0.113.7' ),
			'custom header from untrusted peer'          => array( 'Fastly-Client-IP', 'HTTP_FASTLY_CLIENT_IP', '203.0.113.7', '198.51.100.1', '198.51.100.1' ),
		);
	}

	/**
	 * @testdox With several entries, the entry that contains the peer decides the header.
	 * @dataProvider multiple_entries_provider
	 *
	 * @param string $peer_address Direct peer address.
	 * @param string $expected     Expected visitor IP.
	 */
	public function test_entry_containing_peer_decides_header( string $peer_address, string $expected ): void {
		add_filter(
			VisitorIpResolver::TRUSTED_PROXY_CONFIG_FILTER,
			fn() => array(
				array(
					'addresses' => array( '10.0.0.0/8' ),
					'header'    => 'X-Forwarded-For',
				),
				array(
					'addresses' => array( '173.245.48.0/20' ),
					'header'    => 'CF-Connecting-IP',
				),
			)
		);
		$this->set_server_variables(
			array(
				'REMOTE_ADDR'           => $peer_address,
				'HTTP_X_FORWARDED_FOR'  => '203.0.113.7',
				'HTTP_CF_CONNECTING_IP' => '203.0.113.8',
			)
		);

		$this->assertSame( $expected, $this->sut->get_ip_address() );
	}

	/**
	 * Peers and the visitor IP selected through their entry.
	 *
	 * @return array<string, array{string, string}>
	 */
	public function multiple_entries_provider(): array {
		return array(
			'peer in the first entry uses X-Forwarded-For' => array( '10.0.0.1', '203.0.113.7' ),
			'peer in the second entry uses CF-Connecting-IP' => array( '173.245.48.1', '203.0.113.8' ),
			'peer in no entry keeps REMOTE_ADDR'           => array( '198.51.100.1', '198.51.100.1' ),
		);
	}

	/**
	 * @testdox An X-Forwarded-For chain skips addresses trusted by any entry.
	 */
	public function test_forwarded_chain_skips_addresses_trusted_by_any_entry(): void {
		add_filter(
			VisitorIpResolver::TRUSTED_PROXY_CONFIG_FILTER,
			fn() => array(
				array(
					'addresses' => array( '10.0.0.0/8' ),
					'header'    => 'X-Forwarded-For',
				),
				array(
					'addresses' => array( '173.245.48.0/20' ),
					'header'    => 'CF-Connecting-IP',
				),
			)
		);
		$this->set_server_variables(
			array(
				'REMOTE_ADDR'          => '10.0.0.1',
				'HTTP_X_FORWARDED_FOR' => '203.0.113.7, 173.245.48.1',
			)
		);

		$this->assertSame( '203.0.113.7', $this->sut->get_ip_address() );
	}

	/**
	 * @testdox An unusable entry neither selects a header nor trusts its addresses.
	 */
	public function test_unusable_entry_is_ignored(): void {
		add_filter(
			VisitorIpResolver::TRUSTED_PROXY_CONFIG_FILTER,
			fn() => array(
				array( 'addresses' => array( '10.0.0.0/8' ) ),
				array(
					'addresses' => array( '192.0.2.0/24' ),
					'header'    => 'X-Forwarded-For',
				),
			)
		);
		$this->set_server_variables(
			array(
				'REMOTE_ADDR'          => '192.0.2.1',
				'HTTP_X_FORWARDED_FOR' => '203.0.113.7, 10.0.0.2',
			)
		);

		$this->assertSame( '10.0.0.2', $this->sut->get_ip_address() );

		$this->set_server_variables( array( 'REMOTE_ADDR' => '10.0.0.1' ) );

		$this->assertSame( '10.0.0.1', $this->sut->get_ip_address() );
	}

	/**
	 * @testdox Only the configured header can replace a trusted peer.
	 */
	public function test_only_configured_header_replaces_trusted_peer(): void {
		$this->configure_trusted_proxies( array( '10.0.0.0/8' ), 'CF-Connecting-IP' );
		$this->set_server_variables(
			array(
				'REMOTE_ADDR'          => '10.0.0.1',
				'HTTP_X_FORWARDED_FOR' => '203.0.113.7',
				'HTTP_X_REAL_IP'       => '203.0.113.8',
				'HTTP_TRUE_CLIENT_IP'  => '203.0.113.9',
			)
		);

		$this->assertSame( '10.0.0.1', $this->sut->get_ip_address() );
	}

	/**
	 * @testdox A trusted-proxy header cannot supply an IP when REMOTE_ADDR is invalid.
	 */
	public function test_trusted_proxy_header_cannot_supply_ip_without_valid_remote_addr(): void {
		$this->configure_trusted_proxies( array( '0.0.0.0/0' ), 'X-Forwarded-For' );
		$this->set_server_variables(
			array(
				'REMOTE_ADDR'          => 'not-an-ip',
				'HTTP_X_FORWARDED_FOR' => '203.0.113.7',
			)
		);

		$this->assertNull( $this->sut->get_ip_address() );
	}

	/**
	 * @testdox An incomplete configuration keeps REMOTE_ADDR and logs one local warning per day across requests.
	 */
	public function test_incomplete_config_keeps_peer_and_logs_once_per_day(): void {
		$logging_spy = $this->spy_on_controller_logging();
		add_filter(
			VisitorIpResolver::TRUSTED_PROXY_CONFIG_FILTER,
			fn() => array( 'addresses' => array( '10.0.0.0/8' ) )
		);
		$this->set_server_variables(
			array(
				'REMOTE_ADDR'          => '10.0.0.1',
				'HTTP_X_FORWARDED_FOR' => '203.0.113.7',
			)
		);

		$this->assertSame( '10.0.0.1', $this->sut->get_ip_address() );
		$this->assertSame( '10.0.0.1', ( new VisitorIpResolver() )->get_ip_address(), 'A later request must also keep the peer address' );

		$this->assertLogged( 'warning', 'Trusted proxy configuration is incomplete or invalid', array( 'entries' => array( array( 'addresses' => array( '10.0.0.0/8' ) ) ) ), false );
		$this->assertCount( 1, $logging_spy->entries, 'The same configuration problem must be logged once per day' );
		$this->assertLessThanOrEqual( DAY_IN_SECONDS, (int) get_option( '_transient_timeout_' . self::CONFIG_PROBLEM_LOG_TRANSIENT ) - time(), 'The log limit must expire within a day' );
	}

	/**
	 * @testdox The configuration problem is logged again after the daily limit expires.
	 */
	public function test_config_problem_is_logged_again_after_limit_expires(): void {
		$logging_spy = $this->spy_on_controller_logging();
		add_filter(
			VisitorIpResolver::TRUSTED_PROXY_CONFIG_FILTER,
			fn() => array( 'addresses' => array( '10.0.0.0/8' ) )
		);

		$this->sut->get_trusted_proxy_config();
		delete_transient( self::CONFIG_PROBLEM_LOG_TRANSIENT );
		( new VisitorIpResolver() )->get_trusted_proxy_config();

		$this->assertCount( 2, $logging_spy->entries, 'An expired limit must allow the next log' );
	}

	/**
	 * @testdox A different configuration problem is logged without waiting for the daily limit.
	 */
	public function test_different_config_problem_is_logged_immediately(): void {
		$logging_spy = $this->spy_on_controller_logging();
		$config      = array( 'addresses' => array( '10.0.0.0/8' ) );
		add_filter(
			VisitorIpResolver::TRUSTED_PROXY_CONFIG_FILTER,
			function () use ( &$config ) {
				return $config;
			}
		);

		$this->sut->get_trusted_proxy_config();
		$config = array(
			'addresses' => array( '10.0.0.0/8', 'nope' ),
			'header'    => 'X-Real-IP',
		);
		( new VisitorIpResolver() )->get_trusted_proxy_config();

		$this->assertCount( 2, $logging_spy->entries, 'A changed problem must be logged immediately' );
		$this->assertLogged( 'warning', 'invalid values that were ignored', array( 'entries' => array( array( 'invalid_addresses' => array( 'nope' ) ) ) ), false );
	}

	/**
	 * @testdox Invalid address entries are ignored and logged while valid entries still apply.
	 */
	public function test_invalid_addresses_are_logged_and_valid_entries_apply(): void {
		$this->configure_trusted_proxies( array( 'nope', '10.0.0.0/8' ), 'X-Forwarded-For' );
		$this->set_server_variables(
			array(
				'REMOTE_ADDR'          => '10.0.0.1',
				'HTTP_X_FORWARDED_FOR' => '203.0.113.7',
			)
		);

		$this->assertSame( '203.0.113.7', $this->sut->get_ip_address() );
		$this->assertLogged( 'warning', 'invalid values that were ignored', array( 'entries' => array( array( 'invalid_addresses' => array( 'nope' ) ) ) ), false );
	}

	/**
	 * @testdox A throwing filter keeps the unfiltered configuration and logs a forwarded warning.
	 */
	public function test_throwing_filter_keeps_unfiltered_config(): void {
		add_filter(
			VisitorIpResolver::TRUSTED_PROXY_CONFIG_FILTER,
			function () {
				throw new \RuntimeException( 'boom' );
			}
		);
		$this->set_server_variables(
			array(
				'REMOTE_ADDR'          => '10.0.0.1',
				'HTTP_X_FORWARDED_FOR' => '203.0.113.7',
			)
		);

		$this->assertSame( '10.0.0.1', $this->sut->get_ip_address() );
		$this->assertLogged(
			'warning',
			'Trusted proxy configuration filter threw',
			array(
				'filter'          => VisitorIpResolver::TRUSTED_PROXY_CONFIG_FILTER,
				'exception_class' => \RuntimeException::class,
			),
			true
		);
	}

	/**
	 * @testdox A non-array filter result keeps the unfiltered configuration.
	 */
	public function test_non_array_filter_result_keeps_unfiltered_config(): void {
		add_filter( VisitorIpResolver::TRUSTED_PROXY_CONFIG_FILTER, fn() => 'X-Forwarded-For' );

		$config = $this->sut->get_trusted_proxy_config();

		$this->assertFalse( $config->provided );
		$this->assertLogged( 'warning', 'returned a non-array value', array( 'argument_type' => 'string' ), false );
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

	/**
	 * Configure trusted proxies through the filter.
	 *
	 * @param string[] $addresses Trusted addresses.
	 * @param string   $header    Client IP header.
	 */
	private function configure_trusted_proxies( array $addresses, string $header ): void {
		add_filter(
			VisitorIpResolver::TRUSTED_PROXY_CONFIG_FILTER,
			fn() => array(
				'addresses' => $addresses,
				'header'    => $header,
			)
		);
	}
}
