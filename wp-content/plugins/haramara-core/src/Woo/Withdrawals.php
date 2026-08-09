<?php
/**
 * Internal withdrawals (salidas internas).
 *
 * Records product leaving inventory without a sale — Malva (the sibling
 * restaurant) taking product for their area, an employee snack, merma.
 * Each withdrawal decrements WooCommerce stock directly via
 * wc_update_product_stock() and logs one row per product line to the custom
 * table created by Setup\Activator (`{prefix}haramara_withdrawals`), lines of
 * one withdrawal sharing a group_key.
 *
 * Deliberately NOT modeled as WooCommerce orders: orders with the plugin's
 * paid statuses count as revenue in /pos/summary and the admin reports, and a
 * salida is not income. The log keeps its own name/price snapshots so history
 * survives product renames and price changes.
 *
 * @package Haramara\Core
 */

declare( strict_types=1 );

namespace Haramara\Core\Woo;

use Haramara\Core\Setup\Activator;
use Haramara\Core\Setup\Options;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Withdrawal factory + reporting reads.
 */
final class Withdrawals {

	/** Accepted withdrawal destinations, in display order. */
	public const DESTINATIONS = array( 'malva', 'empleado', 'merma', 'otro' );

	/** Sanity ceiling for a single withdrawal line (parity with WalkInOrders). */
	private const MAX_QTY = 99;

	/** Fully-qualified withdrawals table name. */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . Activator::WITHDRAWALS_TABLE;
	}

	/**
	 * Spanish display labels keyed by destination.
	 *
	 * @return array<string,string>
	 */
	public static function labels(): array {
		return array(
			'malva'    => __( 'Malva', 'haramara-core' ),
			'empleado' => __( 'Empleado', 'haramara-core' ),
			'merma'    => __( 'Merma', 'haramara-core' ),
			'otro'     => __( 'Otro', 'haramara-core' ),
		);
	}

	/**
	 * Record a withdrawal: validate every line, then decrement stock and log.
	 *
	 * Validate-all-then-apply, no SQL transaction: wc_update_product_stock()
	 * is a relative update internally, so concurrent decrements from two
	 * devices cannot lose writes; the worst race outcome is stock briefly
	 * going negative, fixed with a recount. Per line the stock decrement runs
	 * BEFORE the log insert — a decremented-but-unlogged line understates
	 * salidas, while the reverse would corrupt the stock truth.
	 *
	 * @param array<int,array{product_id:int,quantity:int}> $items       Withdrawal lines.
	 * @param string                                        $destination One of DESTINATIONS.
	 * @param string                                        $person      Optional "¿quién lo lleva?".
	 * @param string                                        $note        Optional note.
	 * @param int                                           $user_id     Staff account recording the salida.
	 * @return array<string,mixed>|\WP_Error Serialized withdrawal group, or error.
	 */
	public static function create( array $items, string $destination, string $person = '', string $note = '', int $user_id = 0 ) {
		if ( ! function_exists( 'wc_get_product' ) || ! function_exists( 'wc_update_product_stock' ) ) {
			return new \WP_Error(
				'haramara_wc_unavailable',
				__( 'WooCommerce no está disponible.', 'haramara-core' ),
				array( 'status' => 500 )
			);
		}

		if ( ! in_array( $destination, self::DESTINATIONS, true ) ) {
			return new \WP_Error(
				'haramara_invalid_destination',
				__( 'Destino de salida no válido.', 'haramara-core' ),
				array( 'status' => 400 )
			);
		}

		$lines = self::resolve_lines( $items );
		if ( is_wp_error( $lines ) ) {
			return $lines;
		}

		global $wpdb;

		$group_key = strtolower( wp_generate_password( 12, false, false ) );
		// Café timezone, NOT current_time(): for_date() slices days on this
		// column with "today" resolved in Options::timezone(), so a site clock
		// on UTC would file evening salidas under tomorrow (cf. OrderBoard::day_range()).
		$created_at = ( new \DateTimeImmutable( 'now', Options::timezone() ) )->format( 'Y-m-d H:i:s' );
		$person     = substr( sanitize_text_field( $person ), 0, 80 );
		$note       = substr( sanitize_textarea_field( $note ), 0, 200 );

		$out = array();
		foreach ( $lines as $line ) {
			$product  = $line['product'];
			$quantity = $line['quantity'];
			$price    = (float) $product->get_price();

			wc_update_product_stock( $product, $quantity, 'decrease' );

			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				self::table(),
				array(
					'created_at'   => $created_at,
					'group_key'    => $group_key,
					'destination'  => $destination,
					'person'       => $person,
					'note'         => $note,
					'product_id'   => $product->get_id(),
					'product_name' => substr( $product->get_name(), 0, 200 ),
					'quantity'     => $quantity,
					'unit_price'   => $price,
					'user_id'      => $user_id,
				),
				array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%f', '%d' )
			);

			$out[] = array(
				'product_id' => $product->get_id(),
				'name'       => $product->get_name(),
				'quantity'   => $quantity,
				'unit_price' => $price,
				'value'      => round( $price * $quantity, 2 ),
			);
		}

		$labels = self::labels();

		return array(
			'key'               => $group_key,
			'created_at'        => $created_at,
			'destination'       => $destination,
			'destination_label' => $labels[ $destination ],
			'person'            => $person,
			'note'              => $note,
			'registered_by'     => self::display_name( $user_id ),
			'items'             => $out,
			'total_quantity'    => array_sum( array_column( $out, 'quantity' ) ),
			'total_value'       => round( array_sum( array_column( $out, 'value' ) ), 2 ),
		);
	}

	/**
	 * All withdrawals for a café-local calendar day, newest first, grouped.
	 *
	 * @param string $date Y-m-d in the café timezone (see PosRoutes::requested_date()).
	 * @return array<int,array<string,mixed>>
	 */
	public static function for_date( string $date ): array {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE created_at >= %s AND created_at < %s ORDER BY id DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$date . ' 00:00:00',
				gmdate( 'Y-m-d', strtotime( $date . ' +1 day' ) ) . ' 00:00:00'
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) || empty( $rows ) ) {
			return array();
		}

		$labels = self::labels();
		$groups = array();

		foreach ( $rows as $row ) {
			$key   = (string) $row['group_key'];
			$value = round( (float) $row['unit_price'] * (int) $row['quantity'], 2 );

			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array(
					'key'               => $key,
					'created_at'        => (string) $row['created_at'],
					'destination'       => (string) $row['destination'],
					'destination_label' => $labels[ $row['destination'] ] ?? (string) $row['destination'],
					'person'            => (string) $row['person'],
					'note'              => (string) $row['note'],
					'registered_by'     => self::display_name( (int) $row['user_id'] ),
					'items'             => array(),
					'total_quantity'    => 0,
					'total_value'       => 0.0,
				);
			}

			$groups[ $key ]['items'][]         = array(
				'product_id' => (int) $row['product_id'],
				'name'       => (string) $row['product_name'],
				'quantity'   => (int) $row['quantity'],
				'unit_price' => (float) $row['unit_price'],
				'value'      => $value,
			);
			$groups[ $key ]['total_quantity'] += (int) $row['quantity'];
			$groups[ $key ]['total_value']     = round( $groups[ $key ]['total_value'] + $value, 2 );
		}

		return array_values( $groups );
	}

	/**
	 * Per-destination piece counts and valuation for a day. Valued at the
	 * price snapshot taken when each salida was recorded — never revenue.
	 *
	 * @param string $date Y-m-d in the café timezone.
	 * @return array{pieces:int,value:float,by_destination:array<string,array{pieces:int,value:float}>}
	 */
	public static function summary_for_date( string $date ): array {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT destination, SUM(quantity) AS pieces, SUM(quantity * unit_price) AS total FROM {$table} WHERE created_at >= %s AND created_at < %s GROUP BY destination", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$date . ' 00:00:00',
				gmdate( 'Y-m-d', strtotime( $date . ' +1 day' ) ) . ' 00:00:00'
			),
			ARRAY_A
		);

		$by_destination = array();
		$pieces         = 0;
		$value          = 0.0;

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$dest_pieces = (int) $row['pieces'];
			$dest_value  = round( (float) $row['total'], 2 );

			$by_destination[ (string) $row['destination'] ] = array(
				'pieces' => $dest_pieces,
				'value'  => $dest_value,
			);

			$pieces += $dest_pieces;
			$value   = round( $value + $dest_value, 2 );
		}

		// Stable destination order for clients.
		$ordered = array();
		foreach ( self::DESTINATIONS as $destination ) {
			if ( isset( $by_destination[ $destination ] ) ) {
				$ordered[ $destination ] = $by_destination[ $destination ];
			}
		}

		return array(
			'pieces'         => $pieces,
			'value'          => $value,
			'by_destination' => $ordered + $by_destination,
		);
	}

	/**
	 * Validate the requested lines against catalog + managed stock.
	 *
	 * Mirrors WalkInOrders::resolve_lines() with two deliberate differences:
	 * no is_purchasable() gate (merma of a temporarily unpurchasable product
	 * must still be recordable) and stock managed is required — an unmanaged
	 * product has no count to decrement, so a salida would be meaningless.
	 *
	 * @param array<int,array{product_id:int,quantity:int}> $items Withdrawal lines.
	 * @return array<int,array{product:\WC_Product,quantity:int}>|\WP_Error
	 */
	private static function resolve_lines( array $items ) {
		if ( empty( $items ) ) {
			return new \WP_Error(
				'haramara_empty_withdrawal',
				__( 'La salida no tiene artículos.', 'haramara-core' ),
				array( 'status' => 400 )
			);
		}

		// Merge duplicate product ids so the stock check sees the real total.
		$wanted = array();
		foreach ( $items as $item ) {
			$product_id = (int) ( $item['product_id'] ?? 0 );
			$quantity   = (int) ( $item['quantity'] ?? 0 );

			if ( $product_id <= 0 || $quantity <= 0 ) {
				return new \WP_Error(
					'haramara_invalid_line',
					__( 'Artículo o cantidad no válidos.', 'haramara-core' ),
					array( 'status' => 400 )
				);
			}

			$wanted[ $product_id ] = ( $wanted[ $product_id ] ?? 0 ) + $quantity;
		}

		$lines = array();
		foreach ( $wanted as $product_id => $quantity ) {
			if ( $quantity > self::MAX_QTY ) {
				return new \WP_Error(
					'haramara_invalid_line',
					__( 'Artículo o cantidad no válidos.', 'haramara-core' ),
					array( 'status' => 400 )
				);
			}

			$product = wc_get_product( $product_id );
			if ( ! $product instanceof \WC_Product || 'publish' !== $product->get_status() ) {
				return new \WP_Error(
					'haramara_unknown_product',
					sprintf(
						/* translators: %d: product id. */
						__( 'El producto %d no está disponible.', 'haramara-core' ),
						$product_id
					),
					array( 'status' => 400 )
				);
			}

			if ( ! $product->managing_stock() ) {
				return new \WP_Error(
					'haramara_stock_unmanaged',
					sprintf(
						/* translators: %s: product name. */
						__( '"%s" no maneja inventario.', 'haramara-core' ),
						$product->get_name()
					),
					array( 'status' => 400 )
				);
			}

			$stock = $product->get_stock_quantity();
			if ( null !== $stock && (int) $stock < $quantity ) {
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

	/**
	 * Display name for a staff user id, or '' when unresolvable.
	 *
	 * @param int $user_id Staff user id (0 = unknown).
	 */
	private static function display_name( int $user_id ): string {
		if ( $user_id <= 0 ) {
			return '';
		}

		$user = get_userdata( $user_id );

		return $user ? (string) $user->display_name : '';
	}
}
