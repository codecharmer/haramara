/**
 * Device credentials for the POS: server URL + WordPress Application Password,
 * held in SecureStore. Exposes a ready PosApi instance once signed in.
 */

import { ApiError, PosApi } from '@haramara/api-client';
import * as SecureStore from 'expo-secure-store';
import React, { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';

const KEY = 'haramara_pos_session_v1';

export interface Session {
	baseUrl: string;
	username: string;
	appPassword: string;
}

interface AuthState {
	/** null while SecureStore is loading; false when signed out. */
	session: Session | false | null;
	api: PosApi | null;
	signIn: (session: Session) => Promise<void>;
	signOut: () => Promise<void>;
}

const AuthContext = createContext<AuthState | undefined>(undefined);

/**
 * People paste whatever URL they have handy — the admin, the API root, a
 * trailing slash. The server field wants the site root; anything else makes
 * every request 404 (`/wp-json/wp-json/…`). Normalize instead of erroring.
 */
export function normalizeBaseUrl(input: string): string {
	let url = input.trim();
	if (url === '') return url;
	if (!/^https?:\/\//i.test(url)) url = `https://${url}`;
	url = url.replace(/\/(wp-json|wp-admin|wp-login\.php)([/?#].*)?$/i, '');
	return url.replace(/\/+$/, '');
}

function buildApi(session: Session): PosApi {
	return new PosApi(
		{ baseUrl: normalizeBaseUrl(session.baseUrl) },
		{ username: session.username, appPassword: session.appPassword },
	);
}

export function AuthProvider({ children }: { children: React.ReactNode }) {
	const [session, setSession] = useState<Session | false | null>(null);

	useEffect(() => {
		SecureStore.getItemAsync(KEY)
			.then((raw) => setSession(raw ? (JSON.parse(raw) as Session) : false))
			.catch(() => setSession(false));
	}, []);

	const signIn = useCallback(async (next: Session) => {
		next = { ...next, baseUrl: normalizeBaseUrl(next.baseUrl) };
		// Validate before persisting: a bad password should never get stored.
		await buildApi(next).login();
		try {
			await SecureStore.setItemAsync(KEY, JSON.stringify(next));
		} catch {
			// SecureStore is native-only (no web support): in the browser
			// preview the session simply lives in memory for this visit.
		}
		setSession(next);
	}, []);

	const signOut = useCallback(async () => {
		try {
			await SecureStore.deleteItemAsync(KEY);
		} catch {
			// Web preview: nothing was persisted.
		}
		setSession(false);
	}, []);

	const api = useMemo(() => (session ? buildApi(session) : null), [session]);

	const value = useMemo(() => ({ session, api, signIn, signOut }), [session, api, signIn, signOut]);

	return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth(): AuthState {
	const ctx = useContext(AuthContext);
	if (!ctx) throw new Error('useAuth fuera de AuthProvider');
	return ctx;
}

/** The PosApi for signed-in screens (they only render behind the auth gate). */
export function usePosApi(): PosApi {
	const { api } = useAuth();
	if (!api) throw new Error('Sesión no iniciada');
	return api;
}

/** True when the error means the stored credentials were revoked. */
export function isAuthError(e: unknown): boolean {
	return e instanceof ApiError && (e.status === 401 || e.status === 403);
}
