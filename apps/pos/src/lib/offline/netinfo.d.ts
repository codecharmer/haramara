/**
 * Minimal ambient types for @react-native-community/netinfo so the offline
 * outbox typechecks BEFORE the dependency exists (it arrives with the wiring
 * PR — see docs/phase7-integration.md). DELETE THIS FILE when
 * `npx expo install @react-native-community/netinfo` lands: the real package
 * ships its own, richer declarations and two copies would collide.
 */

declare module '@react-native-community/netinfo' {
	export interface NetInfoState {
		type: string;
		isConnected: boolean | null;
		isInternetReachable: boolean | null;
	}

	export type NetInfoSubscription = () => void;

	export function addEventListener(listener: (state: NetInfoState) => void): NetInfoSubscription;
	export function fetch(): Promise<NetInfoState>;

	const NetInfo: {
		addEventListener: typeof addEventListener;
		fetch: typeof fetch;
	};
	export default NetInfo;
}
