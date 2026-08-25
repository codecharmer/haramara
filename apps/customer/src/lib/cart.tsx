/**
 * Local cart + device order history.
 *
 * The cart lives on the device (AsyncStorage) and is only pushed to the
 * WooCommerce Store API at checkout — guest sessions expire (~48 h) and the
 * bakery's catalog is small, so rebuilding the server cart at the moment of
 * purchase is simpler and more resilient than keeping a live session.
 */

import AsyncStorage from '@react-native-async-storage/async-storage';
import type { ModifierSelection, StoreProduct } from '@haramara/api-client';
import React, { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';

import { formatMinorUnits } from '@haramara/api-client';

const CART_KEY = 'haramara_cart_v2';
/** v1 lines lacked per-selection keys; migrated on load with empty selections. */
const LEGACY_CART_KEY = 'haramara_cart_v1';
const ORDERS_KEY = 'haramara_orders_v1';

export interface CartLine {
	/** Stable line id: product + exact selections. Two lattes with different
	 * milks are two lines — same scheme the POS ticket uses. */
	key: string;
	productId: number;
	name: string;
	/** Unit price in MXN (major units), WITHOUT the modifier delta. */
	price: number;
	/** Per-unit MXN delta from the selections (server re-prices at checkout). */
	priceDelta: number;
	modifiers: ModifierSelection[];
	/** "Leche: Avena" — display only. */
	modifierLabels: string[];
	image: string;
	quantity: number;
}

export function lineKey(productId: number, selections: ModifierSelection[]): string {
	return selections.length === 0 ? String(productId) : `${productId}:${JSON.stringify(selections)}`;
}

export interface LocalOrder {
	id: number;
	key: string;
	number: string;
	total: number;
	createdAt: string;
	pickupLabel: string;
}

interface CartState {
	lines: CartLine[];
	count: number;
	total: number;
	add: (
		product: StoreProduct,
		quantity?: number,
		selections?: ModifierSelection[],
		priceDelta?: number,
		modifierLabels?: string[],
	) => void;
	setQuantity: (lineKey: string, quantity: number) => void;
	clear: () => void;
	orders: LocalOrder[];
	rememberOrder: (order: LocalOrder) => void;
}

const CartContext = createContext<CartState | undefined>(undefined);

export function productPrice(product: StoreProduct): number {
	return Number(formatMinorUnits(product.prices.price, product.prices.currency_minor_unit));
}

export function CartProvider({ children }: { children: React.ReactNode }) {
	const [lines, setLines] = useState<CartLine[]>([]);
	const [orders, setOrders] = useState<LocalOrder[]>([]);
	const [hydrated, setHydrated] = useState(false);

	useEffect(() => {
		Promise.all([AsyncStorage.getItem(CART_KEY), AsyncStorage.getItem(ORDERS_KEY)])
			.then(async ([cartRaw, ordersRaw]) => {
				if (cartRaw) {
					setLines(JSON.parse(cartRaw) as CartLine[]);
				} else {
					// One-time v1 migration: old lines had no key/selections.
					const legacy = await AsyncStorage.getItem(LEGACY_CART_KEY);
					if (legacy) {
						const old = JSON.parse(legacy) as Array<Omit<CartLine, 'key' | 'priceDelta' | 'modifiers' | 'modifierLabels'>>;
						setLines(
							old.map((l) => ({
								...l,
								key: lineKey(l.productId, []),
								priceDelta: 0,
								modifiers: [],
								modifierLabels: [],
							})),
						);
						void AsyncStorage.removeItem(LEGACY_CART_KEY);
					}
				}
				if (ordersRaw) setOrders(JSON.parse(ordersRaw) as LocalOrder[]);
			})
			.catch(() => undefined)
			.finally(() => setHydrated(true));
	}, []);

	useEffect(() => {
		if (hydrated) void AsyncStorage.setItem(CART_KEY, JSON.stringify(lines));
	}, [lines, hydrated]);

	useEffect(() => {
		if (hydrated) void AsyncStorage.setItem(ORDERS_KEY, JSON.stringify(orders));
	}, [orders, hydrated]);

	const add = useCallback(
		(
			product: StoreProduct,
			quantity = 1,
			selections: ModifierSelection[] = [],
			priceDelta = 0,
			modifierLabels: string[] = [],
		) => {
			setLines((prev) => {
				const key = lineKey(product.id, selections);
				const existing = prev.find((l) => l.key === key);
				if (existing) {
					return prev.map((l) => (l.key === key ? { ...l, quantity: Math.min(99, l.quantity + quantity) } : l));
				}
				return [
					...prev,
					{
						key,
						productId: product.id,
						name: product.name,
						price: productPrice(product),
						priceDelta,
						modifiers: selections,
						modifierLabels,
						image: product.images[0]?.thumbnail ?? '',
						quantity: Math.min(99, quantity),
					},
				];
			});
		},
		[],
	);

	const setQuantity = useCallback((key: string, quantity: number) => {
		setLines((prev) =>
			quantity <= 0
				? prev.filter((l) => l.key !== key)
				: prev.map((l) => (l.key === key ? { ...l, quantity: Math.min(99, quantity) } : l)),
		);
	}, []);

	const clear = useCallback(() => setLines([]), []);

	const rememberOrder = useCallback((order: LocalOrder) => {
		setOrders((prev) => [order, ...prev.filter((o) => o.id !== order.id)].slice(0, 25));
	}, []);

	const value = useMemo<CartState>(() => {
		const count = lines.reduce((n, l) => n + l.quantity, 0);
		const total = lines.reduce((sum, l) => sum + (l.price + l.priceDelta) * l.quantity, 0);
		return { lines, count, total, add, setQuantity, clear, orders, rememberOrder };
	}, [lines, orders, add, setQuantity, clear, rememberOrder]);

	return <CartContext.Provider value={value}>{children}</CartContext.Provider>;
}

export function useCart(): CartState {
	const ctx = useContext(CartContext);
	if (!ctx) throw new Error('useCart fuera de CartProvider');
	return ctx;
}
