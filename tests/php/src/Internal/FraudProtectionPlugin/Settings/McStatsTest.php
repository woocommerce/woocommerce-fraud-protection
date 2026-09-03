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
	 * Original WooCommerce tracking option.
	 *
	 * @var mixed
	 */
	private $original_tracking_option;

	public function setUp(): void {
		parent::setUp();
		$this->original_tracking_option = get_option( 'woocommerce_allow_tracking', null );
	}

	public function tearDown(): void {
		remove_all_filters( 'pre_http_request' );
		if ( null === $this->original_tracking_option ) {
			delete_option( 'woocommerce_allow_tracking' );
		} else {
			update_option( 'woocommerce_allow_tracking', $this->original_tracking_option );
		}
		parent::tearDown();
	}

	/**
	 * @testdox Distinct stats are sent in one request per group and then cleared.
	 */
	public function test_batches_distinct_stats_by_group_and_clears_them(): void {
		update_option( 'woocommerce_allow_tracking', 'yes' );
		$urls = array();
		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) use ( &$urls ) {
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

		$sut = new McStats();
		$this->assertTrue( $sut->add( 'first-group', 'one' ) );
		$this->assertTrue( $sut->add( 'first-group', 'two' ) );
		$this->assertFalse( $sut->add( 'first-group', 'one' ) );
		$this->assertTrue( $sut->add( 'second-group', 'three' ) );
		$sut->do_server_side_stats();
		$sut->do_server_side_stats();

		$this->assertCount( 2, $urls );
		$this->assertSame( 'https://pixel.wp.com/b.gif', preg_replace( '/\?.*/', '', $urls[0] ) );
		$this->assertSame( 'one,two', $this->query_value( $urls[0], 'x_woocommerce-first-group' ) );
		$this->assertSame( 'three', $this->query_value( $urls[1], 'x_woocommerce-second-group' ) );
		$this->assertSame( 'wpcom2', $this->query_value( $urls[0], 'v' ) );
	}

	/**
	 * @testdox Stats wait for WooCommerce tracking consent.
	 */
	public function test_requires_tracking_consent(): void {
		$requests = 0;
		add_filter(
			'pre_http_request',
			static function () use ( &$requests ) {
				++$requests;
				return new \WP_Error( 'unexpected_request' );
			}
		);

		$sut = new McStats();
		$sut->add( 'group', 'stat' );
		update_option( 'woocommerce_allow_tracking', 'no' );
		$sut->do_server_side_stats();
		$this->assertSame( 0, $requests );

		update_option( 'woocommerce_allow_tracking', 'yes' );
		$sut->do_server_side_stats();
		$this->assertSame( 1, $requests );
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
