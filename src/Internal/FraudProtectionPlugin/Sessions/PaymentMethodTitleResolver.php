<?php
/**
 * PaymentMethodTitleResolver class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\Sessions;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves the merchant-facing title and icon of a recorded payment method.
 *
 * Recorded events store the gateway id. The title and icon come from the
 * gateway registered under that id, so they follow the store's current
 * gateway configuration; a gateway that is no longer registered keeps its raw
 * id and has no icon.
 */
class PaymentMethodTitleResolver {

	/**
	 * Get the title of a payment gateway id.
	 *
	 * @param string $gateway_id The recorded gateway id.
	 * @return string The gateway's admin title, or the id when no registered gateway provides one.
	 */
	public function resolve( string $gateway_id ): string {
		$gateway = $this->get_gateway( $gateway_id );

		if ( is_null( $gateway ) ) {
			return $gateway_id;
		}

		$title = $gateway->get_method_title();
		$title = is_string( $title ) ? trim( wp_strip_all_tags( $title ) ) : '';

		return '' === $title ? $gateway_id : $title;
	}

	/**
	 * Get the icon URL of a payment gateway id.
	 *
	 * There is no core gateway-id-to-logo registry, so the icon comes from the
	 * gateway itself: its `icon` URL, or the first image its `get_icon()`
	 * markup renders when the property is empty (some gateways build the markup
	 * dynamically). The URL is normalized and sanitized.
	 *
	 * @param string $gateway_id The recorded gateway id.
	 * @return ?string The icon URL, or null when the gateway is unregistered or has no icon.
	 */
	public function resolve_icon( string $gateway_id ): ?string {
		$gateway = $this->get_gateway( $gateway_id );

		if ( is_null( $gateway ) ) {
			return null;
		}

		// The icon property is documented as a string but is unset by default and
		// set by third-party gateways, so treat it as mixed input.
		$icon = trim( (string) $gateway->icon );

		if ( '' === $icon ) {
			try {
				$icon = self::first_image_src( (string) $gateway->get_icon() );
			} catch ( \Throwable $e ) {
				// A gateway that fails to build its icon must not break the list.
				$icon = '';
			}
		}

		if ( '' === $icon ) {
			return null;
		}

		$url = esc_url_raw( \WC_HTTPS::force_https_url( $icon ) );

		return '' === $url ? null : $url;
	}

	/**
	 * Get the registered gateway for an id.
	 *
	 * @param string $gateway_id The recorded gateway id.
	 * @return ?\WC_Payment_Gateway The gateway, or null when the id is empty or unregistered.
	 */
	private function get_gateway( string $gateway_id ): ?\WC_Payment_Gateway {
		if ( '' === $gateway_id ) {
			return null;
		}

		$gateways = WC()->payment_gateways()->payment_gateways();
		$gateway  = is_array( $gateways ) ? ( $gateways[ $gateway_id ] ?? null ) : null;

		return $gateway instanceof \WC_Payment_Gateway ? $gateway : null;
	}

	/**
	 * Extract the first image source from an HTML fragment.
	 *
	 * @param string $html The HTML fragment.
	 * @return string The decoded source, or an empty string when none is found.
	 */
	private static function first_image_src( string $html ): string {
		if ( '' === $html ) {
			return '';
		}

		if ( 1 === preg_match( '/<img[^>]+src=(["\'])(.*?)\1/i', $html, $matches ) ) {
			return html_entity_decode( $matches[2] );
		}

		return '';
	}
}
