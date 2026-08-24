/**
 * Pure-TS ESC/POS byte builder. No dependencies, no I/O — it only assembles
 * Uint8Array programs that transport.ts ships to the printer verbatim.
 *
 * Every command sticks to the lowest-common-denominator Epson dialect that
 * generic 58/80 mm LAN thermal printers understand: ESC @, ESC t, ESC E,
 * ESC a, ESC d, ESC p, GS !, GS V, and native QR via GS ( k.
 */

const ESC = 0x1b;
const GS = 0x1d;
const LF = 0x0a;

export type Align = 'left' | 'center' | 'right';

/**
 * CP850 (selected with ESC t 2) covers Spanish. These are the codepoints the
 * café actually prints — accents, eñes, inverted punctuation, the degree sign.
 */
export const CP850_MAP: Record<string, number> = {
	á: 0xa0,
	é: 0x82,
	í: 0xa1,
	ó: 0xa2,
	ú: 0xa3,
	Á: 0xb5,
	É: 0x90,
	Í: 0xd6,
	Ó: 0xe0,
	Ú: 0xe9,
	ü: 0x81,
	Ü: 0x9a,
	ñ: 0xa4,
	Ñ: 0xa5,
	'¿': 0xa8,
	'¡': 0xad,
	'°': 0xf8,
};

/**
 * Encode a string as CP850 bytes. Plain ASCII passes through, the table above
 * covers Spanish, and anything else degrades safely: strip combining accents
 * (NFD) and keep the ASCII base letter, else print '?'. A wrong glyph on
 * thermal paper is acceptable; a runtime throw mid-service is not.
 */
export function toCp850(text: string): Uint8Array {
	const out: number[] = [];
	for (const ch of text) {
		const code = ch.codePointAt(0) as number;
		if (code < 0x80) {
			out.push(code);
			continue;
		}
		const mapped = CP850_MAP[ch];
		if (mapped !== undefined) {
			out.push(mapped);
			continue;
		}
		const base = ch.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
		const baseCode = base.length === 1 ? (base.codePointAt(0) as number) : -1;
		out.push(baseCode >= 0x20 && baseCode < 0x80 ? baseCode : 0x3f);
	}
	return Uint8Array.from(out);
}

/**
 * Two-column layout: left text, right-aligned amount, always exactly `width`
 * chars with at least one space between. The left column truncates before the
 * right column ever moves — totals must stay in their lane down the ticket.
 */
export function row(left: string, right: string, width: number): string {
	const r = right.length >= width ? right.slice(0, width) : right;
	if (r.length >= width) return r;
	const maxLeft = width - r.length - 1;
	const l = left.length > maxLeft ? left.slice(0, Math.max(0, maxLeft)) : left;
	return l + ' '.repeat(width - l.length - r.length) + r;
}

/** Greedy word wrap; words longer than the width hard-split. Never returns []. */
export function wrap(text: string, width: number): string[] {
	const safeWidth = Math.max(1, width);
	const words = text.split(/\s+/).filter((w) => w.length > 0);
	const lines: string[] = [];
	let current = '';
	for (let word of words) {
		while (word.length > safeWidth) {
			if (current) {
				lines.push(current);
				current = '';
			}
			lines.push(word.slice(0, safeWidth));
			word = word.slice(safeWidth);
		}
		if (!current) current = word;
		else if (current.length + 1 + word.length <= safeWidth) current += ' ' + word;
		else {
			lines.push(current);
			current = word;
		}
	}
	if (current) lines.push(current);
	return lines.length > 0 ? lines : [''];
}

export class EscPos {
	private readonly out: number[] = [];

	raw(...bytes: number[]): this {
		for (const b of bytes) this.out.push(b & 0xff);
		return this;
	}

	/** ESC @ — reset the printer to power-on formatting. Every job starts here. */
	init(): this {
		return this.raw(ESC, 0x40);
	}

	/** ESC t 2 — CP850, the codepage with the Spanish set toCp850() targets. */
	codepage(): this {
		return this.raw(ESC, 0x74, 0x02);
	}

	text(value: string): this {
		for (const b of toCp850(value)) this.out.push(b);
		return this;
	}

	line(value = ''): this {
		return this.text(value).raw(LF);
	}

	/** ESC d n — print buffered data and feed n lines. */
	feed(lines = 1): this {
		return this.raw(ESC, 0x64, Math.max(0, Math.min(255, lines)));
	}

	/** ESC E n. */
	bold(on: boolean): this {
		return this.raw(ESC, 0x45, on ? 1 : 0);
	}

	/** GS ! n — character width/height multipliers (1 = normal, 2 = double). */
	size(width: 1 | 2, height: 1 | 2): this {
		return this.raw(GS, 0x21, ((width - 1) << 4) | (height - 1));
	}

	/** ESC a n. */
	align(where: Align): this {
		return this.raw(ESC, 0x61, where === 'left' ? 0 : where === 'center' ? 1 : 2);
	}

	rule(width: number, ch = '-'): this {
		return this.line(ch.repeat(Math.max(0, width)));
	}

	/** GS V 66 3 — feed to the blade, then partial cut. */
	cut(): this {
		return this.raw(GS, 0x56, 0x42, 0x03);
	}

	/** ESC p 0 25 250 — pulse drawer pin 2. Wired now for the future cash drawer. */
	drawerKick(): this {
		return this.raw(ESC, 0x70, 0x00, 0x19, 0xfa);
	}

	/**
	 * Native QR via GS ( k: model 2, configurable module size (dots), error
	 * correction M. pL/pH encode each function's parameter length little-endian;
	 * for the store-data function that length is `data.length + 3` because the
	 * cn/fn/m identification bytes ride inside the counted payload.
	 */
	qr(data: string, moduleSize = 6): this {
		const payload = toCp850(data);
		const len = payload.length + 3;
		const pL = len & 0xff;
		const pH = (len >> 8) & 0xff;
		const size = Math.max(1, Math.min(16, Math.floor(moduleSize)));
		// Function 65: select model 2.
		this.raw(GS, 0x28, 0x6b, 0x04, 0x00, 0x31, 0x41, 0x32, 0x00);
		// Function 67: module size.
		this.raw(GS, 0x28, 0x6b, 0x03, 0x00, 0x31, 0x43, size);
		// Function 69: error correction level M.
		this.raw(GS, 0x28, 0x6b, 0x03, 0x00, 0x31, 0x45, 0x31);
		// Function 80: store the data in the symbol buffer.
		this.raw(GS, 0x28, 0x6b, pL, pH, 0x31, 0x50, 0x30);
		for (const b of payload) this.out.push(b);
		// Function 81: print the buffered symbol.
		return this.raw(GS, 0x28, 0x6b, 0x03, 0x00, 0x31, 0x51, 0x30);
	}

	build(): Uint8Array {
		return Uint8Array.from(this.out);
	}
}
