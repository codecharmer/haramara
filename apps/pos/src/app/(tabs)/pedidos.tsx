import type { PosOrder, StaffTransition } from '@haramara/api-client';
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import React, { useState } from 'react';
import {
	ActivityIndicator,
	Pressable,
	RefreshControl,
	ScrollView,
	StyleSheet,
	Switch,
	Text,
	View,
} from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { OrderCard } from '../../components/order-card';
import { isAuthError, useAuth, usePosApi } from '../../lib/auth';
import { color, radius, space, type } from '../../lib/theme';

import { notify } from '../../lib/dialog';

const POLL_MS = 30_000;

export default function BoardScreen() {
	const api = usePosApi();
	const { signOut } = useAuth();
	const queryClient = useQueryClient();
	const insets = useSafeAreaInsets();
	const [includeDone, setIncludeDone] = useState(false);
	// undefined = today in the bakery's timezone (the server decides).
	const [selectedDate, setSelectedDate] = useState<string | undefined>(undefined);
	const [busy, setBusy] = useState<{ orderId: number; status: StaffTransition } | null>(null);

	const board = useQuery({
		queryKey: ['board', selectedDate ?? 'today', includeDone],
		queryFn: () => api.board(selectedDate, includeDone),
		refetchInterval: POLL_MS,
		// Keep the previous day's board on screen while the next one loads, so
		// fast arrow taps never hit an empty state.
		placeholderData: keepPreviousData,
	});

	// Credenciales revocadas → de vuelta al inicio de sesión.
	if (board.error && isAuthError(board.error)) {
		void signOut();
	}

	const transition = useMutation({
		mutationFn: ({ order, status }: { order: PosOrder; status: StaffTransition }) =>
			api.transition(order.id, status),
		onMutate: ({ order, status }) => setBusy({ orderId: order.id, status }),
		onSettled: () => {
			setBusy(null);
			void queryClient.invalidateQueries({ queryKey: ['board'] });
		},
		onError: (e) => {
			notify('No se pudo actualizar', e instanceof Error ? e.message : 'Intenta de nuevo.');
		},
	});

	const data = board.data;
	const dateLabel = data
		? new Date(`${data.date}T12:00:00`).toLocaleDateString('es-MX', {
				weekday: 'long',
				day: 'numeric',
				month: 'long',
			})
		: '';

	// Shift from the SELECTED date (local state), not the fetched payload —
	// otherwise taps during a fetch get dropped. The server only anchors the
	// initial "today".
	function shiftDay(delta: number) {
		const base = selectedDate ?? data?.date;
		if (!base) return;
		const d = new Date(`${base}T12:00:00`);
		d.setDate(d.getDate() + delta);
		const ymd = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
		setSelectedDate(ymd);
	}

	return (
		<View style={[styles.screen, { paddingTop: insets.top }]}>
			<View style={styles.header}>
				<View style={{ flexShrink: 1 }}>
					<Text style={styles.title}>Pedidos</Text>
				</View>
				{data && (
					<View style={styles.chips}>
						<Chip label={`${data.counts.pending_prep} por preparar`} tone="attention" />
						<Chip label={`${data.counts.ready} listos`} tone="good" />
					</View>
				)}
			</View>

			<View style={styles.dateNav}>
				<Pressable
					accessibilityRole="button"
					accessibilityLabel="Día anterior"
					disabled={!data}
					onPress={() => shiftDay(-1)}
					style={({ pressed }) => [styles.dateArrow, pressed && { opacity: 0.7 }]}
				>
					<Text style={styles.dateArrowText}>←</Text>
				</Pressable>
				<View style={styles.dateCenter}>
					<Text style={styles.dateLabel}>{data ? capitalize(dateLabel) : '…'}</Text>
					{selectedDate !== undefined && (
						<Pressable accessibilityRole="button" onPress={() => setSelectedDate(undefined)}>
							<Text style={styles.todayLink}>Volver a hoy</Text>
						</Pressable>
					)}
				</View>
				<Pressable
					accessibilityRole="button"
					accessibilityLabel="Día siguiente"
					disabled={!data}
					onPress={() => shiftDay(1)}
					style={({ pressed }) => [styles.dateArrow, pressed && { opacity: 0.7 }]}
				>
					<Text style={styles.dateArrowText}>→</Text>
				</Pressable>
			</View>

			<View style={styles.toggleRow}>
				<Text style={styles.toggleLabel}>Mostrar entregados</Text>
				<Switch
					value={includeDone}
					onValueChange={setIncludeDone}
					trackColor={{ true: color.accent, false: color.hairline }}
					thumbColor={color.surface}
				/>
			</View>

			{board.isLoading ? (
				<View style={styles.center}>
					<ActivityIndicator size="large" color={color.accentDeep} />
				</View>
			) : board.isError ? (
				<View style={styles.center}>
					<Text style={styles.emptyTitle}>Sin conexión con el servidor</Text>
					<Text style={styles.emptyText}>
						{board.error instanceof Error ? board.error.message : 'Revisa la red del local.'}
					</Text>
					<Pressable style={styles.retry} onPress={() => board.refetch()}>
						<Text style={styles.retryText}>Reintentar</Text>
					</Pressable>
				</View>
			) : (
				<ScrollView
					contentContainerStyle={styles.list}
					refreshControl={
						<RefreshControl
							refreshing={board.isRefetching}
							onRefresh={() => board.refetch()}
							tintColor={color.accentDeep}
						/>
					}
				>
					{data && data.slots.length === 0 && (
						<View style={styles.empty}>
							<Text style={styles.emptyTitle}>Sin recolecciones para este día</Text>
							<Text style={styles.emptyText}>
								Los pedidos en línea aparecen en el día de su recolección — usa las flechas para
								revisar los próximos días.
							</Text>
						</View>
					)}

					{data?.slots.map((slot) => (
						<View key={slot.slot || 'sin-horario'} style={styles.slotSection}>
							<Text style={styles.slotHeading}>{slot.slot !== '' ? slot.slot : 'Sin horario'}</Text>
							<View style={styles.slotOrders}>
								{slot.orders.map((order) => (
									<OrderCard
										key={order.id}
										order={order}
										busyStatus={busy?.orderId === order.id ? busy.status : null}
										onTransition={(o, status) => transition.mutate({ order: o, status })}
									/>
								))}
							</View>
						</View>
					))}

					{data && data.walk_ins.length > 0 && (
						<View style={styles.slotSection}>
							<Text style={styles.slotHeading}>Ventas de mostrador ({data.walk_ins.length})</Text>
							<View style={styles.slotOrders}>
								{data.walk_ins.map((order) => (
									<View key={order.id} style={styles.walkInRow}>
										<Text style={styles.walkInText} numberOfLines={1}>
											#{order.number} · {order.items.map((i) => `${i.quantity}× ${i.name}`).join(', ')}
										</Text>
										<Text style={styles.walkInMeta}>{order.payment_method_title}</Text>
									</View>
								))}
							</View>
						</View>
					)}
				</ScrollView>
			)}
		</View>
	);
}

function Chip({ label, tone }: { label: string; tone: 'attention' | 'good' }) {
	const fg = tone === 'attention' ? color.attention : color.good;
	const bg = tone === 'attention' ? color.attentionBg : color.goodBg;
	return (
		<View style={[styles.chip, { backgroundColor: bg }]}>
			<Text style={[styles.chipText, { color: fg }]}>{label}</Text>
		</View>
	);
}

function capitalize(s: string): string {
	return s.charAt(0).toUpperCase() + s.slice(1);
}

const styles = StyleSheet.create({
	screen: { flex: 1, backgroundColor: color.bg },
	header: {
		flexDirection: 'row',
		alignItems: 'flex-end',
		justifyContent: 'space-between',
		paddingHorizontal: space(5),
		paddingTop: space(4),
		gap: space(3),
		flexWrap: 'wrap',
	},
	title: { color: color.text, fontSize: type.display, fontWeight: '700' },
	dateNav: {
		flexDirection: 'row',
		alignItems: 'center',
		justifyContent: 'space-between',
		gap: space(3),
		paddingHorizontal: space(5),
		paddingTop: space(3),
	},
	dateArrow: {
		width: 44,
		height: 44,
		borderRadius: radius.control,
		borderWidth: 1,
		borderColor: color.hairline,
		backgroundColor: color.surface,
		alignItems: 'center',
		justifyContent: 'center',
	},
	dateArrowText: { color: color.text, fontSize: type.title },
	dateCenter: { alignItems: 'center', gap: space(1), flexShrink: 1 },
	dateLabel: { color: color.text, fontSize: type.body, fontWeight: '600', textAlign: 'center' },
	todayLink: { color: color.accentDeep, fontSize: type.small, fontWeight: '700' },
	chips: { flexDirection: 'row', gap: space(2) },
	chip: { borderRadius: radius.pill, paddingHorizontal: space(3), paddingVertical: space(1.5) },
	chipText: { fontSize: type.small, fontWeight: '700' },
	toggleRow: {
		flexDirection: 'row',
		alignItems: 'center',
		justifyContent: 'flex-end',
		gap: space(2),
		paddingHorizontal: space(5),
		paddingVertical: space(2),
	},
	toggleLabel: { color: color.textSoft, fontSize: type.small },
	center: { flex: 1, alignItems: 'center', justifyContent: 'center', padding: space(8), gap: space(2) },
	list: {
		paddingHorizontal: space(5),
		paddingBottom: space(8),
		gap: space(6),
		maxWidth: 920,
		width: '100%',
		alignSelf: 'center',
	},
	slotSection: { gap: space(3) },
	slotHeading: {
		color: color.accentDeep,
		fontSize: type.body,
		fontWeight: '700',
		borderBottomWidth: 1,
		borderBottomColor: color.hairline,
		paddingBottom: space(2),
	},
	slotOrders: { gap: space(3) },
	empty: { alignItems: 'center', paddingVertical: space(12), gap: space(2) },
	emptyTitle: { color: color.text, fontSize: type.title, fontWeight: '600' },
	emptyText: { color: color.textSoft, fontSize: type.body, textAlign: 'center', lineHeight: 22, maxWidth: 420 },
	retry: {
		marginTop: space(3),
		backgroundColor: color.text,
		borderRadius: radius.control,
		paddingHorizontal: space(5),
		paddingVertical: space(2.5),
	},
	retryText: { color: color.surface, fontWeight: '700', fontSize: type.small },
	walkInRow: {
		flexDirection: 'row',
		alignItems: 'center',
		justifyContent: 'space-between',
		gap: space(3),
		backgroundColor: color.surface,
		borderRadius: radius.control,
		borderWidth: 1,
		borderColor: color.hairline,
		paddingHorizontal: space(4),
		paddingVertical: space(3),
	},
	walkInText: { color: color.text, fontSize: type.small, flexShrink: 1 },
	walkInMeta: { color: color.textSoft, fontSize: type.small },
});
