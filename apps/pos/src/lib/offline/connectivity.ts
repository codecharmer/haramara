/**
 * Online detection — Phase 7 (venta offline).
 *
 * Preferred source is @react-native-community/netinfo (real reachability on
 * device), loaded printing-lib style: lazy require + ambient shim
 * (netinfo.d.ts) + graceful fallback. Without it — Expo Go before the
 * dev-build phase, the web preview, plain node — detection falls back to
 * `navigator.onLine` events plus a lightweight fetch probe against the
 * server's own `/wp-json/` index, which is the only signal that actually
 * matters: "can THIS tablet reach THIS server".
 *
 * Transitions are debounced so a flapping café Wi-Fi does not stampede the
 * drain engine on/off; an explicit checkNow() (app foreground) commits
 * immediately because a fresh probe outranks a debounce window.
 */

// Module-scoped shadow so tsc never depends on which global `require` type the
// Expo base config brings along (same pattern as printing/transport.ts).
declare const require: ((id: string) => unknown) | undefined;

export type ConnectivitySource = 'netinfo' | 'probe';

export interface ConnectivityMonitor {
	/** Where the signal comes from (decided lazily at start()). */
	readonly source: () => ConnectivitySource;
	/** Last committed state. Optimistic `true` before the first signal. */
	isOnline(): boolean;
	/** Fires on every committed transition. Returns the unsubscribe. */
	subscribe(listener: (online: boolean) => void): () => void;
	/** Active probe; commits the result immediately (no debounce). */
	checkNow(): Promise<boolean>;
	start(): void;
	stop(): void;
}

export interface ConnectivityOptions {
	/** Site origin, e.g. https://haramara.cafe — probed at `/wp-json/`. */
	baseUrl: string;
	/** Override fetch (tests). */
	fetchFn?: typeof fetch;
	/** Debounce for passively-observed transitions. Default 1200 ms. */
	debounceMs?: number;
	/** Probe cadence when running without netinfo. Default 30 s. */
	probeIntervalMs?: number;
	/** Probe timeout. Default 5 s. */
	probeTimeoutMs?: number;
}

interface NetInfoStateLike {
	isConnected: boolean | null;
	isInternetReachable: boolean | null;
}

interface NetInfoModuleLike {
	addEventListener(listener: (state: NetInfoStateLike) => void): () => void;
	fetch(): Promise<NetInfoStateLike>;
}

function loadNetInfo(): NetInfoModuleLike | null {
	try {
		if (typeof require !== 'function') return null;
		const mod = require('@react-native-community/netinfo');
		const candidates = [(mod as { default?: unknown } | null)?.default, mod];
		const netinfo = candidates.find(
			(c): c is NetInfoModuleLike =>
				c !== null && typeof c === 'object' && typeof (c as NetInfoModuleLike).addEventListener === 'function',
		);
		return netinfo ?? null;
	} catch {
		return null;
	}
}

function netInfoOnline(state: NetInfoStateLike): boolean {
	// `isInternetReachable` is tri-state; only an explicit false is "offline"
	// beyond the link itself.
	return state.isConnected === true && state.isInternetReachable !== false;
}

interface AbortControllerLike {
	signal: unknown;
	abort(): void;
}

interface BrowserGlobals {
	navigator?: { onLine?: boolean };
	addEventListener?: (type: string, listener: () => void) => void;
	removeEventListener?: (type: string, listener: () => void) => void;
	AbortController?: new () => AbortControllerLike;
}

export function createConnectivityMonitor(options: ConnectivityOptions): ConnectivityMonitor {
	const fetchFn = options.fetchFn ?? fetch;
	const debounceMs = options.debounceMs ?? 1200;
	const probeIntervalMs = options.probeIntervalMs ?? 30_000;
	const probeTimeoutMs = options.probeTimeoutMs ?? 5000;
	const probeUrl = `${options.baseUrl.replace(/\/+$/, '')}/wp-json/`;
	const g = globalThis as BrowserGlobals;

	let source: ConnectivitySource = 'probe';
	let committed = true; // Optimistic: the app assumes online until told otherwise.
	let listeners: Array<(online: boolean) => void> = [];
	let debounceTimer: ReturnType<typeof setTimeout> | null = null;
	let probeTimer: ReturnType<typeof setInterval> | null = null;
	let unsubscribeNetInfo: (() => void) | null = null;
	let running = false;

	const commit = (online: boolean) => {
		if (debounceTimer) {
			clearTimeout(debounceTimer);
			debounceTimer = null;
		}
		if (online === committed) return;
		committed = online;
		for (const listener of listeners.slice()) listener(online);
	};

	/** Passive observation: hold the new value for debounceMs before committing. */
	const report = (online: boolean) => {
		if (online === committed) {
			if (debounceTimer) {
				clearTimeout(debounceTimer);
				debounceTimer = null;
			}
			return;
		}
		if (debounceTimer) clearTimeout(debounceTimer);
		debounceTimer = setTimeout(() => {
			debounceTimer = null;
			commit(online);
		}, debounceMs);
	};

	const probe = async (): Promise<boolean> => {
		// The browser saying the interface is down is trustworthy; it saying
		// "up" only means there is a link, so a real probe still runs.
		if (g.navigator && g.navigator.onLine === false) return false;
		let timeout: ReturnType<typeof setTimeout> | null = null;
		try {
			const controller = g.AbortController ? new g.AbortController() : null;
			if (controller) timeout = setTimeout(() => controller.abort(), probeTimeoutMs);
			const init: { method: string; cache: 'no-store'; signal?: unknown } = {
				method: 'HEAD',
				cache: 'no-store',
			};
			if (controller) init.signal = controller.signal;
			await fetchFn(probeUrl, init as Parameters<typeof fetch>[1]);
			// ANY HTTP response — even a 405 on HEAD — proves the path to the
			// server. Only a thrown fetch (no route, timeout) means offline.
			return true;
		} catch {
			return false;
		} finally {
			if (timeout) clearTimeout(timeout);
		}
	};

	const onBrowserOnline = () => report(true);
	const onBrowserOffline = () => report(false);

	return {
		source: () => source,
		isOnline: () => committed,
		subscribe(listener) {
			listeners.push(listener);
			return () => {
				listeners = listeners.filter((l) => l !== listener);
			};
		},
		async checkNow() {
			const online = await probe();
			commit(online);
			return online;
		},
		start() {
			if (running) return;
			running = true;
			const netinfo = loadNetInfo();
			if (netinfo) {
				source = 'netinfo';
				unsubscribeNetInfo = netinfo.addEventListener((state) => report(netInfoOnline(state)));
				void netinfo
					.fetch()
					.then((state) => report(netInfoOnline(state)))
					.catch(() => undefined);
				return;
			}
			source = 'probe';
			if (typeof g.addEventListener === 'function') {
				g.addEventListener('online', onBrowserOnline);
				g.addEventListener('offline', onBrowserOffline);
			}
			if (probeIntervalMs > 0) {
				probeTimer = setInterval(() => {
					void probe().then(report);
				}, probeIntervalMs);
			}
		},
		stop() {
			if (!running) return;
			running = false;
			if (unsubscribeNetInfo) {
				unsubscribeNetInfo();
				unsubscribeNetInfo = null;
			}
			if (typeof g.removeEventListener === 'function') {
				g.removeEventListener('online', onBrowserOnline);
				g.removeEventListener('offline', onBrowserOffline);
			}
			if (probeTimer) {
				clearInterval(probeTimer);
				probeTimer = null;
			}
			if (debounceTimer) {
				clearTimeout(debounceTimer);
				debounceTimer = null;
			}
		},
	};
}
