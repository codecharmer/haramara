/** Minimal fetch plumbing shared by the three API surfaces. */

export interface WpRestError {
	code: string;
	message: string;
	data?: { status?: number };
}

/** Normalized API failure. `message` is the server's own (Spanish) text when available. */
export class ApiError extends Error {
	readonly status: number;
	readonly code: string;

	constructor(status: number, code: string, message: string) {
		super(message);
		this.name = 'ApiError';
		this.status = status;
		this.code = code;
	}
}

export interface HttpConfig {
	/** Site origin, e.g. https://haramara.cafe (no trailing slash). */
	baseUrl: string;
	/** Optional pre-launch shared key sent as X-Haramara-App (see Rest\AccessGate). */
	appKey?: string;
	/** Override fetch (tests). */
	fetchFn?: typeof fetch;
}

export interface RequestOptions {
	method?: 'GET' | 'POST' | 'PUT' | 'DELETE';
	body?: unknown;
	headers?: Record<string, string>;
	/** Called with the raw Response before the body is parsed (header capture). */
	onResponse?: (response: Response) => void;
}

export async function request<T>(cfg: HttpConfig, path: string, opts: RequestOptions = {}): Promise<T> {
	const fetchFn = cfg.fetchFn ?? fetch;
	const headers: Record<string, string> = {
		Accept: 'application/json',
		...(opts.body !== undefined ? { 'Content-Type': 'application/json' } : {}),
		...(cfg.appKey ? { 'X-Haramara-App': cfg.appKey } : {}),
		...opts.headers,
	};

	let response: Response;
	try {
		response = await fetchFn(`${cfg.baseUrl}${path}`, {
			// Live-inventory API: the Store API sends no Cache-Control, and a
			// heuristically cached body (browser disk cache on web, NSURLCache
			// on iOS) serves stale stock indefinitely. Always hit the network.
			cache: 'no-store',
			method: opts.method ?? 'GET',
			headers,
			body: opts.body !== undefined ? JSON.stringify(opts.body) : undefined,
		});
	} catch (e) {
		throw new ApiError(0, 'network_error', 'Sin conexión. Revisa tu internet e intenta de nuevo.');
	}

	opts.onResponse?.(response);

	const text = await response.text();
	let json: unknown = null;
	if (text) {
		try {
			json = JSON.parse(text);
		} catch {
			json = null;
		}
	}

	if (!response.ok) {
		const err = (json ?? {}) as Partial<WpRestError>;
		throw new ApiError(
			response.status,
			err.code ?? `http_${response.status}`,
			err.message ?? `Error del servidor (${response.status}).`,
		);
	}

	return json as T;
}

/** Base64 without relying on btoa (Hermes has no btoa on older RN). */
export function toBase64(input: string): string {
	const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/';
	const bytes: number[] = [];
	// UTF-8 encode.
	for (const ch of input) {
		const cp = ch.codePointAt(0) as number;
		if (cp < 0x80) bytes.push(cp);
		else if (cp < 0x800) bytes.push(0xc0 | (cp >> 6), 0x80 | (cp & 0x3f));
		else if (cp < 0x10000) bytes.push(0xe0 | (cp >> 12), 0x80 | ((cp >> 6) & 0x3f), 0x80 | (cp & 0x3f));
		else bytes.push(0xf0 | (cp >> 18), 0x80 | ((cp >> 12) & 0x3f), 0x80 | ((cp >> 6) & 0x3f), 0x80 | (cp & 0x3f));
	}
	let out = '';
	for (let i = 0; i < bytes.length; i += 3) {
		const [a, b, c] = [bytes[i], bytes[i + 1], bytes[i + 2]];
		out += chars[a >> 2];
		out += chars[((a & 3) << 4) | ((b ?? 0) >> 4)];
		out += b === undefined ? '=' : chars[((b & 15) << 2) | ((c ?? 0) >> 6)];
		out += c === undefined ? '=' : chars[c & 63];
	}
	return out;
}
