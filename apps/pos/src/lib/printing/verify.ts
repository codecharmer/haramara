/**
 * Hardware-free self-verification for the printing module. This lineage has
 * no test suites, so this exports selfTest() — human-readable PASS/FAIL lines
 * — runnable from any TS-capable context (tsx, a compiled node run, or the
 * future "Página de prueba" button in printer settings).
 */

import { renderComanda } from './comanda';
import { EscPos, row, toCp850 } from './escpos';
import { renderPreviewText } from './preview';
import { renderTicket } from './ticket';
import type { PrintOptions, TicketPayload } from './types';

/** Representative sale: 2 items with modifiers, discount, tip, folio + factura QR. */
const SAMPLE: TicketPayload = {
	orderNumber: '1042',
	folio: 'HAR-000123',
	createdAt: '2026-08-24T09:30:00-06:00',
	operator: 'Baltazar',
	items: [
		{
			quantity: 2,
			name: 'Latte de avellana',
			modifiers: ['Leche de avena', 'Sin azúcar'],
			total: 130,
		},
		{
			quantity: 1,
			name: 'Chilaquiles verdes con pollo',
			modifiers: ['Extra queso'],
			total: 145,
		},
	],
	subtotal: 275,
	discount: 20,
	discountLabel: 'Cortesía',
	total: 255,
	paymentLabel: 'Efectivo',
	tip: 25,
	note: 'Sin cebolla, por favor',
	facturaUrl: 'https://haramaracafe.mx/factura/HAR-000123?t=0f3a9c',
};

const OPTS_58: PrintOptions = {
	paperWidth: 58,
	businessName: 'Haramara Café',
	businessAddress: 'Cuernavaca, Morelos',
	businessPhone: '777 000 0000',
	footer: '¡Gracias por tu visita!',
};

const OPTS_80: PrintOptions = { ...OPTS_58, paperWidth: 80 };

function check(label: string, ok: boolean, detail = ''): string {
	return `${ok ? 'PASS' : 'FAIL'}: ${label}${detail ? ` — ${detail}` : ''}`;
}

function findSequence(haystack: Uint8Array, needle: number[]): boolean {
	outer: for (let i = 0; i + needle.length <= haystack.length; i += 1) {
		for (let j = 0; j < needle.length; j += 1) {
			if (haystack[i + j] !== needle[j]) continue outer;
		}
		return true;
	}
	return false;
}

export function selfTest(): string[] {
	const results: string[] = [];

	// 1. CP850 transliteration of the full Spanish set.
	const encoded = Array.from(toCp850('Ñandú café ¿100%?'));
	const expected = [
		0xa5, 0x61, 0x6e, 0x64, 0xa3, 0x20, 0x63, 0x61, 0x66, 0x82, 0x20, 0xa8, 0x31, 0x30,
		0x30, 0x25, 0x3f,
	];
	results.push(
		check(
			'CP850 de "Ñandú café ¿100%?"',
			encoded.length === expected.length && encoded.every((b, i) => b === expected[i]),
			encoded.map((b) => b.toString(16).padStart(2, '0')).join(' '),
		),
	);

	// 2. QR pL/pH: 300 data bytes -> store-data length 303 -> pL=0x2F pH=0x01.
	const qrBytes = new EscPos().qr('x'.repeat(300)).build();
	const storeHeader = [0x1d, 0x28, 0x6b, 0x2f, 0x01, 0x31, 0x50, 0x30];
	results.push(
		check('QR pL/pH para payload de 300 chars (303 = 0x012F)', findSequence(qrBytes, storeHeader)),
	);

	// 3. Column layout truncation at both paper widths.
	for (const width of [32, 48] as const) {
		const r = row('Concha de vainilla edición de la casa', '$1,234.50', width);
		results.push(
			check(
				`row() a ${width} columnas`,
				r.length === width && r.endsWith('$1,234.50') && r.startsWith('Concha'),
				JSON.stringify(r),
			),
		);
	}

	// 4. Renderers produce plausible output for the representative sale.
	const ticket58 = renderTicket(SAMPLE, OPTS_58);
	const ticket80 = renderTicket(SAMPLE, OPTS_80);
	const comanda58 = renderComanda(SAMPLE, OPTS_58);
	const ticketPreview = renderPreviewText(ticket58);
	const comandaPreview = renderPreviewText(comanda58);

	results.push(
		check(
			'renderTicket no vacío (58/80)',
			ticket58.length > 0 && ticket80.length > 0,
			`${ticket58.length}/${ticket80.length} bytes`,
		),
	);
	results.push(
		check(
			'ticket trae TOTAL, QR y "Factura tu ticket"',
			ticketPreview.includes('TOTAL') &&
				ticketPreview.includes('[QR]') &&
				ticketPreview.includes('Factura tu ticket'),
		),
	);
	results.push(check('renderComanda no vacío', comanda58.length > 0, `${comanda58.length} bytes`));
	results.push(check('comanda sin precios (ni un solo "$")', !comandaPreview.includes('$')));
	results.push(
		check(
			'comanda trae items, modificadores y nota',
			comandaPreview.includes('Latte') &&
				comandaPreview.includes('>> Sin azúcar') &&
				comandaPreview.includes('Sin cebolla'),
		),
	);

	return results;
}
