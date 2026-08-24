/**
 * Bar/kitchen slip renderer. No prices anywhere — the comanda answers
 * "¿qué preparo?", never "¿cuánto cuesta?". Quantities and names go out
 * double-size so they read from arm's length over a hot bar, and modifiers
 * stay loud because they are the part the bar gets wrong.
 */

import { EscPos, wrap } from './escpos';
import { charsPerLine, type PrintOptions, type TicketPayload } from './types';

/** `09:30` — an unparseable date falls back to the raw string. */
function fmtTime(iso: string): string {
	const d = new Date(iso);
	if (Number.isNaN(d.getTime())) return iso;
	const p = (n: number) => String(n).padStart(2, '0');
	return `${p(d.getHours())}:${p(d.getMinutes())}`;
}

export function renderComanda(payload: TicketPayload, opts: PrintOptions): Uint8Array {
	const width = charsPerLine(opts.paperWidth);
	const half = Math.floor(width / 2);
	const b = new EscPos();

	b.init().codepage();

	b.align('center').bold(true).size(2, 2).line('COMANDA').size(1, 1).bold(false);
	b.align('left').rule(width, '=');

	b.bold(true).size(2, 1);
	for (const l of wrap(`Pedido #${payload.orderNumber}`, half)) b.line(l);
	b.size(1, 1).bold(false);
	if (payload.folio) b.line(`Folio: ${payload.folio}`);
	b.line(`Hora: ${fmtTime(payload.createdAt)}`);
	b.rule(width, '=');

	for (const item of payload.items) {
		b.bold(true).size(2, 2);
		for (const l of wrap(`${item.quantity} x ${item.name}`, half)) b.line(l);
		// Modifiers: double width, normal height — distinct from the item line
		// but impossible to miss.
		b.size(2, 1);
		for (const mod of item.modifiers ?? []) {
			for (const l of wrap(`>> ${mod}`, half)) b.line(l);
		}
		b.size(1, 1).bold(false).feed(1);
	}

	if (payload.note) {
		b.rule(width);
		b.bold(true).line('NOTA:');
		for (const l of wrap(payload.note, width)) b.line(l);
		b.bold(false);
	}

	b.rule(width, '=');
	b.line(`Atiende: ${payload.operator}`);
	b.feed(4).cut();

	return b.build();
}
