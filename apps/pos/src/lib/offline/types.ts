/**
 * Offline outbox domain shapes — Phase 7 (venta offline).
 *
 * SCOPE GUARD (product decision, deliberate): the outbox accepts exactly two
 * operation kinds — counter SALES (with their tips/discounts/modifiers) and
 * salidas internas (withdrawals). Voids, refunds, shift open/close, cash
 * drops, and loyalty REQUIRE connectivity: they are the anti-fraud controls,
 * and a control that works offline is not a control. The rule lives in this
 * discriminated union on purpose — a third kind is a product decision made
 * with the owner, not a refactor.
 *
 * This module is pure and dependency-free at runtime (only `import type`
 * from the api-client) so `verify.ts` can compile and run it under plain
 * node, the same way the printing lib verifies itself.
 */

import type { WalkInInput, WithdrawalInput } from '@haramara/api-client';

/** The only two things allowed to happen offline. */
export type QueuedOperationKind = 'walk_in' | 'withdrawal';

/**
 * Lifecycle of one queued operation. `done` exists for transient UI states —
 * the engine REMOVES an operation on success rather than storing `done`, so a
 * persisted row is never in that state.
 */
export type OperationStatus = 'queued' | 'syncing' | 'done' | 'failed';

interface QueuedOperationBase {
	/** Outbox row id — NOT the idempotency key; only identifies the row locally. */
	id: string;
	/**
	 * The replay-safety story in one field: minted ONCE at enqueue time,
	 * immutable forever after, and sent on every attempt. The server settles a
	 * replayed key exactly once (`X-Pos-Idempotent-Replay`), so a sale that
	 * synced right before the app died cannot ring twice when it retries.
	 */
	idempotency_key: string;
	/** ISO 8601, device clock at enqueue time. */
	created_at: string;
	/** Send attempts so far (drives the backoff ladder). */
	attempts: number;
	/** Last failure text (the server's own Spanish message when available). */
	last_error: string | null;
	status: OperationStatus;
}

export interface QueuedWalkIn extends QueuedOperationBase {
	kind: 'walk_in';
	input: WalkInInput;
}

export interface QueuedWithdrawal extends QueuedOperationBase {
	kind: 'withdrawal';
	input: WithdrawalInput;
}

export type QueuedOperation = QueuedWalkIn | QueuedWithdrawal;

/** Identity for a new outbox row. The caller mints both values (see outbox.tsx). */
export interface QueuedOperationSeed {
	id: string;
	idempotencyKey: string;
	/** ISO 8601. */
	createdAt: string;
}

/**
 * Build a queued sale. The seed's idempotency key is stamped INTO the stored
 * input so every future send carries it — the input's own key, if any, is the
 * one the caller already used against the network and must win (pass it as
 * the seed key); anything else would let a retry mint a fresh key, which
 * looks like protection and is none.
 */
export function queueWalkIn(input: WalkInInput, seed: QueuedOperationSeed): QueuedWalkIn {
	return {
		kind: 'walk_in',
		id: seed.id,
		idempotency_key: seed.idempotencyKey,
		created_at: seed.createdAt,
		attempts: 0,
		last_error: null,
		status: 'queued',
		input: { ...input, idempotency_key: seed.idempotencyKey },
	};
}

/** Build a queued salida interna. Same key semantics as queueWalkIn. */
export function queueWithdrawal(input: WithdrawalInput, seed: QueuedOperationSeed): QueuedWithdrawal {
	return {
		kind: 'withdrawal',
		id: seed.id,
		idempotency_key: seed.idempotencyKey,
		created_at: seed.createdAt,
		attempts: 0,
		last_error: null,
		status: 'queued',
		input: { ...input, idempotency_key: seed.idempotencyKey },
	};
}

/* -------------------------------------------------------------------------- */
/* UI snapshot                                                                */
/* -------------------------------------------------------------------------- */

/** Cheap counts for badges ("Pendientes: 3") and the corte's failed-ops review. */
export interface OutboxSnapshot {
	/** Waiting for their turn. */
	queued: number;
	/** In flight right now (0 or 1 — the drain is serial). */
	syncing: number;
	/** Rejected permanently by the server; kept for review, never auto-dropped. */
	failed: number;
	/** queued + syncing — what the "Pendientes" badge shows. */
	pending: number;
	/** Age of the oldest not-yet-synced operation, or null when none pend. */
	oldestPendingAgeMs: number | null;
}

export function computeSnapshot(ops: readonly QueuedOperation[], nowMs: number): OutboxSnapshot {
	let queued = 0;
	let syncing = 0;
	let failed = 0;
	let oldest: number | null = null;
	for (const op of ops) {
		if (op.status === 'queued') queued += 1;
		else if (op.status === 'syncing') syncing += 1;
		else if (op.status === 'failed') failed += 1;
		if (op.status === 'queued' || op.status === 'syncing') {
			const created = Date.parse(op.created_at);
			if (Number.isFinite(created) && (oldest === null || created < oldest)) oldest = created;
		}
	}
	return {
		queued,
		syncing,
		failed,
		pending: queued + syncing,
		oldestPendingAgeMs: oldest === null ? null : Math.max(0, nowMs - oldest),
	};
}

/* -------------------------------------------------------------------------- */
/* Error classification                                                       */
/* -------------------------------------------------------------------------- */

/**
 * - `terminal`: the server REJECTED the operation permanently — retrying the
 *   identical bytes yields the identical rejection. Mark `failed` and surface
 *   it; a failed queued sale is money that did NOT happen and the cashier
 *   must see it.
 * - `retryable`: transient — network down, server error, throttling. Keep the
 *   operation queued and back off.
 * - `in_flight`: the server holds an active claim on this idempotency key
 *   (another send of the SAME operation is mid-request). Retry the same op
 *   after the server's stale-claim takeover window.
 */
export type ErrorClassification = 'terminal' | 'retryable' | 'in_flight';

/** The server's 409 while a request with the same idempotency key is running. */
export const IN_FLIGHT_CODE = 'haramara_request_in_flight';

/**
 * Codes that mean "this exact operation will never be accepted" — validation
 * and authorization rejections mirrored from the haramara-core REST layer.
 */
const TERMINAL_CODES: ReadonlySet<string> = new Set([
	'haramara_out_of_stock',
	'haramara_insufficient_stock',
	'haramara_authorization_required',
	'haramara_unknown_product',
	'haramara_discount_too_big',
	'haramara_reason_note_required',
]);

/** Every `haramara_invalid_*` code is a validation rejection. */
const TERMINAL_CODE_PREFIX = 'haramara_invalid_';

/**
 * HTTP statuses that are validation/authorization-class rejections when the
 * code is not more specific. Deliberately NOT here: 401 (device or operator
 * session lapsed — signing back in fixes it, the sale is still good), 408 and
 * 429 (try again later).
 */
const TERMINAL_STATUSES: ReadonlySet<number> = new Set([400, 403, 404, 405, 409, 410, 413, 422]);

interface FailureShape {
	status: number | null;
	code: string | null;
	message: string | null;
}

/** Duck-type ApiError (and anything shaped like it) without importing the class. */
function readFailure(error: unknown): FailureShape {
	if (typeof error !== 'object' || error === null) return { status: null, code: null, message: null };
	const e = error as { status?: unknown; code?: unknown; message?: unknown };
	return {
		status: typeof e.status === 'number' && Number.isFinite(e.status) ? e.status : null,
		code: typeof e.code === 'string' ? e.code : null,
		message: typeof e.message === 'string' && e.message ? e.message : null,
	};
}

export function classifyError(error: unknown): ErrorClassification {
	const { status, code } = readFailure(error);

	if (code === IN_FLIGHT_CODE) return 'in_flight';
	if (code !== null && (TERMINAL_CODES.has(code) || code.startsWith(TERMINAL_CODE_PREFIX))) {
		return 'terminal';
	}

	if (status === null) {
		// Unknown throw (a bug, a storage hiccup): keep the money visible in
		// the queue and retry — never silently abandon a sale on a guess.
		return 'retryable';
	}
	if (status === 0) return 'retryable'; // ApiError network_error
	if (status === 429 || status === 408 || status === 401) return 'retryable';
	if (status >= 500) return 'retryable';
	if (TERMINAL_STATUSES.has(status)) return 'terminal';
	return 'retryable';
}

/** Human text for `last_error` — the server's own Spanish message when it sent one. */
export function describeError(error: unknown): string {
	const { message, code, status } = readFailure(error);
	if (message) return message;
	if (code) return `Error del servidor (${code}).`;
	if (status !== null) return `Error del servidor (${status}).`;
	return 'Error desconocido al sincronizar.';
}
