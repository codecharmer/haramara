/**
 * Outbox persistence — Phase 7 (venta offline).
 *
 * A queued sale must survive the app dying, so the outbox prefers real
 * storage and degrades gracefully, printing-lib style:
 *
 *   1. expo-sqlite       — lazy require + ambient shim (sqlite.d.ts); a real
 *                          table in WAL mode. The production path once the
 *                          dev-build dependency lands.
 *   2. AsyncStorage      — lazy require + ambient shim (async-storage.d.ts);
 *                          the whole queue as one JSON document.
 *   3. localStorage      — same JSON document; keeps the web preview honest.
 *   4. memory            — last resort (and what plain node gets, which is
 *                          how verify.ts runs). Survives the session only.
 *
 * Every backend implements the same tiny async interface, so nothing above
 * this module knows or cares which one won.
 */

import type { OperationStatus, QueuedOperation } from './types';

/** Versioned storage name — AsyncStorage/localStorage key AND sqlite table. */
export const OUTBOX_STORAGE_KEY = 'haramara_pos_outbox_v1';
/** Sqlite database file (the version rides on the table, not the file). */
const DB_NAME = 'haramara_pos_outbox.db';

export type OutboxBackend = 'sqlite' | 'async-storage' | 'web-storage' | 'memory';

/** The only fields the engine may change after enqueue — the key is immutable. */
export interface QueuedOperationPatch {
	status?: OperationStatus;
	attempts?: number;
	last_error?: string | null;
}

export interface OutboxStore {
	readonly backend: OutboxBackend;
	add(op: QueuedOperation): Promise<void>;
	/** FIFO — enqueue order, which is the only order the drain may use. */
	all(): Promise<QueuedOperation[]>;
	update(id: string, patch: QueuedOperationPatch): Promise<void>;
	remove(id: string): Promise<void>;
	/** queued + syncing (not failed) — the "Pendientes" number. */
	pendingCount(): Promise<number>;
}

// Module-scoped shadow so tsc never depends on which global `require` type the
// Expo base config brings along (same pattern as printing/transport.ts).
declare const require: ((id: string) => unknown) | undefined;

/* -------------------------------------------------------------------------- */
/* sqlite backend                                                             */
/* -------------------------------------------------------------------------- */

interface SqliteRunResult {
	changes: number;
}

interface SqliteDatabase {
	execAsync(source: string): Promise<void>;
	runAsync(source: string, ...params: unknown[]): Promise<SqliteRunResult>;
	getAllAsync<T>(source: string, ...params: unknown[]): Promise<T[]>;
	getFirstAsync<T>(source: string, ...params: unknown[]): Promise<T | null>;
}

interface SqliteModule {
	openDatabaseAsync(name: string): Promise<SqliteDatabase>;
}

interface OutboxRow {
	id: string;
	kind: string;
	idempotency_key: string;
	created_at: string;
	attempts: number;
	last_error: string | null;
	status: string;
	input: string;
}

function rowToOp(row: OutboxRow): QueuedOperation | null {
	try {
		return {
			kind: row.kind,
			id: row.id,
			idempotency_key: row.idempotency_key,
			created_at: row.created_at,
			attempts: row.attempts,
			last_error: row.last_error,
			status: row.status,
			input: JSON.parse(row.input),
		} as QueuedOperation;
	} catch {
		return null; // A corrupt row must not take the whole queue down.
	}
}

class SqliteOutboxStore implements OutboxStore {
	readonly backend = 'sqlite' as const;

	constructor(private readonly db: SqliteDatabase) {}

	async add(op: QueuedOperation): Promise<void> {
		await this.db.runAsync(
			`INSERT INTO ${OUTBOX_STORAGE_KEY}
				(id, kind, idempotency_key, created_at, attempts, last_error, status, input)
				VALUES (?, ?, ?, ?, ?, ?, ?, ?)`,
			op.id,
			op.kind,
			op.idempotency_key,
			op.created_at,
			op.attempts,
			op.last_error,
			op.status,
			JSON.stringify(op.input),
		);
	}

	async all(): Promise<QueuedOperation[]> {
		const rows = await this.db.getAllAsync<OutboxRow>(
			`SELECT id, kind, idempotency_key, created_at, attempts, last_error, status, input
				FROM ${OUTBOX_STORAGE_KEY} ORDER BY rowid ASC`,
		);
		const ops: QueuedOperation[] = [];
		for (const row of rows) {
			const op = rowToOp(row);
			if (op) ops.push(op);
		}
		return ops;
	}

	async update(id: string, patch: QueuedOperationPatch): Promise<void> {
		const sets: string[] = [];
		const params: unknown[] = [];
		if (patch.status !== undefined) {
			sets.push('status = ?');
			params.push(patch.status);
		}
		if (patch.attempts !== undefined) {
			sets.push('attempts = ?');
			params.push(patch.attempts);
		}
		if (patch.last_error !== undefined) {
			sets.push('last_error = ?');
			params.push(patch.last_error);
		}
		if (sets.length === 0) return;
		params.push(id);
		await this.db.runAsync(
			`UPDATE ${OUTBOX_STORAGE_KEY} SET ${sets.join(', ')} WHERE id = ?`,
			...params,
		);
	}

	async remove(id: string): Promise<void> {
		await this.db.runAsync(`DELETE FROM ${OUTBOX_STORAGE_KEY} WHERE id = ?`, id);
	}

	async pendingCount(): Promise<number> {
		const row = await this.db.getFirstAsync<{ n: number }>(
			`SELECT COUNT(*) AS n FROM ${OUTBOX_STORAGE_KEY} WHERE status IN ('queued', 'syncing')`,
		);
		return row?.n ?? 0;
	}
}

/** Lazy-load expo-sqlite; anything short of a working database is "unavailable". */
async function tryCreateSqliteStore(): Promise<OutboxStore | null> {
	try {
		if (typeof require !== 'function') return null;
		const mod = require('expo-sqlite');
		const candidates = [mod, (mod as { default?: unknown } | null)?.default];
		const sqlite = candidates.find(
			(c): c is SqliteModule =>
				c !== null && typeof c === 'object' && typeof (c as SqliteModule).openDatabaseAsync === 'function',
		);
		if (!sqlite) return null;
		const db = await sqlite.openDatabaseAsync(DB_NAME);
		await db.execAsync('PRAGMA journal_mode = WAL;');
		await db.execAsync(
			`CREATE TABLE IF NOT EXISTS ${OUTBOX_STORAGE_KEY} (
				id TEXT PRIMARY KEY NOT NULL,
				kind TEXT NOT NULL,
				idempotency_key TEXT NOT NULL,
				created_at TEXT NOT NULL,
				attempts INTEGER NOT NULL DEFAULT 0,
				last_error TEXT,
				status TEXT NOT NULL,
				input TEXT NOT NULL
			);`,
		);
		return new SqliteOutboxStore(db);
	} catch {
		return null; // Missing package / native module (Expo Go, web, node).
	}
}

/* -------------------------------------------------------------------------- */
/* JSON-document backends (AsyncStorage / localStorage / memory)              */
/* -------------------------------------------------------------------------- */

interface KvBackend {
	name: OutboxBackend;
	get(): Promise<string | null>;
	set(value: string): Promise<void>;
}

/**
 * One JSON array under a versioned key. All mutations run through a serial
 * promise chain so two interleaved read-modify-write cycles cannot drop an
 * operation — dropping a queued sale is dropping money.
 */
class JsonOutboxStore implements OutboxStore {
	readonly backend: OutboxBackend;
	private ops: QueuedOperation[] | null = null;
	private tail: Promise<unknown> = Promise.resolve();

	constructor(private readonly kv: KvBackend) {
		this.backend = kv.name;
	}

	private chain<T>(task: () => Promise<T>): Promise<T> {
		const run = this.tail.then(task, task);
		this.tail = run.then(
			() => undefined,
			() => undefined,
		);
		return run;
	}

	private async load(): Promise<QueuedOperation[]> {
		if (this.ops) return this.ops;
		try {
			const raw = await this.kv.get();
			const parsed: unknown = raw ? JSON.parse(raw) : [];
			this.ops = Array.isArray(parsed) ? (parsed as QueuedOperation[]) : [];
		} catch {
			this.ops = [];
		}
		return this.ops;
	}

	private async persist(): Promise<void> {
		await this.kv.set(JSON.stringify(this.ops ?? []));
	}

	add(op: QueuedOperation): Promise<void> {
		return this.chain(async () => {
			const ops = await this.load();
			ops.push(op);
			await this.persist();
		});
	}

	all(): Promise<QueuedOperation[]> {
		return this.chain(async () => {
			const ops = await this.load();
			return ops.slice();
		});
	}

	update(id: string, patch: QueuedOperationPatch): Promise<void> {
		return this.chain(async () => {
			const ops = await this.load();
			const op = ops.find((o) => o.id === id);
			if (!op) return;
			if (patch.status !== undefined) op.status = patch.status;
			if (patch.attempts !== undefined) op.attempts = patch.attempts;
			if (patch.last_error !== undefined) op.last_error = patch.last_error;
			await this.persist();
		});
	}

	remove(id: string): Promise<void> {
		return this.chain(async () => {
			const ops = await this.load();
			const at = ops.findIndex((o) => o.id === id);
			if (at !== -1) ops.splice(at, 1);
			await this.persist();
		});
	}

	pendingCount(): Promise<number> {
		return this.chain(async () => {
			const ops = await this.load();
			return ops.filter((o) => o.status === 'queued' || o.status === 'syncing').length;
		});
	}
}

interface AsyncStorageLike {
	getItem(key: string): Promise<string | null>;
	setItem(key: string, value: string): Promise<void>;
}

/** Lazy-load AsyncStorage (not in apps/pos yet — see async-storage.d.ts). */
function tryCreateAsyncStorageKv(): KvBackend | null {
	try {
		if (typeof require !== 'function') return null;
		const mod = require('@react-native-async-storage/async-storage');
		const candidates = [(mod as { default?: unknown } | null)?.default, mod];
		const storage = candidates.find(
			(c): c is AsyncStorageLike =>
				c !== null &&
				typeof c === 'object' &&
				typeof (c as AsyncStorageLike).getItem === 'function' &&
				typeof (c as AsyncStorageLike).setItem === 'function',
		);
		if (!storage) return null;
		return {
			name: 'async-storage',
			get: () => storage.getItem(OUTBOX_STORAGE_KEY),
			set: (value) => storage.setItem(OUTBOX_STORAGE_KEY, value),
		};
	} catch {
		return null;
	}
}

interface WebStorageLike {
	getItem(key: string): string | null;
	setItem(key: string, value: string): void;
}

/** Web preview: localStorage keeps the queue across reloads. */
function tryCreateWebStorageKv(): KvBackend | null {
	try {
		const g = globalThis as { localStorage?: WebStorageLike };
		const storage = g.localStorage;
		if (!storage || typeof storage.getItem !== 'function') return null;
		// Some webviews expose localStorage but throw on use — probe once.
		storage.setItem(`${OUTBOX_STORAGE_KEY}_probe`, '1');
		return {
			name: 'web-storage',
			get: async () => storage.getItem(OUTBOX_STORAGE_KEY),
			set: async (value) => storage.setItem(OUTBOX_STORAGE_KEY, value),
		};
	} catch {
		return null;
	}
}

function createMemoryKv(): KvBackend {
	let value: string | null = null;
	return {
		name: 'memory',
		get: async () => value,
		set: async (next) => {
			value = next;
		},
	};
}

/** In-memory store — the deterministic backend verify.ts injects. */
export function createMemoryOutboxStore(): OutboxStore {
	return new JsonOutboxStore(createMemoryKv());
}

/**
 * Best available persistence for this runtime: sqlite → AsyncStorage →
 * localStorage → memory. Memory means a force-quit loses the queue — the
 * provider surfaces which backend won so the corte can warn about that.
 */
export async function createOutboxStore(): Promise<OutboxStore> {
	const sqlite = await tryCreateSqliteStore();
	if (sqlite) return sqlite;
	const asyncStorage = tryCreateAsyncStorageKv();
	if (asyncStorage) return new JsonOutboxStore(asyncStorage);
	const web = tryCreateWebStorageKv();
	if (web) return new JsonOutboxStore(web);
	return createMemoryOutboxStore();
}
