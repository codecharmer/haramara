<?php
/**
 * PAC (proveedor autorizado de certificación) client contract.
 *
 * A PAC stamps CFDI 4.0 invoices on the café's behalf; the CSD certificates
 * live at the PAC — never in this repo or on this server. Implementations are
 * gated exactly like Loyalty\WalletPass: credentials come from wp-config
 * constants only, and an unconfigured install answers 503 so the /factura
 * feature simply hides.
 *
 * The `$cfdi` array passed to issue() is a PAC-neutral description of the
 * invoice; each implementation maps it to its provider's wire format (and owns
 * the SAT specifics such as the IVA desglose). Shape:
 *
 *     array{
 *         folio: string,        // POS ticket folio (printed on the ticket).
 *         total: float,         // Grand total charged, MXN, IVA included.
 *         payment_form: string, // SAT c_FormaPago code, e.g. '01', '04'.
 *         receiver: array{rfc: string, name: string, cfdi_use: string, fiscal_regime: string, zip: string},
 *         items: array<int, array{product_id: int, name: string, quantity: int, total: float}>,
 *     }
 *
 * where each item `total` is the line total as charged (MXN, IVA included).
 *
 * @package Haramara\Core
 */

declare( strict_types=1 );

namespace Haramara\Core\Fiscal;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface PacClient {

	/**
	 * Whether this install holds working PAC credentials. Cheap — constant
	 * reads only — so routes may call it on every request.
	 */
	public function is_configured(): bool;

	/**
	 * Issue (stamp) a CFDI 4.0 invoice.
	 *
	 * @param array<string,mixed> $cfdi PAC-neutral invoice description (see class doc).
	 * @return array{uuid:string,pdf_b64:string,xml_b64:string,series:string,folio_fiscal:string}|\WP_Error
	 *         `uuid` is the SAT folio fiscal (UUID); `pdf_b64`/`xml_b64` are the
	 *         stamped documents base64-encoded; `series`/`folio_fiscal` echo the
	 *         Serie/Folio the PAC recorded on the invoice.
	 */
	public function issue( array $cfdi ): array|\WP_Error;

	/**
	 * Cancel a previously issued CFDI by its SAT UUID.
	 *
	 * @param string $uuid SAT folio fiscal (UUID) of the invoice to cancel.
	 * @return true|\WP_Error
	 */
	public function cancel( string $uuid ): true|\WP_Error;
}
