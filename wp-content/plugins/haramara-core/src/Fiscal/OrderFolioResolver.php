<?php
/**
 * The real folio → order resolver (replaces PendingFolioResolver).
 *
 * Resolution is pure derivation — Ordering\Folios encodes the order id and an
 * HMAC checksum in the folio itself — followed by the two business checks the
 * /factura page needs: the order is genuinely paid, and the typed total
 * matches. Total mismatch answers the SAME error as an unknown folio, so the
 * page never becomes an oracle for probing order amounts.
 *
 * @package Haramara\Core
 */

declare( strict_types=1 );

namespace Haramara\Core\Fiscal;

use Haramara\Core\Ordering\Folios;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Folio resolution against real orders.
 */
final class OrderFolioResolver implements FolioResolver {

	/** Order statuses that may be invoiced (paid, not reversed). */
	private const INVOICEABLE = array( 'completed', 'processing' );

	/**
	 * Folio + typed total → invoiceable order id.
	 *
	 * @param string $folio Customer-typed folio.
	 * @param float  $total Customer-typed ticket total.
	 * @return int|\WP_Error
	 */
	public function resolve( string $folio, float $total ): int|\WP_Error {
		$order_id = Folios::parse( $folio );
		if ( is_wp_error( $order_id ) ) {
			return $order_id;
		}

		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false;
		if ( ! $order instanceof \WC_Order ) {
			return $this->not_found();
		}

		if ( ! in_array( $order->get_status(), self::INVOICEABLE, true ) ) {
			return $this->not_found();
		}

		// The customer copies the total off their ticket; a centavo of float
		// drift must not block them, a different amount must.
		if ( abs( (float) $order->get_total() - round( $total, 2 ) ) > 0.01 ) {
			return $this->not_found();
		}

		return $order_id;
	}

	/** Same shape for unknown folio, unpaid order, and wrong total. */
	private function not_found(): \WP_Error {
		return new \WP_Error(
			'haramara_folio_invalid',
			__( 'Folio no válido. Revísalo tal como aparece en tu ticket.', 'haramara-core' ),
			array( 'status' => 404 )
		);
	}
}
