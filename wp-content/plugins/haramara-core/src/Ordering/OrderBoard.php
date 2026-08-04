<?php
/**
 * Operations board queries and serialization.
 *
 * One source for "which orders does the counter care about today": the pickup
 * orders for a date (grouped by slot) plus that day's POS walk-in sales. Shared
 * by the wp-admin dashboard and the POS REST surface so the two can never
 * disagree. All queries are HPOS-safe (wc_get_orders + CRUD meta).
 *
 * @package Haramara\Core
 */

declare( strict_types=1 );

namespace Haramara\Core\Ordering;

use Haramara\Core\Setup\Options;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Board queries + staff serialization.
 */
final class OrderBoard {

	/** Statuses shown on the operations board. */
	public const BOARD_STATUSES = array( 'processing', 'queued', 'preparing', 'ready', 'on-hold' );

	/** Extra statuses included when the caller asks for the day's history. */
	public const DONE_STATUSES = array( 'completed' );

	/**
	 * Orders scheduled for a given pickup date, sorted by slot.
	 *
	 * @param string $date         Pickup date (YYYY-MM-DD).
	 * @param bool   $include_done Also include the day's completed orders.
	 * @return array<int,\WC_Order>
	 */
	public static function orders_for_date( string $date, bool $include_done = false ): array {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}

		$statuses = $include_done
			? array_merge( self::BOARD_STATUSES, self::DONE_STATUSES )
			: self::BOARD_STATUSES;

		$orders = wc_get_orders(
			array(
				'limit'      => -1,
				'status'     => $statuses,
				'meta_query' => array(
					array(
						'key'     => PickupScheduler::META_DATE,
						'value'   => $date,
						'compare' => '=',
					),
				),
			)
		);
		$orders = is_array( $orders ) ? array_filter( $orders, static fn( $o ): bool => $o instanceof \WC_Order ) : array();

		usort(
			$orders,
			static fn( \WC_Order $a, \WC_Order $b ): int => strcmp(
				(string) $a->get_meta( PickupScheduler::META_SLOT ),
				(string) $b->get_meta( PickupScheduler::META_SLOT )
			)
		);

		return array_values( $orders );
	}

	/**
	 * Online orders awaiting POS acceptance, oldest first — every paid
	 * `processing` order regardless of pickup date. Walk-ins never queue (they
	 * are created `completed` at the counter) but are filtered defensively.
	 *
	 * @return array<int,\WC_Order>
	 */
	public static function incoming_orders(): array {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}

		$orders = wc_get_orders(
			array(
				'limit'   => -1,
				'status'  => array( 'processing' ),
				'orderby' => 'date',
				'order'   => 'ASC',
			)
		);
		$orders = is_array( $orders ) ? $orders : array();

		return array_values(
			array_filter(
				$orders,
				static fn( $o ): bool => $o instanceof \WC_Order && WalkInOrders::CREATED_VIA !== $o->get_created_via()
			)
		);
	}

	/**
	 * POS walk-in sales created on a given day.
	 *
	 * @param string $date Sale date (YYYY-MM-DD, bakery timezone).
	 * @return array<int,\WC_Order>
	 */
	public static function walk_ins_for_date( string $date ): array {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}

		$orders = wc_get_orders(
			array(
				'limit'        => -1,
				'status'       => array( 'completed' ),
				'created_via'  => WalkInOrders::CREATED_VIA,
				'date_created' => self::day_range( $date ),
			)
		);

		return is_array( $orders ) ? array_values( array_filter( $orders, static fn( $o ): bool => $o instanceof \WC_Order ) ) : array();
	}

	/**
	 * One bakery-local day as an epoch-timestamp range for `date_created`
	 * queries. A plain YYYY-MM-DD would be read in the SITE timezone, which can
	 * disagree with the bakery timezone around midnight; timestamps can't.
	 *
	 * @param string $date Day in the bakery timezone (YYYY-MM-DD).
	 */
	public static function day_range( string $date ): string {
		$start = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date, Options::timezone() );
		if ( false === $start ) {
			return $date; // Malformed input: fall back to WooCommerce's parsing.
		}
		$end = $start->modify( '+1 day' );
		return $start->getTimestamp() . '...' . ( $end->getTimestamp() - 1 );
	}

	/**
	 * Full board payload for a date: pickup orders grouped by slot, the day's
	 * walk-ins, and attention counts.
	 *
	 * @param string $date         Pickup date (YYYY-MM-DD).
	 * @param bool   $include_done Also include the day's completed orders.
	 * @return array{date:string,slots:array<int,array{slot:string,orders:array<int,array<string,mixed>>}>,walk_ins:array<int,array<string,mixed>>,counts:array{pending_prep:int,ready:int,walk_ins:int}}
	 */
	public static function for_date( string $date, bool $include_done = false ): array {
		$orders   = self::orders_for_date( $date, $include_done );
		$walk_ins = self::walk_ins_for_date( $date );

		$by_slot      = array();
		$pending_prep = 0;
		$ready        = 0;
		foreach ( $orders as $order ) {
			$slot = (string) $order->get_meta( PickupScheduler::META_SLOT );
			if ( ! isset( $by_slot[ $slot ] ) ) {
				$by_slot[ $slot ] = array();
			}
			$by_slot[ $slot ][] = self::serialize_order( $order );

			$status = $order->get_status();
			if ( in_array( $status, array( 'processing', 'queued', 'preparing' ), true ) ) {
				++$pending_prep;
			}
			if ( 'ready' === $status ) {
				++$ready;
			}
		}
		ksort( $by_slot );

		$slots = array();
		foreach ( $by_slot as $slot => $slot_orders ) {
			$slots[] = array(
				'slot'   => (string) $slot,
				'orders' => $slot_orders,
			);
		}

		return array(
			'date'     => $date,
			'slots'    => $slots,
			'walk_ins' => array_map( array( self::class, 'serialize_order' ), $walk_ins ),
			'counts'   => array(
				'pending_prep' => $pending_prep,
				'ready'        => $ready,
				'walk_ins'     => count( $walk_ins ),
			),
		);
	}

	/**
	 * Staff-facing order payload (POS board rows, transition responses).
	 *
	 * @param \WC_Order $order The order to serialize.
	 * @return array<string,mixed>
	 */
	public static function serialize_order( \WC_Order $order ): array {
		$items = array();
		foreach ( $order->get_items() as $item ) {
			$items[] = array(
				'name'     => $item->get_name(),
				'quantity' => (int) $item->get_quantity(),
				'total'    => (float) $order->get_line_total( $item, true ),
			);
		}

		$customer = trim( $order->get_formatted_billing_full_name() );
		$created  = $order->get_date_created();

		return array(
			'id'                   => $order->get_id(),
			'number'               => (string) $order->get_order_number(),
			'status'               => $order->get_status(),
			'status_label'         => function_exists( 'wc_get_order_status_name' )
				? (string) wc_get_order_status_name( $order->get_status() )
				: $order->get_status(),
			'customer'             => '' !== $customer ? $customer : __( 'Invitado', 'haramara-core' ),
			'phone'                => (string) $order->get_billing_phone(),
			'items'                => $items,
			'total'                => (float) $order->get_total(),
			'currency'             => $order->get_currency(),
			'payment_method'       => $order->get_payment_method(),
			'payment_method_title' => $order->get_payment_method_title(),
			'note'                 => (string) $order->get_customer_note(),
			'created_via'          => $order->get_created_via(),
			'created_at'           => $created ? $created->date( DATE_ATOM ) : null,
			'pickup'               => array(
				'date'  => (string) $order->get_meta( PickupScheduler::META_DATE ),
				'slot'  => (string) $order->get_meta( PickupScheduler::META_SLOT ),
				'label' => (string) $order->get_meta( PickupScheduler::META_LABEL ),
			),
			'allowed_transitions'  => StatusTransitions::allowed_for( $order ),
		);
	}
}
