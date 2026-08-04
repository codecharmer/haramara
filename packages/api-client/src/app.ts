import { HttpConfig, request } from './http';
import type { AppConfig, AppOrder, LoyaltyCard, PickupDate, PickupSlotAvailability } from './types';

const NS = '/wp-json/haramara/v1';

/** Public (unauthenticated) client for the customer app surface. */
export class AppApi {
	private readonly cfg: HttpConfig;

	constructor(cfg: HttpConfig) {
		this.cfg = cfg;
	}

	config(): Promise<AppConfig> {
		return request<AppConfig>(this.cfg, `${NS}/app/config`);
	}

	async pickupDates(): Promise<PickupDate[]> {
		const res = await request<{ dates: PickupDate[] }>(this.cfg, `${NS}/pickup-dates`);
		return res.dates;
	}

	async availability(date: string): Promise<PickupSlotAvailability[]> {
		const res = await request<{ date: string; slots: PickupSlotAvailability[] }>(
			this.cfg,
			`${NS}/availability?date=${date}`,
		);
		return res.slots;
	}

	/** Order status, authorized by the order key returned at checkout. */
	order(id: number, key: string): Promise<AppOrder> {
		return request<AppOrder>(this.cfg, `${NS}/app/orders/${id}?key=${encodeURIComponent(key)}`);
	}

	registerPushToken(id: number, key: string, token: string): Promise<{ registered: boolean }> {
		return request<{ registered: boolean }>(this.cfg, `${NS}/app/orders/${id}/push-token`, {
			method: 'POST',
			body: { key, token },
		});
	}

	/** Register (or re-fetch) the device's loyalty card. Idempotent. */
	loyaltyRegister(device: string): Promise<LoyaltyCard> {
		return request<LoyaltyCard>(this.cfg, `${NS}/app/loyalty/register`, {
			method: 'POST',
			body: { device },
		});
	}

	/** Current card state for a signed card token. */
	loyaltyCard(token: string): Promise<LoyaltyCard> {
		return request<LoyaltyCard>(this.cfg, `${NS}/app/loyalty/card?token=${encodeURIComponent(token)}`);
	}
}
