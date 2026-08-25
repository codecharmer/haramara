<?php
/**
 * Cash shifts (turnos) and the arqueo de caja.
 *
 * A shift is one drawer session: opened with a counted fondo inicial, closed
 * with a counted physical drawer. The close is a BLIND count — the cashier
 * declares what they counted before the server reveals what should be there.
 * Expected cash is computed server-side at close and stored:
 *
 *   expected = fondo inicial
 *            + ventas en efectivo del turno (walk-ins stamped with the shift)
 *            + propinas en efectivo         (tips phase; 0 until it lands)
 *            − retiros de efectivo          (cash_drop events)
 *
 * The variance (declared − expected) is the number the whole feature exists
 * to produce: it is what makes a cashier accountable for the drawer.
 *
 * Enforcement note: serialize() never includes expected_cash while a shift is
 * open, but that alone is not the control — the daily summary's cash buckets
 * are also withheld from cajeros while a shift is open (see PosRoutes), or the
 * cashier could reconstruct expected from revenue − card before declaring.
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
 * Shift lifecycle + arqueo math.
 */
final class Shifts {

	/** Walk-in orders stamp the open shift so its cash total is exact (order meta). */
	public const META_SHIFT_ID = '_haramara_pos_shift_id';

	/** Ceiling for fondo/declared amounts — fat-finger guard, not policy. */
	private const MAX_AMOUNT = 100000;

	/** Fully-qualified shifts table name. */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . Activator::SHIFTS_TABLE;
	}

	/**
	 * The currently open shift row, or null.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function current(): ?array {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( "SELECT * FROM {$table} WHERE status = 'open' ORDER BY id DESC LIMIT 1", 'ARRAY_A' );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Open a shift with a counted fondo inicial.
	 *
	 * @param float               $opening_float Counted starting cash.
	 * @param array<string,mixed> $operator      Who opened it (Staff\Operators shape).
	 * @return array<string,mixed>|\WP_Error Serialized open shift.
	 */
	public static function open( float $opening_float, array $operator ) {
		if ( $opening_float < 0 || $opening_float > self::MAX_AMOUNT ) {
			return new \WP_Error(
				'haramara_invalid_amount',
				__( 'Fondo inicial no válido.', 'haramara-core' ),
				array( 'status' => 400 )
			);
		}

		if ( null !== self::current() ) {
			return new \WP_Error(
				'haramara_shift_already_open',
				__( 'Ya hay un turno abierto. Ciérralo antes de abrir otro.', 'haramara-core' ),
				array( 'status' => 409 )
			);
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$inserted = $wpdb->insert(
			self::table(),
			array(
				'status'         => 'open',
				'opened_at'      => ( new \DateTimeImmutable( 'now', Options::timezone() ) )->format( 'Y-m-d H:i:s' ),
				'opened_by_key'  => substr( sanitize_key( (string) ( $operator['key'] ?? '' ) ), 0, 32 ),
				'opened_by_name' => substr( sanitize_text_field( (string) ( $operator['name'] ?? '' ) ), 0, 80 ),
				'opening_float'  => round( $opening_float, 2 ),
			),
			array( '%s', '%s', '%s', '%s', '%f' )
		);

		if ( false === $inserted ) {
			return new \WP_Error(
				'haramara_shift_not_opened',
				__( 'No se pudo abrir el turno. Intenta de nuevo.', 'haramara-core' ),
				array( 'status' => 500 )
			);
		}

		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $wpdb->insert_id ), 'ARRAY_A' );
		if ( ! is_array( $row ) ) {
			return new \WP_Error( 'haramara_shift_not_opened', __( 'No se pudo abrir el turno. Intenta de nuevo.', 'haramara-core' ), array( 'status' => 500 ) );
		}

		return self::serialize( $row );
	}

	/**
	 * Close the open shift against a blind physical count.
	 *
	 * The declared amount comes first; expected and variance are computed and
	 * stored here, never shown beforehand.
	 *
	 * @param float               $declared_cash Counted physical drawer.
	 * @param string              $note          Optional close note.
	 * @param array<string,mixed> $operator            Who closed it.
	 * @param string              $tabs_authorization  Supervisor step-up bound to
	 *                            `close_tabs`: force-voids any open cuentas so
	 *                            the arqueo cannot close over live tickets.
	 * @return array<string,mixed>|\WP_Error Serialized closed shift, variance included.
	 */
	public static function close( float $declared_cash, string $note, array $operator, string $tabs_authorization = '' ) {
		if ( $declared_cash < 0 || $declared_cash > self::MAX_AMOUNT ) {
			return new \WP_Error(
				'haramara_invalid_amount',
				__( 'Cantidad declarada no válida.', 'haramara-core' ),
				array( 'status' => 400 )
			);
		}

		$row = self::current();
		if ( null === $row ) {
			return new \WP_Error(
				'haramara_no_open_shift',
				__( 'No hay un turno abierto.', 'haramara-core' ),
				array( 'status' => 409 )
			);
		}

		// A shift must never close over open cuentas — served product with no
		// settled money would silently vanish from the arqueo. Either the
		// cashier closes/voids them first, or a supervisor authorizes a
		// force-void of everything still open.
		$open_tabs = Tabs::enabled() ? Tabs::open_count() : 0;
		if ( $open_tabs > 0 ) {
			if ( '' === trim( $tabs_authorization ) ) {
				return new \WP_Error(
					'haramara_tabs_open',
					sprintf(
						/* translators: %d: open tab count. */
						__( 'Hay %d cuenta(s) abierta(s). Ciérralas o pide a un supervisor autorizar su anulación.', 'haramara-core' ),
						$open_tabs
					),
					array(
						'status'    => 409,
						'open_tabs' => $open_tabs,
					)
				);
			}

			$supervisor = \Haramara\Core\Staff\Operators::check_authorization( $tabs_authorization, 'close_tabs' );
			if ( is_wp_error( $supervisor ) ) {
				return $supervisor;
			}

			Tabs::void_all_open( $operator, (string) $supervisor['name'] );
		}

		$shift_id = (int) $row['id'];

		$expected = round(
			(float) $row['opening_float']
			+ self::cash_sales( $shift_id )
			+ self::cash_tips( $shift_id )
			- PosEvents::total_for_shift( $shift_id, 'cash_drop' ),
			2
		);
		$declared = round( $declared_cash, 2 );

		global $wpdb;

		// status='open' in the WHERE makes a double-close race lose cleanly:
		// the second update matches zero rows instead of overwriting the count.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$updated = $wpdb->update(
			self::table(),
			array(
				'status'         => 'closed',
				'closed_at'      => ( new \DateTimeImmutable( 'now', Options::timezone() ) )->format( 'Y-m-d H:i:s' ),
				'closed_by_key'  => substr( sanitize_key( (string) ( $operator['key'] ?? '' ) ), 0, 32 ),
				'closed_by_name' => substr( sanitize_text_field( (string) ( $operator['name'] ?? '' ) ), 0, 80 ),
				'declared_cash'  => $declared,
				'expected_cash'  => $expected,
				'variance'       => round( $declared - $expected, 2 ),
				'note'           => substr( sanitize_textarea_field( $note ), 0, 200 ),
			),
			array(
				'id'     => $shift_id,
				'status' => 'open',
			),
			array( '%s', '%s', '%s', '%s', '%f', '%f', '%f', '%s' ),
			array( '%d', '%s' )
		);

		if ( 1 !== (int) $updated ) {
			return new \WP_Error(
				'haramara_shift_close_conflict',
				__( 'El turno ya fue cerrado en otro dispositivo.', 'haramara-core' ),
				array( 'status' => 409 )
			);
		}

		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$closed = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $shift_id ), 'ARRAY_A' );

		return is_array( $closed )
			? self::serialize( $closed )
			: new \WP_Error( 'haramara_shift_close_conflict', __( 'El turno ya fue cerrado en otro dispositivo.', 'haramara-core' ), array( 'status' => 409 ) );
	}

	/**
	 * Record a mid-shift cash drop (large bills leaving the drawer to the safe).
	 *
	 * Recorded as a cash_drop event so it subtracts from expected cash instead
	 * of surfacing as a shortfall at close.
	 *
	 * @param float               $amount   Cash removed.
	 * @param string              $note     Optional note ("al sobre", etc.).
	 * @param array<string,mixed> $operator Who removed it.
	 * @return array<string,mixed>|\WP_Error The recorded event.
	 */
	public static function cash_drop( float $amount, string $note, array $operator ) {
		if ( $amount <= 0 || $amount > self::MAX_AMOUNT ) {
			return new \WP_Error(
				'haramara_invalid_amount',
				__( 'Cantidad de retiro no válida.', 'haramara-core' ),
				array( 'status' => 400 )
			);
		}

		$row = self::current();
		if ( null === $row ) {
			return new \WP_Error(
				'haramara_no_open_shift',
				__( 'No hay un turno abierto.', 'haramara-core' ),
				array( 'status' => 409 )
			);
		}

		return PosEvents::record(
			array(
				'type'        => 'cash_drop',
				'shift_id'    => (int) $row['id'],
				'operator'    => $operator,
				'amount'      => $amount,
				'reason_code' => 'retiro_efectivo',
				'reason_note' => $note,
			)
		);
	}

	/**
	 * Recent shifts, newest first (the variance history on the Corte tab).
	 *
	 * @param int $limit Max rows.
	 * @return array<int,array<string,mixed>> Serialized; closed shifts include variance.
	 */
	public static function recent( int $limit = 14 ): array {
		global $wpdb;

		$table = self::table();
		$limit = max( 1, min( 60, $limit ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit ), 'ARRAY_A' );

		return array_map( array( self::class, 'serialize' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Cash collected by walk-in sales stamped with this shift.
	 *
	 * Exact by construction: WalkInOrders stamps META_SHIFT_ID at creation, so
	 * a sale rung while no shift was open never inflates a later arqueo.
	 *
	 * The stamp filter runs in PHP, not as a meta_query — `wc_get_orders`
	 * silently ignores meta_query on some order-store configurations, and a
	 * dropped filter here would sum EVERY completed order into expected cash
	 * (observed live: 1515 instead of 130). The candidate set is bounded by
	 * created_via + the shift's open date, so the PHP pass stays small.
	 *
	 * @param int $shift_id Shift ID.
	 */
	public static function cash_sales( int $shift_id ): float {
		global $wpdb;

		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$opened_at = (string) $wpdb->get_var( $wpdb->prepare( "SELECT opened_at FROM {$table} WHERE id = %d", $shift_id ) );
		if ( '' === $opened_at ) {
			return 0.0;
		}

		$orders = wc_get_orders(
			array(
				'created_via'  => WalkInOrders::CREATED_VIA,
				'status'       => array( 'wc-completed' ),
				'limit'        => -1,
				// Café-local date floor: cheap DB-side bound; the stamp is the
				// real filter below.
				'date_created' => '>=' . substr( $opened_at, 0, 10 ),
			)
		);

		$total = 0.0;
		foreach ( $orders as $order ) {
			if ( ! $order instanceof \WC_Order ) {
				continue;
			}
			if ( (string) $shift_id !== (string) $order->get_meta( self::META_SHIFT_ID ) ) {
				continue;
			}
			if ( 'cash' !== (string) $order->get_meta( WalkInOrders::META_PAYMENT ) ) {
				continue;
			}
			$total += (float) $order->get_total() - (float) $order->get_total_refunded();
		}

		return round( $total, 2 );
	}

	/**
	 * Cash tips collected during this shift.
	 *
	 * Tips are order META, never order lines — a propina is pass-through to
	 * staff, not revenue — so this walks the shift's walk-ins and sums the
	 * cash-method tips. Same PHP-side meta filtering as cash_sales(), and for
	 * the same reason: wc_get_orders silently drops meta_query on some order
	 * stores.
	 *
	 * @param int $shift_id Shift ID.
	 */
	public static function cash_tips( int $shift_id ): float {
		global $wpdb;

		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$opened_at = (string) $wpdb->get_var( $wpdb->prepare( "SELECT opened_at FROM {$table} WHERE id = %d", $shift_id ) );
		if ( '' === $opened_at ) {
			return 0.0;
		}

		$orders = wc_get_orders(
			array(
				'created_via'  => WalkInOrders::CREATED_VIA,
				'status'       => array( 'wc-completed' ),
				'limit'        => -1,
				'date_created' => '>=' . substr( $opened_at, 0, 10 ),
			)
		);

		$total = 0.0;
		foreach ( $orders as $order ) {
			if ( ! $order instanceof \WC_Order ) {
				continue;
			}
			if ( (string) $shift_id !== (string) $order->get_meta( self::META_SHIFT_ID ) ) {
				continue;
			}
			if ( 'cash' !== (string) $order->get_meta( WalkInOrders::META_TIP_METHOD ) ) {
				continue;
			}
			$total += (float) $order->get_meta( WalkInOrders::META_TIP );
		}

		/**
		 * Filters the cash-tip total the arqueo adds to expected cash.
		 *
		 * @param float $total    Cash tips for the shift.
		 * @param int   $shift_id Shift ID.
		 */
		return round( (float) apply_filters( 'haramara_shift_cash_tips', $total, $shift_id ), 2 );
	}

	/**
	 * Wire shape of a shift row.
	 *
	 * expected_cash and variance are ONLY present on closed shifts — while the
	 * shift is open they exist nowhere in an API response, by design.
	 *
	 * @param array<string,mixed> $row DB row.
	 * @return array<string,mixed>
	 */
	public static function serialize( array $row ): array {
		$closed = 'closed' === (string) $row['status'];

		$out = array(
			'id'            => (int) $row['id'],
			'status'        => (string) $row['status'],
			'opened_at'     => (string) $row['opened_at'],
			'opened_by'     => (string) $row['opened_by_name'],
			'opening_float' => round( (float) $row['opening_float'], 2 ),
			'cash_drops'    => PosEvents::total_for_shift( (int) $row['id'], 'cash_drop' ),
		);

		if ( $closed ) {
			$out['closed_at']     = (string) $row['closed_at'];
			$out['closed_by']     = (string) $row['closed_by_name'];
			$out['declared_cash'] = round( (float) $row['declared_cash'], 2 );
			$out['expected_cash'] = round( (float) $row['expected_cash'], 2 );
			$out['variance']      = round( (float) $row['variance'], 2 );
			$out['note']          = (string) $row['note'];
		}

		return $out;
	}
}
