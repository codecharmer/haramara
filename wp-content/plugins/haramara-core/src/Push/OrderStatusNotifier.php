<?php
/**
 * Customer order-status push notifications.
 *
 * When staff move an order through its lifecycle, the customer device that
 * placed it (token attached via POST app/orders/{id}/push-token) gets an Expo
 * push mirroring the WhatsApp updates. One token per order, stored in order
 * meta; tokens Expo reports as dead are cleared off the order. Copy is
 * filterable via `haramara_push_message`.
 *
 * @package Haramara\Core
 */

declare( strict_types=1 );

namespace Haramara\Core\Push;

use Haramara\Core\Contracts\Bootable;
use Haramara\Core\Ordering\WalkInOrders;
use Haramara\Core\Rest\AppRoutes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pushes status updates to the customer's device.
 */
final class OrderStatusNotifier implements Bootable {

	/** Statuses that notify the customer (mirrors Sms\OrderNotifications). */
	private const CUSTOMER_STATUSES = array( 'processing', 'preparing', 'ready', 'completed', 'cancelled' );

	/**
	 * Hook every status transition.
	 */
	public function boot(): void {
		add_action( 'woocommerce_order_status_changed', array( $this, 'notify' ), 10, 4 );
	}

	/**
	 * Push the new status to the device that placed the order, if it registered.
	 *
	 * @param int|mixed    $order_id The order that changed.
	 * @param string|mixed $from     Previous status.
	 * @param string|mixed $to       New status.
	 * @param mixed        $order    Order instance when the caller provides it.
	 */
	public function notify( $order_id, $from, $to, $order ): void {
		$to = (string) $to;
		if ( ! in_array( $to, self::CUSTOMER_STATUSES, true ) ) {
			return;
		}

		if ( ! $order instanceof \WC_Order ) {
			$order = wc_get_order( (int) $order_id );
		}
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		// Counter sales have no customer device behind them.
		if ( WalkInOrders::CREATED_VIA === $order->get_created_via() ) {
			return;
		}

		$token = (string) $order->get_meta( AppRoutes::META_PUSH_TOKEN );
		if ( '' === $token ) {
			return;
		}

		$body = $this->message( $to, $order );
		if ( '' === $body ) {
			return;
		}

		ExpoPush::send(
			array( $token ),
			sprintf(
				/* translators: %s: order number. */
				__( 'Pedido #%s', 'haramara-core' ),
				$order->get_order_number()
			),
			$body,
			array(
				'type'     => 'order_status',
				'order_id' => $order->get_id(),
				'status'   => $to,
			),
			static function ( string $stale ) use ( $order ): void {
				// Device gone: clear the stale token off this order.
				if ( $stale === (string) $order->get_meta( AppRoutes::META_PUSH_TOKEN ) ) {
					$order->delete_meta_data( AppRoutes::META_PUSH_TOKEN );
					$order->save();
				}
			}
		);
	}

	/**
	 * Short Spanish body for the status; the title carries the order number.
	 *
	 * @param string    $status New status slug (no wc- prefix).
	 * @param \WC_Order $order  The order the message concerns.
	 */
	private function message( string $status, \WC_Order $order ): string {
		switch ( $status ) {
			case 'processing':
				$fallback = __( 'Recibimos tu pedido. Te avisaremos cuando esté listo.', 'haramara-core' );
				break;
			case 'preparing':
				$fallback = __( 'Ya está en la barra: lo estamos preparando.', 'haramara-core' );
				break;
			case 'ready':
				$fallback = __( '¡Está listo! Te esperamos en la barra.', 'haramara-core' );
				break;
			case 'completed':
				$fallback = __( 'Entregado. ¡Gracias y buen provecho!', 'haramara-core' );
				break;
			case 'cancelled':
				$fallback = __( 'Tu pedido fue cancelado. Escríbenos por WhatsApp si tienes dudas.', 'haramara-core' );
				break;
			default:
				return '';
		}

		/**
		 * Filter an outbound push message body before it is sent.
		 *
		 * @param string    $fallback Rendered default copy.
		 * @param string    $key      Template key (e.g. 'customer_ready').
		 * @param \WC_Order $order    The order the message concerns.
		 */
		return (string) apply_filters( 'haramara_push_message', $fallback, 'customer_' . $status, $order );
	}
}
