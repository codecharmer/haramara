import {
	newIdempotencyKey,
	type ModifierSelection,
	type PosProduct,
	type WalkInPayment,
} from '@haramara/api-client';
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

import type { AdjustmentReason, TipMethod } from '@haramara/api-client';
import { needsAuthorization, ReasonSheet, REASON_LABELS, SupervisorSheet } from '../../lib/adjust';
import { usePosApi } from '../../lib/auth';
import { confirmDialog, notify } from '../../lib/dialog';
import { ModifierSheet } from '../../lib/modifiers';
import { useOperator } from '../../lib/operator';
import { color, money, radius, space, type } from '../../lib/theme';

/**
 * One ticket line. Two lattes with different milks are two lines, so lines are
 * keyed by product + the exact selections; a modifier-less product keeps one
 * line whose key is just its id (preserving the old tap-to-increment feel).
 */
interface TicketLine {
	key: string;
	product_id: number;
	quantity: number;
	selections: ModifierSelection[];
	/** Per-unit MXN delta from the selections, priced by the sheet. */
	priceDelta: number;
	/** "Leche: Avena · Extra shot" — display only; the server re-derives. */
	selectionLabel: string;
}

function lineKey(productId: number, selections: ModifierSelection[]): string {
	return selections.length === 0 ? String(productId) : `${productId}:${JSON.stringify(selections)}`;
}

export default function MostradorScreen() {
	const api = usePosApi();
	const queryClient = useQueryClient();
	const insets = useSafeAreaInsets();
	const { width } = useWindowDimensions();
	const twoPane = width >= 760;

	const [ticket, setTicket] = useState<TicketLine[]>([]);
	const [sheetProduct, setSheetProduct] = useState<PosProduct | null>(null);
	const [payment, setPayment] = useState<WalkInPayment>('cash');
	const [note, setNote] = useState('');
	/**
	 * One key per ticket, minted when the ticket opens and held steady across
	 * every retry of that sale. If the network drops after the server rang the
	 * order but before the reply arrived, pressing "Cobrar" again replays the
	 * same key and returns the original order instead of charging twice. A new
	 * key is minted only when the ticket is cleared for the next customer.
	 */
	const [saleKey, setSaleKey] = useState(() => newIdempotencyKey());
	const { roster } = useOperator();
	/** Applied ticket discount, chosen through the ReasonSheet. */
	const [discount, setDiscount] = useState<{ amount: number; reason: AdjustmentReason; note: string } | null>(null);
	const [discountAmount, setDiscountAmount] = useState('');
	const [discountSheet, setDiscountSheet] = useState(false);
	/** Pending supervisor authorization, retried into the charge. */
	const [authSheet, setAuthSheet] = useState(false);
	const [authorization, setAuthorization] = useState('');
	/** Propina — rides as meta, shown separately, never inside the total. */
	const [tipAmount, setTipAmount] = useState('');
	const [tipMethod, setTipMethod] = useState<TipMethod | null>(null);

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
			ticket
				.map((l) => ({ ...l, product: byId.get(l.product_id) }))
				.filter((l): l is TicketLine & { product: PosProduct } => l.product !== undefined && l.quantity > 0),
		[ticket, byId],
	);
	const itemsTotal = lines.reduce((sum, l) => sum + (l.product.price + l.priceDelta) * l.quantity, 0);
	const total = Math.max(0, itemsTotal - (discount?.amount ?? 0));
	const tipValue = Number.parseFloat(tipAmount.replace(/[^0-9.]/g, '')) || 0;

	/** Units of a product across ALL its lines (stock ceiling + tile badge). */
	function unitsOf(productId: number): number {
		return ticket.reduce((n, l) => (l.product_id === productId ? n + l.quantity : n), 0);
	}

	function addLine(product: PosProduct, selections: ModifierSelection[], priceDelta: number, selectionLabel: string, delta: number) {
		setTicket((prev) => {
			const key = lineKey(product.id, selections);
			const ceiling =
				product.manage_stock && product.stock_quantity !== null ? product.stock_quantity : 99;
			const others = prev.reduce((n, l) => (l.product_id === product.id && l.key !== key ? n + l.quantity : n), 0);
			const existing = prev.find((l) => l.key === key);
			const current = existing?.quantity ?? 0;
			const qty = Math.max(0, Math.min(Math.max(0, ceiling - others), current + delta));

			if (qty === 0) return prev.filter((l) => l.key !== key);
			if (existing) return prev.map((l) => (l.key === key ? { ...l, quantity: qty } : l));
			return [...prev, { key, product_id: product.id, quantity: qty, selections, priceDelta, selectionLabel }];
		});
	}

	/** Tile tap: products with modifier groups open the sheet; the rest add instantly. */
	function tapProduct(product: PosProduct) {
		if ((product.modifier_groups?.length ?? 0) > 0) {
			setSheetProduct(product);
		} else {
			addLine(product, [], 0, '', 1);
		}
	}

	/** "Leche: Avena · Extras: Shot extra" — display only, from the feed's groups. */
	function labelFor(product: PosProduct, selections: ModifierSelection[]): string {
		const parts: string[] = [];
		for (const sel of selections) {
			const group = product.modifier_groups?.find((g) => g.id === sel.group_id);
			if (!group) continue;
			const names = sel.option_keys
				.map((k) => group.options.find((o) => o.key === k)?.name)
				.filter((n): n is string => n !== undefined);
			if (names.length > 0) parts.push(`${group.name}: ${names.join(', ')}`);
		}
		return parts.join(' · ');
	}

	function confirmSheet(selections: ModifierSelection[], priceDelta: number) {
		if (sheetProduct) {
			addLine(sheetProduct, selections, priceDelta, labelFor(sheetProduct, selections), 1);
		}
		setSheetProduct(null);
	}

	const sale = useMutation({
		mutationFn: () =>
			api.createWalkIn({
				items: lines.map((l) => ({
					product_id: l.product.id,
					quantity: l.quantity,
					modifiers: l.selections.length > 0 ? l.selections : undefined,
				})),
				payment,
				note: note.trim() || undefined,
				idempotency_key: saleKey,
				discount: discount
					? {
							amount: discount.amount,
							reason_code: discount.reason,
							reason_note: discount.note || undefined,
							authorization: authorization || undefined,
						}
					: undefined,
				tip:
					tipValue > 0
						? { amount: tipValue, method: tipMethod ?? (payment === 'cash' ? 'cash' : 'card') }
						: undefined,
			}),
		onSuccess: (order) => {
			setTicket([]);
			setNote('');
			setDiscount(null);
			setDiscountAmount('');
			setAuthorization('');
			setTipAmount('');
			setTipMethod(null);
			// Next customer, next ticket, next key.
			setSaleKey(newIdempotencyKey());
			notify('Venta registrada', `Pedido #${order.number} · ${money(order.total)}`);
			void queryClient.invalidateQueries({ queryKey: ['pos-products'] });
			void queryClient.invalidateQueries({ queryKey: ['board'] });
			void queryClient.invalidateQueries({ queryKey: ['summary'] });
		},
		onError: (e) => {
			// The server said this discount needs a supervisor — get the
			// authorization and let the cashier press Cobrar again.
			if (needsAuthorization(e)) {
				setAuthSheet(true);
				return;
			}
			notify('No se pudo cobrar', e instanceof Error ? e.message : 'Intenta de nuevo.');
			void queryClient.invalidateQueries({ queryKey: ['pos-products'] });
		},
	});

	function charge() {
		if (lines.length === 0 || sale.isPending) return;
		confirmDialog({
			title: tipValue > 0 ? `Cobrar ${money(total)} + ${money(tipValue)} propina` : `Cobrar ${money(total)}`,
			message: payment === 'cash' ? 'Pago en efectivo.' : 'Pago con tarjeta en la terminal externa.',
			confirmText: 'Cobrar',
			onConfirm: () => sale.mutate(),
		});
	}

	const modifierModal = sheetProduct !== null && (
		<View style={styles.sheetOverlay}>
			<View style={styles.sheetCard}>
				<Text style={styles.sheetTitle}>{sheetProduct.name}</Text>
				<ModifierSheet
					groups={sheetProduct.modifier_groups ?? []}
					onConfirm={confirmSheet}
					onCancel={() => setSheetProduct(null)}
				/>
			</View>
		</View>
	);

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
							const inTicket = unitsOf(p.id);
							const soldOut = !p.in_stock || (p.manage_stock && p.stock_quantity === 0);
							return (
								<Pressable
									key={p.id}
									accessibilityRole="button"
									disabled={soldOut}
									onPress={() => tapProduct(p)}
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
						<View key={l.key} style={styles.line}>
							<View style={styles.lineText}>
								<Text style={styles.lineName} numberOfLines={1}>
									{l.product.name}
								</Text>
								{l.selectionLabel !== '' && (
									<Text style={styles.lineMods} numberOfLines={2}>
										{l.selectionLabel}
									</Text>
								)}
							</View>
							<View style={styles.stepper}>
								<Pressable
									accessibilityLabel={`Quitar ${l.product.name}`}
									style={styles.step}
									onPress={() => addLine(l.product, l.selections, l.priceDelta, l.selectionLabel, -1)}
								>
									<Text style={styles.stepText}>−</Text>
								</Pressable>
								<Text style={styles.qty}>{l.quantity}</Text>
								<Pressable
									accessibilityLabel={`Agregar ${l.product.name}`}
									style={styles.step}
									onPress={() => addLine(l.product, l.selections, l.priceDelta, l.selectionLabel, 1)}
								>
									<Text style={styles.stepText}>+</Text>
								</Pressable>
							</View>
							<Text style={styles.lineTotal}>{money((l.product.price + l.priceDelta) * l.quantity)}</Text>
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

			<View style={styles.discountRow}>
				<TextInput
					style={styles.discountInput}
					value={tipAmount}
					onChangeText={setTipAmount}
					keyboardType="decimal-pad"
					placeholder="Propina $ (opcional)"
					placeholderTextColor={color.textSoft}
				/>
				<Pressable
					accessibilityRole="button"
					onPress={() => setTipMethod('cash')}
					style={({ pressed }) => [styles.tipChip, (tipMethod ?? (payment === 'cash' ? 'cash' : 'card')) === 'cash' && styles.tipChipActive, pressed && { opacity: 0.8 }]}
				>
					<Text style={styles.tipChipText}>Efvo.</Text>
				</Pressable>
				<Pressable
					accessibilityRole="button"
					onPress={() => setTipMethod('card')}
					style={({ pressed }) => [styles.tipChip, (tipMethod ?? (payment === 'cash' ? 'cash' : 'card')) === 'card' && styles.tipChipActive, pressed && { opacity: 0.8 }]}
				>
					<Text style={styles.tipChipText}>Tarj.</Text>
				</Pressable>
			</View>

			{discount ? (
				<View style={styles.discountRow}>
					<Text style={styles.discountText}>
						Descuento −{money(discount.amount)} · {REASON_LABELS[discount.reason]}
					</Text>
					<Pressable
						accessibilityRole="button"
						onPress={() => {
							setDiscount(null);
							setAuthorization('');
						}}
					>
						<Text style={styles.discountRemove}>Quitar</Text>
					</Pressable>
				</View>
			) : (
				<View style={styles.discountRow}>
					<TextInput
						style={styles.discountInput}
						value={discountAmount}
						onChangeText={setDiscountAmount}
						keyboardType="decimal-pad"
						placeholder="Descuento $"
						placeholderTextColor={color.textSoft}
					/>
					<Pressable
						accessibilityRole="button"
						onPress={() => {
							const amount = Number.parseFloat(discountAmount.replace(/[^0-9.]/g, ''));
							if (!Number.isNaN(amount) && amount > 0 && amount <= itemsTotal) setDiscountSheet(true);
						}}
						style={({ pressed }) => [styles.discountApply, pressed && { opacity: 0.8 }]}
					>
						<Text style={styles.discountApplyText}>Aplicar</Text>
					</Pressable>
				</View>
			)}

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
			{modifierModal}
			{discountSheet && (
				<ReasonSheet
					title={`Descuento de ${money(Number.parseFloat(discountAmount.replace(/[^0-9.]/g, '')) || 0)}`}
					flow="discount"
					onConfirm={(reason, reasonNote) => {
						const amount = Number.parseFloat(discountAmount.replace(/[^0-9.]/g, ''));
						setDiscount({ amount, reason, note: reasonNote });
						setDiscountSheet(false);
					}}
					onCancel={() => setDiscountSheet(false)}
				/>
			)}
			{authSheet && (
				<SupervisorSheet
					actionLabel={`aplicar un descuento de ${money(discount?.amount ?? 0)}`}
					action="discount"
					supervisors={roster.filter((o) => o.role === 'supervisor')}
					authorize={(key, pin, action) => api.operatorAuthorize(key, pin, action)}
					onAuthorized={(auth) => {
						setAuthorization(auth);
						setAuthSheet(false);
						notify('Autorizado', 'Vuelve a presionar Cobrar.');
					}}
					onCancel={() => setAuthSheet(false)}
				/>
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
	lineText: { flex: 1, gap: 2 },
	lineName: { color: color.text, fontSize: type.small, fontWeight: '600' },
	lineMods: { color: color.textSoft, fontSize: type.tiny, lineHeight: 14 },
	sheetOverlay: {
		position: 'absolute',
		top: 0,
		right: 0,
		bottom: 0,
		left: 0,
		backgroundColor: 'rgba(0,0,0,0.55)',
		alignItems: 'center',
		justifyContent: 'center',
		padding: space(5),
	},
	sheetCard: {
		width: '100%',
		maxWidth: 480,
		maxHeight: '85%',
		backgroundColor: color.surface,
		borderRadius: radius.card,
		borderWidth: 1,
		borderColor: color.hairline,
		padding: space(5),
		gap: space(3),
	},
	sheetTitle: { color: color.text, fontSize: type.title, fontWeight: '700' },
	discountRow: { flexDirection: 'row', alignItems: 'center', gap: space(2), marginTop: space(2) },
	discountText: { flex: 1, color: color.attention, fontSize: type.small, fontWeight: '600' },
	discountRemove: { color: color.danger, fontSize: type.small, fontWeight: '600' },
	discountInput: {
		flex: 1,
		borderWidth: 1,
		borderColor: color.hairline,
		borderRadius: radius.control,
		backgroundColor: color.bg,
		paddingHorizontal: space(3),
		paddingVertical: space(2),
		fontSize: type.small,
		color: color.text,
	},
	discountApply: {
		borderWidth: 1,
		borderColor: color.hairline,
		borderRadius: radius.control,
		paddingHorizontal: space(3),
		paddingVertical: space(2),
		backgroundColor: color.bg,
	},
	discountApplyText: { color: color.accentDeep, fontSize: type.small, fontWeight: '600' },
	tipChip: {
		borderWidth: 1,
		borderColor: color.hairline,
		borderRadius: radius.pill,
		paddingHorizontal: space(2.5),
		paddingVertical: space(1.5),
		backgroundColor: color.bg,
	},
	tipChipActive: { borderColor: color.accentDeep, backgroundColor: color.attentionBg },
	tipChipText: { color: color.text, fontSize: type.tiny, fontWeight: '700' },
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
