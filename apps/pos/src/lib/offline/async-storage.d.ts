/**
 * Minimal ambient types for @react-native-async-storage/async-storage.
 *
 * NOTE: unlike the customer app's cart, apps/pos does NOT have AsyncStorage
 * installed yet (checked apps/pos/package.json, 2026-08-24) — so it gets the
 * same treatment as expo-sqlite: lazy require in store.ts + this shim.
 * DELETE THIS FILE when `npx expo install @react-native-async-storage/async-storage`
 * lands: the real package ships its own declarations and two copies would
 * collide.
 */

declare module '@react-native-async-storage/async-storage' {
	export interface AsyncStorageStatic {
		getItem(key: string): Promise<string | null>;
		setItem(key: string, value: string): Promise<void>;
		removeItem(key: string): Promise<void>;
	}

	const AsyncStorage: AsyncStorageStatic;
	export default AsyncStorage;
}
