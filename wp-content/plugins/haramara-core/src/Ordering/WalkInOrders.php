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

use Haramara\Core\Catalog\ModifierApplication;
use Haramara\Core\Setup\Options;
use Haramara\Core\Staff\Operators;

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

	/** Who rang the sale — operator key and display name (order meta). */
	public const META_OPERATOR_KEY  = '_haramara_pos_operator_key';
	public const META_OPERATOR_NAME = '_haramara_pos_operator_name';

	/**
	 * Propina (order meta). Deliberately NEVER a line on the order: a tip is
	 * pass-through to staff, not the business's sale, so keeping it off the
	 * WooCommerce total keeps revenue honest in every report downstream. The
	 * arqueo adds CASH tips to expected drawer cash (Shifts::cash_tips), and
	 * the corte reports tips per employee from these keys.
	 */
	public const META_TIP        = '_haramara_pos_tip';
	public const META_TIP_METHOD = '_haramara_pos_tip_method';

	/** How a tip was handed over. Cash lands in the drawer; card rides the terminal batch. */
	public const TIP_METHODS = array( 'cash', 'card' );

	/**
	 * External-terminal reference (order meta). The Clip/Point/bank terminal
	 * prints an auth/reference number with every charge; capturing it is what
	 * lets the day's card orders reconcile against the terminal batch by hand
	 * until a real terminal integration exists.
	 */
	public const META_CARD_REF = '_haramara_pos_card_ref';

	/** Accepted counter payment kinds. */
	public const PAYMENTS = array( 'cash', 'card_external' );

	/** Sanity ceiling for a single counter line. */
	private const MAX_QTY = 99;

	/**
	 * Create a completed walk-in order from counter line items.
	 *
	 * @param array<int,array{product_id:int,quantity:int,modifiers?:array<int,array<string,mixed>>}> $items Sale lines.
	 * @param string                                        $payment  One of PAYMENTS ('card-external' accepted as alias).
	 * @param string                                        $note     Optional private note for the order.
	 * @param array{key?:string,name?:string,role?:string}  $operator Who rang it, from Staff\Operators. Empty when
	 *                                                                the tablet sent no operator (older app build).
	 * @param array{amount?:float,reason_code?:string,reason_note?:string,authorized_by?:string} $discount
	 *                  Pre-validated sale discount (PosRoutes owns the threshold +
	 *                  authorization policy). amount is MXN off the ticket.
	 * @param array{amount?:float,method?:string} $tip Propina. Stored as meta only —
	 *                  never a line, never revenue. method defaults to the payment
	 *                  kind (cash sale → cash tip).
	 * @param array{stock_already_reserved?:bool} $flags stock_already_reserved: the
	 *                  caller (Ordering\Tabs) took the stock at serve-time, so
	 *                  availability is neither re-checked nor re-decremented —
	 *                  `_order_stock_reduced` is pre-set so WooCommerce's
	 *                  completed-status reduction skips, and a later cancel
	 *                  restores correctly.
	 * @return \WC_Order|\WP_Error
	 */
	public static function create( array $items, string $payment, string $note = '', array $operator = array(), array $discount = array(), array $tip = array(), array $flags = array(), string $card_reference = '' ) {
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

		$stock_reserved = ! empty( $flags['stock_already_reserved'] );

		$lines = self::resolve_lines( $items, $stock_reserved );
		if ( is_wp_error( $lines ) ) {
			return $lines;
		}

		// Validate every line's modifier selections before any order exists —
		// a bad selection must never leave a half-built completed order.
		// validate() also enforces REQUIRED groups on lines that sent none.
		foreach ( $lines as $i => $line ) {
			$validated = ModifierApplication::validate(
				$line['product']->get_id(),
				array_values( (array) ( $items[ $i ]['modifiers'] ?? array() ) )
			);
			if ( is_wp_error( $validated ) ) {
				return $validated;
			}
			$lines[ $i ]['modifiers'] = $validated;
		}

		// ---- Discount policy (before any order exists) --------------------
		// The threshold is % of the pre-discount ticket; above it, a
		// supervisor authorization bound to `discount` is mandatory. A comp
		// (100%) is allowed; a discount that would make the ticket negative
		// is not. All of it enforced HERE so no route can forget it.
		$discount_amount = round( (float) ( $discount['amount'] ?? 0 ), 2 );
		$discount_reason = sanitize_key( (string) ( $discount['reason_code'] ?? '' ) );
		$discount_note   = sanitize_textarea_field( (string) ( $discount['reason_note'] ?? '' ) );
		$authorized_by   = '';

		// ---- Tip validation (meta-only, so this is shape, not policy) ------
		$tip_amount = round( (float) ( $tip['amount'] ?? 0 ), 2 );
		$tip_method = sanitize_key( (string) ( $tip['method'] ?? '' ) );
		if ( '' === $tip_method ) {
			$tip_method = 'cash' === str_replace( '-', '_', sanitize_key( $payment ) ) ? 'cash' : 'card';
		}
		if ( $tip_amount < 0 || $tip_amount > 100000 || ( $tip_amount > 0 && ! in_array( $tip_method, self::TIP_METHODS, true ) ) ) {
			return new \WP_Error(
				'haramara_invalid_tip',
				__( 'Propina no válida.', 'haramara-core' ),
				array( 'status' => 400 )
			);
		}

		if ( $discount_amount < 0 || $discount_amount > 100000 ) {
			return new \WP_Error(
				'haramara_invalid_discount',
				__( 'Descuento no válido.', 'haramara-core' ),
				array( 'status' => 400 )
			);
		}

		if ( $discount_amount > 0 ) {
			if ( ! in_array( $discount_reason, PosEvents::REASONS, true ) ) {
				return new \WP_Error(
					'haramara_invalid_reason',
					__( 'Motivo de descuento no válido.', 'haramara-core' ),
					array( 'status' => 400 )
				);
			}
			if ( 'otro' === $discount_reason && '' === trim( $discount_note ) ) {
				return new \WP_Error(
					'haramara_reason_note_required',
					__( 'Con motivo "Otro" es obligatorio escribir el detalle.', 'haramara-core' ),
					array( 'status' => 400 )
				);
			}

			$items_total = 0.0;
			foreach ( $lines as $line ) {
				$items_total += (float) $line['product']->get_price() * $line['quantity']
					+ ModifierApplication::price_delta( $line['modifiers'] ) * $line['quantity'];
			}

			if ( $discount_amount > $items_total + 0.005 ) {
				return new \WP_Error(
					'haramara_discount_too_big',
					__( 'El descuento no puede ser mayor que la cuenta.', 'haramara-core' ),
					array( 'status' => 400 )
				);
			}

			$pct       = $items_total > 0 ? ( $discount_amount / $items_total ) * 100 : 100.0;
			$threshold = (int) Options::get( Options::POS, 'discount_supervisor_pct', 15 );

			if ( $pct > $threshold ) {
				$authorization = (string) ( $discount['authorization'] ?? '' );
				if ( '' === trim( $authorization ) ) {
					return new \WP_Error(
						'haramara_authorization_required',
						sprintf(
							/* translators: %d: percent threshold. */
							__( 'Un descuento mayor al %d%% necesita autorización de un supervisor.', 'haramara-core' ),
							$threshold
						),
						array( 'status' => 403 )
					);
				}
				$supervisor = Operators::check_authorization( $authorization, 'discount' );
				if ( is_wp_error( $supervisor ) ) {
					return $supervisor;
				}
				$authorized_by = (string) $supervisor['name'];
			}
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

		$item_ids = array();
		foreach ( $lines as $i => $line ) {
			$item_ids[ $i ] = $order->add_product( $line['product'], $line['quantity'] );
		}

		// Mutate the order's OWN item instances — never a get_item() re-read.
		// get_item() can return a fresh copy while calculate_totals() sums the
		// in-memory collection, so a delta applied to the copy saves to the DB
		// and then gets overwritten by the stale total (observed live: item 80,
		// order 65).
		$order_items = $order->get_items();
		foreach ( $lines as $i => $line ) {
			if ( array() === $line['modifiers'] ) {
				continue;
			}

			$item = $order_items[ $item_ids[ $i ] ] ?? null;
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}

			ModifierApplication::apply( $item, $line['modifiers'] );

			// price_delta() is per unit; scale by the line quantity. The
			// calculate_totals() below sums these in-memory line totals, so the
			// delta flows into the order total, summary revenue, and corte;
			// the order's save persists the items — no per-item save() needed.
			$delta = ModifierApplication::price_delta( $line['modifiers'] ) * $line['quantity'];
			if ( 0.0 !== $delta ) {
				$item->set_subtotal( (string) ( (float) $item->get_subtotal() + $delta ) );
				$item->set_total( (string) ( (float) $item->get_total() + $delta ) );
			}
		}

		// Sale discount: a negative fee line, so it is visible on the order,
		// subtracts from revenue honestly, and never rewrites product prices.
		// The ledger row is the caller's job (needs shift + operator context).
		if ( $discount_amount > 0 ) {
			$fee = new \WC_Order_Item_Fee();
			$fee->set_name(
				sprintf(
					/* translators: %s: reason code. */
					__( 'Descuento (%s)', 'haramara-core' ),
					$discount_reason
				)
			);
			$fee->set_amount( (string) ( -$discount_amount ) );
			$fee->set_total( (string) ( -$discount_amount ) );
			$order->add_item( $fee );
		}

		$order->set_payment_method( 'cod' );
		$order->set_payment_method_title(
			'cash' === $payment
				? __( 'Efectivo en mostrador', 'haramara-core' )
				: __( 'Tarjeta (terminal externa)', 'haramara-core' )
		);
		$order->update_meta_data( self::META_PAYMENT, $payment );

		// Attribution. Stamped on the order for convenience; the durable audit
		// record for adjustments lives in the append-only POS event log, since
		// order meta is editable from wp-admin.
		$operator_key  = sanitize_key( (string) ( $operator['key'] ?? '' ) );
		$operator_name = mb_substr( sanitize_text_field( (string) ( $operator['name'] ?? '' ) ), 0, 80 );
		if ( '' !== $operator_key ) {
			$order->update_meta_data( self::META_OPERATOR_KEY, $operator_key );
			$order->update_meta_data( self::META_OPERATOR_NAME, $operator_name );
		}

		// Stamp the open shift so the arqueo's cash math is exact. A sale rung
		// while no turno is open carries no stamp and never inflates a later
		// count — the gap surfaces in the corte instead, where it belongs.
		$shift = Shifts::current();
		if ( null !== $shift ) {
			$order->update_meta_data( Shifts::META_SHIFT_ID, (string) (int) $shift['id'] );
		}

		// Folio is derived from the order id (Folios::for_order); this meta is
		// only the searchable mirror for wp-admin. Stamped after the first
		// save has assigned the id — update_status below persists it.
		$order->update_meta_data( Folios::META_FOLIO, Folios::for_order( $order->get_id() ) );

		if ( $tip_amount > 0 ) {
			$order->update_meta_data( self::META_TIP, (string) $tip_amount );
			$order->update_meta_data( self::META_TIP_METHOD, $tip_method );
		}

		$card_reference = substr( sanitize_text_field( $card_reference ), 0, 40 );
		if ( '' !== $card_reference ) {
			$order->update_meta_data( self::META_CARD_REF, $card_reference );
		}

		$order->calculate_totals();

		if ( '' !== trim( $note ) ) {
			$order->add_order_note( sanitize_textarea_field( $note ) );
		}

		if ( $stock_reserved ) {
			// The tab already decremented stock line by line at serve-time.
			// This flag is what wc_maybe_reduce_stock_levels checks before
			// reducing on `completed` — and what makes a later cancellation
			// restore stock, keeping the whole lifecycle coherent.
			$order->update_meta_data( '_order_stock_reduced', 'true' );
		}

		// Paid and handed over at the counter: `completed` reduces stock and
		// counts as revenue, and — with no pickup meta — stays invisible to the
		// slot-capacity math and the pickups board.
		$order->update_status(
			'completed',
			'' === $operator_name
				? __( 'Venta de mostrador registrada desde el POS.', 'haramara-core' )
				: sprintf(
					/* translators: %s: operator name. */
					__( 'Venta de mostrador registrada desde el POS por %s.', 'haramara-core' ),
					$operator_name
				)
		);

		// The discount's audit row — order meta can be edited from wp-admin,
		// the ledger cannot. A comp is a discount that zeroed the ticket.
		if ( $discount_amount > 0 ) {
			$shift = Shifts::current();
			PosEvents::record(
				array(
					'type'          => (float) $order->get_total() <= 0.005 ? 'comp' : 'discount',
					'shift_id'      => null !== $shift ? (int) $shift['id'] : 0,
					'operator'      => $operator,
					'authorized_by' => $authorized_by,
					'order_id'      => $order->get_id(),
					'amount'        => $discount_amount,
					'reason_code'   => $discount_reason,
					'reason_note'   => $discount_note,
				)
			);
		}

		return $order;
	}

	/**
	 * Validate the requested lines against catalog + stock.
	 *
	 * @param array<int,array{product_id:int,quantity:int}> $items Sale lines.
	 * @return array<int,array{product:\WC_Product,quantity:int}>|\WP_Error
	 */
	private static function resolve_lines( array $items, bool $skip_stock_checks = false ) {
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

			if ( ! $skip_stock_checks && ! $product->is_in_stock() ) {
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
			if ( ! $skip_stock_checks && $product->managing_stock() && ! $product->backorders_allowed() && null !== $stock && (int) $stock < $quantity ) {
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
