<?php
/**
 * Allowed order-status transitions for staff surfaces.
 *
 * Single authority for which target statuses staff tooling (admin dashboard
 * AJAX, POS REST) may move an order to, so the surfaces can never drift.
 *
 * @package Haramara\Core
 */

declare( strict_types=1 );

namespace Haramara\Core\Ordering;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The staff transition rule set.
 */
final class StatusTransitions {

	/** Target statuses staff may transition an order to. */
	public const ALLOWED = array( 'preparing', 'ready', 'completed' );

	/** Statuses that end an order's lifecycle — no further staff transitions. */
	public const TERMINAL = array( 'completed', 'cancelled', 'refunded', 'failed' );

	/** Status an order enters when accepted from the POS queue. */
	public const ACCEPT_TARGET = 'queued';

	/** Order meta: when the POS accepted the order (site-local MySQL datetime). */
	public const META_ACCEPTED_AT = '_haramara_pos_accepted_at';

	/** Order meta: user ID of the staff member who accepted the order. */
	public const META_ACCEPTED_BY = '_haramara_pos_accepted_by';

	/**
	 * Whether a target status is in the staff-allowed set.
	 *
	 * @param string $target Status slug (no wc- prefix).
	 */
	public static function is_allowed( string $target ): bool {
		return in_array( $target, self::ALLOWED, true );
	}

	/**
	 * Targets currently offered for an order: the allowed set minus its own
	 * status, or nothing once the order is terminal.
	 *
	 * @param \WC_Order $order The order to inspect.
	 * @return string[]
	 */
	public static function allowed_for( \WC_Order $order ): array {
		$status = $order->get_status();
		if ( in_array( $status, self::TERMINAL, true ) ) {
			return array();
		}
		return array_values( array_diff( self::ALLOWED, array( $status ) ) );
	}

	/**
	 * Apply a staff transition.
	 *
	 * @param \WC_Order $order  The order to transition.
	 * @param string    $target Target status slug (no wc- prefix).
	 * @param string    $note   Order note recording who/where the change came from.
	 * @return true|\WP_Error True when the status update was issued; WP_Error
	 *                        (status 400/409) for a disallowed or stale target.
	 */
	public static function apply( \WC_Order $order, string $target, string $note ) {
		if ( ! self::is_allowed( $target ) ) {
			return new \WP_Error(
				'haramara_invalid_status',
				__( 'Estado no permitido.', 'haramara-core' ),
				array( 'status' => 400 )
			);
		}
		if ( ! in_array( $target, self::allowed_for( $order ), true ) ) {
			return new \WP_Error(
				'haramara_transition_conflict',
				__( 'El pedido ya no admite ese cambio de estado.', 'haramara-core' ),
				array( 'status' => 409 )
			);
		}

		$order->update_status( $target, $note );

		return true;
	}

	/**
	 * Accept an incoming order from the POS queue: claim it, then move it to
	 * "En fila". Only a paid, unaccepted `processing` order can be accepted —
	 * anything else (already accepted on another device, already advanced on
	 * the board) is a conflict the client resolves by refetching the queue.
	 *
	 * @param \WC_Order $order The order to accept.
	 * @param string    $note  Order note recording who accepted it.
	 * @return true|\WP_Error True when accepted; WP_Error (status 409) on conflict.
	 */
	public static function accept( \WC_Order $order, string $note ) {
		if ( 'processing' !== $order->get_status() || '' !== (string) $order->get_meta( self::META_ACCEPTED_AT ) ) {
			return new \WP_Error(
				'haramara_accept_conflict',
				__( 'El pedido ya fue aceptado o cambió de estado.', 'haramara-core' ),
				array( 'status' => 409 )
			);
		}

		// Claim first so a near-simultaneous accept on another device sees the
		// meta and gets the 409 instead of double-transitioning.
		$order->update_meta_data( self::META_ACCEPTED_AT, current_time( 'mysql' ) );
		$order->update_meta_data( self::META_ACCEPTED_BY, (string) get_current_user_id() );
		$order->save();

		$order->update_status( self::ACCEPT_TARGET, $note );

		return true;
	}
}
