<?php
/**
 * GatewayIdPattern class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin;

defined( 'ABSPATH' ) || exit;

/**
 * Matches gateway IDs against the patterns that gateways register their callbacks under.
 *
 * A pattern is an exact gateway ID, or a prefix followed by `*` that matches every gateway ID
 * starting with that prefix, so a plugin with one gateway per payment method can register once
 * for all of them. A `*` anywhere else is an ordinary character, and `*` alone matches nothing,
 * so that no registration reaches the attempts of every gateway.
 */
final class GatewayIdPattern {

	/**
	 * This class only exposes static helpers.
	 */
	private function __construct() {}

	/**
	 * Find the most specific pattern that matches a gateway ID.
	 *
	 * An exact gateway ID wins over any prefix, and a longer prefix wins over a shorter one.
	 *
	 * @param array<int|string> $patterns   The registered patterns. Array keys can hold them, so integers are read as strings.
	 * @param string            $gateway_id The gateway ID to match.
	 * @return string|null The matching pattern, or null when none matches.
	 */
	public static function find_best_match( array $patterns, string $gateway_id ): ?string {
		$best_match  = null;
		$best_length = -1;

		foreach ( $patterns as $pattern ) {
			$pattern = (string) $pattern;
			if ( $pattern === $gateway_id ) {
				return $pattern;
			}

			if ( ! str_ends_with( $pattern, '*' ) ) {
				continue;
			}

			$prefix = substr( $pattern, 0, -1 );
			if ( '' !== $prefix && strlen( $prefix ) > $best_length && str_starts_with( $gateway_id, $prefix ) ) {
				$best_match  = $pattern;
				$best_length = strlen( $prefix );
			}
		}

		return $best_match;
	}
}
