import { useQuery } from '@tanstack/react-query';
import React from 'react';
import {
	ActivityIndicator,
	Alert,
	Pressable,
	RefreshControl,
	ScrollView,
	StyleSheet,
	Text,
	View,
} from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { useAuth, usePosApi } from '../../lib/auth';
import { color, money, radius, space, type } from '../../lib/theme';

const CHANNEL_LABELS: Record<string, string> = {
	pickup_online: 'Pedidos en línea',
	walkin_cash: 'Mostrador · efectivo',
	walkin_card_external: 'Mostrador · tarjeta',
};

const PAYMENT_LABELS: Record<string, string> = {
	cod: 'Paga al recoger / mostrador',
	stripe: 'Tarjeta (Stripe)',
	other: 'Otros',
};

export default function CorteScreen() {
	const api = usePosApi();
	const { session, signOut } = useAuth();
	const insets = useSafeAreaInsets();

	const summary = useQuery({
		queryKey: ['summary'],
		queryFn: () => api.summary(),
		refetchInterval: 60_000,
	});

	const data = summary.data;

	function confirmSignOut() {
		Alert.alert('Cerrar sesión', 'La tableta pedirá la contraseña de aplicación de nuevo.', [
			{ text: 'Cancelar', style: 'cancel' },
			{ text: 'Cerrar sesión', style: 'destructive', onPress: () => void signOut() },
		]);
	}

	return (
		<View style={[styles.screen, { paddingTop: insets.top }]}>
			<View style={styles.header}>
				<Text style={styles.title}>Corte del día</Text>
				{data && <Text style={styles.subtitle}>{data.date}</Text>}
			</View>

			{summary.isLoading ? (
				<View style={styles.center}>
					<ActivityIndicator size="large" color={color.accentDeep} />
				</View>
			) : summary.isError ? (
				<View style={styles.center}>
					<Text style={styles.emptyText}>No se pudo cargar el corte.</Text>
					<Pressable style={styles.retry} onPress={() => summary.refetch()}>
						<Text style={styles.retryText}>Reintentar</Text>
					</Pressable>
				</View>
			) : data ? (
				<ScrollView
					contentContainerStyle={styles.list}
					refreshControl={
						<RefreshControl
							refreshing={summary.isRefetching}
							onRefresh={() => summary.refetch()}
							tintColor={color.accentDeep}
						/>
					}
				>
					<View style={styles.receipt}>
						<View style={styles.totalRow}>
							<Text style={styles.totalLabel}>Ingresos del día</Text>
							<Text style={styles.totalValue}>{money(data.revenue)}</Text>
						</View>
						<Text style={styles.totalMeta}>
							{data.orders_total} {data.orders_total === 1 ? 'pedido' : 'pedidos'}
						</Text>

						<Text style={styles.sectionHeading}>Por canal</Text>
						{Object.entries(data.by_channel).map(([key, bucket]) => (
							<Row
								key={key}
								label={`${CHANNEL_LABELS[key] ?? key} (${bucket.count})`}
								value={money(bucket.revenue)}
							/>
						))}
						{Object.keys(data.by_channel).length === 0 && (
							<Text style={styles.emptyText}>Sin ventas registradas hoy.</Text>
						)}

						<Text style={styles.sectionHeading}>Por forma de pago</Text>
						{Object.entries(data.by_payment_method).map(([key, bucket]) => (
							<Row
								key={key}
								label={`${PAYMENT_LABELS[key] ?? key} (${bucket.count})`}
								value={money(bucket.revenue)}
							/>
						))}

						{data.top_items.length > 0 && (
							<>
								<Text style={styles.sectionHeading}>Más vendidos</Text>
								{data.top_items.map((item) => (
									<Row key={item.name} label={item.name} value={`${item.quantity} pz`} />
								))}
							</>
						)}
					</View>

					<View style={styles.session}>
						<Text style={styles.sessionText}>
							Conectado a {session ? session.baseUrl : ''} como {session ? session.username : ''}
						</Text>
						<Pressable accessibilityRole="button" onPress={confirmSignOut} style={styles.signOut}>
							<Text style={styles.signOutText}>Cerrar sesión</Text>
						</Pressable>
					</View>
				</ScrollView>
			) : null}
		</View>
	);
}

function Row({ label, value }: { label: string; value: string }) {
	return (
		<View style={styles.row}>
			<Text style={styles.rowLabel} numberOfLines={1}>
				{label}
			</Text>
			<View style={styles.rowRule} />
			<Text style={styles.rowValue}>{value}</Text>
		</View>
	);
}

const styles = StyleSheet.create({
	screen: { flex: 1, backgroundColor: color.bg },
	header: { paddingHorizontal: space(5), paddingTop: space(4), paddingBottom: space(2) },
	title: { color: color.text, fontSize: type.display, fontWeight: '700' },
	subtitle: { color: color.textSoft, fontSize: type.body, marginTop: space(1) },
	center: { flex: 1, alignItems: 'center', justifyContent: 'center', padding: space(8), gap: space(3) },
	list: { padding: space(5), paddingBottom: space(10), gap: space(5), maxWidth: 680, width: '100%', alignSelf: 'center' },
	receipt: {
		backgroundColor: color.surface,
		borderRadius: radius.card,
		borderWidth: 1,
		borderColor: color.hairline,
		padding: space(6),
		gap: space(2),
	},
	totalRow: { flexDirection: 'row', alignItems: 'baseline', justifyContent: 'space-between', gap: space(3) },
	totalLabel: { color: color.textSoft, fontSize: type.body },
	totalValue: { color: color.text, fontSize: 34, fontWeight: '700', fontVariant: ['tabular-nums'] },
	totalMeta: { color: color.textSoft, fontSize: type.small },
	sectionHeading: {
		color: color.accentDeep,
		fontSize: type.small,
		fontWeight: '700',
		marginTop: space(5),
		marginBottom: space(1),
	},
	row: { flexDirection: 'row', alignItems: 'baseline', gap: space(2), paddingVertical: space(1.5) },
	rowLabel: { color: color.text, fontSize: type.body, flexShrink: 1 },
	rowRule: { flex: 1, borderBottomWidth: 1, borderBottomColor: color.hairline, borderStyle: 'dotted' },
	rowValue: { color: color.text, fontSize: type.body, fontWeight: '600', fontVariant: ['tabular-nums'] },
	emptyText: { color: color.textSoft, fontSize: type.body, lineHeight: 22 },
	retry: {
		backgroundColor: color.text,
		borderRadius: radius.control,
		paddingHorizontal: space(5),
		paddingVertical: space(2.5),
	},
	retryText: { color: color.surface, fontWeight: '700', fontSize: type.small },
	session: {
		flexDirection: 'row',
		alignItems: 'center',
		justifyContent: 'space-between',
		gap: space(3),
		flexWrap: 'wrap',
	},
	sessionText: { color: color.textSoft, fontSize: type.small, flexShrink: 1 },
	signOut: {
		borderWidth: 1,
		borderColor: color.hairline,
		borderRadius: radius.control,
		paddingHorizontal: space(4),
		paddingVertical: space(2),
		backgroundColor: color.surface,
	},
	signOutText: { color: color.danger, fontSize: type.small, fontWeight: '600' },
});
