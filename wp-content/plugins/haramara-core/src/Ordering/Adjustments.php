<?php
/**
 * Cancelaciones y devoluciones — money moving backwards, on the record.
 *
 * Every reversal lands as an append-only row in the PosEvents ledger with a
 * mandatory reason and the operator who did it; the WooCommerce order remains
 * the money record (status flip / WC_Order_Refund), the ledger remains the
 * artifact that proves it happened and cannot be edited from wp-admin.
 *
 * Authorization policy (enforced here, not in the routes, so no future route
 * can forget it):
 *
 * - VOID of an order inside the CURRENTLY OPEN shift: any operator, reason
 *   required. Fixing a mis-rung ticket seconds later must not need a manager.
 * - VOID outside an open shift (or of an order from another shift): requires
 *   a supervisor authorization bound to action `void`.
 * - REFUND: always requires a supervisor authorization bound to `refund` —
 *   money physically leaves the drawer.
 *
 * Discounts are deliberately NOT here: they are pre-settle and belong to the
 * sale itself (WalkInOrders::create), where the threshold check lives.
 *
 * @package Haramara\Core
 */

declare( strict_types=1 );

namespace Haramara\Core\Ordering;

use Haramara\Core\Staff\Operators;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Void + refund against the ledger.
 */
final class Adjustments {

	/**
	 * Cancel an order: status → cancelled (WooCommerce restocks reduced
	 * lines automatically on that transition) + a `void` ledger row.
	 *
	 * @param int                 $order_id      Order to void.
	 * @param string              $reason_code   One of PosEvents::REASONS.
	 * @param string              $reason_note   Free note; required when reason is `otro`.
	 * @param array<string,mixed> $operator      Who is voiding (Staff\Operators shape). May be empty pre-rollout.
	 * @param string              $authorization Supervisor step-up token, when policy demands one.
	 * @return array<string,mixed>|\WP_Error The ledger event.
	 */
	public static function void( int $order_id, string $reason_code, string $reason_note, array $operator, string $authorization = '' ) {
		$order = self::load_order( $order_id );
		if ( is_wp_error( $order ) ) {
			return $order;
		}

		if ( in_array( $order->get_status(), StatusTransitions::TERMINAL, true ) && 'completed' !== $order->get_status() ) {
			return new \WP_Error(
				'haramara_already_terminal',
				__( 'Este pedido ya está cancelado o devuelto.', 'haramara-core' ),
				array( 'status' => 409 )
			);
		}

		$reason = self::check_reason( $reason_code, $reason_note );
		if ( is_wp_error( $reason ) ) {
			return $reason;
		}

		// Same-open-shift voids are the cashier's own error-correction path;
		// everything else is a supervisor action.
		$shift         = Shifts::current();
		$order_shift   = (string) $order->get_meta( Shifts::META_SHIFT_ID );
		$in_open_shift = null !== $shift && '' !== $order_shift && (string) (int) $shift['id'] === $order_shift;

		$authorized_by = '';
		if ( ! $in_open_shift ) {
			$supervisor = self::require_authorization( $authorization, 'void' );
			if ( is_wp_error( $supervisor ) ) {
				return $supervisor;
			}
			$authorized_by = $supervisor;
		}

		$amount = (float) $order->get_total();
		$items  = self::items_snapshot( $order );

		$order->update_status(
			'cancelled',
			sprintf(
				/* translators: 1: reason label, 2: operator name. */
				__( 'Cancelado desde el POS. Motivo: %1$s. Operador: %2$s.', 'haramara-core' ),
				$reason_code,
				(string) ( $operator['name'] ?? '—' )
			)
		);

		return PosEvents::record(
			array(
				'type'          => 'void',
				'shift_id'      => null !== $shift ? (int) $shift['id'] : (int) $order_shift,
				'operator'      => $operator,
				'authorized_by' => $authorized_by,
				'order_id'      => $order_id,
				'amount'        => $amount,
				'reason_code'   => $reason_code,
				'reason_note'   => $reason_note,
				'items'         => $items,
			)
		);
	}

	/**
	 * Full refund: WC_Order_Refund with restock + a `refund` ledger row.
	 * Always supervisor-authorized.
	 *
	 * @param int                 $order_id      Order to refund.
	 * @param string              $reason_code   One of PosEvents::REASONS.
	 * @param string              $reason_note   Free note; required when reason is `otro`.
	 * @param array<string,mixed> $operator      Who is refunding.
	 * @param string              $authorization Supervisor step-up token (mandatory).
	 * @return array<string,mixed>|\WP_Error The ledger event.
	 */
	public static function refund( int $order_id, string $reason_code, string $reason_note, array $operator, string $authorization ) {
		$order = self::load_order( $order_id );
		if ( is_wp_error( $order ) ) {
			return $order;
		}

		$reason = self::check_reason( $reason_code, $reason_note );
		if ( is_wp_error( $reason ) ) {
			return $reason;
		}

		$supervisor = self::require_authorization( $authorization, 'refund' );
		if ( is_wp_error( $supervisor ) ) {
			return $supervisor;
		}

		$remaining = (float) $order->get_total() - (float) $order->get_total_refunded();
		if ( $remaining <= 0 ) {
			return new \WP_Error(
				'haramara_nothing_to_refund',
				__( 'Este pedido ya fue devuelto por completo.', 'haramara-core' ),
				array( 'status' => 409 )
			);
		}

		// Full-line refund so restock_items can restore inventory.
		$line_items = array();
		foreach ( $order->get_items() as $item_id => $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$line_items[ $item_id ] = array(
				'qty'          => $item->get_quantity(),
				'refund_total' => (float) $item->get_total(),
			);
		}

		$refund = wc_create_refund(
			array(
				'order_id'       => $order_id,
				'amount'         => $remaining,
				'reason'         => $reason_code,
				'line_items'     => $line_items,
				'restock_items'  => true,
				'refund_payment' => false,
			)
		);
		if ( is_wp_error( $refund ) ) {
			return $refund;
		}

		$shift = Shifts::current();

		return PosEvents::record(
			array(
				'type'          => 'refund',
				'shift_id'      => null !== $shift ? (int) $shift['id'] : 0,
				'operator'      => $operator,
				'authorized_by' => $supervisor,
				'order_id'      => $order_id,
				'amount'        => $remaining,
				'reason_code'   => $reason_code,
				'reason_note'   => $reason_note,
				'items'         => self::items_snapshot( $order ),
			)
		);
	}

	/**
	 * Load a POS-adjustable order.
	 *
	 * @param int $order_id Order id.
	 * @return \WC_Order|\WP_Error
	 */
	private static function load_order( int $order_id ) {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return new \WP_Error(
				'haramara_wc_unavailable',
				__( 'WooCommerce no está disponible.', 'haramara-core' ),
				array( 'status' => 500 )
			);
		}

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return new \WP_Error(
				'haramara_order_not_found',
				__( 'Pedido no encontrado.', 'haramara-core' ),
				array( 'status' => 404 )
			);
		}

		return $order;
	}

	/**
	 * A mandatory reason, with `otro` demanding an actual explanation.
	 *
	 * @param string $reason_code Reason code.
	 * @param string $reason_note Free note.
	 * @return true|\WP_Error
	 */
	private static function check_reason( string $reason_code, string $reason_note ) {
		if ( ! in_array( $reason_code, PosEvents::REASONS, true ) ) {
			return new \WP_Error(
				'haramara_invalid_reason',
				__( 'Motivo no válido.', 'haramara-core' ),
				array( 'status' => 400 )
			);
		}

		if ( 'otro' === $reason_code && '' === trim( $reason_note ) ) {
			return new \WP_Error(
				'haramara_reason_note_required',
				__( 'Con motivo "Otro" es obligatorio escribir el detalle.', 'haramara-core' ),
				array( 'status' => 400 )
			);
		}

		return true;
	}

	/**
	 * Validate a supervisor authorization for an action.
	 *
	 * @param string $authorization Step-up token from /pos/operator/authorize.
	 * @param string $action        Action it must be bound to.
	 * @return string|\WP_Error Supervisor display name.
	 */
	private static function require_authorization( string $authorization, string $action ) {
		if ( '' === trim( $authorization ) ) {
			return new \WP_Error(
				'haramara_authorization_required',
				__( 'Esta operación necesita la autorización de un supervisor.', 'haramara-core' ),
				array( 'status' => 403 )
			);
		}

		$supervisor = Operators::check_authorization( $authorization, $action );
		if ( is_wp_error( $supervisor ) ) {
			return $supervisor;
		}

		return (string) $supervisor['name'];
	}

	/**
	 * Line snapshot for the ledger — survives later product edits.
	 *
	 * @param \WC_Order $order Order.
	 * @return array<int,array<string,mixed>>
	 */
	private static function items_snapshot( \WC_Order $order ): array {
		$out = array();
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$out[] = array(
				'name'     => $item->get_name(),
				'quantity' => (int) $item->get_quantity(),
				'total'    => (float) $item->get_total(),
			);
		}
		return $out;
	}
}
