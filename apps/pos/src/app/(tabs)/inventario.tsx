import type { PosProduct, WithdrawalDestination } from '@haramara/api-client';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import React, { useMemo, useState } from 'react';
import {
	ActivityIndicator,
	Alert,
	Pressable,
	RefreshControl,
	ScrollView,
	StyleSheet,
	Text,
	TextInput,
	useWindowDimensions,
	View,
} from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { usePosApi } from '../../lib/auth';
import { color, DESTINATION_LABELS, destinationStyle, money, radius, space, type } from '../../lib/theme';

type Mode = 'stock' | 'salida' | 'historial';

const DESTINATIONS: WithdrawalDestination[] = ['malva', 'empleado', 'merma', 'otro'];

export default function InventarioScreen() {
	const api = usePosApi();
	const queryClient = useQueryClient();
	const insets = useSafeAreaInsets();
	const { width } = useWindowDimensions();
	const twoPane = width >= 760;

	const [mode, setMode] = useState<Mode>('stock');

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

	// ── Existencias: per-product recount drafts (string: the TextInput owns it). ──
	const [drafts, setDrafts] = useState<Record<number, string>>({});

	function draftFor(p: PosProduct): string {
		return drafts[p.id] ?? String(p.stock_quantity ?? 0);
	}

	function draftNumber(p: PosProduct): number | null {
		const n = parseInt(draftFor(p), 10);
		return Number.isFinite(n) ? n : null;
	}

	function isDirty(p: PosProduct): boolean {
		const n = draftNumber(p);
		return drafts[p.id] !== undefined && n !== null && n !== (p.stock_quantity ?? 0);
	}

	function bumpDraft(p: PosProduct, delta: number) {
		const current = draftNumber(p) ?? 0;
		setDrafts((prev) => ({ ...prev, [p.id]: String(Math.max(0, Math.min(999, current + delta))) }));
	}

	function clearDraft(id: number) {
		setDrafts((prev) => {
			const next = { ...prev };
			delete next[id];
			return next;
		});
	}

	const setStock = useMutation({
		mutationFn: ({ id, qty }: { id: number; qty: number }) => api.setStock(id, qty),
		onSuccess: (product) => {
			queryClient.setQueryData<PosProduct[]>(['pos-products'], (prev) =>
				prev?.map((p) => (p.id === product.id ? product : p)),
			);
			clearDraft(product.id);
			void queryClient.invalidateQueries({ queryKey: ['summary'] });
		},
		onError: (e) => {
			Alert.alert('No se pudo guardar', e instanceof Error ? e.message : 'Intenta de nuevo.');
			void queryClient.invalidateQueries({ queryKey: ['pos-products'] });
		},
	});

	// ── Nueva salida: ticket-style lines + destination + person + note. ──
	const [salida, setSalida] = useState<Record<number, number>>({});
	const [destination, setDestination] = useState<WithdrawalDestination | null>(null);
	const [person, setPerson] = useState('');
	const [note, setNote] = useState('');

	// Shared employee list for "¿Quién lo lleva?". If the endpoint is missing
	// (older server) the picker falls back to free text so salidas never block.
	const employeesQuery = useQuery({
		queryKey: ['employees'],
		queryFn: () => api.employees(),
		refetchInterval: 300_000,
	});
	const employeeNames = useMemo(
		() => [...(employeesQuery.data ?? [])].sort((a, b) => a.localeCompare(b, 'es')),
		[employeesQuery.data],
	);
	const [addingPerson, setAddingPerson] = useState(false);
	const [newName, setNewName] = useState('');

	const addEmployee = useMutation({
		mutationFn: (name: string) => api.addEmployee(name),
		onSuccess: (names, name) => {
			queryClient.setQueryData(['employees'], names);
			// The server no-ops on duplicates; select the stored spelling.
			setPerson(names.find((n) => n.toLowerCase() === name.toLowerCase()) ?? name);
			setNewName('');
			setAddingPerson(false);
		},
		onError: (e) => {
			Alert.alert('No se pudo agregar', e instanceof Error ? e.message : 'Intenta de nuevo.');
		},
	});

	const salidaLines = useMemo(
		() =>
			Object.entries(salida)
				.map(([id, qty]) => ({ product: byId.get(Number(id)), qty }))
				.filter((l): l is { product: PosProduct; qty: number } => l.product !== undefined && l.qty > 0),
		[salida, byId],
	);
	const salidaPieces = salidaLines.reduce((sum, l) => sum + l.qty, 0);
	const salidaValue = salidaLines.reduce((sum, l) => sum + l.product.price * l.qty, 0);

	function addToSalida(product: PosProduct, delta: number) {
		setSalida((prev) => {
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

	const withdrawal = useMutation({
		mutationFn: () =>
			api.createWithdrawal({
				items: salidaLines.map((l) => ({ product_id: l.product.id, quantity: l.qty })),
				destination: destination as WithdrawalDestination,
				person: person.trim() || undefined,
				note: note.trim() || undefined,
			}),
		onSuccess: (created) => {
			setSalida({});
			setDestination(null);
			setPerson('');
			setNote('');
			setAddingPerson(false);
			setNewName('');
			Alert.alert(
				'Salida registrada',
				`${created.total_quantity} pzas a ${created.destination_label} · ${money(created.total_value)}`,
			);
			void queryClient.invalidateQueries({ queryKey: ['pos-products'] });
			void queryClient.invalidateQueries({ queryKey: ['withdrawals'] });
			void queryClient.invalidateQueries({ queryKey: ['summary'] });
		},
		onError: (e) => {
			Alert.alert('No se pudo registrar', e instanceof Error ? e.message : 'Intenta de nuevo.');
			void queryClient.invalidateQueries({ queryKey: ['pos-products'] });
		},
	});

	function confirmSalida() {
		if (salidaLines.length === 0 || !destination || withdrawal.isPending) return;
		Alert.alert(
			`Salida a ${DESTINATION_LABELS[destination]}`,
			`${salidaPieces} pzas · ${money(salidaValue)}. Se descuenta del inventario y no cuenta como venta.`,
			[
				{ text: 'Cancelar', style: 'cancel' },
				{ text: 'Registrar', onPress: () => withdrawal.mutate() },
			],
		);
	}

	// ── Historial ──
	const history = useQuery({
		queryKey: ['withdrawals'],
		queryFn: () => api.withdrawals(),
		refetchInterval: 60_000,
	});

	const loadError = (
		<View style={styles.center}>
			<Text style={styles.emptyText}>No se pudo cargar el catálogo.</Text>
			<Pressable style={styles.retry} onPress={() => productsQuery.refetch()}>
				<Text style={styles.retryText}>Reintentar</Text>
			</Pressable>
		</View>
	);

	// ── Mode 1: Existencias ──
	const stockPane = (
		<ScrollView contentContainerStyle={styles.stockScroll}>
			{productsQuery.isLoading && (
				<View style={styles.center}>
					<ActivityIndicator size="large" color={color.accentDeep} />
				</View>
			)}
			{productsQuery.isError && loadError}
			{groups.map(([category, items]) => (
				<View key={category} style={styles.group}>
					<Text style={styles.groupHeading}>{category}</Text>
					{items.map((p) => {
						const managed = p.manage_stock;
						const soldOut = !p.in_stock || (p.manage_stock && p.stock_quantity === 0);
						const dirty = managed && isDirty(p);
						const saving = setStock.isPending && setStock.variables?.id === p.id;
						return (
							<View key={p.id} style={styles.stockRow}>
								<View style={styles.stockInfo}>
									<Text style={styles.stockName} numberOfLines={1}>
										{p.name}
									</Text>
									<View style={styles.stockMeta}>
										<Text style={styles.stockPrice}>{money(p.price)}</Text>
										{managed && soldOut ? (
											<Text style={styles.soldOut}>Agotado</Text>
										) : managed && p.stock_quantity !== null && p.stock_quantity <= 5 ? (
											<Text style={styles.lowStock}>Quedan {p.stock_quantity}</Text>
										) : null}
									</View>
								</View>
								{managed ? (
									<View style={styles.stockControls}>
										<View style={styles.stepper}>
											<Pressable
												accessibilityLabel={`Menos ${p.name}`}
												style={styles.step}
												onPress={() => bumpDraft(p, -1)}
											>
												<Text style={styles.stepText}>−</Text>
											</Pressable>
											<TextInput
												style={styles.qtyInput}
												value={draftFor(p)}
												onChangeText={(t) =>
													setDrafts((prev) => ({ ...prev, [p.id]: t.replace(/[^0-9]/g, '').slice(0, 3) }))
												}
												keyboardType="number-pad"
												selectTextOnFocus
												accessibilityLabel={`Existencia de ${p.name}`}
											/>
											<Pressable
												accessibilityLabel={`Más ${p.name}`}
												style={styles.step}
												onPress={() => bumpDraft(p, 1)}
											>
												<Text style={styles.stepText}>+</Text>
											</Pressable>
										</View>
										<Pressable
											accessibilityRole="button"
											disabled={!dirty || saving}
											onPress={() => {
												const qty = draftNumber(p);
												if (qty !== null) setStock.mutate({ id: p.id, qty });
											}}
											style={[styles.save, (!dirty || saving) && styles.saveIdle]}
										>
											{saving ? (
												<ActivityIndicator size="small" color={color.surface} />
											) : (
												<Text style={[styles.saveText, !dirty && styles.saveTextIdle]}>Guardar</Text>
											)}
										</Pressable>
									</View>
								) : (
									<Text style={styles.unmanaged}>—</Text>
								)}
							</View>
						);
					})}
				</View>
			))}
		</ScrollView>
	);

	// ── Mode 2: Nueva salida ──
	const productPane = (
		<ScrollView contentContainerStyle={styles.productsScroll}>
			{productsQuery.isLoading && (
				<View style={styles.center}>
					<ActivityIndicator size="large" color={color.accentDeep} />
				</View>
			)}
			{productsQuery.isError && loadError}
			{groups.map(([category, items]) => (
				<View key={category} style={styles.group}>
					<Text style={styles.groupHeading}>{category}</Text>
					<View style={styles.grid}>
						{items.map((p) => {
							const inSalida = salida[p.id] ?? 0;
							const soldOut = !p.in_stock || (p.manage_stock && p.stock_quantity === 0);
							return (
								<Pressable
									key={p.id}
									accessibilityRole="button"
									disabled={soldOut}
									onPress={() => addToSalida(p, 1)}
									style={({ pressed }) => [
										styles.tile,
										inSalida > 0 && styles.tileActive,
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
										) : inSalida > 0 ? (
											<View style={styles.tileBadge}>
												<Text style={styles.tileBadgeText}>{inSalida}</Text>
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

	const salidaPane = (
		<View style={[styles.ticket, twoPane ? styles.ticketSide : styles.ticketBottom]}>
			<Text style={styles.ticketHeading}>Salida</Text>
			{salidaLines.length === 0 ? (
				<Text style={styles.emptyText}>Toca un producto para agregarlo.</Text>
			) : (
				<ScrollView style={styles.ticketLines}>
					{salidaLines.map((l) => (
						<View key={l.product.id} style={styles.line}>
							<Text style={styles.lineName} numberOfLines={1}>
								{l.product.name}
							</Text>
							<View style={styles.stepper}>
								<Pressable
									accessibilityLabel={`Quitar ${l.product.name}`}
									style={styles.step}
									onPress={() => addToSalida(l.product, -1)}
								>
									<Text style={styles.stepText}>−</Text>
								</Pressable>
								<Text style={styles.qty}>{l.qty}</Text>
								<Pressable
									accessibilityLabel={`Agregar ${l.product.name}`}
									style={styles.step}
									onPress={() => addToSalida(l.product, 1)}
								>
									<Text style={styles.stepText}>+</Text>
								</Pressable>
							</View>
							<Text style={styles.lineTotal}>{money(l.product.price * l.qty)}</Text>
						</View>
					))}
				</ScrollView>
			)}

			<View style={styles.destRow}>
				{DESTINATIONS.map((d) => (
					<Segment
						key={d}
						label={DESTINATION_LABELS[d]}
						active={destination === d}
						onPress={() => setDestination(d)}
					/>
				))}
			</View>

			{employeesQuery.isError ? (
				<TextInput
					style={styles.noteInput}
					value={person}
					onChangeText={setPerson}
					placeholder="¿Quién lo lleva? (opcional)"
					placeholderTextColor={color.textSoft}
				/>
			) : (
				<View style={styles.personField}>
					<Text style={styles.fieldLabel}>¿Quién lo lleva? (opcional)</Text>
					<View style={styles.destRow}>
						{employeeNames.map((n) => (
							<Segment
								key={n}
								label={n}
								active={person === n}
								onPress={() => setPerson(person === n ? '' : n)}
							/>
						))}
						<Segment
							label="+ Agregar"
							active={addingPerson}
							onPress={() => setAddingPerson((v) => !v)}
						/>
					</View>
					{addingPerson && (
						<View style={styles.addRow}>
							<TextInput
								style={[styles.noteInput, { flex: 1 }]}
								value={newName}
								onChangeText={setNewName}
								placeholder="Nombre del empleado"
								placeholderTextColor={color.textSoft}
								autoFocus
								autoCapitalize="words"
								onSubmitEditing={() => {
									if (newName.trim()) addEmployee.mutate(newName.trim());
								}}
							/>
							<Pressable
								accessibilityRole="button"
								disabled={!newName.trim() || addEmployee.isPending}
								onPress={() => addEmployee.mutate(newName.trim())}
								style={[styles.save, (!newName.trim() || addEmployee.isPending) && styles.saveIdle]}
							>
								{addEmployee.isPending ? (
									<ActivityIndicator size="small" color={color.surface} />
								) : (
									<Text style={[styles.saveText, !newName.trim() && styles.saveTextIdle]}>Agregar</Text>
								)}
							</Pressable>
						</View>
					)}
				</View>
			)}
			<TextInput
				style={styles.noteInput}
				value={note}
				onChangeText={setNote}
				placeholder="Nota (opcional)"
				placeholderTextColor={color.textSoft}
			/>

			<Pressable
				accessibilityRole="button"
				disabled={salidaLines.length === 0 || !destination || withdrawal.isPending}
				onPress={confirmSalida}
				style={({ pressed }) => [
					styles.register,
					(salidaLines.length === 0 || !destination || withdrawal.isPending) && { opacity: 0.4 },
					pressed && { opacity: 0.85 },
				]}
			>
				{withdrawal.isPending ? (
					<ActivityIndicator color={color.surface} />
				) : (
					<Text style={styles.registerText}>
						{salidaPieces > 0
							? `Registrar salida · ${salidaPieces} pzas · ${money(salidaValue)}`
							: 'Registrar salida'}
					</Text>
				)}
			</Pressable>
		</View>
	);

	const salidaView = twoPane ? (
		<View style={styles.panes}>
			<View style={styles.productsPane}>{productPane}</View>
			{salidaPane}
		</View>
	) : (
		<View style={{ flex: 1 }}>
			<View style={{ flex: 1 }}>{productPane}</View>
			{salidaPane}
		</View>
	);

	// ── Mode 3: Historial ──
	const totals = history.data?.totals;
	const historialView = history.isLoading ? (
		<View style={styles.center}>
			<ActivityIndicator size="large" color={color.accentDeep} />
		</View>
	) : history.isError ? (
		<View style={styles.center}>
			<Text style={styles.emptyText}>No se pudo cargar el historial.</Text>
			<Pressable style={styles.retry} onPress={() => history.refetch()}>
				<Text style={styles.retryText}>Reintentar</Text>
			</Pressable>
		</View>
	) : (
		<ScrollView
			contentContainerStyle={styles.historyList}
			refreshControl={
				<RefreshControl
					refreshing={history.isRefetching}
					onRefresh={() => history.refetch()}
					tintColor={color.accentDeep}
				/>
			}
		>
			{totals && totals.pieces > 0 && (
				<View style={styles.totalsStrip}>
					{Object.entries(totals.by_destination).map(([dest, bucket]) => {
						const s = destinationStyle(dest);
						return (
							<View key={dest} style={[styles.totalsChip, { backgroundColor: s.bg }]}>
								<Text style={[styles.totalsChipText, { color: s.fg }]}>
									{DESTINATION_LABELS[dest] ?? dest} · {bucket.pieces} pz · {money(bucket.value)}
								</Text>
							</View>
						);
					})}
				</View>
			)}
			{(history.data?.withdrawals ?? []).length === 0 ? (
				<View style={styles.center}>
					<Text style={styles.emptyText}>Sin salidas registradas hoy.</Text>
				</View>
			) : (
				(history.data?.withdrawals ?? []).map((w) => {
					const s = destinationStyle(w.destination);
					return (
						<View key={w.key} style={styles.card}>
							<View style={styles.cardHeader}>
								<Text style={styles.cardTime}>{w.created_at.slice(11, 16)}</Text>
								<View style={[styles.pill, { backgroundColor: s.bg }]}>
									<Text style={[styles.pillText, { color: s.fg }]}>{w.destination_label}</Text>
								</View>
								<View style={{ flex: 1 }} />
								<Text style={styles.cardValue}>{money(w.total_value)}</Text>
							</View>
							{(w.person !== '' || w.note !== '') && (
								<Text style={styles.cardMeta} numberOfLines={2}>
									{[w.person, w.note].filter(Boolean).join(' · ')}
								</Text>
							)}
							{w.items.map((item) => (
								<Text key={`${w.key}-${item.product_id}`} style={styles.cardLine}>
									{item.quantity} × {item.name}
								</Text>
							))}
						</View>
					);
				})
			)}
		</ScrollView>
	);

	return (
		<View style={[styles.screen, { paddingTop: insets.top }]}>
			<View style={styles.header}>
				<Text style={styles.title}>Inventario</Text>
				<View style={styles.modeRow}>
					<Segment label="Existencias" active={mode === 'stock'} onPress={() => setMode('stock')} />
					<Segment label="Nueva salida" active={mode === 'salida'} onPress={() => setMode('salida')} />
					<Segment label="Historial" active={mode === 'historial'} onPress={() => setMode('historial')} />
				</View>
			</View>
			{mode === 'stock' ? stockPane : mode === 'salida' ? salidaView : historialView}
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
			<Text style={[styles.segmentText, active && styles.segmentTextActive]} numberOfLines={1}>
				{label}
			</Text>
		</Pressable>
	);
}

const styles = StyleSheet.create({
	screen: { flex: 1, backgroundColor: color.bg },
	header: { paddingHorizontal: space(5), paddingTop: space(4), paddingBottom: space(3), gap: space(3) },
	title: { color: color.text, fontSize: type.display, fontWeight: '700' },
	modeRow: { flexDirection: 'row', gap: space(2) },
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

	// Existencias.
	stockScroll: {
		padding: space(5),
		gap: space(5),
		paddingBottom: space(8),
		maxWidth: 680,
		width: '100%',
		alignSelf: 'center',
	},
	stockRow: {
		flexDirection: 'row',
		alignItems: 'center',
		gap: space(3),
		backgroundColor: color.surface,
		borderRadius: radius.card,
		borderWidth: 1,
		borderColor: color.hairline,
		paddingHorizontal: space(4),
		paddingVertical: space(3),
	},
	stockInfo: { flex: 1, gap: space(1) },
	stockName: { color: color.text, fontSize: type.small, fontWeight: '600' },
	stockMeta: { flexDirection: 'row', alignItems: 'center', gap: space(2) },
	stockPrice: { color: color.textSoft, fontSize: type.small },
	stockControls: { flexDirection: 'row', alignItems: 'center', gap: space(2) },
	qtyInput: {
		minWidth: 56,
		height: 36,
		borderWidth: 1,
		borderColor: color.hairline,
		borderRadius: radius.control,
		backgroundColor: color.bg,
		color: color.text,
		fontSize: type.body,
		fontWeight: '700',
		textAlign: 'center',
		paddingVertical: 0,
	},
	save: {
		backgroundColor: color.accentDeep,
		borderRadius: radius.control,
		paddingHorizontal: space(3.5),
		paddingVertical: space(2),
		minWidth: 76,
		alignItems: 'center',
	},
	saveIdle: { backgroundColor: color.bg, borderWidth: 1, borderColor: color.hairline },
	saveText: { color: color.surface, fontSize: type.small, fontWeight: '700' },
	saveTextIdle: { color: color.textSoft },
	unmanaged: { color: color.textSoft, fontSize: type.body, minWidth: 76, textAlign: 'center' },
	lowStock: { color: color.attention, fontSize: type.tiny, fontWeight: '600' },
	soldOut: { color: color.danger, fontSize: type.tiny, fontWeight: '700' },

	// Nueva salida (grid + panel, lifted from Mostrador).
	panes: { flex: 1, flexDirection: 'row' },
	productsPane: { flex: 1 },
	productsScroll: { padding: space(5), gap: space(5), paddingBottom: space(8) },
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
	ticket: {
		backgroundColor: color.surface,
		borderColor: color.hairline,
		padding: space(4),
		gap: space(3),
	},
	ticketSide: { width: 360, borderLeftWidth: 1 },
	ticketBottom: { borderTopWidth: 1, maxHeight: 420 },
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
	destRow: { flexDirection: 'row', flexWrap: 'wrap', gap: space(2) },
	personField: { gap: space(2) },
	fieldLabel: {
		color: color.textSoft,
		fontSize: type.tiny,
		fontWeight: '600',
		textTransform: 'uppercase',
		letterSpacing: 0.5,
	},
	addRow: { flexDirection: 'row', alignItems: 'center', gap: space(2) },
	noteInput: {
		borderWidth: 1,
		borderColor: color.hairline,
		borderRadius: radius.control,
		backgroundColor: color.bg,
		paddingHorizontal: space(3),
		paddingVertical: space(2.5),
		fontSize: type.small,
		color: color.text,
	},
	segment: {
		flexGrow: 1,
		borderRadius: radius.control,
		borderWidth: 1,
		borderColor: color.hairline,
		paddingVertical: space(2.5),
		paddingHorizontal: space(3),
		alignItems: 'center',
		backgroundColor: color.bg,
	},
	segmentActive: { backgroundColor: color.text, borderColor: color.text },
	segmentText: { color: color.text, fontSize: type.small, fontWeight: '600' },
	segmentTextActive: { color: color.surface },
	register: {
		backgroundColor: color.accentDeep,
		borderRadius: radius.control,
		paddingVertical: space(3.5),
		alignItems: 'center',
	},
	registerText: { color: color.surface, fontSize: type.body, fontWeight: '700', letterSpacing: 0.5 },

	// Historial.
	historyList: {
		padding: space(5),
		paddingBottom: space(10),
		gap: space(3),
		maxWidth: 680,
		width: '100%',
		alignSelf: 'center',
	},
	totalsStrip: { flexDirection: 'row', flexWrap: 'wrap', gap: space(2), marginBottom: space(2) },
	totalsChip: {
		borderRadius: radius.pill,
		paddingHorizontal: space(3),
		paddingVertical: space(1.5),
	},
	totalsChipText: { fontSize: type.small, fontWeight: '700' },
	card: {
		backgroundColor: color.surface,
		borderRadius: radius.card,
		borderWidth: 1,
		borderColor: color.hairline,
		padding: space(4),
		gap: space(2),
	},
	cardHeader: { flexDirection: 'row', alignItems: 'center', gap: space(2) },
	cardTime: { color: color.textSoft, fontSize: type.small, fontVariant: ['tabular-nums'] },
	pill: { borderRadius: radius.pill, paddingHorizontal: space(2.5), paddingVertical: space(1) },
	pillText: { fontSize: type.tiny, fontWeight: '700' },
	cardValue: { color: color.text, fontSize: type.body, fontWeight: '700', fontVariant: ['tabular-nums'] },
	cardMeta: { color: color.textSoft, fontSize: type.small },
	cardLine: { color: color.text, fontSize: type.small },
});
