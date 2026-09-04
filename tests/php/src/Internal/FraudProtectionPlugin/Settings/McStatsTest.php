<?php
/**
 * McStatsTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin\Settings;

use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Settings\McStats;

/**
 * Tests for the plugin-owned MC Stats sender.
 */
class McStatsTest extends FraudProtectionUnitTestCase {

	/**
	 * The System Under Test.
	 *
	 * @var McStats
	 */
	private $sut;

	public function setUp(): void {
		parent::setUp();
		$this->sut = new McStats();
	}

	/**
	 * @testdox Distinct stats are sent in one request per group and then cleared.
	 */
	public function test_batches_distinct_stats_by_group_and_clears_them(): void {
		update_option( 'woocommerce_allow_tracking', 'yes' );
		$urls = array();
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$urls ) {
				unset( $preempt, $args );
				$urls[] = $url;
				return array(
					'headers'  => array(),
					'body'     => '',
					'response' => array( 'code' => 200 ),
					'cookies'  => array(),
				);
			},
			10,
			3
		);

		$this->assertTrue( $this->sut->add( 'first-group', 'one' ) );
		$this->assertTrue( $this->sut->add( 'first-group', 'two' ) );
		$this->assertFalse( $this->sut->add( 'first-group', 'one' ) );
		$this->assertTrue( $this->sut->add( 'second-group', 'three' ) );
		$this->sut->do_server_side_stats();
		// Confirm that sent stats are removed from the queue.
		$this->sut->do_server_side_stats();

		$this->assertCount( 2, $urls );
		$this->assertSame( 'https://pixel.wp.com/b.gif', preg_replace( '/\?.*/', '', $urls[0] ) );
		$this->assertSame( 'one,two', $this->query_value( $urls[0], 'x_first-group' ) );
		$this->assertSame( 'three', $this->query_value( $urls[1], 'x_second-group' ) );
		$this->assertSame( 'wpcom2', $this->query_value( $urls[0], 'v' ) );
	}

	/**
	 * @testdox Stats wait for WooCommerce tracking consent.
	 */
	public function test_requires_tracking_consent(): void {
		$requests = 0;
		add_filter(
			'pre_http_request',
			function () use ( &$requests ) {
				++$requests;
				return array(
					'headers'  => array(),
					'body'     => '',
					'response' => array( 'code' => 200 ),
					'cookies'  => array(),
				);
			}
		);

		$this->sut->add( 'group', 'stat' );
		update_option( 'woocommerce_allow_tracking', 'no' );
		$this->sut->do_server_side_stats();
		$this->assertSame( 0, $requests );

		update_option( 'woocommerce_allow_tracking', 'yes' );
		$this->sut->do_server_side_stats();
		$this->assertSame( 1, $requests );
	}

	/**
	 * @testdox Non-string stat names and groups are rejected.
	 *
	 * @dataProvider invalid_stat_provider
	 *
	 * @param mixed $group Group name.
	 * @param mixed $stat  Statistic name.
	 */
	public function test_rejects_non_string_values( $group, $stat ): void {
		$this->assertFalse( $this->sut->add( $group, $stat ) );
	}

	/**
	 * Provide invalid statistic values.
	 *
	 * @return array<string, array{mixed, mixed}>
	 */
	public function invalid_stat_provider(): array {
		return array(
			'integer group' => array( 1, 'stat' ),
			'null group'    => array( null, 'stat' ),
			'array stat'    => array( 'group', array( 'stat' ) ),
			'boolean stat'  => array( 'group', false ),
		);
	}

	/**
	 * Get a query parameter from a request URL.
	 *
	 * @param string $url Request URL.
	 * @param string $key Query parameter name.
	 * @return string|null
	 */
	private function query_value( string $url, string $key ): ?string {
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

		return is_string( $query[ $key ] ?? null ) ? $query[ $key ] : null;
	}
}
