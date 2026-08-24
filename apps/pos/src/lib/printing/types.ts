/**
 * Printing domain shapes — Phase 5 (ticket + comanda over raw ESC/POS).
 *
 * TicketPayload is deliberately NOT PosOrder: the server will eventually store
 * this exact payload per sale (byte-identical reprints, audited) and stamp it
 * with a folio + an HMAC-signed factura URL. Those two fields are optional
 * today because that server work lands in a later phase; everything else the
 * POS can already fill from the walk-in / order data at hand.
 */

/** Supported roll widths in millimeters. */
export type PaperWidth = 58 | 80;

/**
 * Printable columns at Font A (12x24 dots) — the classic pairing:
 * 58 mm -> 32 columns, 80 mm -> 48 columns.
 */
export function charsPerLine(paper: PaperWidth): 32 | 48 {
	return paper === 58 ? 32 : 48;
}

/** One sold line. `total` is the line total in MXN (unit x quantity). */
export interface TicketLine {
	quantity: number;
	name: string;
	/** Preparation notes ("Leche de avena", "Sin azúcar") — indented on the ticket, loud on the comanda. */
	modifiers?: string[];
	total: number;
}

export interface TicketPayload {
	/** Woo order number — what staff and customers call "el pedido". */
	orderNumber: string;
	/** Server-issued consecutive for reprint/factura tracking. Arrives in a later phase. */
	folio?: string;
	/** ISO 8601. */
	createdAt: string;
	/** Counter operator display name ("Le atendió: X"). */
	operator: string;
	items: TicketLine[];
	subtotal: number;
	/** Positive amount already subtracted from `total`. */
	discount?: number;
	/** Defaults to "Descuento" on the printed line. */
	discountLabel?: string;
	total: number;
	/** Human label, already localized ("Efectivo", "Tarjeta (terminal externa)"). */
	paymentLabel: string;
	/** Positive tip amount, printed after the payment line. */
	tip?: number;
	/** Kitchen-relevant free text; the comanda prints it, the ticket does not. */
	note?: string;
	/** HMAC-signed self-service factura link (the ticket QR). Arrives in a later phase. */
	facturaUrl?: string;
}

/** Per-print layout + branding. Business identity rides here so the renderers stay pure. */
export interface PrintOptions {
	paperWidth: PaperWidth;
	businessName: string;
	businessAddress: string;
	businessPhone: string;
	/** Closing message; a default thank-you prints when omitted. */
	footer?: string;
}

/** LAN printer endpoint — to be persisted in AsyncStorage by the printer-settings UI. */
export interface PrinterConfig {
	host: string;
	/** Raw-socket ("RAW"/JetDirect) port; 9100 when omitted. */
	port?: number;
	paperWidth: PaperWidth;
}
