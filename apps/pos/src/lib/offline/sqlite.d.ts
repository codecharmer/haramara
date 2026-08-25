/**
 * Minimal ambient types for expo-sqlite so the offline outbox typechecks
 * BEFORE the dependency exists (it arrives with the wiring PR — see
 * docs/phase7-integration.md). DELETE THIS FILE when
 * `npx expo install expo-sqlite` lands: the real package ships its own,
 * richer declarations and two copies would collide.
 */

declare module 'expo-sqlite' {
	export interface SQLiteRunResult {
		lastInsertRowId: number;
		changes: number;
	}

	export interface SQLiteDatabase {
		execAsync(source: string): Promise<void>;
		runAsync(source: string, ...params: unknown[]): Promise<SQLiteRunResult>;
		getAllAsync<T>(source: string, ...params: unknown[]): Promise<T[]>;
		getFirstAsync<T>(source: string, ...params: unknown[]): Promise<T | null>;
		closeAsync(): Promise<void>;
	}

	export function openDatabaseAsync(databaseName: string): Promise<SQLiteDatabase>;
}
