import { ApiError, HttpConfig, request, toBase64 } from './http';
import type {
	AdjustmentReason,
	Board,
	DailySummary,
	EmployeesResponse,
	LoyaltyCard,
	ModifierGroup,
	ModifierGroupsResponse,
	Operator,
	OperatorAuthorization,
	OperatorSession,
	OperatorsResponse,
	PosEvent,
	Shift,
	ShiftResponse,
	ShiftsResponse,
	PosOrder,
	PosProduct,
	QueueResponse,
	StaffTransition,
	WalkInInput,
	Withdrawal,
	WithdrawalInput,
	WithdrawalsResponse,
} from './types';

export interface PosCredentials {
	username: string;
	/** WordPress Application Password (spaces optional). */
	appPassword: string;
}

const NS = '/wp-json/haramara/v1';

/**
 * A random key for one logical write, used for idempotency.
 *
 * Not crypto — it only has to be unique across a counter's requests, and
 * `crypto.randomUUID` is absent on Hermes.
 */
export function newIdempotencyKey(): string {
	let out = '';
	for (let i = 0; i < 4; i += 1) out += Math.random().toString(36).slice(2, 10);
	return out.slice(0, 32);
}

/**
 * Staff client for `haramara/v1/pos/*`. Auth is HTTP Basic with a WordPress
 * Application Password; the account must hold the `manage_haramara` capability.
 */
export class PosApi {
	private readonly cfg: HttpConfig;
	private readonly auth: string;
	/**
	 * The signed operator session, when someone has entered their PIN. The app
	 * password identifies the *tablet*; this identifies the *person*, and every
	 * write is attributed to whoever it names.
	 */
	private operatorToken: string | null = null;

	constructor(cfg: HttpConfig, creds: PosCredentials) {
		this.cfg = cfg;
		this.auth = 'Basic ' + toBase64(`${creds.username}:${creds.appPassword.replace(/\s+/g, '')}`);
	}

	/** Attach (or clear, with null) the operator session sent on every call. */
	setOperatorToken(token: string | null): void {
		this.operatorToken = token;
	}

	private req<T>(path: string, opts: { method?: 'GET' | 'POST'; body?: unknown } = {}): Promise<T> {
		return request<T>(this.cfg, path, {
			...opts,
			headers: {
				Authorization: this.auth,
				...(this.operatorToken ? { 'X-Pos-Operator': this.operatorToken } : {}),
			},
		});
	}

	/* ---------------------------------------------------------------------- */
	/* Operator identity */
	/* ---------------------------------------------------------------------- */

	/** People who can sign in — active, with a PIN set. */
	async operators(): Promise<Operator[]> {
		const res = await this.req<OperatorsResponse>(`${NS}/pos/operators`);
		return res.operators;
	}

	/** Exchange a PIN for an operator session. Does not attach it — call setOperatorToken. */
	operatorLogin(operatorKey: string, pin: string): Promise<OperatorSession> {
		return this.req<OperatorSession>(`${NS}/pos/operator/login`, {
			method: 'POST',
			body: { operator: operatorKey, pin },
		});
	}

	/**
	 * Supervisor step-up for one privileged action.
	 *
	 * The returned authorization is bound to `action` and expires in about two
	 * minutes, so an approval for a void cannot be replayed against a refund.
	 */
	operatorAuthorize(operatorKey: string, pin: string, action: string): Promise<OperatorAuthorization> {
		return this.req<OperatorAuthorization>(`${NS}/pos/operator/authorize`, {
			method: 'POST',
			body: { operator: operatorKey, pin, action },
		});
	}

	/** Validates the stored credentials with the cheapest authenticated call. */
	async login(): Promise<Board> {
		return this.board();
	}

	board(date?: string, includeDone = false): Promise<Board> {
		const params = new URLSearchParams();
		if (date) params.set('date', date);
		if (includeDone) params.set('include_done', '1');
		const qs = params.toString();
		return this.req<Board>(`${NS}/pos/board${qs ? `?${qs}` : ''}`);
	}

	/** Online orders awaiting acceptance, oldest first, across all pickup dates. */
	queue(): Promise<QueueResponse> {
		return this.req<QueueResponse>(`${NS}/pos/queue`);
	}

	/** Slide-to-accept: moves a processing order to "En fila". 409 if already accepted. */
	accept(orderId: number): Promise<PosOrder> {
		return this.req<PosOrder>(`${NS}/pos/orders/${orderId}/accept`, { method: 'POST' });
	}

	/** Register this device's Expo push token for new-order alerts. */
	registerPushToken(token: string): Promise<{ registered: boolean }> {
		return this.req<{ registered: boolean }>(`${NS}/pos/push-token`, {
			method: 'POST',
			body: { token },
		});
	}

	transition(orderId: number, status: StaffTransition): Promise<PosOrder> {
		return this.req<PosOrder>(`${NS}/pos/orders/${orderId}/transition`, {
			method: 'POST',
			body: { status },
		});
	}

	/**
	 * Ring a counter sale.
	 *
	 * Pass `idempotency_key` and hold it steady across retries of the SAME
	 * logical sale — that is the only thing that stops a retry after a network
	 * timeout from ringing twice. Deliberately not minted here: a key generated
	 * per call changes on every retry, which looks like protection and is none.
	 * Use `newIdempotencyKey()` once, where the ticket lives.
	 */
	createWalkIn(input: WalkInInput): Promise<PosOrder> {
		return this.req<PosOrder>(`${NS}/pos/walk-in`, { method: 'POST', body: input });
	}

	/** Card state for a scanned loyalty token (read-only preview). */
	loyaltyCard(token: string): Promise<LoyaltyCard> {
		return this.req<LoyaltyCard>(`${NS}/app/loyalty/card?token=${encodeURIComponent(token)}`);
	}

	/**
	 * Add one stamp to the scanned card.
	 *
	 * Pass a stable `idempotencyKey` per scan — a double-tap or a retry must
	 * not stamp twice.
	 */
	loyaltyStamp(token: string, idempotencyKey?: string): Promise<LoyaltyCard> {
		return this.req<LoyaltyCard>(`${NS}/pos/loyalty/stamp`, {
			method: 'POST',
			body: { token, ...(idempotencyKey ? { idempotency_key: idempotencyKey } : {}) },
		});
	}

	/** Record one redemption on the scanned card. A redeem is free product — key it. */
	loyaltyRedeem(token: string, idempotencyKey?: string): Promise<LoyaltyCard> {
		return this.req<LoyaltyCard>(`${NS}/pos/loyalty/redeem`, {
			method: 'POST',
			body: { token, ...(idempotencyKey ? { idempotency_key: idempotencyKey } : {}) },
		});
	}

	/* ---------------------------------------------------------------------- */
	/* Cancelaciones y devoluciones */
	/* ---------------------------------------------------------------------- */

	/**
	 * Void an order. Inside the current open shift any operator may void with
	 * a reason; outside it (or with no shift) pass a supervisor authorization
	 * bound to action `void`. Key it — a retry must not double-void.
	 */
	async voidOrder(
		orderId: number,
		reason: AdjustmentReason,
		opts: { note?: string; authorization?: string; idempotencyKey?: string } = {},
	): Promise<PosEvent> {
		const res = await this.req<{ event: PosEvent }>(`${NS}/pos/orders/${orderId}/void`, {
			method: 'POST',
			body: {
				reason_code: reason,
				reason_note: opts.note ?? '',
				authorization: opts.authorization ?? '',
				...(opts.idempotencyKey ? { idempotency_key: opts.idempotencyKey } : {}),
			},
		});
		return res.event;
	}

	/** Full refund. ALWAYS needs a supervisor authorization bound to `refund`. */
	async refundOrder(
		orderId: number,
		reason: AdjustmentReason,
		authorization: string,
		opts: { note?: string; idempotencyKey?: string } = {},
	): Promise<PosEvent> {
		const res = await this.req<{ event: PosEvent }>(`${NS}/pos/orders/${orderId}/refund`, {
			method: 'POST',
			body: {
				reason_code: reason,
				reason_note: opts.note ?? '',
				authorization,
				...(opts.idempotencyKey ? { idempotency_key: opts.idempotencyKey } : {}),
			},
		});
		return res.event;
	}

	/* ---------------------------------------------------------------------- */
	/* Turno de caja */
	/* ---------------------------------------------------------------------- */

	/** The open shift, or null. Open shifts never include expected_cash. */
	async shift(): Promise<Shift | null> {
		const res = await this.req<ShiftResponse>(`${NS}/pos/shift/current`);
		return res.shift;
	}

	/** Variance history, newest first. */
	async shifts(): Promise<Shift[]> {
		const res = await this.req<ShiftsResponse>(`${NS}/pos/shifts`);
		return res.shifts;
	}

	/** Open a turno with the counted fondo inicial. Key it — a retry must not double-open. */
	async openShift(openingFloat: number, idempotencyKey?: string): Promise<Shift> {
		const res = await this.req<{ shift: Shift }>(`${NS}/pos/shift/open`, {
			method: 'POST',
			body: { opening_float: openingFloat, ...(idempotencyKey ? { idempotency_key: idempotencyKey } : {}) },
		});
		return res.shift;
	}

	/**
	 * Close the turno against the blind physical count. The response is the
	 * first place expected_cash and variance ever appear.
	 */
	async closeShift(declaredCash: number, note?: string, idempotencyKey?: string): Promise<Shift> {
		const res = await this.req<{ shift: Shift }>(`${NS}/pos/shift/close`, {
			method: 'POST',
			body: {
				declared_cash: declaredCash,
				note: note ?? '',
				...(idempotencyKey ? { idempotency_key: idempotencyKey } : {}),
			},
		});
		return res.shift;
	}

	/** Record a mid-shift retiro de efectivo (bills to the safe). */
	async cashDrop(amount: number, note?: string, idempotencyKey?: string): Promise<PosEvent> {
		const res = await this.req<{ event: PosEvent }>(`${NS}/pos/shift/cash-drop`, {
			method: 'POST',
			body: { amount, note: note ?? '', ...(idempotencyKey ? { idempotency_key: idempotencyKey } : {}) },
		});
		return res.event;
	}

	summary(date?: string): Promise<DailySummary> {
		return this.req<DailySummary>(`${NS}/pos/summary${date ? `?date=${date}` : ''}`);
	}

	/** Resolved modifier groups for one product (no-store fallback to the feed's copy). */
	async modifierGroups(productId: number): Promise<ModifierGroup[]> {
		const res = await this.req<ModifierGroupsResponse>(
			`${NS}/pos/modifier-groups?product_id=${productId}`,
		);
		return res.groups;
	}

	async products(): Promise<PosProduct[]> {
		const res = await this.req<{ products: PosProduct[] }>(`${NS}/pos/products`);
		return res.products;
	}

	/**
	 * Set the absolute on-hand quantity after a recount.
	 *
	 * Pass a stable `idempotencyKey` for one recount so a retry cannot be
	 * mistaken for a second, different count.
	 */
	setStock(productId: number, quantity: number, idempotencyKey?: string): Promise<PosProduct> {
		return this.req<PosProduct>(`${NS}/pos/products/${productId}/stock`, {
			method: 'POST',
			body: { quantity, ...(idempotencyKey ? { idempotency_key: idempotencyKey } : {}) },
		});
	}

	/** Record a salida interna (Malva, empleado, merma…). Decrements stock; never revenue. */
	createWithdrawal(input: WithdrawalInput): Promise<Withdrawal> {
		return this.req<Withdrawal>(`${NS}/pos/withdrawals`, { method: 'POST', body: input });
	}

	/** Salidas internas history + per-destination totals for a day (default: today). */
	withdrawals(date?: string): Promise<WithdrawalsResponse> {
		return this.req<WithdrawalsResponse>(`${NS}/pos/withdrawals${date ? `?date=${date}` : ''}`);
	}

	/** Shared employee-name list for the "¿Quién lo lleva?" picker. */
	async employees(): Promise<string[]> {
		const res = await this.req<EmployeesResponse>(`${NS}/pos/employees`);
		return res.employees;
	}

	/** Add a name to the shared list. Duplicates succeed without change. */
	async addEmployee(name: string): Promise<string[]> {
		const res = await this.req<EmployeesResponse>(`${NS}/pos/employees`, {
			method: 'POST',
			body: { name },
		});
		return res.employees;
	}
}

export { ApiError };
