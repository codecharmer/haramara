/**
 * Network-free self-verification for the offline outbox. This lineage has no
 * test suites, so this exports selfTest() — human-readable PASS/FAIL lines —
 * exercising the pure parts (types + store + sync) with an injected fake API,
 * fake clock, and fake connectivity. Runnable from any TS-capable context;
 * docs/phase7-integration.md records an actual node run.
 *
 * Everything here imports only type-erased shapes from @haramara/api-client,
 * so the compiled JS has zero runtime dependencies.
 */

import type { WalkInInput, WithdrawalInput } from '@haramara/api-client';

import { createMemoryOutboxStore } from './store';
import type { OutboxStore } from './store';
import { IN_FLIGHT_HOLD_MS, OutboxSync } from './sync';
import { classifyError, computeSnapshot, queueWalkIn, queueWithdrawal } from './types';
import type { QueuedOperationKind } from './types';

/* -------------------------------------------------------------------------- */
/* Fixtures                                                                   */
/* -------------------------------------------------------------------------- */

/** Representative sale: modifiers, cortesía discount, cash tip, note. */
const SAMPLE_SALE: WalkInInput = {
	items: [{ product_id: 7, quantity: 2, modifiers: [{ group_id: 1, option_keys: ['avena'] }] }],
	payment: 'cash',
	discount: { amount: 10, reason_code: 'cortesia' },
	tip: { amount: 25, method: 'cash' },
	note: 'Sin azúcar',
};

const SAMPLE_SALIDA: WithdrawalInput = {
	items: [{ product_id: 9, quantity: 1 }],
	destination: 'malva',
	person: 'Malva',
};

interface FakeCall {
	kind: QueuedOperationKind;
	key: string | undefined;
}

interface Harness {
	store: OutboxStore;
	sync: OutboxSync;
	calls: FakeCall[];
	delays: number[];
	nowMs: () => number;
	setOnline(online: boolean): void;
	/** Queue the errors thrown, in order, for calls carrying this key. */
	failFor(key: string, errors: unknown[]): void;
	enqueueSale(key: string): Promise<string>;
	enqueueSalida(key: string): Promise<string>;
}

function makeHarness(opts: { online?: boolean } = {}): Harness {
	const store = createMemoryOutboxStore();
	const calls: FakeCall[] = [];
	const delays: number[] = [];
	const failures = new Map<string, unknown[]>();
	let online = opts.online ?? true;
	let nowMs = Date.parse('2026-08-24T08:00:00-06:00');
	let seq = 0;

	const exec = async (kind: QueuedOperationKind, key: string | undefined): Promise<void> => {
		calls.push({ kind, key });
		const pending = failures.get(key ?? '');
		if (pending && pending.length > 0) throw pending.shift();
	};

	const sync = new OutboxSync({
		store,
		executors: {
			walk_in: (input) => exec('walk_in', input.idempotency_key),
			withdrawal: (input) => exec('withdrawal', input.idempotency_key),
		},
		isOnline: () => online,
		now: () => nowMs,
		delay: (ms) => {
			delays.push(ms);
			nowMs += ms; // The fake clock jumps instead of sleeping.
			return Promise.resolve();
		},
		random: () => 1, // Deterministic jitter: backoffMs returns the full step.
		intervalMs: 0,
	});

	const seed = (key: string) => {
		seq += 1;
		return { id: `op_${seq}`, idempotencyKey: key, createdAt: new Date(nowMs).toISOString() };
	};

	return {
		store,
		sync,
		calls,
		delays,
		nowMs: () => nowMs,
		setOnline: (next) => {
			online = next;
		},
		failFor: (key, errors) => failures.set(key, errors.slice()),
		enqueueSale: async (key) => {
			const op = queueWalkIn(SAMPLE_SALE, seed(key));
			await store.add(op);
			return op.id;
		},
		enqueueSalida: async (key) => {
			const op = queueWithdrawal(SAMPLE_SALIDA, seed(key));
			await store.add(op);
			return op.id;
		},
	};
}

function check(label: string, ok: boolean, detail = ''): string {
	return `${ok ? 'PASS' : 'FAIL'}: ${label}${detail ? ` — ${detail}` : ''}`;
}

/* -------------------------------------------------------------------------- */
/* Scenarios                                                                  */
/* -------------------------------------------------------------------------- */

export async function selfTest(): Promise<string[]> {
	const results: string[] = [];

	// 1. Error classification: the anti-fraud line between "retry forever"
	//    and "stop and show a human".
	{
		const cases: Array<[string, unknown, string]> = [
			['network_error status 0', { status: 0, code: 'network_error', message: 'x' }, 'retryable'],
			['HTTP 503', { status: 503, code: 'http_503', message: 'x' }, 'retryable'],
			['HTTP 429', { status: 429, code: 'http_429', message: 'x' }, 'retryable'],
			['HTTP 401 (sesión caducada)', { status: 401, code: 'http_401', message: 'x' }, 'retryable'],
			['haramara_request_in_flight', { status: 409, code: 'haramara_request_in_flight', message: 'x' }, 'in_flight'],
			['haramara_out_of_stock', { status: 409, code: 'haramara_out_of_stock', message: 'x' }, 'terminal'],
			['haramara_invalid_items (prefijo)', { status: 400, code: 'haramara_invalid_items', message: 'x' }, 'terminal'],
			['haramara_discount_too_big', { status: 400, code: 'haramara_discount_too_big', message: 'x' }, 'terminal'],
			['haramara_authorization_required', { status: 403, code: 'haramara_authorization_required', message: 'x' }, 'terminal'],
			['HTTP 404 sin código', { status: 404, code: 'http_404', message: 'x' }, 'terminal'],
			['throw desconocido', new Error('boom'), 'retryable'],
		];
		const bad = cases.filter(([, error, expected]) => classifyError(error) !== expected);
		results.push(
			check(
				'clasificación de errores (11 casos)',
				bad.length === 0,
				bad.map(([label]) => label).join(', '),
			),
		);
	}

	// 2. FIFO order preserved across kinds (order matters for stock).
	{
		const h = makeHarness();
		await h.enqueueSale('A');
		await h.enqueueSale('B');
		await h.enqueueSalida('C');
		await h.enqueueSale('D');
		const before = computeSnapshot(await h.store.all(), h.nowMs());
		await h.sync.drainNow();
		const order = h.calls.map((c) => c.key).join(',');
		results.push(check('FIFO preservado (venta,venta,salida,venta)', order === 'A,B,C,D', order));
		results.push(
			check(
				'kinds despachados al executor correcto',
				h.calls[2]?.kind === 'withdrawal' && h.calls[0]?.kind === 'walk_in',
			),
		);
		results.push(
			check('snapshot antes del drain: pending=4, oldest age', before.pending === 4 && before.oldestPendingAgeMs === 0),
		);
		const after = await h.store.all();
		results.push(check('éxito elimina del outbox (cola vacía)', after.length === 0));
	}

	// 3. THE replay-safety property: the original idempotency key is reused
	//    across retries — the fake API must see the SAME key twice.
	{
		const h = makeHarness();
		h.failFor('k-retry', [{ status: 0, code: 'network_error', message: 'Sin conexión.' }]);
		await h.enqueueSale('k-retry');
		await h.sync.drainNow();
		results.push(
			check(
				'reintento reutiliza la MISMA idempotency key (2 llamadas)',
				h.calls.length === 2 && h.calls[0].key === 'k-retry' && h.calls[1].key === 'k-retry',
				h.calls.map((c) => c.key).join(','),
			),
		);
		results.push(check('primer backoff ≈ 1s', h.delays[0] === 1000, `${h.delays[0]} ms`));
		results.push(check('tras el reintento exitoso la cola queda vacía', (await h.store.all()).length === 0));
	}

	// 4. Terminal error: failed, kept, NOT retried — and it does not block the
	//    operation behind it.
	{
		const h = makeHarness();
		h.failFor('k-term', [{ status: 409, code: 'haramara_out_of_stock', message: 'Sin existencias de Concha.' }]);
		await h.enqueueSale('k-term');
		await h.enqueueSale('k-ok');
		await h.sync.drainNow();
		const termCalls = h.calls.filter((c) => c.key === 'k-term').length;
		const ops = await h.store.all();
		const failedOp = ops.find((op) => op.idempotency_key === 'k-term');
		results.push(check('error terminal: exactamente 1 intento', termCalls === 1, `${termCalls}`));
		results.push(
			check(
				'error terminal: marcado failed y CONSERVADO con last_error',
				ops.length === 1 && failedOp?.status === 'failed' && failedOp?.last_error === 'Sin existencias de Concha.',
			),
		);
		results.push(
			check(
				'la operación siguiente sí se envió (failed no bloquea)',
				h.calls.some((c) => c.key === 'k-ok') && !ops.some((op) => op.idempotency_key === 'k-ok'),
			),
		);
		results.push(check('sin backoff tras error terminal', h.delays.length === 0));
	}

	// 5. Backoff growth: 1s → 2s → 4s … capped at 60s (random()=1 makes the
	//    jitter deterministic at the full step).
	{
		const h = makeHarness();
		const failure = { status: 500, code: 'internal_server_error', message: 'Error 500.' };
		h.failFor('k-back', [failure, failure, failure, failure, failure, failure, failure, failure]);
		await h.enqueueSale('k-back');
		await h.sync.drainNow();
		const expected = [1000, 2000, 4000, 8000, 16000, 32000, 60000, 60000];
		const got = h.delays.join(',');
		results.push(
			check(
				'backoff exponencial con tope 60s',
				h.delays.length === expected.length && h.delays.every((ms, i) => ms === expected[i]),
				got,
			),
		);
		results.push(check('novena llamada liquida la venta', h.calls.length === 9 && (await h.store.all()).length === 0));
		results.push(
			check(
				'las 9 llamadas llevaron la key original',
				h.calls.every((c) => c.key === 'k-back'),
			),
		);
	}

	// 6. haramara_request_in_flight: hold ≥65s (the server's 60s stale-claim
	//    takeover window, with margin) before retrying THAT op.
	{
		const h = makeHarness();
		h.failFor('k-flight', [{ status: 409, code: 'haramara_request_in_flight', message: 'Solicitud en curso.' }]);
		await h.enqueueSale('k-flight');
		await h.sync.drainNow();
		results.push(
			check(
				`in_flight espera ≥65s (${IN_FLIGHT_HOLD_MS} ms) antes de reintentar`,
				h.delays.length === 1 && h.delays[0] >= 65_000,
				`${h.delays[0]} ms`,
			),
		);
		results.push(
			check(
				'in_flight reintenta con la MISMA key y liquida',
				h.calls.length === 2 &&
					h.calls[0].key === 'k-flight' &&
					h.calls[1].key === 'k-flight' &&
					(await h.store.all()).length === 0,
			),
		);
	}

	// 7. Offline gate + reconnect: nothing leaves while offline; the drain
	//    resumes (in order) when connectivity returns.
	{
		const h = makeHarness({ online: false });
		await h.enqueueSale('k-off-1');
		await h.enqueueSalida('k-off-2');
		await h.sync.drainNow();
		const offlineCalls = h.calls.length;
		const queuedWhileOffline = (await h.store.all()).every((op) => op.status === 'queued');
		h.setOnline(true);
		await h.sync.drainNow();
		results.push(check('sin conexión: 0 llamadas, todo sigue en cola', offlineCalls === 0 && queuedWhileOffline));
		results.push(
			check(
				'al reconectar el drain vacía la cola en orden',
				h.calls.map((c) => c.key).join(',') === 'k-off-1,k-off-2' && (await h.store.all()).length === 0,
			),
		);
	}

	// 8. Crash recovery: a row left `syncing` (app died mid-send) is retried
	//    with its original key instead of being stranded.
	{
		const h = makeHarness();
		const id = await h.enqueueSale('k-crash');
		await h.store.update(id, { status: 'syncing' });
		await h.sync.drainNow();
		results.push(
			check(
				'fila huérfana en `syncing` se reintenta y liquida',
				h.calls.length === 1 && h.calls[0].key === 'k-crash' && (await h.store.all()).length === 0,
			),
		);
	}

	return results;
}
