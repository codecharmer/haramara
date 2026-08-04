<?php
/**
 * Outbound order SMS notifications (customer-facing).
 *
 * Customers receive a friendly Spanish update on every customer-relevant status
 * change. Staff are no longer messaged — new orders reach the team through the
 * POS queue and Push\NewOrderNotifier instead. Every send flows through
 * TwilioClient and is written to the Logger. All copy is filterable via
 * `haramara_sms_message` so it can be edited without touching code.
 *
 * @package Haramara\Core
 */

declare( strict_types=1 );

namespace Haramara\Core\Sms;

use Haramara\Core\Contracts\Bootable;
use Haramara\Core\Setup\Options;
use Haramara\Core\Ordering\WalkInOrders;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class OrderNotifications implements Bootable {

	/** Statuses that trigger a customer SMS. */
	private const CUSTOMER_STATUSES = array( 'processing', 'preparing', 'ready', 'completed', 'cancelled' );

	public function boot(): void {
		// Any status change → notify the customer.
		add_action( 'woocommerce_order_status_changed', array( $this, 'notify_customer' ), 10, 4 );
	}

	/* ---------------------------------------------------------------------- */
	/* Customer */
	/* ---------------------------------------------------------------------- */

	/**
	 * @param int    $order_id
	 * @param string $from
	 * @param string $to
	 * @param mixed  $order
	 */
	public function notify_customer( $order_id, $from, $to, $order ): void {
		$sms = Options::sms();
		if ( empty( $sms['enabled'] ) || empty( $sms['notify_customer'] ) ) {
			return;
		}

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

		// Counter sales carry no billing phone by design; skip explicitly anyway.
		if ( WalkInOrders::CREATED_VIA === $order->get_created_via() ) {
			return;
		}

		$phone = trim( (string) $order->get_billing_phone() );
		if ( '' === $phone ) {
			return;
		}

		$body = $this->customer_message( $to, $order );
		if ( '' === $body ) {
			return;
		}

		$this->dispatch( new TwilioClient(), $phone, $body, 'outbound', $order );
	}

	/**
	 * Friendly Spanish message describing the new status.
	 */
	private function customer_message( string $status, \WC_Order $order ): string {
		$number   = $order->get_order_number();
		$business = Options::business();
		$address  = (string) ( $business['address'] ?? '' );

		switch ( $status ) {
			case 'processing':
				$fallback = sprintf(
					/* translators: %s: order number. */
					__( '¡Gracias! Recibimos tu pedido #%s. Te avisaremos cuando esté listo. 🥖', 'haramara-core' ),
					$number
				);
				break;
			case 'preparing':
				$fallback = sprintf(
					/* translators: %s: order number. */
					__( 'Manos a la masa: estamos preparando tu pedido #%s.', 'haramara-core' ),
					$number
				);
				break;
			case 'ready':
				$fallback = sprintf(
					/* translators: 1: order number, 2: business address. */
					__( '¡Tu pedido #%1$s está listo para recoger! Te esperamos en %2$s.', 'haramara-core' ),
					$number,
					$address
				);
				break;
			case 'completed':
				$fallback = sprintf(
					/* translators: %s: order number. */
					__( '¡Gracias por tu compra! Entregamos tu pedido #%s. ¡Buen provecho! 🌾', 'haramara-core' ),
					$number
				);
				break;
			case 'cancelled':
				$fallback = sprintf(
					/* translators: %s: order number. */
					__( 'Tu pedido #%s fue cancelado. Si tienes dudas, contáctanos.', 'haramara-core' ),
					$number
				);
				break;
			default:
				return '';
		}

		return $this->template( 'customer_' . $status, $fallback, $order );
	}

	/* ---------------------------------------------------------------------- */
	/* Helpers */
	/* ---------------------------------------------------------------------- */

	/**
	 * Send + log a single message.
	 */
	private function dispatch( TwilioClient $client, string $to, string $body, string $direction, \WC_Order $order ): void {
		$result = $client->send( $to, $body );

		Logger::record(
			array(
				'direction'    => $direction,
				'order_id'     => $order->get_id(),
				'recipient'    => $to,
				'sender'       => (string) ( Options::sms()['twilio_from'] ?? '' ),
				'body'         => $body,
				'status'       => $result['success'] ? 'sent' : 'failed',
				'provider_sid' => $result['sid'],
				'error'        => $result['error'],
			)
		);
	}

	/**
	 * Apply the editable-copy filter.
	 *
	 * @param string $key     Template key (e.g. 'staff_new_order', 'customer_ready').
	 * @param string $fallback Default message body.
	 */
	private function template( string $key, string $fallback, \WC_Order $order ): string {
		/**
		 * Filter an outbound SMS message body before it is sent.
		 *
		 * @param string    $fallback Rendered default copy.
		 * @param string    $key     Template key.
		 * @param \WC_Order $order   The order the message concerns.
		 */
		return (string) apply_filters( 'haramara_sms_message', $fallback, $key, $order );
	}
}
