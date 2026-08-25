/**
 * Outbox drain engine — Phase 7 (venta offline).
 *
 * Serial FIFO, one operation in flight at a time: sales decrement stock, so
 * they must land in the order they were rung. Every send carries the
 * operation's ORIGINAL idempotency key (minted at enqueue, immutable), which
 * is the whole reason a retry after a mid-flight death cannot ring twice.
 *
 * Failure policy:
 * - retryable (red caída, 5xx, 429): exponential backoff with jitter,
 *   1s → 2s → 4s … capped at 60s. The operation stays `queued`.
 * - `haramara_request_in_flight`: the server still holds a live claim on this
 *   key. Its stale-claim takeover happens at 60s, so hold ≥65s before
 *   retrying THAT op (holding the op holds the queue — correct, because the
 *   op ahead is the one the server is settling).
 * - terminal (validation/authorization rejections): mark `failed` and move
 *   on. Failed operations are KEPT for the review UI, never silently dropped
 *   — a failed queued sale is money that did NOT happen and the cashier must
 *   see it.
 *
 * Dependencies (clock, delay, randomness, connectivity, executors) are all
 * injectable so verify.ts can run the engine under node with a fake API and
 * a fake clock.
 */

import type { WalkInInput, WithdrawalInput } from '@haramara/api-client';

import type { OutboxStore } from './store';
import { classifyError, computeSnapshot, describeError } from './types';
import type { OutboxSnapshot, QueuedOperation } from './types';

/** First-retry backoff. */
export const BASE_BACKOFF_MS = 1000;
/** Backoff ceiling. */
export const MAX_BACKOFF_MS = 60_000;
/**
 * Hold after `haramara_request_in_flight`: the server takes over an abandoned
 * claim after 60s, so waiting 65s guarantees the next send either replays the
 * settled result or wins the takeover.
 */
export const IN_FLIGHT_HOLD_MS = 65_000;
/** Coarse safety-net drain interval (foreground only under React Native). */
export const DEFAULT_INTERVAL_MS = 30_000;

/** How the engine talks to the world — bind these to PosApi in the provider. */
export interface OutboxExecutors {
	walk_in(input: WalkInInput): Promise<unknown>;
	withdrawal(input: WithdrawalInput): Promise<unknown>;
}

export interface OutboxSyncDeps {
	store: OutboxStore;
	executors: OutboxExecutors;
	/** Gate: the drain pauses while this reports false. Default: always true. */
	isOnline?: () => boolean;
	/** Clock (fake in tests). Default Date.now. */
	now?: () => number;
	/** Sleeper (recorded + instant in tests). Default setTimeout. */
	delay?: (ms: number) => Promise<void>;
	/** Jitter source in [0, 1]. Default Math.random. */
	random?: () => number;
	/** Coarse interval for start(); 0 disables. Default DEFAULT_INTERVAL_MS. */
	intervalMs?: number;
}

/** Pushed to subscribers after every state transition. */
export interface OutboxSyncState {
	draining: boolean;
	snapshot: OutboxSnapshot;
	/** Terminal rejections awaiting human review (retry / discard). */
	failed: QueuedOperation[];
}

type Listener = (state: OutboxSyncState) => void;

export class OutboxSync {
	private readonly store: OutboxStore;
	private readonly executors: OutboxExecutors;
	private readonly isOnline: () => boolean;
	private readonly now: () => number;
	private readonly delay: (ms: number) => Promise<void>;
	private readonly random: () => number;
	private readonly intervalMs: number;

	private listeners: Listener[] = [];
	private current: Promise<void> | null = null;
	private looping = false;
	private timer: ReturnType<typeof setInterval> | null = null;
	private disposed = false;

	constructor(deps: OutboxSyncDeps) {
		this.store = deps.store;
		this.executors = deps.executors;
		this.isOnline = deps.isOnline ?? (() => true);
		this.now = deps.now ?? (() => Date.now());
		this.delay = deps.delay ?? ((ms) => new Promise((resolve) => setTimeout(resolve, ms)));
		this.random = deps.random ?? Math.random;
		this.intervalMs = deps.intervalMs ?? DEFAULT_INTERVAL_MS;
	}

	/** Notified after every operation transition. Returns the unsubscribe. */
	subscribe(listener: Listener): () => void {
		this.listeners.push(listener);
		return () => {
			this.listeners = this.listeners.filter((l) => l !== listener);
		};
	}

	/** Current queue state without waiting for a transition. */
	async readState(): Promise<OutboxSyncState> {
		const ops = await this.store.all();
		return this.toState(ops);
	}

	/**
	 * Drain until the queue has nothing actionable or connectivity drops.
	 * Reentrant-safe: while a drain runs, callers share its promise (the loop
	 * re-reads the store each iteration, so operations enqueued mid-drain are
	 * picked up).
	 */
	drainNow(): Promise<void> {
		if (this.disposed) return Promise.resolve();
		if (this.current) return this.current;
		this.current = this.drainLoop().finally(() => {
			this.current = null;
		});
		return this.current;
	}

	/** Arm the coarse interval trigger (connectivity/foreground are wired by the provider). */
	start(): void {
		if (this.timer || this.disposed || this.intervalMs <= 0) return;
		this.timer = setInterval(() => {
			if (this.isOnline()) void this.drainNow();
		}, this.intervalMs);
	}

	stop(): void {
		if (this.timer) {
			clearInterval(this.timer);
			this.timer = null;
		}
	}

	/** stop() + refuse further drains. The store itself is left untouched. */
	dispose(): void {
		this.stop();
		this.disposed = true;
		this.listeners = [];
	}

	/* ---------------------------------------------------------------------- */

	private toState(ops: readonly QueuedOperation[]): OutboxSyncState {
		return {
			draining: this.looping,
			snapshot: computeSnapshot(ops, this.now()),
			failed: ops.filter((op) => op.status === 'failed'),
		};
	}

	private async emit(): Promise<void> {
		if (this.listeners.length === 0) return;
		const ops = await this.store.all();
		const state = this.toState(ops);
		for (const listener of this.listeners.slice()) listener(state);
	}

	/** attempts is the count INCLUDING the failure that triggered this wait. */
	private backoffMs(attempts: number): number {
		const exp = Math.min(BASE_BACKOFF_MS * 2 ** Math.min(attempts - 1, 10), MAX_BACKOFF_MS);
		// Equal jitter: [exp/2, exp] — spreads reconnect stampedes without
		// ever retrying faster than half the ladder step.
		return Math.floor(exp / 2 + this.random() * (exp / 2));
	}

	private async drainLoop(): Promise<void> {
		this.looping = true;
		while (!this.disposed) {
			if (!this.isOnline()) break;

			const ops = await this.store.all();
			// `syncing` is included: a row stuck there means the app died
			// mid-send — exactly the case the immutable idempotency key exists
			// for. `failed` rows are skipped (and do NOT block the queue);
			// only a human retry() re-queues them.
			const next = ops.find((op) => op.status === 'queued' || op.status === 'syncing');
			if (!next) break;

			await this.store.update(next.id, { status: 'syncing' });
			await this.emit();

			let holdMs = 0;
			try {
				// The stored input already carries the key, but re-stamping the
				// op's own immutable key here makes the invariant unconditional.
				if (next.kind === 'walk_in') {
					await this.executors.walk_in({ ...next.input, idempotency_key: next.idempotency_key });
				} else {
					await this.executors.withdrawal({ ...next.input, idempotency_key: next.idempotency_key });
				}
				await this.store.remove(next.id);
			} catch (error) {
				const attempts = next.attempts + 1;
				const last_error = describeError(error);
				const kind = classifyError(error);
				if (kind === 'terminal') {
					await this.store.update(next.id, { status: 'failed', attempts, last_error });
				} else {
					await this.store.update(next.id, { status: 'queued', attempts, last_error });
					holdMs = kind === 'in_flight' ? IN_FLIGHT_HOLD_MS : this.backoffMs(attempts);
				}
			}

			await this.emit();
			if (holdMs > 0) await this.delay(holdMs);
		}
		this.looping = false;
		await this.emit();
	}
}
