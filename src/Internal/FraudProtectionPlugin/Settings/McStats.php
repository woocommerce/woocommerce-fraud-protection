<?php
/**
 * McStats class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Sends plugin-owned server-side MC Stats.
 */
class McStats {

	private const PIXEL_URL = 'https://pixel.wp.com/b.gif';

	/**
	 * Stats waiting to be sent.
	 *
	 * @var array<string, string[]>
	 */
	private array $stats = array();

	/**
	 * Add a distinct statistic to a group.
	 *
	 * @param mixed $group Group name.
	 * @param mixed $stat  Statistic name.
	 * @return bool Whether the statistic was added.
	 */
	public function add( $group, $stat ): bool {
		if ( ! is_string( $group ) || ! is_string( $stat ) ) {
			return false;
		}

		if ( ! isset( $this->stats[ $group ] ) ) {
			$this->stats[ $group ] = array();
		}

		if ( in_array( $stat, $this->stats[ $group ], true ) ) {
			return false;
		}

		$this->stats[ $group ][] = $stat;

		return true;
	}

	/**
	 * Send all accumulated server-side statistics.
	 */
	public function do_server_side_stats(): void {
		if ( ! \WC_Site_Tracking::is_tracking_enabled() ) {
			return;
		}

		$stats       = $this->stats;
		$this->stats = array();

		foreach ( $stats as $group => $group_stats ) {
			$url = add_query_arg(
				array(
					'v'           => 'wpcom2',
					'rand'        => md5( (string) wp_rand( 0, 999 ) . time() ),
					'x_' . $group => implode( ',', $group_stats ),
				),
				self::PIXEL_URL
			);

			wp_remote_get(
				esc_url_raw( $url ),
				array(
					'blocking' => false,
					'timeout'  => 1,
				)
			);
		}
	}
}
