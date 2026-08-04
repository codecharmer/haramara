import type { PosProduct, WalkInPayment } from '@haramara/api-client';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import React, { useMemo, useState } from 'react';
import {
	ActivityIndicator,
	Pressable,
	ScrollView,
	StyleSheet,
	Text,
	TextInput,
	useWindowDimensions,
	View,
} from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { usePosApi } from '../../lib/auth';
import { confirmDialog, notify } from '../../lib/dialog';
import { color, money, radius, space, type } from '../../lib/theme';

type Ticket = Record<number, number>; // product_id -> quantity

export default function MostradorScreen() {
	const api = usePosApi();
	const queryClient = useQueryClient();
	const insets = useSafeAreaInsets();
	const { width } = useWindowDimensions();
	const twoPane = width >= 760;

	const [ticket, setTicket] = useState<Ticket>({});
	const [payment, setPayment] = useState<WalkInPayment>('cash');
	const [note, setNote] = useState('');

	const productsQuery = useQuery({
		queryKey: ['pos-products'],
		queryFn: () => api.products(),
		refetchInterval: 60_000,
	});
	const products = useMemo(() => productsQuery.data ?? [], [productsQuery.data]);
	const byId = useMemo(() => new Map(products.map((p) => [p.id, p])), [products]);

	const groups = useMemo(() => {
		const map = new Map<string, PosProduct[]>();
		for (const p of products) {
			const cat = p.categories[0] ?? 'Otros';
			if (!map.has(cat)) map.set(cat, []);
			(map.get(cat) as PosProduct[]).push(p);
		}
		return [...map.entries()];
	}, [products]);

	const lines = useMemo(
		() =>
			Object.entries(ticket)
				.map(([id, qty]) => ({ product: byId.get(Number(id)), qty }))
				.filter((l): l is { product: PosProduct; qty: number } => l.product !== undefined && l.qty > 0),
		[ticket, byId],
	);
	const total = lines.reduce((sum, l) => sum + l.product.price * l.qty, 0);

	function add(product: PosProduct, delta: number) {
		setTicket((prev) => {
			const next = { ...prev };
			const current = next[product.id] ?? 0;
			const ceiling =
				product.manage_stock && product.stock_quantity !== null ? product.stock_quantity : 99;
			const qty = Math.max(0, Math.min(ceiling, current + delta));
			if (qty === 0) delete next[product.id];
			else next[product.id] = qty;
			return next;
		});
	}

	const sale = useMutation({
		mutationFn: () =>
			api.createWalkIn({
				items: lines.map((l) => ({ product_id: l.product.id, quantity: l.qty })),
				payment,
				note: note.trim() || undefined,
			}),
		onSuccess: (order) => {
			setTicket({});
			setNote('');
			notify('Venta registrada', `Pedido #${order.number} · ${money(order.total)}`);
			void queryClient.invalidateQueries({ queryKey: ['pos-products'] });
			void queryClient.invalidateQueries({ queryKey: ['board'] });
			void queryClient.invalidateQueries({ queryKey: ['summary'] });
		},
		onError: (e) => {
			notify('No se pudo cobrar', e instanceof Error ? e.message : 'Intenta de nuevo.');
			void queryClient.invalidateQueries({ queryKey: ['pos-products'] });
		},
	});

	function charge() {
		if (lines.length === 0 || sale.isPending) return;
		confirmDialog({
			title: `Cobrar ${money(total)}`,
			message: payment === 'cash' ? 'Pago en efectivo.' : 'Pago con tarjeta en la terminal externa.',
			confirmText: 'Cobrar',
			onConfirm: () => sale.mutate(),
		});
	}

	const productPane = (
		<ScrollView contentContainerStyle={styles.productsScroll}>
			{productsQuery.isLoading && (
				<View style={styles.center}>
					<ActivityIndicator size="large" color={color.accentDeep} />
				</View>
			)}
			{productsQuery.isError && (
				<View style={styles.center}>
					<Text style={styles.emptyText}>No se pudo cargar el catálogo.</Text>
					<Pressable style={styles.retry} onPress={() => productsQuery.refetch()}>
						<Text style={styles.retryText}>Reintentar</Text>
					</Pressable>
				</View>
			)}
			{groups.map(([category, items]) => (
				<View key={category} style={styles.group}>
					<Text style={styles.groupHeading}>{category}</Text>
					<View style={styles.grid}>
						{items.map((p) => {
							const inTicket = ticket[p.id] ?? 0;
							const soldOut = !p.in_stock || (p.manage_stock && p.stock_quantity === 0);
							return (
								<Pressable
									key={p.id}
									accessibilityRole="button"
									disabled={soldOut}
									onPress={() => add(p, 1)}
									style={({ pressed }) => [
										styles.tile,
										inTicket > 0 && styles.tileActive,
										soldOut && styles.tileSoldOut,
										pressed && { opacity: 0.85 },
									]}
								>
									<Text style={styles.tileName} numberOfLines={2}>
										{p.name}
									</Text>
									<View style={styles.tileBottom}>
										<Text style={styles.tilePrice}>{money(p.price)}</Text>
										{soldOut ? (
											<Text style={styles.soldOut}>Agotado</Text>
										) : inTicket > 0 ? (
											<View style={styles.tileBadge}>
												<Text style={styles.tileBadgeText}>{inTicket}</Text>
											</View>
										) : p.manage_stock && p.stock_quantity !== null && p.stock_quantity <= 5 ? (
											<Text style={styles.lowStock}>Quedan {p.stock_quantity}</Text>
										) : null}
									</View>
								</Pressable>
							);
						})}
					</View>
				</View>
			))}
		</ScrollView>
	);

	const ticketPane = (
		<View style={[styles.ticket, twoPane ? styles.ticketSide : styles.ticketBottom]}>
			<Text style={styles.ticketHeading}>Cuenta</Text>
			{lines.length === 0 ? (
				<Text style={styles.emptyText}>Toca un producto para agregarlo.</Text>
			) : (
				<ScrollView style={styles.ticketLines}>
					{lines.map((l) => (
						<View key={l.product.id} style={styles.line}>
							<Text style={styles.lineName} numberOfLines={1}>
								{l.product.name}
							</Text>
							<View style={styles.stepper}>
								<Pressable accessibilityLabel={`Quitar ${l.product.name}`} style={styles.step} onPress={() => add(l.product, -1)}>
									<Text style={styles.stepText}>−</Text>
								</Pressable>
								<Text style={styles.qty}>{l.qty}</Text>
								<Pressable accessibilityLabel={`Agregar ${l.product.name}`} style={styles.step} onPress={() => add(l.product, 1)}>
									<Text style={styles.stepText}>+</Text>
								</Pressable>
							</View>
							<Text style={styles.lineTotal}>{money(l.product.price * l.qty)}</Text>
						</View>
					))}
				</ScrollView>
			)}

			<TextInput
				style={styles.note}
				value={note}
				onChangeText={setNote}
				placeholder="Nota (opcional)"
				placeholderTextColor={color.textSoft}
			/>

			<View style={styles.paymentRow}>
				<Segment
					label="Efectivo"
					active={payment === 'cash'}
					onPress={() => setPayment('cash')}
				/>
				<Segment
					label="Tarjeta (terminal)"
					active={payment === 'card_external'}
					onPress={() => setPayment('card_external')}
				/>
			</View>

			<Pressable
				accessibilityRole="button"
				disabled={lines.length === 0 || sale.isPending}
				onPress={charge}
				style={({ pressed }) => [
					styles.charge,
					(lines.length === 0 || sale.isPending) && { opacity: 0.4 },
					pressed && { opacity: 0.85 },
				]}
			>
				{sale.isPending ? (
					<ActivityIndicator color={color.surface} />
				) : (
					<Text style={styles.chargeText}>Cobrar {lines.length > 0 ? money(total) : ''}</Text>
				)}
			</Pressable>
		</View>
	);

	return (
		<View style={[styles.screen, { paddingTop: insets.top }]}>
			<View style={styles.header}>
				<Text style={styles.title}>Venta de mostrador</Text>
			</View>
			{twoPane ? (
				<View style={styles.panes}>
					<View style={styles.productsPane}>{productPane}</View>
					{ticketPane}
				</View>
			) : (
				<View style={{ flex: 1 }}>
					<View style={{ flex: 1 }}>{productPane}</View>
					{ticketPane}
				</View>
			)}
		</View>
	);
}

function Segment({ label, active, onPress }: { label: string; active: boolean; onPress: () => void }) {
	return (
		<Pressable
			accessibilityRole="button"
			accessibilityState={{ selected: active }}
			onPress={onPress}
			style={[styles.segment, active && styles.segmentActive]}
		>
			<Text style={[styles.segmentText, active && styles.segmentTextActive]}>{label}</Text>
		</Pressable>
	);
}

const styles = StyleSheet.create({
	screen: { flex: 1, backgroundColor: color.bg },
	header: { paddingHorizontal: space(5), paddingTop: space(4), paddingBottom: space(2) },
	title: { color: color.text, fontSize: type.display, fontWeight: '700' },
	panes: { flex: 1, flexDirection: 'row' },
	productsPane: { flex: 1 },
	productsScroll: { padding: space(5), gap: space(5), paddingBottom: space(8) },
	center: { alignItems: 'center', justifyContent: 'center', padding: space(8), gap: space(3) },
	emptyText: { color: color.textSoft, fontSize: type.small, lineHeight: 20 },
	retry: {
		backgroundColor: color.text,
		borderRadius: radius.control,
		paddingHorizontal: space(5),
		paddingVertical: space(2.5),
	},
	retryText: { color: color.surface, fontWeight: '700', fontSize: type.small },
	group: { gap: space(3) },
	groupHeading: {
		color: color.accentDeep,
		fontSize: type.body,
		fontWeight: '700',
		borderBottomWidth: 1,
		borderBottomColor: color.hairline,
		paddingBottom: space(2),
	},
	grid: { flexDirection: 'row', flexWrap: 'wrap', gap: space(3) },
	tile: {
		width: 168,
		minHeight: 96,
		backgroundColor: color.surface,
		borderRadius: radius.card,
		borderWidth: 1,
		borderColor: color.hairline,
		padding: space(3),
		justifyContent: 'space-between',
	},
	tileActive: { borderColor: color.accentDeep, borderWidth: 2 },
	tileSoldOut: { opacity: 0.5 },
	tileName: { color: color.text, fontSize: type.small, fontWeight: '600', lineHeight: 18 },
	tileBottom: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' },
	tilePrice: { color: color.textSoft, fontSize: type.small },
	tileBadge: {
		backgroundColor: color.accentDeep,
		minWidth: 24,
		height: 24,
		borderRadius: 12,
		alignItems: 'center',
		justifyContent: 'center',
		paddingHorizontal: space(1.5),
	},
	tileBadgeText: { color: color.surface, fontSize: type.small, fontWeight: '700' },
	lowStock: { color: color.attention, fontSize: type.tiny, fontWeight: '600' },
	soldOut: { color: color.danger, fontSize: type.tiny, fontWeight: '700' },
	ticket: {
		backgroundColor: color.surface,
		borderColor: color.hairline,
		padding: space(4),
		gap: space(3),
	},
	ticketSide: { width: 340, borderLeftWidth: 1 },
	ticketBottom: { borderTopWidth: 1, maxHeight: 380 },
	ticketHeading: { color: color.text, fontSize: type.title, fontWeight: '700' },
	ticketLines: { flexGrow: 0 },
	line: {
		flexDirection: 'row',
		alignItems: 'center',
		gap: space(2),
		paddingVertical: space(2),
		borderBottomWidth: 1,
		borderBottomColor: color.hairline,
	},
	lineName: { flex: 1, color: color.text, fontSize: type.small, fontWeight: '600' },
	stepper: { flexDirection: 'row', alignItems: 'center', gap: space(1) },
	step: {
		width: 32,
		height: 32,
		borderRadius: radius.control,
		borderWidth: 1,
		borderColor: color.hairline,
		alignItems: 'center',
		justifyContent: 'center',
		backgroundColor: color.bg,
	},
	stepText: { color: color.text, fontSize: type.body, fontWeight: '700' },
	qty: { color: color.text, fontSize: type.body, fontWeight: '700', minWidth: 24, textAlign: 'center' },
	lineTotal: { color: color.textSoft, fontSize: type.small, minWidth: 64, textAlign: 'right' },
	note: {
		borderWidth: 1,
		borderColor: color.hairline,
		borderRadius: radius.control,
		backgroundColor: color.bg,
		paddingHorizontal: space(3),
		paddingVertical: space(2.5),
		fontSize: type.small,
		color: color.text,
	},
	paymentRow: { flexDirection: 'row', gap: space(2) },
	segment: {
		flex: 1,
		borderRadius: radius.control,
		borderWidth: 1,
		borderColor: color.hairline,
		paddingVertical: space(2.5),
		alignItems: 'center',
		backgroundColor: color.bg,
	},
	segmentActive: { backgroundColor: color.text, borderColor: color.text },
	segmentText: { color: color.text, fontSize: type.small, fontWeight: '600' },
	segmentTextActive: { color: color.surface },
	charge: {
		backgroundColor: color.accentDeep,
		borderRadius: radius.control,
		paddingVertical: space(3.5),
		alignItems: 'center',
	},
	chargeText: { color: color.surface, fontSize: type.body, fontWeight: '700', letterSpacing: 0.5 },
});
