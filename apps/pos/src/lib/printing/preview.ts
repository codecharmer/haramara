/**
 * Best-effort ESC/POS decoder so templates can be verified without hardware.
 * Text comes back as plain lines; structural commands become symbolic markers
 * ([CORTE], [QR] <data>, [CAJÓN]). Pure formatting (bold, size, alignment,
 * codepage) is consumed silently — the preview shows content and order, not
 * glyph metrics.
 */

import { CP850_MAP } from './escpos';

const REVERSE_CP850: Record<number, string> = {};
for (const [ch, code] of Object.entries(CP850_MAP)) REVERSE_CP850[code] = ch;

function decodeByte(b: number): string {
	if (b >= 0x20 && b < 0x7f) return String.fromCharCode(b);
	return REVERSE_CP850[b] ?? '?';
}

export function renderPreviewText(bytes: Uint8Array): string {
	const lines: string[] = [];
	let current = '';

	const flush = () => {
		lines.push(current);
		current = '';
	};
	const marker = (text: string) => {
		if (current) flush();
		lines.push(text);
	};

	let i = 0;
	while (i < bytes.length) {
		const b = bytes[i];

		if (b === 0x0a) {
			flush();
			i += 1;
		} else if (b === 0x0d) {
			i += 1;
		} else if (b === 0x1b) {
			// ESC family.
			const cmd = bytes[i + 1];
			if (cmd === 0x40) {
				i += 2; // init
			} else if (cmd === 0x64) {
				// Feed n: print the buffer, advance n lines.
				const n = bytes[i + 2] ?? 0;
				if (current) flush();
				for (let f = 0; f < n; f += 1) lines.push('');
				i += 3;
			} else if (cmd === 0x70) {
				marker('[CAJÓN: pulso]');
				i += 5; // ESC p m t1 t2
			} else if (cmd === 0x74 || cmd === 0x45 || cmd === 0x61 || cmd === 0x21) {
				i += 3; // one-arg formatting: codepage / bold / align / font
			} else {
				i += 2; // unknown ESC — skip the command byte, keep decoding
			}
		} else if (b === 0x1d) {
			// GS family.
			const cmd = bytes[i + 1];
			if (cmd === 0x21) {
				i += 3; // size
			} else if (cmd === 0x56) {
				// Cut; functions A/B (65/66) carry a feed argument.
				const m = bytes[i + 2];
				marker('[------ CORTE ------]');
				i += m === 0x41 || m === 0x42 ? 4 : 3;
			} else if (cmd === 0x28) {
				// GS ( x pL pH <payload>. QR store-data (k, cn=49, fn=80)
				// carries the symbol text; everything else is skipped whole.
				const sub = bytes[i + 2];
				const len = (bytes[i + 3] ?? 0) + ((bytes[i + 4] ?? 0) << 8);
				const body = bytes.slice(i + 5, i + 5 + len);
				if (sub === 0x6b && body[0] === 0x31 && body[1] === 0x50) {
					let data = '';
					for (let d = 3; d < body.length; d += 1) data += decodeByte(body[d]);
					marker(`[QR] ${data}`);
				}
				i += 5 + len;
			} else {
				i += 2;
			}
		} else {
			current += decodeByte(b);
			i += 1;
		}
	}
	if (current) flush();
	return lines.join('\n');
}

/** Classic three-column dump: offset, hex, printable ASCII. */
export function hexDump(bytes: Uint8Array): string {
	const rows: string[] = [];
	for (let offset = 0; offset < bytes.length; offset += 16) {
		const slice = Array.from(bytes.slice(offset, offset + 16));
		const hex = slice.map((b) => b.toString(16).padStart(2, '0')).join(' ');
		const ascii = slice
			.map((b) => (b >= 0x20 && b < 0x7f ? String.fromCharCode(b) : '.'))
			.join('');
		rows.push(`${offset.toString(16).padStart(8, '0')}  ${hex.padEnd(47)}  |${ascii}|`);
	}
	return rows.join('\n');
}
