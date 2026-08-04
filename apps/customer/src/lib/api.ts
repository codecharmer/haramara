/**
 * API singletons for the customer app. The base URL comes from
 * EXPO_PUBLIC_API_URL (dev builds pointing at wp-env) and falls back to
 * production. The Store API Cart-Token persists in AsyncStorage so a guest
 * session survives restarts; the local cart is the source of truth regardless
 * (see cart.tsx) — the server cart is rebuilt at checkout.
 */

import AsyncStorage from '@react-native-async-storage/async-storage';
import { AppApi, StoreApi } from '@haramara/api-client';

// STARTER: point the fallback at the production site once it exists.
export const BASE_URL = (process.env.EXPO_PUBLIC_API_URL ?? 'https://example.com').replace(/\/+$/, '');

/** Optional pre-launch AccessGate key (see Rest\AccessGate + HARAMARA_APP_KEY). */
const APP_KEY = process.env.EXPO_PUBLIC_APP_KEY;

const CART_TOKEN_KEY = 'haramara_cart_token_v1';

export const appApi = new AppApi({ baseUrl: BASE_URL, appKey: APP_KEY });

export const storeApi = new StoreApi({
	baseUrl: BASE_URL,
	appKey: APP_KEY,
	getCartToken: () => AsyncStorage.getItem(CART_TOKEN_KEY),
	onCartToken: async (token) => {
		if (token === '') await AsyncStorage.removeItem(CART_TOKEN_KEY);
		else await AsyncStorage.setItem(CART_TOKEN_KEY, token);
	},
});
