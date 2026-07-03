<?php
/**
 * OrderData schema class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\Schemas;

use Automattic\WooCommerce\Internal\FraudProtectionPlugin\FraudProtectionController;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable record object representing order / cart data.
 */
class OrderData {

	/**
	 * Private constructor — use factory methods.
	 *
	 * @param int        $order_id          Order ID (0 when not yet created).
	 * @param int|string $customer_id       Customer ID or 'guest'.
	 * @param float      $total             Order total.
	 * @param float      $items_total       Items subtotal.
	 * @param float      $shipping_total    Shipping total.
	 * @param float      $tax_total         Tax total.
	 * @param ?float     $shipping_tax_rate Shipping tax rate.
	 * @param float      $discount_total    Discount total.
	 * @param ?string    $currency          Currency code (defaults to store currency).
	 * @param ?string    $cart_hash         Cart hash.
	 * @param CartItem[] $items             Cart items.
	 */
	private function __construct(
		private readonly int $order_id = 0,
		private readonly int|string $customer_id = 'guest',
		private readonly float $total = 0,
		private readonly float $items_total = 0,
		private readonly float $shipping_total = 0,
		private readonly float $tax_total = 0,
		private readonly ?float $shipping_tax_rate = null,
		private readonly float $discount_total = 0,
		private readonly ?string $currency = null,
		private readonly ?string $cart_hash = null,
		private readonly array $items = array()
	) {}

	/**
	 * Build from a WooCommerce cart.
	 *
	 * @param int          $order_id Order ID (0 when not yet created).
	 * @param \WC_Cart     $cart     WooCommerce cart.
	 * @param \WC_Customer $customer WooCommerce customer.
	 * @return self
	 */
	public static function from_cart( int $order_id, \WC_Cart $cart, \WC_Customer $customer ): self {
		$customer_id = $customer->get_id() ? $customer->get_id() : 'guest';

		$items_total    = (float) $cart->get_subtotal();
		$shipping_total = (float) $cart->get_shipping_total();
		$tax_total      = (float) $cart->get_cart_contents_tax();
		$discount_total = (float) $cart->get_discount_total();
		$cart_hash      = $cart->get_cart_hash();
		$total          = (float) $cart->get_total( 'edit' );
		$currency       = \WC()->call_function( 'get_woocommerce_currency' );

		// Calculate shipping_tax_rate.
		$shipping_tax      = (float) $cart->get_shipping_tax();
		$shipping_tax_rate = ( $shipping_total > 0 && $shipping_tax > 0 )
			? $shipping_tax / $shipping_total
			: null;

		// Build cart items — per-item try/catch so one bad item doesn't lose the rest.
		$items = array();
		foreach ( $cart->get_cart() as $cart_item ) {
			try {
				$product = $cart_item['data'] ?? null;
				if ( ! $product instanceof \WC_Product ) {
					continue;
				}
				$items[] = CartItem::from_cart_entry( $cart_item, $product );
			} catch ( \Throwable $e ) {
				FraudProtectionController::log(
					'warning',
					'Failed to build cart item for order data; item dropped',
					array(
						'event_source'      => 'order_data_from_cart',
						'exception_class'   => $e::class,
						'exception_message' => $e->getMessage(),
						'exception_file'    => $e->getFile(),
						'exception_line'    => $e->getLine(),
					),
					true
				);
			}
		}

		return new self(
			$order_id,
			$customer_id,
			$total,
			$items_total,
			$shipping_total,
			$tax_total,
			$shipping_tax_rate,
			$discount_total,
			$currency,
			$cart_hash,
			$items,
		);
	}

	/**
	 * Build an empty OrderData for graceful degradation.
	 *
	 * @param int $order_id Order ID (0 when not yet created).
	 * @return self
	 */
	public static function empty( int $order_id = 0 ): self {
		return new self( $order_id );
	}

	/**
	 * Serialize to array.
	 *
	 * @return array
	 */
	public function to_array(): array {
		return array(
			'order_id'          => $this->order_id,
			'customer_id'       => $this->customer_id,
			'total'             => $this->total,
			'items_total'       => $this->items_total,
			'shipping_total'    => $this->shipping_total,
			'tax_total'         => $this->tax_total,
			'shipping_tax_rate' => $this->shipping_tax_rate,
			'discount_total'    => $this->discount_total,
			'currency'          => $this->currency,
			'cart_hash'         => $this->cart_hash,
			'items'             => array_map(
				function ( CartItem $item ) {
					return $item->to_array();
				},
				$this->items,
			),
		);
	}
}
