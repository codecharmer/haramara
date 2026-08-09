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
}

export interface RevenueBucket {
	count: number;
	revenue: number;
}

export interface DailySummary {
	date: string;
	orders_total: number;
	revenue: number;
	currency: string;
	by_channel: Record<string, RevenueBucket>;
	by_payment_method: Record<string, RevenueBucket>;
	top_items: Array<{ name: string; quantity: number }>;
	/** Salidas internas (optional: absent on pre-1.1.0 servers). Never revenue. */
	withdrawals?: WithdrawalTotals;
}

export interface WalkInInput {
	items: Array<{ product_id: number; quantity: number }>;
	payment: WalkInPayment;
	note?: string;
}

/** Where a salida interna went (mirrored from Woo\Withdrawals::DESTINATIONS). */
export type WithdrawalDestination = 'malva' | 'empleado' | 'merma' | 'otro';

export interface WithdrawalInput {
	items: Array<{ product_id: number; quantity: number }>;
	destination: WithdrawalDestination;
	/** "¿Quién lo lleva?" — optional free text. */
	person?: string;
	note?: string;
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
	registered_by: string;
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
