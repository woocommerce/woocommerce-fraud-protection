<?php
/**
 * TrustedProxyEntry class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin;

defined( 'ABSPATH' ) || exit;

/**
 * One validated trusted-proxy entry: the proxy addresses and the header they set with the client address.
 */
final class TrustedProxyEntry {

	/**
	 * Header names that are rejected even though they match the name pattern.
	 *
	 * `Forwarded` uses the RFC 7239 syntax instead of an address list, and PHP does not expose the
	 * content headers under an `HTTP_` key.
	 */
	private const UNSUPPORTED_HEADERS = array( 'forwarded', 'content-type', 'content-length' );

	/**
	 * Whether the entry was an array.
	 *
	 * @var bool
	 */
	public readonly bool $valid_shape;

	/**
	 * Valid trusted proxy addresses and CIDR ranges, as supplied.
	 *
	 * @var string[]
	 */
	public readonly array $addresses;

	/**
	 * Printable representations of the ignored address entries.
	 *
	 * @var string[]
	 */
	public readonly array $invalid_addresses;

	/**
	 * Client IP header name as configured, or null when missing or unsupported.
	 *
	 * @var ?string
	 */
	public readonly ?string $header;

	/**
	 * Printable representation of an unsupported header value, or null.
	 *
	 * @var ?string
	 */
	public readonly ?string $invalid_header;

	/**
	 * Parsed ranges as packed network addresses with their prefix lengths.
	 *
	 * @var array<int, array{0: string, 1: int}>
	 */
	private array $ranges;

	/**
	 * Build the entry from validated values.
	 *
	 * @param bool                                 $valid_shape       Whether the entry was an array.
	 * @param string[]                             $addresses         Valid address entries.
	 * @param string[]                             $invalid_addresses Ignored address entries.
	 * @param ?string                              $header            Supported header name.
	 * @param ?string                              $invalid_header    Unsupported header value.
	 * @param array<int, array{0: string, 1: int}> $ranges            Parsed ranges.
	 */
	private function __construct( bool $valid_shape, array $addresses, array $invalid_addresses, ?string $header, ?string $invalid_header, array $ranges ) {
		$this->valid_shape       = $valid_shape;
		$this->addresses         = $addresses;
		$this->invalid_addresses = $invalid_addresses;
		$this->header            = $header;
		$this->invalid_header    = $invalid_header;
		$this->ranges            = $ranges;
	}

	/**
	 * Validate an entry value of any type.
	 *
	 * Expected shape: `array( 'addresses' => string[], 'header' => string )`. Unknown keys are ignored,
	 * invalid address entries are skipped, and an unsupported header name makes the entry unusable.
	 *
	 * @param mixed $entry Entry value.
	 * @return self
	 */
	public static function from_mixed( mixed $entry ): self {
		if ( ! is_array( $entry ) ) {
			return new self( false, array(), array(), null, null, array() );
		}

		$addresses         = array();
		$invalid_addresses = array();
		$ranges            = array();
		$address_entries   = $entry['addresses'] ?? array();

		if ( ! is_array( $address_entries ) ) {
			$invalid_addresses[] = self::describe( $address_entries );
			$address_entries     = array();
		}

		foreach ( $address_entries as $address ) {
			$range = is_string( $address ) ? self::parse_range( $address ) : null;
			if ( null === $range ) {
				$invalid_addresses[] = self::describe( $address );
				continue;
			}

			$addresses[] = $address;
			$ranges[]    = $range;
		}

		$header         = null;
		$invalid_header = null;
		$header_value   = $entry['header'] ?? null;
		if ( null !== $header_value ) {
			$header         = is_string( $header_value ) && self::is_supported_header( $header_value ) ? $header_value : null;
			$invalid_header = null === $header ? self::describe( $header_value ) : null;
		}

		return new self( true, $addresses, $invalid_addresses, $header, $invalid_header, $ranges );
	}

	/**
	 * Whether the entry has a supported header and at least one valid address.
	 *
	 * @return bool
	 */
	public function is_usable(): bool {
		return null !== $this->header && array() !== $this->ranges;
	}

	/**
	 * Whether the entry has any problem that an operator should fix.
	 *
	 * @return bool
	 */
	public function has_problems(): bool {
		return ! $this->is_usable() || array() !== $this->invalid_addresses;
	}

	/**
	 * The `$_SERVER` key that holds the entry's header, or null.
	 *
	 * @return ?string
	 */
	public function get_header_server_key(): ?string {
		return null === $this->header ? null : 'HTTP_' . strtoupper( str_replace( '-', '_', $this->header ) );
	}

	/**
	 * Whether a valid IP address belongs to one of the entry's ranges.
	 *
	 * An IPv4-mapped IPv6 address also matches the IPv4 ranges.
	 *
	 * @param string $ip_address Valid IP address.
	 * @return bool
	 */
	public function contains( string $ip_address ): bool {
		$packed = inet_pton( $ip_address );
		if ( false === $packed ) {
			return false;
		}

		$candidates = array( $packed );
		if ( 16 === strlen( $packed ) && str_starts_with( $packed, str_repeat( "\0", 10 ) . "\xff\xff" ) ) {
			$candidates[] = substr( $packed, 12 );
		}

		foreach ( $this->ranges as list( $network, $prefix ) ) {
			foreach ( $candidates as $candidate ) {
				if ( self::packed_in_range( $candidate, $network, $prefix ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Get the entry details for diagnostics.
	 *
	 * @return array{valid_shape: bool, addresses: string[], invalid_addresses: string[], header: ?string, invalid_header: ?string}
	 */
	public function to_diagnostic_array(): array {
		return array(
			'valid_shape'       => $this->valid_shape,
			'addresses'         => $this->addresses,
			'invalid_addresses' => $this->invalid_addresses,
			'header'            => $this->header,
			'invalid_header'    => $this->invalid_header,
		);
	}

	/**
	 * Parse an IP address or CIDR range.
	 *
	 * @param string $address Address entry.
	 * @return ?array{0: string, 1: int} Packed network and prefix length, or null when invalid.
	 */
	private static function parse_range( string $address ): ?array {
		$parts = explode( '/', $address );
		if ( count( $parts ) > 2 || false === filter_var( $parts[0], FILTER_VALIDATE_IP ) ) {
			return null;
		}

		$packed = inet_pton( $parts[0] );
		if ( false === $packed ) {
			return null;
		}

		$max_prefix = strlen( $packed ) * 8;
		if ( 1 === count( $parts ) ) {
			return array( $packed, $max_prefix );
		}

		if ( 1 !== preg_match( '/^(0|[1-9][0-9]{0,2})$/', $parts[1] ) || (int) $parts[1] > $max_prefix ) {
			return null;
		}

		return array( $packed, (int) $parts[1] );
	}

	/**
	 * Whether a packed address belongs to a packed network of the same family.
	 *
	 * @param string $packed  Packed address.
	 * @param string $network Packed network.
	 * @param int    $prefix  Prefix length in bits.
	 * @return bool
	 */
	private static function packed_in_range( string $packed, string $network, int $prefix ): bool {
		if ( strlen( $packed ) !== strlen( $network ) ) {
			return false;
		}

		$full_bytes = intdiv( $prefix, 8 );
		if ( substr( $packed, 0, $full_bytes ) !== substr( $network, 0, $full_bytes ) ) {
			return false;
		}

		$remaining_bits = $prefix % 8;
		if ( 0 === $remaining_bits ) {
			return true;
		}

		$mask = ( 0xff << ( 8 - $remaining_bits ) ) & 0xff;

		return ( ord( $packed[ $full_bytes ] ) & $mask ) === ( ord( $network[ $full_bytes ] ) & $mask );
	}

	/**
	 * Whether a header name can identify a client IP header.
	 *
	 * Only letters, digits, and single inner dashes are accepted. Web servers pass these names to PHP
	 * unchanged apart from case and dashes, so each name maps to exactly one `$_SERVER` key. Names with
	 * underscores are rejected because they would share that key with the dashed spelling.
	 *
	 * @param string $header Header name.
	 * @return bool
	 */
	private static function is_supported_header( string $header ): bool {
		return 1 === preg_match( '/^[A-Za-z0-9]+(?:-[A-Za-z0-9]+)*$/', $header )
			&& ! in_array( strtolower( $header ), self::UNSUPPORTED_HEADERS, true );
	}

	/**
	 * Describe an invalid configuration value for diagnostics.
	 *
	 * @param mixed $value Invalid value.
	 * @return string
	 */
	private static function describe( mixed $value ): string {
		return is_string( $value ) ? $value : get_debug_type( $value );
	}
}
