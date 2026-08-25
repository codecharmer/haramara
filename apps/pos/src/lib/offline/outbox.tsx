/**
 * Outbox React context — Phase 7 (venta offline).
 *
 * The screen-facing surface: `enqueueWalkIn` / `enqueueWithdrawal` mint the
 * idempotency key (once, at enqueue — immutable thereafter), persist the
 * operation, and poke the drain engine; `snapshot` feeds the "Pendientes"
 * badge; `failed` + `retry`/`discard` feed the review UI.
 *
 * Nothing imports this yet — see docs/phase7-integration.md for wiring
 * (`OutboxProvider` goes INSIDE `AuthProvider` in the root layout, because it
 * binds the drain engine to the signed-in `PosApi`).
 */

import { newIdempotencyKey } from '@haramara/api-client';
import type { WalkInInput, WithdrawalInput } from '@haramara/api-client';
import React, { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react';
import { AppState } from 'react-native';

import { useAuth } from '../auth';
import { createConnectivityMonitor } from './connectivity';
import type { ConnectivityMonitor } from './connectivity';
import { createOutboxStore } from './store';
import type { OutboxBackend, OutboxStore } from './store';
import { OutboxSync } from './sync';
import { computeSnapshot, queueWalkIn, queueWithdrawal } from './types';
import type { OutboxSnapshot, QueuedOperation } from './types';

const EMPTY_SNAPSHOT: OutboxSnapshot = {
	queued: 0,
	syncing: 0,
	failed: 0,
	pending: 0,
	oldestPendingAgeMs: null,
};

export interface OutboxContextValue {
	/** Committed connectivity state (drives the "sin conexión" chrome). */
	online: boolean;
	/** Which persistence won (memory = warn: a force-quit loses the queue). */
	backend: OutboxBackend | null;
	snapshot: OutboxSnapshot;
	/** Terminal rejections awaiting human review — never auto-dropped. */
	failed: QueuedOperation[];
	/**
	 * Queue a counter sale. If the input already carries an idempotency key
	 * (e.g. mostrador's `saleKey` after an online attempt died mid-flight)
	 * that key is kept — the server may already hold it; a fresh key here
	 * could ring the sale twice. Otherwise one is minted now, and it never
	 * changes again. Returns the outbox row id.
	 */
	enqueueWalkIn(input: WalkInInput): Promise<string>;
	/** Queue a salida interna. Same key semantics as enqueueWalkIn. */
	enqueueWithdrawal(input: WithdrawalInput): Promise<string>;
	/** Re-queue a failed operation (it keeps its original key and position). */
	retry(id: string): Promise<void>;
	/**
	 * Drop a failed operation FOREVER. The call site MUST confirm with the
	 * cashier first (confirmDialog) — a discarded sale is money that never
	 * happened, and this is the only place in the flow where it can vanish.
	 */
	discard(id: string): Promise<void>;
	/** Manual sync trigger (the "Sincronizar" button). */
	drainNow(): Promise<void>;
}

const OutboxContext = createContext<OutboxContextValue | undefined>(undefined);

interface Engine {
	store: OutboxStore;
	sync: OutboxSync;
	connectivity: ConnectivityMonitor;
}

export function OutboxProvider({ children }: { children: React.ReactNode }) {
	const { api, session } = useAuth();
	const baseUrl = session ? session.baseUrl : null;

	const [online, setOnline] = useState(true);
	const [backend, setBackend] = useState<OutboxBackend | null>(null);
	const [snapshot, setSnapshot] = useState<OutboxSnapshot>(EMPTY_SNAPSHOT);
	const [failed, setFailed] = useState<QueuedOperation[]>([]);

	/** Set synchronously by the effect so enqueues during startup just wait. */
	const engineRef = useRef<Promise<Engine> | null>(null);

	useEffect(() => {
		if (!api || !baseUrl) {
			engineRef.current = null;
			setBackend(null);
			setSnapshot(EMPTY_SNAPSHOT);
			setFailed([]);
			return;
		}

		let disposed = false;
		const connectivity = createConnectivityMonitor({ baseUrl });

		const enginePromise: Promise<Engine> = (async () => {
			const store = await createOutboxStore();
			const sync = new OutboxSync({
				store,
				executors: {
					walk_in: (input) => api.createWalkIn(input),
					withdrawal: (input) => api.createWithdrawal(input),
				},
				isOnline: () => connectivity.isOnline(),
			});
			return { store, sync, connectivity };
		})();
		engineRef.current = enginePromise;

		const subscriptions: Array<() => void> = [];

		void enginePromise.then((engine) => {
			if (disposed) {
				engine.sync.dispose();
				connectivity.stop();
				return;
			}
			setBackend(engine.store.backend);

			subscriptions.push(
				engine.sync.subscribe((state) => {
					setSnapshot(state.snapshot);
					setFailed(state.failed);
				}),
			);
			subscriptions.push(
				connectivity.subscribe((isOnline) => {
					setOnline(isOnline);
					if (isOnline) void engine.sync.drainNow();
				}),
			);

			const appState = AppState.addEventListener('change', (next) => {
				if (next !== 'active') return;
				void connectivity
					.checkNow()
					.then((isOnline) => {
						setOnline(isOnline);
						if (isOnline) return engine.sync.drainNow();
						return undefined;
					})
					.catch(() => undefined);
			});
			subscriptions.push(() => appState.remove());

			connectivity.start();
			engine.sync.start();
			setOnline(connectivity.isOnline());

			// Surface anything a previous session left behind, then try to send it.
			void engine.sync
				.readState()
				.then((state) => {
					if (disposed) return;
					setSnapshot(state.snapshot);
					setFailed(state.failed);
					if (state.snapshot.pending > 0) void engine.sync.drainNow();
				})
				.catch(() => undefined);
		});

		return () => {
			disposed = true;
			engineRef.current = null;
			for (const unsubscribe of subscriptions) unsubscribe();
			void enginePromise.then((engine) => {
				engine.sync.dispose();
				engine.connectivity.stop();
			});
		};
	}, [api, baseUrl]);

	const requireEngine = useCallback(async (): Promise<Engine> => {
		const pending = engineRef.current;
		if (!pending) throw new Error('Sesión no iniciada — no se puede guardar la operación.');
		return pending;
	}, []);

	const refresh = useCallback(async (engine: Engine) => {
		const ops = await engine.store.all();
		setSnapshot(computeSnapshot(ops, Date.now()));
		setFailed(ops.filter((op) => op.status === 'failed'));
	}, []);

	const enqueueWalkIn = useCallback(
		async (input: WalkInInput): Promise<string> => {
			const engine = await requireEngine();
			const op = queueWalkIn(input, {
				id: newIdempotencyKey(),
				idempotencyKey: input.idempotency_key ?? newIdempotencyKey(),
				createdAt: new Date().toISOString(),
			});
			await engine.store.add(op);
			await refresh(engine);
			void engine.sync.drainNow();
			return op.id;
		},
		[requireEngine, refresh],
	);

	const enqueueWithdrawal = useCallback(
		async (input: WithdrawalInput): Promise<string> => {
			const engine = await requireEngine();
			const op = queueWithdrawal(input, {
				id: newIdempotencyKey(),
				idempotencyKey: input.idempotency_key ?? newIdempotencyKey(),
				createdAt: new Date().toISOString(),
			});
			await engine.store.add(op);
			await refresh(engine);
			void engine.sync.drainNow();
			return op.id;
		},
		[requireEngine, refresh],
	);

	const retry = useCallback(
		async (id: string): Promise<void> => {
			const engine = await requireEngine();
			await engine.store.update(id, { status: 'queued', last_error: null });
			await refresh(engine);
			void engine.sync.drainNow();
		},
		[requireEngine, refresh],
	);

	const discard = useCallback(
		async (id: string): Promise<void> => {
			const engine = await requireEngine();
			await engine.store.remove(id);
			await refresh(engine);
		},
		[requireEngine, refresh],
	);

	const drainNow = useCallback(async (): Promise<void> => {
		const engine = await requireEngine();
		await engine.connectivity.checkNow();
		await engine.sync.drainNow();
	}, [requireEngine]);

	const value = useMemo<OutboxContextValue>(
		() => ({
			online,
			backend,
			snapshot,
			failed,
			enqueueWalkIn,
			enqueueWithdrawal,
			retry,
			discard,
			drainNow,
		}),
		[online, backend, snapshot, failed, enqueueWalkIn, enqueueWithdrawal, retry, discard, drainNow],
	);

	return <OutboxContext.Provider value={value}>{children}</OutboxContext.Provider>;
}

export function useOutbox(): OutboxContextValue {
	const ctx = useContext(OutboxContext);
	if (!ctx) throw new Error('useOutbox fuera de OutboxProvider');
	return ctx;
}
