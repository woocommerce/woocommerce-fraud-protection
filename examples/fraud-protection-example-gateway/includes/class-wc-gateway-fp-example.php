<?php
/**
 * WC_Gateway_FP_Example class file.
 *
 * @package WooCommerce\FraudProtection\Examples
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * A barebones card gateway with a native Fraud Protection integration.
 *
 * The gateway class only shows how the integration is declared: the `$supports`
 * entries in the constructor. The integration itself lives in
 * {@see FP_Example_Fraud_Protection}. The checkout form posts a card brand and the
 * last four digits in place of the token a real gateway would collect in the browser.
 */
class WC_Gateway_FP_Example extends WC_Payment_Gateway {

	/**
	 * Gateway ID.
	 */
	public const ID = 'fp_example';

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id                 = self::ID;
		$this->has_fields         = true;
		$this->method_title       = __( 'Fraud Protection example', 'fp-example-gateway' );
		$this->method_description = __( 'A barebones gateway that integrates WooCommerce Fraud Protection natively. Reference code only.', 'fp-example-gateway' );

		// Fraud Protection features are declared like any other gateway feature, with the
		// literal strings: the declaration must also work when Fraud Protection is not
		// installed. Declare a feature only once the integration implements it, because
		// each one switches off the matching built-in compatibility layer for this gateway.
		$this->supports = array(
			'products',
			'fraud_protection_payment_data',
			'fraud_protection_checkout',
			'fraud_protection_express_checkout',
		);

		$this->init_form_fields();
		$this->init_settings();

		$this->title   = $this->get_option( 'title' );
		$this->enabled = $this->get_option( 'enabled' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	/**
	 * Define the settings fields.
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled' => array(
				'title'   => __( 'Enable/Disable', 'fp-example-gateway' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable the example gateway', 'fp-example-gateway' ),
				'default' => 'no',
			),
			'title'   => array(
				'title'       => __( 'Title', 'fp-example-gateway' ),
				'type'        => 'text',
				'description' => __( 'The payment method name shown at checkout.', 'fp-example-gateway' ),
				'default'     => __( 'Example card payment', 'fp-example-gateway' ),
				'desc_tip'    => true,
			),
		);
	}

	/**
	 * Render the checkout fields.
	 *
	 * A real gateway tokenizes the card in the browser and posts the token. This example
	 * posts the fields that {@see FP_Example_Fraud_Protection::resolve_payment_data()} reads.
	 */
	public function payment_fields() {
		?>
		<p class="form-row form-row-wide">
			<label for="fp_example_card_brand"><?php esc_html_e( 'Card brand', 'fp-example-gateway' ); ?></label>
			<select id="fp_example_card_brand" name="fp_example_card_brand">
				<option value="visa">Visa</option>
				<option value="mastercard">Mastercard</option>
			</select>
		</p>
		<p class="form-row form-row-wide">
			<label for="fp_example_card_last4"><?php esc_html_e( 'Last four digits', 'fp-example-gateway' ); ?></label>
			<input id="fp_example_card_last4" name="fp_example_card_last4" type="text" inputmode="numeric" maxlength="4" value="4242" />
		</p>
		<?php
	}

	/**
	 * Complete the payment.
	 *
	 * @param int $order_id Order ID.
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return array( 'result' => 'failure' );
		}

		$order->payment_complete( 'fp-example-' . $order_id );
		WC()->cart->empty_cart();

		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}
}
