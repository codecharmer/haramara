/**
 * Raw TCP transport to a LAN thermal printer (the "RAW"/JetDirect protocol:
 * open socket, write bytes, close — the printer speaks nothing back).
 *
 * react-native-tcp-socket is NOT a dependency yet. Printing needs a
 * development build — Expo Go and web ship no TCP sockets — so the require is
 * lazy and guarded: this module can sit in the tree, typecheck, and fail with
 * a clear Spanish error until the dev-build phase lands. The ambient types in
 * ./tcp-socket.d.ts exist for the same reason.
 */

import type { Socket } from 'react-native-tcp-socket';

import type { PrinterConfig } from './types';

export const DEFAULT_PRINTER_PORT = 9100;
const DEFAULT_TIMEOUT_MS = 4000;

/**
 * Thrown when the TCP module (or its native side) is missing — Expo Go, web,
 * or a JS-only install. Callers show `message` verbatim; `code` lets them
 * branch (e.g. hide the print button instead of toasting every sale).
 */
export class PrinterUnavailableError extends Error {
	readonly code = 'printer_unavailable';

	constructor() {
		super(
			'La impresión requiere un development build con react-native-tcp-socket. ' +
				'En Expo Go y en web no hay sockets TCP; genera el build de desarrollo para imprimir.',
		);
		this.name = 'PrinterUnavailableError';
	}
}

// Module-scoped shadow so tsc never depends on which global `require` type the
// Expo base config brings along. Metro provides the real one at runtime.
declare const require: ((id: string) => unknown) | undefined;

interface TcpModule {
	createConnection(options: { host: string; port: number }, callback?: () => void): Socket;
}

/** Lazy-load the TCP module; anything short of a working export is "unavailable". */
function loadTcpModule(): TcpModule {
	try {
		if (typeof require === 'function') {
			const mod = require('react-native-tcp-socket');
			const candidate = (mod as { default?: unknown } | null)?.default ?? mod;
			if (
				candidate !== null &&
				typeof candidate === 'object' &&
				typeof (candidate as TcpModule).createConnection === 'function'
			) {
				return candidate as TcpModule;
			}
		}
	} catch {
		// Missing module — fall through to the typed error.
	}
	throw new PrinterUnavailableError();
}

function toError(value: unknown, fallback: string): Error {
	if (value instanceof Error && value.message) return new Error(`${fallback} (${value.message})`);
	return new Error(fallback);
}

/**
 * Ship one ESC/POS job to the printer: connect (own timeout — the library's
 * default is too patient for a cashier mid-line), write, wait for the flush
 * callback, destroy. Resolves once the OS has taken the whole job.
 */
export function sendToPrinter(
	config: PrinterConfig,
	bytes: Uint8Array,
	timeoutMs = DEFAULT_TIMEOUT_MS,
): Promise<void> {
	const tcp = loadTcpModule();
	const host = config.host.trim();
	const port = config.port ?? DEFAULT_PRINTER_PORT;
	if (!host) {
		return Promise.reject(new Error('Configura la dirección IP de la impresora en ajustes.'));
	}

	return new Promise<void>((resolve, reject) => {
		let socket: Socket | null = null;
		let settled = false;

		const finish = (error?: Error) => {
			if (settled) return;
			settled = true;
			clearTimeout(timer);
			try {
				socket?.destroy();
			} catch {
				// Already torn down.
			}
			if (error) reject(error);
			else resolve();
		};

		const timer = setTimeout(() => {
			finish(
				new Error(
					`La impresora en ${host}:${port} no respondió en ${Math.round(timeoutMs / 1000)} segundos. ` +
						'Revisa que esté encendida y en la misma red.',
				),
			);
		}, timeoutMs);

		try {
			socket = tcp.createConnection({ host, port }, () => {
				// Connected. The write callback fires once the bytes are flushed
				// to the OS; only then is the socket safe to destroy.
				(socket as Socket).write(bytes, undefined, (writeError) => {
					if (writeError) {
						finish(toError(writeError, `No se pudo enviar el ticket a ${host}:${port}.`));
					} else {
						finish();
					}
				});
			});
			socket.on('error', (e) => {
				finish(toError(e, `No hay conexión con la impresora en ${host}:${port}.`));
			});
		} catch {
			// createConnection exploding synchronously means the package is in
			// node_modules but its native module is not in this binary (Expo Go).
			finish(new PrinterUnavailableError());
		}
	});
}
