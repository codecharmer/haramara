/**
 * Haramara brand tokens for the customer app — the cave at dusk.
 *
 * Mirrors the block theme's world (theme.json is the web source of truth;
 * DESIGN.md records the rules): matte warm blacks under limestone/bone text,
 * brass as the only luminous voice, square-cornered chrome, Italiana for
 * display moments over system UI type. Values here are the hand-synced native
 * copy — change theme.json first, then this file and the POS twin.
 */

export const color = {
	/** Page ground — carbon. */
	bg: '#131110',
	/** Elevated surface — charred wood. */
	surface: '#1D1916',
	/** Deeper wells (inputs, insets) — espresso. */
	well: '#241D17',
	/** Deepest ground — noche; the only ink on bone or brass fills. */
	noche: '#0D0C0A',
	/** Primary text — bone. */
	text: '#EFE8DC',
	/** Secondary text — smoke (6.5:1 on carbon). */
	textSoft: '#A29888',
	/** Brass: the one luminous accent; actions and focus. */
	accent: '#C8A566',
	/** Brass-tinted surface for chips/badges on dark. */
	accentSoft: '#33291A',
	/** Brighter brass for pressed/emphasis states. */
	accentDeep: '#E0C48D',
	/** Hairline on dark grounds. */
	hairline: '#2E2922',
	good: '#9DBE8D',
	goodBg: '#20291C',
	attention: '#D9B36A',
	attentionBg: '#2E2515',
	danger: '#D98D80',
	dangerBg: '#301B18',
} as const;

export const space = (n: number) => n * 4;

/** Square corners are the brand; the pill survives only for badges. */
export const radius = {
	card: 2,
	control: 2,
	pill: 999,
} as const;

export const font = {
	display: 'Italiana_400Regular',
	displayItalic: 'Petrona_500Medium_Italic',
} as const;

export const type = {
	hero: 34,
	display: 26,
	title: 19,
	body: 16,
	small: 13,
	tiny: 11,
} as const;

const STATUS_STYLES: Record<string, { label: string; fg: string; bg: string }> = {
	pending: { label: 'Pago pendiente', fg: color.attention, bg: color.attentionBg },
	processing: { label: 'Recibido', fg: color.attention, bg: color.attentionBg },
	'on-hold': { label: 'En espera', fg: color.attention, bg: color.attentionBg },
	queued: { label: 'En fila', fg: color.accentDeep, bg: color.accentSoft },
	preparing: { label: 'En barra', fg: color.accentDeep, bg: color.accentSoft },
	ready: { label: 'Listo para recoger', fg: color.good, bg: color.goodBg },
	completed: { label: 'Entregado', fg: color.textSoft, bg: color.surface },
	cancelled: { label: 'Cancelado', fg: color.danger, bg: color.dangerBg },
};

export function statusStyle(status: string, fallbackLabel?: string) {
	return STATUS_STYLES[status] ?? { label: fallbackLabel ?? status, fg: color.textSoft, bg: color.surface };
}

const mxn = new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' });

export function money(amount: number): string {
	return mxn.format(amount);
}
