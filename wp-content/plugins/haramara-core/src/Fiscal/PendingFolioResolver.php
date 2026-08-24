<?php
/**
 * Placeholder folio resolver — always "not available yet".
 *
 * Phase 2 (POS ticket folios) has not landed, so no folio can be resolved to
 * an order. Mirroring the WalletPass gating precedent, the unavailable state
 * answers 503 and the /factura page simply shows its "aún no disponible"
 * state; nothing else about the fiscal stack needs to know.
 *
 * @package Haramara\Core
 */

declare( strict_types=1 );

namespace Haramara\Core\Fiscal;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PendingFolioResolver implements FolioResolver {

	/**
	 * Always unavailable: Phase 2 owns folio assignment and it is still in flight.
	 *
	 * @param string $folio Folio exactly as printed on the ticket (unused).
	 * @param float  $total Ticket total in MXN (unused).
	 * @return int|\WP_Error Always a 503 WP_Error.
	 */
	public function resolve( string $folio, float $total ): int|\WP_Error {
		unset( $folio, $total );

		return new \WP_Error(
			'haramara_factura_unavailable',
			__( 'La facturación en línea aún no está disponible. Guarda tu ticket e inténtalo más tarde.', 'haramara-core' ),
			array( 'status' => 503 )
		);
	}
}
