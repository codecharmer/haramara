/**
 * Haramara brand tokens for the POS — the staff tool lives in the same cave.
 *
 * Dark like the bar it sits on (a white tablet would be the loudest object in
 * the room): carbon grounds, bone text, brass for the primary action and the
 * active state. Status hues are POS-specific operational roles; every fg
 * passes 4.5:1 on its tint. Hand-synced native copy of theme.json — change
 * the web tokens first, then this file and the customer twin.
 */

export const color = {
	// Brand.
	accent: '#C8A566',
	accentDeep: '#E0C48D',
	text: '#EFE8DC',
	textSoft: '#A29888',
	bg: '#131110',
	surface: '#1D1916',
	hairline: '#2E2922',

	// Status accents (fg passes 4.5:1 on its dark tint).
	attention: '#D9B36A', // por preparar
	attentionBg: '#2E2515',
	active: '#D99E80', // preparando
	activeBg: '#2E1F16',
	good: '#9DBE8D', // listo
	goodBg: '#20291C',
	queued: '#C39BB4', // en fila (aceptado, aún sin preparar)
	queuedBg: '#2A1F27',
	muted: '#A29888', // entregado / neutro
	mutedBg: '#1D1916',
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

export const type = {
	display: 28,
	title: 20,
	body: 16,
	small: 13,
	tiny: 11,
} as const;

const STATUS_STYLES: Record<string, { label: string; fg: string; bg: string }> = {
	processing: { label: 'Por preparar', fg: color.attention, bg: color.attentionBg },
	'on-hold': { label: 'En espera', fg: color.attention, bg: color.attentionBg },
	queued: { label: 'En fila', fg: color.queued, bg: color.queuedBg },
	preparing: { label: 'Preparando', fg: color.active, bg: color.activeBg },
	ready: { label: 'Listo', fg: color.good, bg: color.goodBg },
	completed: { label: 'Entregado', fg: color.muted, bg: color.mutedBg },
	cancelled: { label: 'Cancelado', fg: color.danger, bg: color.dangerBg },
};

export function statusStyle(status: string, fallbackLabel?: string) {
	return (
		STATUS_STYLES[status] ?? {
			label: fallbackLabel ?? status,
			fg: color.muted,
			bg: color.mutedBg,
		}
	);
}

export const TRANSITION_LABELS: Record<string, string> = {
	preparing: 'Preparando',
	ready: 'Listo',
	completed: 'Entregado',
};

/** Salidas internas destinations (mirrors Woo\Withdrawals::DESTINATIONS). */
export const DESTINATION_LABELS: Record<string, string> = {
	malva: 'Malva',
	empleado: 'Empleado',
	merma: 'Merma',
	otro: 'Otro',
};

// Reuses the status-accent pairs — every fg passes 4.5:1 on its tint.
const DESTINATION_STYLES: Record<string, { fg: string; bg: string }> = {
	malva: { fg: color.queued, bg: color.queuedBg },
	empleado: { fg: color.attention, bg: color.attentionBg },
	merma: { fg: color.danger, bg: color.dangerBg },
	otro: { fg: color.muted, bg: color.mutedBg },
};

export function destinationStyle(destination: string) {
	return DESTINATION_STYLES[destination] ?? { fg: color.muted, bg: color.mutedBg };
}

const mxn = new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' });

export function money(amount: number): string {
	return mxn.format(amount);
}
