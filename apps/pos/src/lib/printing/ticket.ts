/**
 * Customer receipt renderer. Pure function: payload + layout options in,
 * ESC/POS bytes out — no I/O, no state. The server will later store the exact
 * payload each ticket was rendered from, so a reprint replays these bytes
 * byte-identically (and gets audited server-side).
 */

import { EscPos, row, wrap } from './escpos';
import { charsPerLine, type PrintOptions, type TicketPayload } from './types';

const DEFAULT_FOOTER = '¡Gracias por tu visita!';

/**
 * `$1,234.50` — hand-rolled instead of Intl so the rendered bytes are
 * identical on Hermes, node, and whatever runs a future reprint.
 */
export function fmtMoney(amount: number): string {
	const sign = amount < 0 ? '-' : '';
	const [int, dec] = Math.abs(amount).toFixed(2).split('.');
	return `${sign}$${int.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}.${dec}`;
}

/** `24/08/2026 09:30` — an unparseable date falls back to the raw string. */
export function fmtDateTime(iso: string): string {
	const d = new Date(iso);
	if (Number.isNaN(d.getTime())) return iso;
	const p = (n: number) => String(n).padStart(2, '0');
	return `${p(d.getDate())}/${p(d.getMonth() + 1)}/${d.getFullYear()} ${p(d.getHours())}:${p(d.getMinutes())}`;
}

export function renderTicket(payload: TicketPayload, opts: PrintOptions): Uint8Array {
	const width = charsPerLine(opts.paperWidth);
	const half = Math.floor(width / 2);
	const b = new EscPos();

	b.init().codepage();

	// Business header — the name goes out double-width, so wrap at half.
	b.align('center').bold(true).size(2, 1);
	for (const l of wrap(opts.businessName, half)) b.line(l);
	b.size(1, 1).bold(false);
	for (const l of wrap(opts.businessAddress, width)) b.line(l);
	b.line(`Tel. ${opts.businessPhone}`);

	b.align('left').rule(width);

	// Folio + order number + timestamp.
	if (payload.folio) b.line(row(`Folio: ${payload.folio}`, `#${payload.orderNumber}`, width));
	else b.line(`Pedido #${payload.orderNumber}`);
	b.line(fmtDateTime(payload.createdAt));
	b.rule(width);

	// Line items; modifiers indent under their item.
	for (const item of payload.items) {
		b.line(row(`${item.quantity} ${item.name}`, fmtMoney(item.total), width));
		for (const mod of item.modifiers ?? []) {
			wrap(mod, width - 4).forEach((l, i) => b.line(`${i === 0 ? '  + ' : '    '}${l}`));
		}
	}
	b.rule(width);

	// Totals. Double height on TOTAL keeps the column math intact (GS ! only
	// stretches vertically here), so the amount stays in its lane.
	b.line(row('Subtotal', fmtMoney(payload.subtotal), width));
	if (payload.discount && payload.discount > 0) {
		b.line(row(payload.discountLabel ?? 'Descuento', `-${fmtMoney(payload.discount)}`, width));
	}
	b.bold(true).size(1, 2);
	b.line(row('TOTAL', fmtMoney(payload.total), width));
	b.size(1, 1).bold(false);
	b.line(row('Pago', payload.paymentLabel, width));
	if (payload.tip && payload.tip > 0) b.line(row('Propina', fmtMoney(payload.tip), width));

	b.rule(width);
	b.line(`Le atendió: ${payload.operator}`);

	// Self-service factura QR (server phase supplies the signed URL).
	if (payload.facturaUrl) {
		b.feed(1).align('center');
		b.qr(payload.facturaUrl, opts.paperWidth === 58 ? 5 : 7);
		b.line('Factura tu ticket');
		b.align('left');
	}

	b.feed(1).align('center');
	for (const l of wrap(opts.footer ?? DEFAULT_FOOTER, width)) b.line(l);
	b.align('left').feed(4).cut();

	return b.build();
}
