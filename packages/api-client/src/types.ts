/**
 * Shapes mirrored from the haramara-core PHP serializers. Field names match the
 * JSON payloads exactly — see Rest\AppRoutes, Rest\PosRoutes, Ordering\OrderBoard.
 */

export type StaffTransition = 'preparing' | 'ready' | 'completed';

export type WalkInPayment = 'cash' | 'card_external';

export interface OrderItem {
	name: string;
	quantity: number;
	total: number;
	/** Visible modifier lines ("Leche: Avena (+$15.00)"); absent on old servers. */
	modifiers?: string[];
}

export interface PickupInfo {
	date: string;
	slot: string;
	label: string;
}

/** Staff-facing order (Ordering\OrderBoard::serialize_order). */
export interface PosOrder {
	id: number;
	number: string;
	/** Printable ticket folio (`F4M-443F`); the /factura handle. Absent pre-Phase-2. */
	folio?: string;
	/** Propina in MXN. Meta-only — never part of `total`. 0 when none. */
	tip?: number;
	/** '' when no tip. */
	tip_method?: TipMethod | '';
	status: string;
	status_label: string;
	customer: string;
	phone: string;
	items: OrderItem[];
	total: number;
	currency: string;
	payment_method: string;
	payment_method_title: string;
	note: string;
	created_via: string;
	created_at: string | null;
	pickup: PickupInfo;
	allowed_transitions: StaffTransition[];
}

/** Incoming online orders awaiting POS acceptance (GET /pos/queue). */
export interface QueueResponse {
	orders: PosOrder[];
	count: number;
}

export interface BoardSlot {
	slot: string;
	orders: PosOrder[];
}

export interface Board {
	date: string;
	slots: BoardSlot[];
	walk_ins: PosOrder[];
	counts: {
		pending_prep: number;
		ready: number;
		walk_ins: number;
	};
}

export interface PosProduct {
	id: number;
	name: string;
	price: number;
	in_stock: boolean;
	manage_stock: boolean;
	stock_quantity: number | null;
	image: string;
	categories: string[];
	/** Resolved modifier groups (absent on pre-Phase-4 servers). */
	modifier_groups?: ModifierGroup[];
}

/* -------------------------------------------------------------------------- */
/* Modificadores (Catalog\ModifierResolver / Rest\CatalogRoutes)              */
/* -------------------------------------------------------------------------- */

/** One selectable option inside a modifier group. `price_delta` in MXN, per unit. */
export interface ModifierOption {
	key: string;
	name: string;
	price_delta: number;
}

/**
 * One resolved modifier group (Catalog\ModifierResolver::serialize).
 * `max` 0 = no limit, 1 = single-select. A `required` group needs at least
 * max(min, 1) picks; an optional group may be skipped entirely, but once one
 * option is chosen its min/max apply.
 */
export interface ModifierGroup {
	id: number;
	name: string;
	min: number;
	max: number;
	required: boolean;
	options: ModifierOption[];
}

/** GET /pos/modifier-groups?product_id= (Rest\CatalogRoutes). */
export interface ModifierGroupsResponse {
	product_id: number;
	groups: ModifierGroup[];
}

/** Chosen option keys for one group, sent per sale line. */
export interface ModifierSelection {
	group_id: number;
	option_keys: string[];
}

export interface RevenueBucket {
	count: number;
	revenue: number;
}

export interface DailySummary {
	date: string;
	orders_total: number;
	/**
	 * Absent while a shift is open and the requester is not a supervisor —
	 * everything cash can be derived from is withheld until the blind count
	 * is declared (`cash_visible: false`).
	 */
	revenue?: number;
	currency: string;
	/** False = cash buckets and revenue are redacted for the blind count. */
	cash_visible?: boolean;
	by_channel: Record<string, RevenueBucket>;
	by_payment_method: Record<string, RevenueBucket>;
	top_items: Array<{ name: string; quantity: number }>;
	/** Salidas internas (optional: absent on pre-1.1.0 servers). Never revenue. */
	withdrawals?: WithdrawalTotals;
	/** Cancelaciones/devoluciones/descuentos/cortesías — never netted into revenue. */
	adjustments?: AdjustmentsSummary;
	/** Propinas — excluded from revenue by construction; absent while redacted. */
	tips?: TipsSummary;
}

/** The day's propinas, per method and per employee. */
export interface TipsSummary {
	total: number;
	by_method: Partial<Record<TipMethod, number>>;
	by_operator: Record<string, number>;
}

/** PosEvents::summary_for_date — the corte's adjustment buckets. */
export interface AdjustmentsSummary {
	by_type: Partial<Record<PosEvent['type'], { count: number; value: number }>>;
	events: PosEvent[];
}

/** Reason codes the ledger accepts; `otro` requires a note. */
export type AdjustmentReason =
	| 'error_captura'
	| 'cliente_cancelo'
	| 'producto_mal_hecho'
	| 'cortesia'
	| 'ajuste_precio'
	| 'retiro_efectivo'
	| 'otro';

/** How a propina was handed over: cash lands in the drawer, card rides the terminal. */
export type TipMethod = 'cash' | 'card';

/** Propina riding WalkInInput. Never revenue; cash tips count toward expected drawer cash. */
export interface WalkInTip {
	amount: number;
	/** Defaults server-side to the payment kind. */
	method?: TipMethod;
}

/** Sale-time discount riding WalkInInput. Above the configured % it needs `authorization`. */
export interface WalkInDiscount {
	amount: number;
	reason_code: AdjustmentReason;
	reason_note?: string;
	authorization?: string;
}

export interface WalkInInput {
	items: Array<{ product_id: number; quantity: number; modifiers?: ModifierSelection[] }>;
	payment: WalkInPayment;
	note?: string;
	discount?: WalkInDiscount;
	tip?: WalkInTip;
	/**
	 * Client-generated key that makes a retried charge settle once. Omit and
	 * PosApi mints one; pass your own to retry the *same* logical sale after a
	 * network failure (that is the whole point — a fresh key rings twice).
	 */
	idempotency_key?: string;
}

/** Where a salida interna went (mirrored from Woo\Withdrawals::DESTINATIONS). */
export type WithdrawalDestination = 'malva' | 'empleado' | 'merma' | 'otro';

export interface WithdrawalInput {
	items: Array<{ product_id: number; quantity: number }>;
	destination: WithdrawalDestination;
	/** "¿Quién lo lleva?" — optional free text. */
	person?: string;
	note?: string;
	/** See WalkInInput.idempotency_key. */
	idempotency_key?: string;
}

export interface WithdrawalLine {
	product_id: number;
	name: string;
	quantity: number;
	unit_price: number;
	value: number;
}

/** One recorded salida interna (Woo\Withdrawals — lines share a group key). */
export interface Withdrawal {
	key: string;
	created_at: string;
	destination: WithdrawalDestination;
	destination_label: string;
	person: string;
	note: string;
	/** Counter operator when known, else the device account's display name. */
	registered_by: string;
	/** The PIN-authenticated operator who recorded it; '' on pre-roster rows. */
	operator?: string;
	items: WithdrawalLine[];
	total_quantity: number;
	total_value: number;
}

export interface WithdrawalTotals {
	pieces: number;
	value: number;
	by_destination: Partial<Record<WithdrawalDestination, { pieces: number; value: number }>>;
}

export interface WithdrawalsResponse {
	date: string;
	withdrawals: Withdrawal[];
	totals: WithdrawalTotals;
}

/** Shared employee-name list backing the withdrawal "¿Quién lo lleva?" picker. */
export interface EmployeesResponse {
	employees: string[];
}

/* -------------------------------------------------------------------------- */
/* Counter operators (Staff\Operators)                                        */
/* -------------------------------------------------------------------------- */

/**
 * Error codes meaning "the operator session is stale" — NOT "the device
 * credentials are bad". The distinction is load-bearing: treating these as a
 * device-auth failure signs the tablet out and forces re-entry of the server
 * URL and application password mid-service, when all that was needed was a NIP.
 */
export const OPERATOR_SESSION_ERROR_CODES = [
	'haramara_operator_bad_token',
	'haramara_operator_token_expired',
] as const;

/** `supervisor` may authorize privileged actions; `cajero` may not. */
export type OperatorRole = 'cajero' | 'supervisor';

/** A person the POS can sign in as. Never carries the PIN hash. */
export interface Operator {
	key: string;
	name: string;
	role: OperatorRole;
}

/* -------------------------------------------------------------------------- */
/* Turno de caja (Ordering\Shifts)                                            */
/* -------------------------------------------------------------------------- */

/**
 * One drawer session. `expected_cash`/`variance`/`declared_cash` exist ONLY on
 * closed shifts — an open shift never carries them anywhere in the API; the
 * blind count is the point.
 */
export interface Shift {
	id: number;
	status: 'open' | 'closed';
	opened_at: string;
	opened_by: string;
	opening_float: number;
	/** Mid-shift retiros already recorded against this shift. */
	cash_drops: number;
	closed_at?: string;
	closed_by?: string;
	declared_cash?: number;
	expected_cash?: number;
	/** declared − expected. Negative = faltante, positive = sobrante. */
	variance?: number;
	note?: string;
}

export interface ShiftResponse {
	shift: Shift | null;
}

export interface ShiftsResponse {
	shifts: Shift[];
}

/** A ledger row from the append-only POS event table. */
export interface PosEvent {
	id: number;
	created_at: string;
	shift_id: number;
	operator: string;
	authorized_by: string;
	type: 'void' | 'refund' | 'discount' | 'comp' | 'cash_drop' | 'no_sale' | 'reprint';
	type_label: string;
	order_id: number | null;
	tab_id: number | null;
	amount: number;
	reason_code: string;
	reason_note: string;
	items: Array<Record<string, unknown>> | null;
}

export interface OperatorsResponse {
	operators: Operator[];
}

/** Result of POST /pos/operator/login. */
export interface OperatorSession {
	operator: Operator;
	/** Signed `key.expiry.hmac`, sent back as the X-Pos-Operator header. */
	token: string;
	/** Unix seconds. */
	expires: number;
}

/** Result of POST /pos/operator/authorize — a supervisor step-up, bound to one action. */
export interface OperatorAuthorization {
	authorization: string;
	authorized_by: string;
	/** Unix seconds — these live for about two minutes. */
	expires: number;
}

/** Customer-facing order (Rest\AppRoutes::get_order). */
export interface AppOrder {
	id: number;
	number: string;
	status: string;
	status_label: string;
	items: OrderItem[];
	total: number;
	currency: string;
	payment_method: string;
	payment_method_title: string;
	pickup: PickupInfo & { instructions: string };
}

export interface AppConfig {
	business: {
		name: string;
		phone: string;
		whatsapp: string;
		address: string;
		hours_summary: string;
		hours_closed: string;
		instagram: string;
		maps_url: string;
		latitude: string;
		longitude: string;
	};
	pickup: {
		instructions: string;
		lead_time_hours: number;
		open_days: number[];
	};
	payments: {
		cod: boolean;
		stripe: boolean;
		mercadopago: boolean;
	};
	/** Whether the server can sign Apple Wallet loyalty passes (absent on pre-1.1.0 servers). */
	wallet_pass?: boolean;
	min_app_version: string;
}

export interface PickupDate {
	date: string;
	weekday: number;
	label: string;
}

export interface PickupSlotAvailability {
	slot: string;
	label: string;
	remaining: number;
}

/** Lealtad Haramara card state (mirrored from Loyalty\\Members::card_payload). */
export interface LoyaltyCard {
	memberKey: string;
	token: string;
	stamps: number;
	redeemed: number;
}
