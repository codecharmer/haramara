/**
 * Minimal ambient types for react-native-tcp-socket so the printing module
 * typechecks BEFORE the dependency exists (it arrives with the dev-build
 * phase — see docs/phase5-integration.md). DELETE THIS FILE when
 * `npx expo install react-native-tcp-socket` lands: the real package ships
 * its own, richer declarations and two copies would collide.
 */

declare module 'react-native-tcp-socket' {
	export interface ConnectionOptions {
		host: string;
		port: number;
		/** Upstream accepts more (tls, interface, ...); widen as needed. */
		[key: string]: unknown;
	}

	export interface Socket {
		write(
			data: string | Uint8Array,
			encoding?: string,
			callback?: (error?: unknown) => void,
		): boolean;
		on(event: string, listener: (...args: unknown[]) => void): Socket;
		setTimeout(timeout: number, callback?: () => void): Socket;
		end(): void;
		destroy(): void;
	}

	export function createConnection(options: ConnectionOptions, callback?: () => void): Socket;

	const TcpSocket: {
		createConnection: typeof createConnection;
	};
	export default TcpSocket;
}
