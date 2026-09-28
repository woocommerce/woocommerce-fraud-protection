<?php
/**
 * TrustedProxyConfig class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin;

defined( 'ABSPATH' ) || exit;

/**
 * Validated trusted-proxy configuration used to select the visitor IP address.
 *
 * The configuration is either one entry, `array( 'addresses' => string[], 'header' => string )`,
 * or a list of such entries.
 */
final class TrustedProxyConfig {

	/**
	 * Whether a non-empty configuration was supplied.
	 *
	 * @var bool
	 */
	public readonly bool $provided;

	/**
	 * Whether the supplied configuration was an array.
	 *
	 * @var bool
	 */
	public readonly bool $valid_shape;

	/**
	 * Validated entries, in configuration order.
	 *
	 * @var TrustedProxyEntry[]
	 */
	public readonly array $entries;

	/**
	 * Build the configuration from validated values.
	 *
	 * @param bool                $provided    Whether a non-empty configuration was supplied.
	 * @param bool                $valid_shape Whether the configuration was an array.
	 * @param TrustedProxyEntry[] $entries     Validated entries.
	 */
	private function __construct( bool $provided, bool $valid_shape, array $entries ) {
		$this->provided    = $provided;
		$this->valid_shape = $valid_shape;
		$this->entries     = $entries;
	}

	/**
	 * Validate a configuration value of any type.
	 *
	 * @param mixed $config Configuration value.
	 * @return self
	 */
	public static function from_mixed( mixed $config ): self {
		if ( null === $config || array() === $config ) {
			return new self( false, true, array() );
		}

		if ( ! is_array( $config ) ) {
			return new self( true, false, array() );
		}

		$entry_values = array_is_list( $config ) ? $config : array( $config );

		return new self( true, true, array_map( array( TrustedProxyEntry::class, 'from_mixed' ), $entry_values ) );
	}

	/**
	 * Whether at least one entry can replace the direct peer address.
	 *
	 * @return bool
	 */
	public function is_active(): bool {
		return null !== $this->find_first_usable_entry( null );
	}

	/**
	 * Whether a supplied configuration has any problem that an operator should fix.
	 *
	 * @return bool
	 */
	public function has_problems(): bool {
		if ( ! $this->provided ) {
			return false;
		}

		if ( ! $this->valid_shape ) {
			return true;
		}

		foreach ( $this->entries as $entry ) {
			if ( $entry->has_problems() ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Find the first usable entry whose addresses include the direct peer.
	 *
	 * @param string $peer_address Valid direct peer address.
	 * @return ?TrustedProxyEntry
	 */
	public function find_entry_for_peer( string $peer_address ): ?TrustedProxyEntry {
		return $this->find_first_usable_entry( $peer_address );
	}

	/**
	 * Whether a valid IP address belongs to any usable entry.
	 *
	 * @param string $ip_address Valid IP address.
	 * @return bool
	 */
	public function is_trusted( string $ip_address ): bool {
		return null !== $this->find_first_usable_entry( $ip_address );
	}

	/**
	 * Get the configuration details for diagnostics.
	 *
	 * @return array{valid_shape: bool, entries: array<int, array<string, mixed>>}
	 */
	public function to_diagnostic_array(): array {
		return array(
			'valid_shape' => $this->valid_shape,
			'entries'     => array_map( fn( TrustedProxyEntry $entry ): array => $entry->to_diagnostic_array(), $this->entries ),
		);
	}

	/**
	 * Find the first usable entry, optionally one that contains an address.
	 *
	 * @param ?string $ip_address Valid IP address, or null to accept any usable entry.
	 * @return ?TrustedProxyEntry
	 */
	private function find_first_usable_entry( ?string $ip_address ): ?TrustedProxyEntry {
		foreach ( $this->entries as $entry ) {
			if ( $entry->is_usable() && ( null === $ip_address || $entry->contains( $ip_address ) ) ) {
				return $entry;
			}
		}

		return null;
	}
}
