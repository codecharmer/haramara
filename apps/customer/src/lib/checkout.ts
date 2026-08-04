/**
 * Checkout submission: rebuild the server cart from the local one, then place
 * the order through the Store API with the pickup additional fields. The
 * server re-validates everything (stock, slot capacity, lead time) and its
 * error messages are already in Spanish — surface them verbatim.
 */

import { ApiError, type CheckoutResult } from '@haramara/api-client';

import { storeApi } from './api';
import type { CartLine } from './cart';

export interface CheckoutForm {
	firstName: string;
	lastName: string;
	phone: string;
	email: string;
	pickupDate: string;
	pickupSlot: string;
}

/** "777 123 4567" → "+527771234567"; already-E.164 numbers pass through. */
export function normalizePhone(raw: string): string {
	const digits = raw.replace(/[^\d+]/g, '');
	if (digits.startsWith('+')) return digits;
	const bare = digits.replace(/\D/g, '');
	if (bare.length === 10) return `+52${bare}`;
	if (bare.length === 12 && bare.startsWith('52')) return `+${bare}`;
	return digits;
}

export function isValidPhone(raw: string): boolean {
	return /^\+\d{11,15}$/.test(normalizePhone(raw));
}

export function isValidEmail(raw: string): boolean {
	return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(raw.trim());
}

export async function submitOrder(lines: CartLine[], form: CheckoutForm): Promise<CheckoutResult> {
	if (lines.length === 0) {
		throw new ApiError(0, 'empty_cart', 'Tu canasta está vacía.');
	}

	// Fresh guest session so stale carts can't double the items.
	await storeApi.resetSession();

	for (const line of lines) {
		try {
			await storeApi.addItem(line.productId, line.quantity);
		} catch (e) {
			if (e instanceof ApiError) {
				throw new ApiError(e.status, e.code, `${line.name}: ${e.message}`);
			}
			throw e;
		}
	}

	return storeApi.checkout({
		billing_address: {
			first_name: form.firstName.trim(),
			last_name: form.lastName.trim(),
			email: form.email.trim(),
			phone: normalizePhone(form.phone),
			country: 'MX',
		},
		payment_method: 'cod',
		additional_fields: {
			'haramara/pickup-date': form.pickupDate,
			'haramara/pickup-slot': form.pickupSlot,
		},
	});
}
