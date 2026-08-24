<?php
/**
 * Append-only POS audit-event ledger.
 *
 * Every movement of money outside a plain sale — cash drops now; voids,
 * refunds, discounts, cortesías, no-sale drawer opens, and reprints in the
 * adjustments phase — lands here as one immutable row: who, what, how much,
 * why, in which shift. Deliberately NOT WooCommerce orders or order meta:
 * meta is editable by anyone with wp-admin, and this table is the artifact
 * that proves an adjustment happened. There is no update or delete API on
 * purpose, and rows are never pruned.
 *
 * `order_id` is nullable and paired with `tab_id`: open-tab line voids (a
 * later phase — tabs are not WC orders until close) belong in the same
 * ledger even though no order exists yet.
 *
 * @package Haramara\Core
 */

declare( strict_types=1 );

namespace Haramara\Core\Ordering;

use Haramara\Core\Setup\Activator;
use Haramara\Core\Setup\Options;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Writer + reporting reads for the POS event ledger.
 */
final class PosEvents {

	/** Event types, in the order the corte reports them. */
	public const TYPES = array( 'void', 'refund', 'discount', 'comp', 'cash_drop', 'no_sale', 'reprint' );

	/** Reason codes offered by the POS; `otro` requires a note. */
	public const REASONS = array( 'error_captura', 'cliente_cancelo', 'producto_mal_hecho', 'cortesia', 'ajuste_precio', 'retiro_efectivo', 'otro' );

	/** Fully-qualified events table name. */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . Activator::EVENTS_TABLE;
	}

	/**
	 * Spanish display labels keyed by event type.
	 *
	 * @return array<string,string>
	 */
	public static function labels(): array {
		return array(
			'void'      => __( 'Cancelación', 'haramara-core' ),
			'refund'    => __( 'Devolución', 'haramara-core' ),
			'discount'  => __( 'Descuento', 'haramara-core' ),
			'comp'      => __( 'Cortesía', 'haramara-core' ),
			'cash_drop' => __( 'Retiro de efectivo', 'haramara-core' ),
			'no_sale'   => __( 'Apertura sin venta', 'haramara-core' ),
			'reprint'   => __( 'Reimpresión', 'haramara-core' ),
		);
	}

	/**
	 * Append one event. The only write this class offers.
	 *
	 * @param array{
	 *     type:string,
	 *     shift_id?:int,
	 *     operator?:array{key?:string,name?:string},
	 *     authorized_by?:string,
	 *     order_id?:int|null,
	 *     tab_id?:int|null,
	 *     amount?:float,
	 *     reason_code?:string,
	 *     reason_note?:string,
	 *     items?:array<int,array<string,mixed>>|null
	 * } $event Event fields.
	 * @return array<string,mixed>|\WP_Error The recorded row, serialized.
	 */
	public static function record( array $event ) {
		global $wpdb;

		$type = (string) ( $event['type'] ?? '' );
		if ( ! in_array( $type, self::TYPES, true ) ) {
			return new \WP_Error(
				'haramara_invalid_event',
				__( 'Tipo de evento no válido.', 'haramara-core' ),
				array( 'status' => 400 )
			);
		}

		$reason = (string) ( $event['reason_code'] ?? '' );
		if ( '' !== $reason && ! in_array( $reason, self::REASONS, true ) ) {
			return new \WP_Error(
				'haramara_invalid_reason',
				__( 'Motivo no válido.', 'haramara-core' ),
				array( 'status' => 400 )
			);
		}

		$operator = (array) ( $event['operator'] ?? array() );
		$items    = $event['items'] ?? null;

		$row = array(
			// Café-local, like Withdrawals: the day windows slice on this column.
			'created_at'    => ( new \DateTimeImmutable( 'now', Options::timezone() ) )->format( 'Y-m-d H:i:s' ),
			'shift_id'      => max( 0, (int) ( $event['shift_id'] ?? 0 ) ),
			'operator_key'  => substr( sanitize_key( (string) ( $operator['key'] ?? '' ) ), 0, 32 ),
			'operator_name' => substr( sanitize_text_field( (string) ( $operator['name'] ?? '' ) ), 0, 80 ),
			'authorized_by' => substr( sanitize_text_field( (string) ( $event['authorized_by'] ?? '' ) ), 0, 80 ),
			'type'          => $type,
			'order_id'      => isset( $event['order_id'] ) ? (int) $event['order_id'] : null,
			'tab_id'        => isset( $event['tab_id'] ) ? (int) $event['tab_id'] : null,
			'amount'        => round( (float) ( $event['amount'] ?? 0 ), 2 ),
			'reason_code'   => $reason,
			'reason_note'   => substr( sanitize_textarea_field( (string) ( $event['reason_note'] ?? '' ) ), 0, 200 ),
			'items_json'    => null === $items ? null : (string) wp_json_encode( $items ),
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$inserted = $wpdb->insert(
			self::table(),
			$row,
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%f', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return new \WP_Error(
				'haramara_event_not_recorded',
				__( 'No se pudo registrar el evento. Intenta de nuevo.', 'haramara-core' ),
				array( 'status' => 500 )
			);
		}

		$row['id'] = (int) $wpdb->insert_id;

		return self::serialize( $row );
	}

	/**
	 * Events for one shift, oldest first.
	 *
	 * @param int         $shift_id Shift ID.
	 * @param string|null $type     Optional single type filter.
	 * @return array<int,array<string,mixed>>
	 */
	public static function for_shift( int $shift_id, ?string $type = null ): array {
		global $wpdb;

		$table = self::table();

		if ( null !== $type ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE shift_id = %d AND type = %s ORDER BY id ASC", $shift_id, $type ), 'ARRAY_A' );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE shift_id = %d ORDER BY id ASC", $shift_id ), 'ARRAY_A' );
		}

		return array_map( array( self::class, 'serialize' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Sum of `amount` for one type inside one shift (e.g. cash drops).
	 *
	 * @param int    $shift_id Shift ID.
	 * @param string $type     Event type.
	 */
	public static function total_for_shift( int $shift_id, string $type ): float {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return round( (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount),0) FROM {$table} WHERE shift_id = %d AND type = %s", $shift_id, $type ) ), 2 );
	}

	/**
	 * Events for a café-local calendar day, newest first (corte buckets).
	 *
	 * @param string $date Y-m-d in the café timezone.
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
			'ARRAY_A'
		);

		return array_map( array( self::class, 'serialize' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Per-type counts and totals for a day — the corte's adjustment buckets.
	 *
	 * These must never net silently into revenue; the corte renders each
	 * bucket by name with its own count and value.
	 *
	 * @param string $date Y-m-d in the café timezone.
	 * @return array{by_type:array<string,array{count:int,value:float}>,events:array<int,array<string,mixed>>}
	 */
	public static function summary_for_date( string $date ): array {
		$events  = self::for_date( $date );
		$by_type = array();

		foreach ( $events as $event ) {
			$type = (string) $event['type'];
			if ( ! isset( $by_type[ $type ] ) ) {
				$by_type[ $type ] = array(
					'count' => 0,
					'value' => 0.0,
				);
			}
			++$by_type[ $type ]['count'];
			$by_type[ $type ]['value'] = round( $by_type[ $type ]['value'] + (float) $event['amount'], 2 );
		}

		return array(
			'by_type' => $by_type,
			'events'  => $events,
		);
	}

	/**
	 * Wire shape of one event row.
	 *
	 * @param array<string,mixed> $row DB row.
	 * @return array<string,mixed>
	 */
	private static function serialize( array $row ): array {
		$labels = self::labels();
		$type   = (string) $row['type'];
		$items  = null;
		if ( isset( $row['items_json'] ) && is_string( $row['items_json'] ) && '' !== $row['items_json'] ) {
			$decoded = json_decode( $row['items_json'], true );
			$items   = is_array( $decoded ) ? $decoded : null;
		}

		return array(
			'id'            => (int) ( $row['id'] ?? 0 ),
			'created_at'    => (string) $row['created_at'],
			'shift_id'      => (int) $row['shift_id'],
			'operator'      => (string) $row['operator_name'],
			'authorized_by' => (string) $row['authorized_by'],
			'type'          => $type,
			'type_label'    => $labels[ $type ] ?? $type,
			'order_id'      => isset( $row['order_id'] ) && null !== $row['order_id'] ? (int) $row['order_id'] : null,
			'tab_id'        => isset( $row['tab_id'] ) && null !== $row['tab_id'] ? (int) $row['tab_id'] : null,
			'amount'        => round( (float) $row['amount'], 2 ),
			'reason_code'   => (string) $row['reason_code'],
			'reason_note'   => (string) $row['reason_note'],
			'items'         => $items,
		);
	}
}
