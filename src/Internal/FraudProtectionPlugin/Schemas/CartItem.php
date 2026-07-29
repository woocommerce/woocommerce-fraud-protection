<?php
/**
 * CartItem schema class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\Schemas;

use Automattic\WooCommerce\Internal\FraudProtectionPlugin\QuantityValue;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable record object representing a single cart item.
 */
class CartItem {

	use ReadsFiniteNumbers;

	/**
	 * Private constructor — use factory methods.
	 *
	 * @param int     $product_id           WooCommerce product ID.
	 * @param ?string $name                 Product name.
	 * @param ?string $category             Comma-separated category names.
	 * @param ?string $sku                  Product SKU.
	 * @param mixed   $quantity             Quantity in cart, relayed verbatim.
	 * @param ?float  $unit_price           Per-unit price; null when it has no numeric reading.
	 * @param ?float  $unit_tax_amount      Per-unit tax amount; null when underivable.
	 * @param ?float  $unit_discount_amount Per-unit discount amount; null when underivable.
	 * @param ?string $product_type         WooCommerce product type.
	 * @param bool    $is_virtual           Whether the product is virtual.
	 * @param bool    $is_downloadable      Whether the product is downloadable.
	 * @param array   $attributes           Product attributes.
	 */
	private function __construct(
		private readonly int $product_id,
		private readonly ?string $name,
		private readonly ?string $category,
		private readonly ?string $sku,
		private readonly mixed $quantity,
		private readonly ?float $unit_price,
		private readonly ?float $unit_tax_amount,
		private readonly ?float $unit_discount_amount,
		private readonly ?string $product_type,
		private readonly bool $is_virtual,
		private readonly bool $is_downloadable,
		private readonly array $attributes
	) {}

	/**
	 * Build from a WooCommerce cart entry and its product.
	 *
	 * @param array       $cart_item Cart item array from WC_Cart::get_cart().
	 * @param \WC_Product $product   The product object.
	 * @return self
	 */
	public static function from_cart_entry( array $cart_item, \WC_Product $product ): self {
		// Relay the raw value verbatim; parse a finite float only for the local calculations.
		$quantity        = $cart_item['quantity'] ?? 1;
		$quantity_number = QuantityValue::as_finite_float( $quantity );

		$unit_price    = self::finite_number( $product->get_price() );
		$line_tax      = $cart_item['line_tax'] ?? 0;
		$line_discount = self::line_discount( $cart_item );
		$unit_tax      = self::per_unit_amount( $line_tax, $quantity_number );
		$unit_discount = self::per_unit_amount( $line_discount, $quantity_number );

		$category = self::get_product_category_names( $product );

		return new self(
			$product->get_id(),
			$product->get_name() ? $product->get_name() : null,
			$category,
			$product->get_sku() ? $product->get_sku() : null,
			$quantity,
			$unit_price,
			$unit_tax,
			$unit_discount,
			$product->get_type() ? $product->get_type() : null,
			$product->is_virtual(),
			$product->is_downloadable(),
			$product->get_attributes() ? $product->get_attributes() : array(),
		);
	}

	/**
	 * Read the discount on a line as what it would have cost minus what it did.
	 *
	 * Both operands are read as numbers first: core keeps these amounts as int|float, but they
	 * pass through cart filters on the way here, and subtracting a string with no numeric
	 * reading raises a TypeError that the caller's per-item guard turns into a dropped order
	 * line — the whole item for one unreadable field.
	 *
	 * @param array<string, mixed> $cart_item Raw cart entry.
	 * @return float|null The discount, or null when either amount has no numeric reading.
	 */
	private static function line_discount( array $cart_item ): ?float {
		$subtotal = $cart_item['line_subtotal'] ?? 0;
		$total    = $cart_item['line_total'] ?? 0;

		if ( ! is_numeric( $subtotal ) || ! is_numeric( $total ) ) {
			return null;
		}

		return (float) $subtotal - (float) $total;
	}

	/**
	 * Calculate a per-unit amount from a line total.
	 *
	 * Four checks, in an order that matters: no numeric reading of the quantity means nothing to
	 * divide by; a line amount is only guaranteed numeric inside core, and an unreadable one must
	 * not cast to a meaningless 0.0; the quantity's sign keeps the historical zero amounts rather
	 * than dividing; and two usable operands can still divide into a result that is not. This is
	 * the plugin's own arithmetic, so no usable result means no amount reported.
	 *
	 * @param mixed      $line_amount Total amount for the line.
	 * @param float|null $quantity    Parsed quantity.
	 * @return float|null
	 */
	private static function per_unit_amount( mixed $line_amount, ?float $quantity ): ?float {
		if ( null === $quantity ) {
			return null;
		}

		if ( null === self::finite_number( $line_amount ) ) {
			return null;
		}

		// Checked after the amount, not before: the historical zero stands for "nothing to
		// divide", and an amount nobody can read is not nothing.
		if ( $quantity <= 0 ) {
			return 0.0;
		}

		$unit_amount = (float) $line_amount / $quantity;

		return is_finite( $unit_amount ) ? $unit_amount : null;
	}

	/**
	 * Get product category names as comma-separated list.
	 *
	 * @param \WC_Product $product The product object.
	 * @return ?string Comma-separated category names or null.
	 */
	private static function get_product_category_names( \WC_Product $product ): ?string {
		$terms = \WC()->call_function( 'wc_get_product_terms', $product->get_id(), 'product_cat' );
		if ( empty( $terms ) || ! is_array( $terms ) ) {
			return null;
		}
		$category_names = array_map(
			function ( $term ) {
				return $term->name;
			},
			$terms
		);
		return implode( ', ', $category_names );
	}

	/**
	 * Serialize to array.
	 *
	 * @return array
	 */
	public function to_array(): array {
		return array(
			'product_id'           => $this->product_id,
			'name'                 => $this->name,
			'category'             => $this->category,
			'sku'                  => $this->sku,
			'quantity'             => $this->quantity,
			'unit_price'           => $this->unit_price,
			'unit_tax_amount'      => $this->unit_tax_amount,
			'unit_discount_amount' => $this->unit_discount_amount,
			'product_type'         => $this->product_type,
			'is_virtual'           => $this->is_virtual,
			'is_downloadable'      => $this->is_downloadable,
			'attributes'           => $this->attributes,
		);
	}
}
