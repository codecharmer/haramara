import type { PosOrder } from '@haramara/api-client';
import { useRouter } from 'expo-router';
import React, { useMemo } from 'react';
import {
	ActivityIndicator,
	Pressable,
	RefreshControl,
	ScrollView,
	StyleSheet,
	Text,
	View,
} from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { IncomingOrderCard } from '../../components/incoming-order-card';
import { isAuthError, useAuth } from '../../lib/auth';
import { useAcceptOrder, useQueueQuery } from '../../lib/queue';
import { color, radius, space, type } from '../../lib/theme';

export default function IncomingScreen() {
	const { signOut } = useAuth();
	const router = useRouter();
	const insets = useSafeAreaInsets();

	const queue = useQueueQuery();
	const accept = useAcceptOrder();

	// Credenciales revocadas → de vuelta al inicio de sesión.
	if (queue.error && isAuthError(queue.error)) {
		void signOut();
	}

	const orders = queue.data?.orders ?? [];

	// Group by entrega (pickup) day — soonest day first, oldest arrival first
	// within a day. Orders missing a pickup date (shouldn't happen for online
	// orders) sink to the bottom.
	const days = useMemo(() => {
		const byDate = new Map<string, PosOrder[]>();
		for (const order of orders) {
			const date = order.pickup.date;
			const group = byDate.get(date);
			if (group) {
				group.push(order);
			} else {
				byDate.set(date, [order]);
			}
		}
		return [...byDate.entries()].sort(([a], [b]) => {
			if (a === '') return 1;
			if (b === '') return -1;
			return a.localeCompare(b);
		});
	}, [orders]);

	function openDetail(order: PosOrder) {
		router.push({ pathname: '/order/[id]', params: { id: String(order.id) } });
	}

	return (
		<View style={[styles.screen, { paddingTop: insets.top }]}>
			<View style={styles.header}>
				<Text style={styles.title}>Entrantes</Text>
				{queue.data && queue.data.count > 0 && (
					<View style={styles.countChip}>
						<Text style={styles.countChipText}>
							{queue.data.count === 1 ? '1 pedido nuevo' : `${queue.data.count} pedidos nuevos`}
						</Text>
					</View>
				)}
			</View>

			{queue.isLoading ? (
				<View style={styles.center}>
					<ActivityIndicator size="large" color={color.accentDeep} />
				</View>
			) : queue.isError ? (
				<View style={styles.center}>
					<Text style={styles.emptyTitle}>Sin conexión con el servidor</Text>
					<Text style={styles.emptyText}>
						{queue.error instanceof Error ? queue.error.message : 'Revisa la red del local.'}
					</Text>
					<Pressable style={styles.retry} onPress={() => queue.refetch()}>
						<Text style={styles.retryText}>Reintentar</Text>
					</Pressable>
				</View>
			) : (
				<ScrollView
					contentContainerStyle={styles.list}
					refreshControl={
						<RefreshControl
							refreshing={queue.isRefetching}
							onRefresh={() => queue.refetch()}
							tintColor={color.accentDeep}
						/>
					}
				>
					{orders.length === 0 && (
						<View style={styles.empty}>
							<Text style={styles.emptyTitle}>Sin pedidos entrantes</Text>
							<Text style={styles.emptyText}>
								Los pedidos nuevos aparecen aquí en cuanto se pagan. Desliza la barra de un pedido
								para aceptarlo y pasarlo a la fila.
							</Text>
						</View>
					)}

					{days.map(([date, dayOrders]) => (
						<View key={date || 'sin-fecha'} style={styles.daySection}>
							<Text style={styles.dayHeading}>
								{date !== '' ? `Entrega: ${dayLabel(date)}` : 'Sin fecha de entrega'}
							</Text>
							<View style={styles.dayOrders}>
								{dayOrders.map((order) => (
									<IncomingOrderCard
										key={order.id}
										order={order}
										busy={accept.isPending && accept.variables?.id === order.id}
										onPress={openDetail}
										onAccept={(o) => accept.mutate(o)}
									/>
								))}
							</View>
						</View>
					))}
				</ScrollView>
			)}
		</View>
	);
}

/** "2026-08-05" → "Miércoles 5 de agosto" (noon anchor dodges DST/UTC edges). */
function dayLabel(date: string): string {
	const label = new Date(`${date}T12:00:00`).toLocaleDateString('es-MX', {
		weekday: 'long',
		day: 'numeric',
		month: 'long',
	});
	return label.charAt(0).toUpperCase() + label.slice(1);
}

const styles = StyleSheet.create({
	screen: { flex: 1, backgroundColor: color.bg },
	header: {
		flexDirection: 'row',
		alignItems: 'flex-end',
		justifyContent: 'space-between',
		paddingHorizontal: space(5),
		paddingTop: space(4),
		paddingBottom: space(3),
		gap: space(3),
		flexWrap: 'wrap',
	},
	title: { color: color.text, fontSize: type.display, fontWeight: '700' },
	countChip: {
		backgroundColor: color.attentionBg,
		borderRadius: radius.pill,
		paddingHorizontal: space(3),
		paddingVertical: space(1.5),
	},
	countChipText: { color: color.attention, fontSize: type.small, fontWeight: '700' },
	center: { flex: 1, alignItems: 'center', justifyContent: 'center', padding: space(8), gap: space(2) },
	list: {
		paddingHorizontal: space(5),
		paddingTop: space(2),
		paddingBottom: space(8),
		gap: space(6),
		maxWidth: 920,
		width: '100%',
		alignSelf: 'center',
	},
	daySection: { gap: space(3) },
	dayHeading: {
		color: color.accentDeep,
		fontSize: type.body,
		fontWeight: '700',
		borderBottomWidth: 1,
		borderBottomColor: color.hairline,
		paddingBottom: space(2),
	},
	dayOrders: { gap: space(3) },
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
});
