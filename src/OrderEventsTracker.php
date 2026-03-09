<?php
/**
 * OrderEventsTracker class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\FraudProtection;

defined( 'ABSPATH' ) || exit;

/**
 * Tracks order lifecycle events and reports them to the Blackbox API.
 *
 * Listens to WooCommerce order hooks (e.g. order notes) and sends
 * event data to the report endpoint so Blackbox can correlate
 * outcomes with the original fraud-check session.
 *
 * Fire-and-forget: failures are logged but never affect the order flow.
 *
 * @internal
 */
class OrderEventsTracker {

	/**
	 * API client instance.
	 *
	 * @var ApiClient
	 */
	private ApiClient $api_client;

	/**
	 * Initialize with dependencies.
	 *
	 * @internal
	 *
	 * @param ApiClient $api_client The API client instance.
	 */
	final public function init( ApiClient $api_client ): void {
		$this->api_client = $api_client;
	}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'woocommerce_order_note_added', array( $this, 'on_order_note_added' ), 10, 2 );
		add_action( 'woocommerce_order_refunded', array( $this, 'on_order_refunded' ), 10, 2 );
	}

	/**
	 * Report an order note event to the Blackbox API.
	 *
	 * @internal
	 *
	 * @param int       $comment_id The comment ID of the order note.
	 * @param \WC_Order $order      The order the note was added to.
	 */
	public function on_order_note_added( int $comment_id, \WC_Order $order ): void {
		$session_id = $order->get_meta( SessionVerifier::ORDER_BLACKBOX_SESSION_ID_KEY );
		if ( ! is_string( $session_id ) || '' === $session_id ) {
			return;
		}

		$comment = get_comment( $comment_id );
		if ( ! $comment instanceof \WP_Comment ) {
			return;
		}

		$this->api_client->report(
			$session_id,
			array(
				'label'  => 'demo-label',
				'source' => 'order_note_added',
				'notes'  => $comment->comment_content,
			)
		);
	}

	/**
	 * Report an order refund event to the Blackbox API.
	 *
	 * @internal
	 *
	 * @param int $order_id  The order ID.
	 * @param int $refund_id The refund ID.
	 */
	public function on_order_refunded( int $order_id, int $refund_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$session_id = $order->get_meta( SessionVerifier::ORDER_BLACKBOX_SESSION_ID_KEY );
		if ( ! is_string( $session_id ) || '' === $session_id ) {
			return;
		}

		$refund = wc_get_order( $refund_id );
		if ( ! $refund instanceof \WC_Order_Refund ) {
			return;
		}

		$this->api_client->report(
			$session_id,
			array(
				'label'  => 'demo-label',
				'source' => 'order_refunded',
				'notes'  => $refund->get_reason(),
			)
		);
	}
}
