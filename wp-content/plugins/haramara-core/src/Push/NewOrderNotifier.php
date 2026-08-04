<?php
/**
 * New-order push notifications for the POS.
 *
 * Replaces the retired staff SMS alert: when an online order is first paid it
 * lands in the POS "Entrantes" queue, and every registered staff device gets an
 * Expo push so the counter hears about it even with the app closed. Fires once
 * per order (either the processing transition or payment_complete may run
 * first), and never for counter walk-ins.
 *
 * @package Haramara\Core
 */

declare( strict_types=1 );

namespace Haramara\Core\Push;

use Haramara\Core\Contracts\Bootable;
use Haramara\Core\Ordering\OrderMeta;
use Haramara\Core\Ordering\WalkInOrders;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pushes "Nuevo pedido" to the POS tablets.
 */
final class NewOrderNotifier implements Bootable {

	/** Order meta flag guarding against duplicate staff pushes. */
	private const SENT_FLAG = '_haramara_staff_push_sent';

	/** Max characters for the item summary line. */
	private const ITEMS_MAX = 160;

	/**
	 * Hook the new-paid-order triggers.
	 */
	public function boot(): void {
		// New paid order → push to POS devices once (either hook may fire first).
		add_action( 'woocommerce_order_status_processing', array( $this, 'notify' ), 10, 1 );
		add_action( 'woocommerce_payment_complete', array( $this, 'notify' ), 10, 1 );
	}

	/**
	 * Push "Nuevo pedido" to every registered POS device, once per order.
	 *
	 * @param int|mixed $order_id The order that was just paid.
	 */
	public function notify( $order_id ): void {
		$order = wc_get_order( (int) $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		// Counter sales are rung up by staff — alerting staff about them is noise.
		if ( WalkInOrders::CREATED_VIA === $order->get_created_via() ) {
			return;
		}

		// Dedupe: both processing and payment_complete can fire for one order.
		if ( '' !== (string) $order->get_meta( self::SENT_FLAG ) ) {
			return;
		}

		// No claimed flag yet: if no device is registered, leave the flag unset
		// so a device that registers between the two hooks still gets the push.
		$tokens = StaffTokens::all();
		if ( empty( $tokens ) ) {
			return;
		}

		// Claim the flag up front so a second hook can't double-send.
		$order->update_meta_data( self::SENT_FLAG, current_time( 'mysql' ) );
		$order->save();

		$customer = trim( $order->get_formatted_billing_full_name() );
		if ( '' === $customer ) {
			$customer = __( 'Cliente', 'haramara-core' );
		}

		ExpoPush::send(
			$tokens,
			sprintf(
				/* translators: %s: order number. */
				__( 'Nuevo pedido #%s', 'haramara-core' ),
				$order->get_order_number()
			),
			sprintf(
				/* translators: 1: customer name, 2: item summary, 3: pickup label. */
				__( '%1$s · %2$s · Recoge: %3$s', 'haramara-core' ),
				$customer,
				$this->items_summary( $order ),
				$this->pickup_label( $order )
			),
			array(
				'type'     => 'new_order',
				'order_id' => $order->get_id(),
			)
		);
	}

	/**
	 * Pickup label via the ordering module, guarded so absence never fatals.
	 *
	 * @param \WC_Order $order The order to describe.
	 */
	private function pickup_label( \WC_Order $order ): string {
		if ( class_exists( OrderMeta::class ) && method_exists( OrderMeta::class, 'label' ) ) {
			$label = (string) OrderMeta::label( $order );
			if ( '' !== $label ) {
				return $label;
			}
		}
		return __( 'por confirmar', 'haramara-core' );
	}

	/**
	 * "Name x2, Other x1" summary, truncated to notification length.
	 *
	 * @param \WC_Order $order The order to summarise.
	 */
	private function items_summary( \WC_Order $order ): string {
		$parts = array();
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$parts[] = sprintf( '%s x%d', $item->get_name(), (int) $item->get_quantity() );
		}

		$summary = implode( ', ', $parts );
		if ( mb_strlen( $summary ) > self::ITEMS_MAX ) {
			$summary = rtrim( mb_substr( $summary, 0, self::ITEMS_MAX - 1 ) ) . '…';
		}

		return '' !== $summary ? $summary : __( '(sin artículos)', 'haramara-core' );
	}
}
