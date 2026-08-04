<?php
/**
 * Surfaces the pickup date/time everywhere an order is displayed: admin order
 * screen, order-received/thank-you, My Account order view, and emails.
 *
 * @package Haramara\Core
 */

declare( strict_types=1 );

namespace Haramara\Core\Ordering;

use Haramara\Core\Contracts\Bootable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class OrderMeta implements Bootable {

	public function boot(): void {
		// Admin order edit screen.
		add_action( 'woocommerce_admin_order_data_after_order_details', array( $this, 'render_admin' ) );

		// Order-received (thank-you) + My Account order view share this hook.
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'render_frontend' ) );

		// Order emails.
		add_action( 'woocommerce_email_after_order_table', array( $this, 'render_email' ), 10, 4 );
	}

	/* ---------------------------------------------------------------------- */
	/* Helper */
	/* ---------------------------------------------------------------------- */

	/**
	 * The stored pickup label, or a rebuilt one from date + slot as a fallback.
	 */
	public static function label( \WC_Order $order ): string {
		$label = (string) $order->get_meta( PickupScheduler::META_LABEL );
		if ( '' !== $label ) {
			return $label;
		}

		$date = (string) $order->get_meta( PickupScheduler::META_DATE );
		$slot = (string) $order->get_meta( PickupScheduler::META_SLOT );
		if ( '' === $date && '' === $slot ) {
			return '';
		}
		return PickupScheduler::build_label( $date, $slot );
	}

	/* ---------------------------------------------------------------------- */
	/* Renderers */
	/* ---------------------------------------------------------------------- */

	/**
	 * Admin order screen block.
	 *
	 * @param \WC_Order $order
	 */
	public function render_admin( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$label = self::label( $order );
		if ( '' === $label ) {
			return;
		}
		echo '<div class="haramara-pickup-admin"><p><strong>';
		echo esc_html__( 'Recolección:', 'haramara-core' );
		echo '</strong><br>';
		echo esc_html( $label );
		echo '</p></div>';
	}

	/**
	 * Front-end order details (thank-you page + My Account view-order).
	 *
	 * @param \WC_Order $order
	 */
	public function render_frontend( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$label = self::label( $order );
		if ( '' === $label ) {
			return;
		}
		echo '<section class="haramara-pickup-details woocommerce-order-details">';
		echo '<h2 class="woocommerce-order-details__title">' . esc_html__( 'Recolección en tienda', 'haramara-core' ) . '</h2>';
		echo '<p>' . esc_html__( 'Fecha y horario:', 'haramara-core' ) . ' <strong>' . esc_html( $label ) . '</strong></p>';
		echo '</section>';
	}

	/**
	 * Order emails (customer + admin).
	 *
	 * @param \WC_Order      $order
	 * @param bool           $sent_to_admin
	 * @param bool           $plain_text
	 * @param \WC_Email|null $email
	 */
	public function render_email( $order, $sent_to_admin = false, $plain_text = false, $email = null ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$label = self::label( $order );
		if ( '' === $label ) {
			return;
		}

		if ( $plain_text ) {
			echo "\n" . esc_html__( 'Recolección en tienda', 'haramara-core' ) . "\n";
			echo esc_html__( 'Fecha y horario:', 'haramara-core' ) . ' ' . esc_html( $label ) . "\n";
			return;
		}

		echo '<div style="margin-bottom:24px;">';
		echo '<h2 style="color:#2E2016;font-family:serif;">' . esc_html__( 'Recolección en tienda', 'haramara-core' ) . '</h2>';
		echo '<p style="font-size:15px;">' . esc_html__( 'Fecha y horario:', 'haramara-core' ) . ' <strong>' . esc_html( $label ) . '</strong></p>';
		echo '</div>';
	}
}
