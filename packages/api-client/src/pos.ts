import { ApiError, HttpConfig, request, toBase64 } from './http';
import type { Board, DailySummary, LoyaltyCard, PosOrder, PosProduct, QueueResponse, StaffTransition, WalkInInput } from './types';

export interface PosCredentials {
	username: string;
	/** WordPress Application Password (spaces optional). */
	appPassword: string;
}

const NS = '/wp-json/haramara/v1';

/**
 * Staff client for `haramara/v1/pos/*`. Auth is HTTP Basic with a WordPress
 * Application Password; the account must hold the `manage_haramara` capability.
 */
export class PosApi {
	private readonly cfg: HttpConfig;
	private readonly auth: string;

	constructor(cfg: HttpConfig, creds: PosCredentials) {
		this.cfg = cfg;
		this.auth = 'Basic ' + toBase64(`${creds.username}:${creds.appPassword.replace(/\s+/g, '')}`);
	}

	private req<T>(path: string, opts: { method?: 'GET' | 'POST'; body?: unknown } = {}): Promise<T> {
		return request<T>(this.cfg, path, { ...opts, headers: { Authorization: this.auth } });
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

	createWalkIn(input: WalkInInput): Promise<PosOrder> {
		return this.req<PosOrder>(`${NS}/pos/walk-in`, { method: 'POST', body: input });
	}

	/** Card state for a scanned loyalty token (read-only preview). */
	loyaltyCard(token: string): Promise<LoyaltyCard> {
		return this.req<LoyaltyCard>(`${NS}/app/loyalty/card?token=${encodeURIComponent(token)}`);
	}

	/** Add one stamp to the scanned card. */
	loyaltyStamp(token: string): Promise<LoyaltyCard> {
		return this.req<LoyaltyCard>(`${NS}/pos/loyalty/stamp`, { method: 'POST', body: { token } });
	}

	/** Record one redemption on the scanned card. */
	loyaltyRedeem(token: string): Promise<LoyaltyCard> {
		return this.req<LoyaltyCard>(`${NS}/pos/loyalty/redeem`, { method: 'POST', body: { token } });
	}

	summary(date?: string): Promise<DailySummary> {
		return this.req<DailySummary>(`${NS}/pos/summary${date ? `?date=${date}` : ''}`);
	}

	async products(): Promise<PosProduct[]> {
		const res = await this.req<{ products: PosProduct[] }>(`${NS}/pos/products`);
		return res.products;
	}
}

export { ApiError };
