<?php
/**
 * TrustedProxyConfigTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin;

use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\TrustedProxyConfig;

/**
 * Tests for TrustedProxyConfig.
 *
 * @covers \Automattic\WooCommerce\Internal\FraudProtectionPlugin\TrustedProxyConfig
 */
class TrustedProxyConfigTest extends FraudProtectionUnitTestCase {

	/**
	 * @testdox An absent or empty configuration is not provided and has no problems.
	 * @dataProvider absent_config_provider
	 *
	 * @param mixed $config Configuration value.
	 */
	public function test_absent_config_is_not_provided( $config ): void {
		$sut = TrustedProxyConfig::from_mixed( $config );

		$this->assertFalse( $sut->provided );
		$this->assertSame( array(), $sut->entries );
		$this->assertFalse( $sut->is_active() );
		$this->assertFalse( $sut->has_problems() );
	}

	/**
	 * Absent configuration values.
	 *
	 * @return array<string, array{mixed}>
	 */
	public function absent_config_provider(): array {
		return array(
			'null'        => array( null ),
			'empty array' => array( array() ),
		);
	}

	/**
	 * @testdox A non-array configuration is provided, inactive, and reported as a problem.
	 * @dataProvider non_array_config_provider
	 *
	 * @param mixed $config Configuration value.
	 */
	public function test_non_array_config_is_inactive( $config ): void {
		$sut = TrustedProxyConfig::from_mixed( $config );

		$this->assertTrue( $sut->provided );
		$this->assertFalse( $sut->valid_shape );
		$this->assertSame( array(), $sut->entries );
		$this->assertFalse( $sut->is_active() );
		$this->assertTrue( $sut->has_problems() );
	}

	/**
	 * Non-array configuration values.
	 *
	 * @return array<string, array{mixed}>
	 */
	public function non_array_config_provider(): array {
		return array(
			'string'  => array( '10.0.0.1' ),
			'integer' => array( 1 ),
			'boolean' => array( true ),
			'object'  => array( new \stdClass() ),
		);
	}

	/**
	 * @testdox A single-entry configuration becomes one entry.
	 */
	public function test_single_entry_config_becomes_one_entry(): void {
		$sut = TrustedProxyConfig::from_mixed(
			array(
				'addresses' => array( '10.0.0.0/8' ),
				'header'    => 'X-Forwarded-For',
			)
		);

		$this->assertCount( 1, $sut->entries );
		$this->assertSame( array( '10.0.0.0/8' ), $sut->entries[0]->addresses );
		$this->assertTrue( $sut->is_active() );
		$this->assertFalse( $sut->has_problems() );
	}

	/**
	 * @testdox A list configuration keeps its entries in order.
	 */
	public function test_list_config_keeps_entries_in_order(): void {
		$sut = TrustedProxyConfig::from_mixed(
			array(
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

		$this->assertCount( 2, $sut->entries );
		$this->assertSame( 'X-Forwarded-For', $sut->entries[0]->header );
		$this->assertSame( 'CF-Connecting-IP', $sut->entries[1]->header );
		$this->assertTrue( $sut->is_active() );
		$this->assertFalse( $sut->has_problems() );
	}

	/**
	 * @testdox An invalid list entry is reported while usable entries keep the configuration active.
	 */
	public function test_invalid_list_entry_is_reported(): void {
		$sut = TrustedProxyConfig::from_mixed(
			array(
				'10.0.0.1',
				array( 'addresses' => array( '192.0.2.0/24' ) ),
				array(
					'addresses' => array( '10.0.0.0/8' ),
					'header'    => 'X-Real-IP',
				),
			)
		);

		$this->assertCount( 3, $sut->entries );
		$this->assertFalse( $sut->entries[0]->valid_shape );
		$this->assertFalse( $sut->entries[1]->is_usable() );
		$this->assertTrue( $sut->entries[2]->is_usable() );
		$this->assertTrue( $sut->is_active() );
		$this->assertTrue( $sut->has_problems() );
	}

	/**
	 * @testdox A list without usable entries is inactive.
	 */
	public function test_list_without_usable_entries_is_inactive(): void {
		$sut = TrustedProxyConfig::from_mixed( array( array( 'addresses' => array( '10.0.0.0/8' ) ), array() ) );

		$this->assertCount( 2, $sut->entries );
		$this->assertFalse( $sut->is_active() );
		$this->assertTrue( $sut->has_problems() );
	}

	/**
	 * @testdox find_entry_for_peer() returns the first usable entry that contains the peer.
	 * @dataProvider peer_entry_provider
	 *
	 * @param string  $peer_address    Direct peer address.
	 * @param ?string $expected_header Header of the expected entry, or null when no entry matches.
	 */
	public function test_find_entry_for_peer_returns_first_usable_match( string $peer_address, ?string $expected_header ): void {
		$sut = TrustedProxyConfig::from_mixed(
			array(
				array( 'addresses' => array( '192.0.2.0/24' ) ),
				array(
					'addresses' => array( '10.0.0.0/8' ),
					'header'    => 'X-Real-IP',
				),
				array(
					'addresses' => array( '10.1.0.0/16', '192.0.2.0/24' ),
					'header'    => 'CF-Connecting-IP',
				),
			)
		);

		$entry = $sut->find_entry_for_peer( $peer_address );

		$this->assertSame( $expected_header, null === $entry ? null : $entry->header );
	}

	/**
	 * Peers and the header of the entry that should handle them.
	 *
	 * @return array<string, array{string, ?string}>
	 */
	public function peer_entry_provider(): array {
		return array(
			'first usable match wins over later overlap' => array( '10.1.2.3', 'X-Real-IP' ),
			'unusable entry is skipped'                  => array( '192.0.2.5', 'CF-Connecting-IP' ),
			'no matching entry'                          => array( '203.0.113.7', null ),
		);
	}

	/**
	 * @testdox is_trusted() accepts addresses from any usable entry only.
	 */
	public function test_is_trusted_uses_every_usable_entry(): void {
		$sut = TrustedProxyConfig::from_mixed(
			array(
				array(
					'addresses' => array( '10.0.0.0/8' ),
					'header'    => 'X-Forwarded-For',
				),
				array(
					'addresses' => array( '173.245.48.0/20' ),
					'header'    => 'CF-Connecting-IP',
				),
				array( 'addresses' => array( '192.0.2.0/24' ) ),
			)
		);

		$this->assertTrue( $sut->is_trusted( '10.0.0.1' ) );
		$this->assertTrue( $sut->is_trusted( '173.245.48.1' ) );
		$this->assertFalse( $sut->is_trusted( '192.0.2.1' ), 'An unusable entry must not trust its addresses' );
		$this->assertFalse( $sut->is_trusted( '203.0.113.7' ) );
	}
}
