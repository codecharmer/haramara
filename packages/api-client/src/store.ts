import { HttpConfig, request } from './http';

const NS = '/wp-json/wc/store/v1';

/* Pragmatic subsets of the WooCommerce Store API payloads — only the fields the
 * apps consume. Prices arrive in minor units as strings with currency metadata. */

export interface StorePrices {
	price: string;
	regular_price: string;
	currency_code: string;
	currency_minor_unit: number;
}

export interface StoreImage {
	id: number;
	src: string;
	thumbnail: string;
	alt: string;
}

export interface StoreProduct {
	id: number;
	name: string;
	slug: string;
	description: string;
	short_description: string;
	is_in_stock: boolean;
	low_stock_remaining: number | null;
	images: StoreImage[];
	categories: Array<{ id: number; name: string; slug: string }>;
	prices: StorePrices;
}

export interface StoreCategory {
	id: number;
	name: string;
	slug: string;
	count: number;
}

export interface CartItem {
	key: string;
	id: number;
	name: string;
	quantity: number;
	images: StoreImage[];
	prices: StorePrices;
	totals: { line_total: string; currency_minor_unit: number; currency_code: string };
}

export interface Cart {
	items: CartItem[];
	items_count: number;
	totals: { total_price: string; currency_minor_unit: number; currency_code: string };
}

export interface CheckoutBilling {
	first_name: string;
	last_name: string;
	email: string;
	phone: string;
	country?: string;
}

export interface CheckoutInput {
	billing_address: CheckoutBilling;
	payment_method: string;
	additional_fields: {
		'haramara/pickup-date': string;
		'haramara/pickup-slot': string;
	};
}

export interface CheckoutResult {
	order_id: number;
	order_key: string;
	status: string;
	payment_result: {
		payment_status: 'success' | 'failure' | 'pending' | 'error';
		redirect_url: string;
	};
}

export interface StoreApiOptions extends HttpConfig {
	/** Return the persisted Cart-Token, if any. */
	getCartToken?: () => string | null | Promise<string | null>;
	/** Persist a fresh Cart-Token whenever the server issues one. */
	onCartToken?: (token: string) => void | Promise<void>;
}

/**
 * Guest cart + checkout over the WooCommerce Store API. Session identity is the
 * Cart-Token header — capture and persistence are delegated to the host app so
 * the token survives restarts (AsyncStorage) without this package depending on
 * React Native.
 */
export class StoreApi {
	private readonly opts: StoreApiOptions;
	private token: string | null = null;

	constructor(opts: StoreApiOptions) {
		this.opts = opts;
	}

	private async req<T>(path: string, init: { method?: 'GET' | 'POST' | 'DELETE'; body?: unknown } = {}): Promise<T> {
		if (this.token === null && this.opts.getCartToken) {
			this.token = (await this.opts.getCartToken()) ?? null;
		}
		// Cart mutations are only authorized by a Cart-Token, and the server
		// mints one exclusively on GET — a tokenless POST is rejected asking
		// for a nonce. Bootstrap the guest session first.
		if ((init.method ?? 'GET') !== 'GET' && !this.token && path !== '/cart') {
			await this.cart();
		}
		return request<T>(this.opts, `${NS}${path}`, {
			...init,
			headers: this.token ? { 'Cart-Token': this.token } : {},
			onResponse: (response) => {
				const fresh = response.headers.get('Cart-Token');
				if (fresh && fresh !== this.token) {
					this.token = fresh;
					void this.opts.onCartToken?.(fresh);
				}
			},
		});
	}

	/** Drop the session (e.g. after checkout or a 403/409 cart error). */
	async resetSession(): Promise<void> {
		this.token = null;
		await this.opts.onCartToken?.('');
	}

	products(): Promise<StoreProduct[]> {
		return this.req<StoreProduct[]>('/products?per_page=100&status=publish');
	}

	categories(): Promise<StoreCategory[]> {
		return this.req<StoreCategory[]>('/products/categories?per_page=100&hide_empty=true');
	}

	cart(): Promise<Cart> {
		return this.req<Cart>('/cart');
	}

	addItem(id: number, quantity: number): Promise<Cart> {
		return this.req<Cart>('/cart/add-item', { method: 'POST', body: { id, quantity } });
	}

	updateItem(key: string, quantity: number): Promise<Cart> {
		return this.req<Cart>('/cart/update-item', { method: 'POST', body: { key, quantity } });
	}

	removeItem(key: string): Promise<Cart> {
		return this.req<Cart>('/cart/remove-item', { method: 'POST', body: { key } });
	}

	checkout(input: CheckoutInput): Promise<CheckoutResult> {
		return this.req<CheckoutResult>('/checkout', { method: 'POST', body: input });
	}
}

/** Format a Store API minor-unit price string, e.g. ("4800", 2) -> "48.00". */
export function formatMinorUnits(price: string, minorUnit: number): string {
	const n = Number(price) / 10 ** minorUnit;
	return n.toFixed(minorUnit);
}
