/**
 * Lealtad Haramara — device-registered QR card.
 *
 * The card is anonymous: a stable device key is generated once, the server
 * answers with a signed card token (see Loyalty\Members in haramara-core),
 * and the token is what the QR encodes. Registration is idempotent, so a
 * reinstall with the same stored device key recovers the same card; a lost
 * token re-registers transparently.
 */

import AsyncStorage from '@react-native-async-storage/async-storage';
import { useQuery } from '@tanstack/react-query';
import type { LoyaltyCard } from '@haramara/api-client';

import { appApi } from './api';

const DEVICE_KEY = 'haramara_loyalty_device_v1';
const TOKEN_KEY = 'haramara_loyalty_token_v1';

function randomKey(): string {
	let out = '';
	for (let i = 0; i < 32; i += 1) {
		out += Math.floor(Math.random() * 16).toString(16);
	}
	return `${Date.now().toString(16)}-${out}`;
}

async function deviceKey(): Promise<string> {
	const existing = await AsyncStorage.getItem(DEVICE_KEY);
	if (existing) return existing;
	const fresh = randomKey();
	await AsyncStorage.setItem(DEVICE_KEY, fresh);
	return fresh;
}

async function fetchCard(): Promise<LoyaltyCard> {
	const token = await AsyncStorage.getItem(TOKEN_KEY);

	if (token) {
		try {
			const card = await appApi.loyaltyCard(token);
			return { ...card, token };
		} catch {
			// Unknown/invalid token (e.g. server reset) — fall through and
			// re-register with the stable device key.
		}
	}

	const card = await appApi.loyaltyRegister(await deviceKey());
	await AsyncStorage.setItem(TOKEN_KEY, card.token);
	return card;
}

/** The member's card; refetches on focus so bar stamps appear promptly. */
export function useLoyaltyCard() {
	return useQuery({
		queryKey: ['loyalty', 'card'],
		queryFn: fetchCard,
		staleTime: 30_000,
	});
}
