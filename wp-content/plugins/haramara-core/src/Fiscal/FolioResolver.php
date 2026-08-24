<?php
/**
 * Ticket-folio → order resolution contract.
 *
 * The /factura flow starts from two things printed on the counter ticket: the
 * folio and the total. Resolving that pair to a WooCommerce order is Phase 2
 * territory (`_pos_folio` + HMAC token assignment is still in flight there),
 * so Phase 6 codes against this interface and ships with the
 * PendingFolioResolver stub. Swapping in the real resolver is a one-line
 * change in Rest\FiscalRoutes::__construct() — see docs/phase6-integration.md.
 *
 * @package Haramara\Core
 */

declare( strict_types=1 );

namespace Haramara\Core\Fiscal;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface FolioResolver {

	/**
	 * Resolve a printed ticket folio + total to an order ID.
	 *
	 * The total doubles as the knowledge proof: the caller must return the SAME
	 * generic not-found error for an unknown folio and for a folio whose total
	 * does not match, so the public /factura endpoints cannot be used to probe
	 * which folios exist.
	 *
	 * Expected error shapes (WP_Error data carries the HTTP status):
	 *  - `haramara_factura_not_found` (404) — unknown folio or total mismatch.
	 *  - `haramara_factura_unavailable` (503) — resolution not available yet.
	 *
	 * @param string $folio Folio exactly as printed on the ticket.
	 * @param float  $total Ticket total in MXN, IVA included, as printed.
	 * @return int|\WP_Error WooCommerce order ID, or error.
	 */
	public function resolve( string $folio, float $total ): int|\WP_Error;
}
