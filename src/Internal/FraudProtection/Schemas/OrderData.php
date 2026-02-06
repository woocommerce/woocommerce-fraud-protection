<?php
/**
 * OrderData schema class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtection\Schemas;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable record object representing order / cart data.
 *
 * @since 10.5.0
 * @internal This class is part of the internal API and is subject to change without notice.
 */
class OrderData {

	/**
	 * Order ID.
	 *
	 * @var ?int
	 */
	private ?int $order_id;

	/**
	 * Customer ID or 'guest'.
	 *
	 * @var int|string
	 */
	private $customer_id;

	/**
	 * Order total.
	 *
	 * @var float
	 */
	private float $total;

	/**
	 * Items subtotal.
	 *
	 * @var float
	 */
	private float $items_total;

	/**
	 * Shipping total.
	 *
	 * @var float
	 */
	private float $shipping_total;

	/**
	 * Tax total.
	 *
	 * @var float
	 */
	private float $tax_total;

	/**
	 * Shipping tax rate.
	 *
	 * @var ?float
	 */
	private ?float $shipping_tax_rate;

	/**
	 * Discount total.
	 *
	 * @var float
	 */
	private float $discount_total;

	/**
	 * Currency code.
	 *
	 * @var ?string
	 */
	private ?string $currency;

	/**
	 * Cart hash.
	 *
	 * @var ?string
	 */
	private ?string $cart_hash;

	/**
	 * Cart items.
	 *
	 * @var CartItem[]
	 */
	private array $items;

	/**
	 * Private constructor — use factory methods.
	 *
	 * @param ?int       $order_id          Order ID.
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
		?int $order_id = null,
		$customer_id = 'guest',
		float $total = 0,
		float $items_total = 0,
		float $shipping_total = 0,
		float $tax_total = 0,
		?float $shipping_tax_rate = null,
		float $discount_total = 0,
		?string $currency = null,
		?string $cart_hash = null,
		array $items = array()
	) {
		$this->order_id          = $order_id;
		$this->customer_id       = $customer_id;
		$this->total             = $total;
		$this->items_total       = $items_total;
		$this->shipping_total    = $shipping_total;
		$this->tax_total         = $tax_total;
		$this->shipping_tax_rate = $shipping_tax_rate;
		$this->discount_total    = $discount_total;
		$this->currency          = $currency;
		$this->cart_hash         = $cart_hash;
		$this->items             = $items;
	}

	/**
	 * Build from a WooCommerce cart.
	 *
	 * @param ?int         $order_id Order ID.
	 * @param \WC_Cart     $cart     WooCommerce cart.
	 * @param \WC_Customer $customer WooCommerce customer.
	 * @return self
	 */
	public static function from_cart( ?int $order_id, \WC_Cart $cart, \WC_Customer $customer ): self {
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
				continue;
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
	 * @param ?int $order_id Order ID.
	 * @return self
	 */
	public static function empty( ?int $order_id = null ): self {
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
