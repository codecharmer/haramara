/**
 * Who is at the counter.
 *
 * `auth.tsx` answers "is this our tablet?" — one shared Application Password in
 * SecureStore. That is device identity, and it is not enough: with it alone no
 * sale, salida, cancelación, or descuento can be attributed to a person, which
 * is the whole point of the money-leak controls. This layer answers "who is
 * standing here?" with a per-employee PIN, and attaches the resulting signed
 * session to every PosApi call as `X-Pos-Operator`.
 *
 * Rollout safety: the lock engages only when the server reports at least one
 * person with a PIN set. A café that has deployed the code but not yet
 * configured NIPs keeps ringing sales exactly as before — unattributed, but
 * never blocked. The counter must never be bricked by a deploy.
 *
 * The token is persisted so a reload mid-shift does not re-prompt, but its
 * expiry is enforced server-side; the client copy is a convenience, not a
 * control.
 */

import {
	ApiError,
	OPERATOR_SESSION_ERROR_CODES,
	type Operator,
	type OperatorAuthorization,
} from '@haramara/api-client';
import * as SecureStore from 'expo-secure-store';
import React, { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react';
import { AppState } from 'react-native';

import { useAuth } from './auth';

const KEY = 'haramara_pos_operator_v1';

/** Lock the counter after this long without a touch. */
const IDLE_LOCK_MS = 5 * 60 * 1000;

interface StoredOperator {
	operator: Operator;
	token: string;
	/** Unix seconds. */
	expires: number;
}

interface OperatorState {
	/** null while loading, false when locked, else the signed-in person. */
	operator: Operator | false | null;
	/** People who can sign in. Empty means the PIN layer is not configured yet. */
	roster: Operator[];
	/** True when a PIN is required before the counter can be used. */
	lockRequired: boolean;
	signIn: (operatorKey: string, pin: string) => Promise<void>;
	lock: () => Promise<void>;
	/** Supervisor step-up for one privileged action (voids, refunds). */
	authorize: (operatorKey: string, pin: string, action: string) => Promise<OperatorAuthorization>;
	/** Called on interaction to defer the idle lock. */
	touch: () => void;
	refreshRoster: () => void;
}

const OperatorContext = createContext<OperatorState | undefined>(undefined);

export function OperatorProvider({ children }: { children: React.ReactNode }) {
	const { api, session } = useAuth();
	const [stored, setStored] = useState<StoredOperator | false | null>(null);
	const [roster, setRoster] = useState<Operator[]>([]);
	const lastTouch = useRef<number>(Date.now());

	const loadRoster = useCallback(() => {
		if (!api) return;
		api
			.operators()
			.then(setRoster)
			// A server that predates this feature 404s; an empty roster is the
			// correct, non-blocking interpretation either way.
			.catch(() => setRoster([]));
	}, [api]);

	// Restore any persisted operator session, discarding an expired one.
	useEffect(() => {
		if (!session) {
			setStored(false);
			return;
		}
		SecureStore.getItemAsync(KEY)
			.then((raw) => {
				if (!raw) return setStored(false);
				const parsed = JSON.parse(raw) as StoredOperator;
				setStored(parsed.expires * 1000 > Date.now() ? parsed : false);
			})
			.catch(() => setStored(false));
	}, [session]);

	useEffect(loadRoster, [loadRoster]);

	// Push the token into the API client whenever it changes, so every
	// subsequent request carries the operator.
	useEffect(() => {
		api?.setOperatorToken(stored ? stored.token : null);
	}, [api, stored]);

	const lock = useCallback(async () => {
		try {
			await SecureStore.deleteItemAsync(KEY);
		} catch {
			// Web preview: nothing was persisted.
		}
		setStored(false);
	}, []);

	const signIn = useCallback(
		async (operatorKey: string, pin: string) => {
			if (!api) throw new Error('Sesión no iniciada');
			const result = await api.operatorLogin(operatorKey, pin);
			const next: StoredOperator = {
				operator: result.operator,
				token: result.token,
				expires: result.expires,
			};
			try {
				await SecureStore.setItemAsync(KEY, JSON.stringify(next));
			} catch {
				// Web preview: in-memory for this visit.
			}
			lastTouch.current = Date.now();
			setStored(next);
		},
		[api],
	);

	const authorize = useCallback(
		(operatorKey: string, pin: string, action: string) => {
			if (!api) throw new Error('Sesión no iniciada');
			return api.operatorAuthorize(operatorKey, pin, action);
		},
		[api],
	);

	const touch = useCallback(() => {
		lastTouch.current = Date.now();
	}, []);

	// Idle lock. Checked on a coarse interval and again whenever the app comes
	// back to the foreground — a tablet left face-up on the bar is exactly the
	// situation the PIN is meant to cover.
	useEffect(() => {
		if (!stored) return undefined;

		const check = () => {
			if (Date.now() - lastTouch.current >= IDLE_LOCK_MS) void lock();
		};
		const timer = setInterval(check, 30_000);
		const sub = AppState.addEventListener('change', (state) => {
			if (state === 'active') check();
		});

		return () => {
			clearInterval(timer);
			sub.remove();
		};
	}, [stored, lock]);

	const value = useMemo<OperatorState>(
		() => ({
			operator: stored === null ? null : stored === false ? false : stored.operator,
			roster,
			lockRequired: roster.length > 0,
			signIn,
			lock,
			authorize,
			touch,
			refreshRoster: loadRoster,
		}),
		[stored, roster, signIn, lock, authorize, touch, loadRoster],
	);

	return <OperatorContext.Provider value={value}>{children}</OperatorContext.Provider>;
}

export function useOperator(): OperatorState {
	const ctx = useContext(OperatorContext);
	if (!ctx) throw new Error('useOperator fuera de OperatorProvider');
	return ctx;
}

/** True when the error means the operator session expired or was rejected. */
export function isOperatorError(e: unknown): boolean {
	return e instanceof ApiError && (OPERATOR_SESSION_ERROR_CODES as readonly string[]).includes(e.code);
}
