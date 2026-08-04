<?php
/**
 * POS walk-in (counter) sales.
 *
 * Creates an already-paid-and-delivered WooCommerce order for a vitrina sale so
 * stock and daily totals stay unified with online pickup orders. Walk-ins are
 * deliberately different from pickup orders in two load-bearing ways:
 *
 * - They NEVER carry `_haramara_pickup_date` meta — that key is what
 *   PickupScheduler::slot_counts() counts, so setting it would consume online
 *   slot capacity.
 * - They NEVER carry a billing phone — the customer is standing at the counter,
 *   and a phone would trigger the WhatsApp status notifications.
 *
 * @package Haramara\Core
 */

declare( strict_types=1 );

namespace Haramara\Core\Ordering;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Counter-sale order factory.
 */
final class WalkInOrders {

	/** Marks the order as a POS counter sale (order `created_via`). */
	public const CREATED_VIA = 'haramara-pos';

	/** How the customer paid at the counter (order meta). */
	public const META_PAYMENT = '_haramara_pos_payment';

	/** Accepted counter payment kinds. */
	public const PAYMENTS = array( 'cash', 'card_external' );

	/** Sanity ceiling for a single counter line. */
	private const MAX_QTY = 99;

	/**
	 * Create a completed walk-in order from counter line items.
	 *
	 * @param array<int,array{product_id:int,quantity:int}> $items   Sale lines.
	 * @param string                                        $payment One of PAYMENTS ('card-external' accepted as alias).
	 * @param string                                        $note    Optional private note for the order.
	 * @return \WC_Order|\WP_Error
	 */
	public static function create( array $items, string $payment, string $note = '' ) {
		if ( ! function_exists( 'wc_create_order' ) || ! function_exists( 'wc_get_product' ) ) {
			return new \WP_Error(
				'haramara_wc_unavailable',
				__( 'WooCommerce no está disponible.', 'haramara-core' ),
				array( 'status' => 500 )
			);
		}

		$payment = str_replace( '-', '_', sanitize_key( $payment ) );
		if ( ! in_array( $payment, self::PAYMENTS, true ) ) {
			return new \WP_Error(
				'haramara_invalid_payment',
				__( 'Forma de pago no válida. Usa "cash" o "card_external".', 'haramara-core' ),
				array( 'status' => 400 )
			);
		}

		$lines = self::resolve_lines( $items );
		if ( is_wp_error( $lines ) ) {
			return $lines;
		}

		$order = wc_create_order(
			array(
				'created_via' => self::CREATED_VIA,
				'customer_id' => 0,
			)
		);
		if ( is_wp_error( $order ) ) {
			return $order;
		}

		foreach ( $lines as $line ) {
			$order->add_product( $line['product'], $line['quantity'] );
		}

		$order->set_payment_method( 'cod' );
		$order->set_payment_method_title(
			'cash' === $payment
				? __( 'Efectivo en mostrador', 'haramara-core' )
				: __( 'Tarjeta (terminal externa)', 'haramara-core' )
		);
		$order->update_meta_data( self::META_PAYMENT, $payment );
		$order->calculate_totals();

		if ( '' !== trim( $note ) ) {
			$order->add_order_note( sanitize_textarea_field( $note ) );
		}

		// Paid and handed over at the counter: `completed` reduces stock and
		// counts as revenue, and — with no pickup meta — stays invisible to the
		// slot-capacity math and the pickups board.
		$order->update_status( 'completed', __( 'Venta de mostrador registrada desde el POS.', 'haramara-core' ) );

		return $order;
	}

	/**
	 * Validate the requested lines against catalog + stock.
	 *
	 * @param array<int,array{product_id:int,quantity:int}> $items Sale lines.
	 * @return array<int,array{product:\WC_Product,quantity:int}>|\WP_Error
	 */
	private static function resolve_lines( array $items ) {
		if ( empty( $items ) ) {
			return new \WP_Error(
				'haramara_empty_sale',
				__( 'La venta no tiene artículos.', 'haramara-core' ),
				array( 'status' => 400 )
			);
		}

		$lines = array();
		foreach ( $items as $item ) {
			$product_id = (int) ( $item['product_id'] ?? 0 );
			$quantity   = (int) ( $item['quantity'] ?? 0 );

			if ( $product_id <= 0 || $quantity <= 0 || $quantity > self::MAX_QTY ) {
				return new \WP_Error(
					'haramara_invalid_line',
					__( 'Artículo o cantidad no válidos.', 'haramara-core' ),
					array( 'status' => 400 )
				);
			}

			$product = wc_get_product( $product_id );
			if ( ! $product instanceof \WC_Product || 'publish' !== $product->get_status() || ! $product->is_purchasable() ) {
				return new \WP_Error(
					'haramara_unknown_product',
					sprintf(
						/* translators: %d: product id. */
						__( 'El producto %d no está disponible para la venta.', 'haramara-core' ),
						$product_id
					),
					array( 'status' => 400 )
				);
			}

			if ( ! $product->is_in_stock() ) {
				return new \WP_Error(
					'haramara_out_of_stock',
					sprintf(
						/* translators: %s: product name. */
						__( '"%s" está agotado.', 'haramara-core' ),
						$product->get_name()
					),
					array( 'status' => 409 )
				);
			}

			$stock = $product->get_stock_quantity();
			if ( $product->managing_stock() && ! $product->backorders_allowed() && null !== $stock && (int) $stock < $quantity ) {
				return new \WP_Error(
					'haramara_insufficient_stock',
					sprintf(
						/* translators: 1: product name, 2: units in stock. */
						__( 'Solo quedan %2$d de "%1$s".', 'haramara-core' ),
						$product->get_name(),
						(int) $stock
					),
					array( 'status' => 409 )
				);
			}

			$lines[] = array(
				'product'  => $product,
				'quantity' => $quantity,
			);
		}

		return $lines;
	}
}
