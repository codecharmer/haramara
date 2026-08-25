import { useQuery } from '@tanstack/react-query';
import { router, useLocalSearchParams } from 'expo-router';
import React, { useEffect, useRef } from 'react';
import {
	ActivityIndicator,
	Linking,
	Pressable,
	ScrollView,
	StyleSheet,
	Text,
	View,
} from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { appApi } from '../../lib/api';
import { registerForOrderUpdates } from '../../lib/notifications';
import { color, font, money, radius, space, statusStyle, type } from '../../lib/theme';

const TIMELINE = ['processing', 'preparing', 'ready', 'completed'] as const;
const TIMELINE_LABELS: Record<(typeof TIMELINE)[number], string> = {
	processing: 'Recibido',
	preparing: 'En barra',
	ready: 'Listo',
	completed: 'Entregado',
};

const OPEN_STATUSES = new Set(['pending', 'processing', 'on-hold', 'queued', 'preparing', 'ready']);

export default function OrderScreen() {
	const { id, key, nuevo } = useLocalSearchParams<{ id: string; key: string; nuevo?: string }>();
	const insets = useSafeAreaInsets();

	const order = useQuery({
		queryKey: ['order', id],
		queryFn: () => appApi.order(Number(id), key ?? ''),
		refetchInterval: (query) =>
			query.state.data && OPEN_STATUSES.has(query.state.data.status) ? 60_000 : false,
	});

	const config = useQuery({ queryKey: ['config'], queryFn: () => appApi.config() });

	// Re-attach this device's push token once per visit while the order is
	// open: covers permission granted after checkout, token rotation, and the
	// race where a status changed before checkout's registration landed.
	const pushRegistered = useRef(false);
	useEffect(() => {
		const status = order.data?.status;
		if (pushRegistered.current || !status || !OPEN_STATUSES.has(status) || !key) {
			return;
		}
		pushRegistered.current = true;
		void registerForOrderUpdates(Number(id), key);
	}, [order.data?.status, id, key]);

	const data = order.data;
	const st = data ? statusStyle(data.status, data.status_label) : null;
	// "En fila" (accepted at the POS, not yet at the bar) sits on the same
	// timeline step as "Recibido".
	const timelineStatus = data?.status === 'queued' ? 'processing' : data?.status;
	const timelineIndex = data ? TIMELINE.indexOf(timelineStatus as (typeof TIMELINE)[number]) : -1;

	return (
		<View style={[styles.screen, { paddingTop: insets.top + space(2) }]}>
			<View style={styles.header}>
				<Pressable
					accessibilityRole="button"
					accessibilityLabel="Regresar"
					onPress={() => (router.canGoBack() ? router.back() : router.replace('/'))}
					style={styles.back}
				>
					<Text style={styles.backText}>←</Text>
				</Pressable>
				<Text style={styles.title}>Pedido #{id}</Text>
			</View>

			{order.isLoading ? (
				<View style={styles.center}>
					<ActivityIndicator size="large" color={color.accentDeep} />
				</View>
			) : order.isError || !data ? (
				<View style={styles.center}>
					<Text style={styles.emptyTitle}>No encontramos este pedido</Text>
					<Text style={styles.emptyText}>
						Verifica tu conexión o contáctanos si crees que es un error.
					</Text>
				</View>
			) : (
				<ScrollView contentContainerStyle={styles.scroll}>
					{nuevo === '1' && (
						<View style={styles.confirmation}>
							<Text style={styles.confirmationTitle}>Tu pedido está apartado.</Text>
							<Text style={styles.confirmationText}>
								Te avisaremos aquí y por WhatsApp cuando esté listo.
							</Text>
						</View>
					)}

					<View style={styles.card}>
						{st && (
							<View style={[styles.pill, { backgroundColor: st.bg }]}>
								<Text style={[styles.pillText, { color: st.fg }]}>{st.label}</Text>
							</View>
						)}

						{data.status !== 'cancelled' && timelineIndex >= 0 && (
							<View style={styles.timeline}>
								{TIMELINE.map((step, i) => (
									<View key={step} style={styles.timelineStep}>
										<View
											style={[
												styles.dot,
												i <= timelineIndex ? styles.dotDone : styles.dotPending,
											]}
										/>
										<Text
											style={[
												styles.timelineLabel,
												i <= timelineIndex && styles.timelineLabelDone,
											]}
										>
											{TIMELINE_LABELS[step]}
										</Text>
									</View>
								))}
							</View>
						)}

						<View style={styles.pickupBox}>
							<Text style={styles.pickupLabel}>Recolección</Text>
							<Text style={styles.pickupValue}>{data.pickup.label || 'Por confirmar'}</Text>
							{data.pickup.instructions !== '' && (
								<Text style={styles.pickupInstructions}>{data.pickup.instructions}</Text>
							)}
						</View>
					</View>

					<View style={styles.card}>
						<Text style={styles.sectionTitle}>Tu pedido</Text>
						{data.items.map((item, i) => (
							<View key={`${item.name}-${i}`} style={styles.itemRow}>
								<View style={styles.itemText}>
									<Text style={styles.itemName}>
										{item.quantity}× {item.name}
									</Text>
									{(item.modifiers?.length ?? 0) > 0 && (
										<Text style={styles.itemMods} numberOfLines={2}>
											{(item.modifiers ?? []).join(' · ')}
										</Text>
									)}
								</View>
								<Text style={styles.itemTotal}>{money(item.total)}</Text>
							</View>
						))}
						<View style={styles.totalRow}>
							<Text style={styles.totalLabel}>Total · {data.payment_method_title}</Text>
							<Text style={styles.totalValue}>{money(data.total)}</Text>
						</View>
					</View>

					{config.data && (
						<View style={styles.card}>
							<Text style={styles.sectionTitle}>La barra</Text>
							<Text style={styles.address}>{config.data.business.address}</Text>
							<Text style={styles.hours}>{config.data.business.hours_summary}</Text>
							<View style={styles.linksRow}>
								<LinkButton
									label="Cómo llegar"
									onPress={() => void Linking.openURL(config.data.business.maps_url)}
								/>
								<LinkButton
									label="WhatsApp"
									onPress={() => void Linking.openURL(config.data.business.whatsapp)}
								/>
							</View>
						</View>
					)}
				</ScrollView>
			)}
		</View>
	);
}

function LinkButton({ label, onPress }: { label: string; onPress: () => void }) {
	return (
		<Pressable
			accessibilityRole="button"
			onPress={onPress}
			style={({ pressed }) => [styles.link, pressed && { opacity: 0.8 }]}
		>
			<Text style={styles.linkText}>{label}</Text>
		</Pressable>
	);
}

const styles = StyleSheet.create({
	screen: { flex: 1, backgroundColor: color.bg },
	header: {
		flexDirection: 'row',
		alignItems: 'center',
		gap: space(3),
		paddingHorizontal: space(4),
		paddingBottom: space(3),
	},
	back: {
		width: 40,
		height: 40,
		borderRadius: 20,
		backgroundColor: color.surface,
		alignItems: 'center',
		justifyContent: 'center',
		borderWidth: 1,
		borderColor: color.hairline,
	},
	backText: { color: color.text, fontSize: 20 },
	title: { fontFamily: font.display, fontSize: type.display, color: color.text },
	center: { flex: 1, alignItems: 'center', justifyContent: 'center', padding: space(8), gap: space(2) },
	scroll: { padding: space(4), gap: space(4), paddingBottom: space(10) },
	confirmation: {
		backgroundColor: color.goodBg,
		borderRadius: radius.card,
		padding: space(4),
		gap: space(1),
	},
	confirmationTitle: { color: color.good, fontSize: type.title, fontWeight: '700' },
	confirmationText: { color: color.good, fontSize: type.small, lineHeight: 20 },
	card: {
		backgroundColor: color.surface,
		borderRadius: radius.card,
		borderWidth: 1,
		borderColor: color.hairline,
		padding: space(4),
		gap: space(3),
	},
	pill: { alignSelf: 'flex-start', borderRadius: radius.pill, paddingHorizontal: space(3), paddingVertical: space(1.5) },
	pillText: { fontSize: type.small, fontWeight: '700' },
	timeline: { flexDirection: 'row', justifyContent: 'space-between', marginTop: space(1) },
	timelineStep: { alignItems: 'center', gap: space(1.5), flex: 1 },
	dot: { width: 14, height: 14, borderRadius: 7 },
	dotDone: { backgroundColor: color.accentDeep },
	dotPending: { backgroundColor: color.hairline },
	timelineLabel: { color: color.textSoft, fontSize: type.tiny, textAlign: 'center' },
	timelineLabelDone: { color: color.text, fontWeight: '600' },
	pickupBox: {
		backgroundColor: color.bg,
		borderRadius: radius.control,
		padding: space(3.5),
		gap: space(1),
	},
	pickupLabel: { color: color.accentDeep, fontSize: type.tiny, fontWeight: '700', letterSpacing: 1 },
	pickupValue: { color: color.text, fontSize: type.body, fontWeight: '600' },
	pickupInstructions: { color: color.textSoft, fontSize: type.small, lineHeight: 18, marginTop: space(1) },
	sectionTitle: { color: color.text, fontSize: type.title, fontWeight: '600' },
	itemRow: { flexDirection: 'row', justifyContent: 'space-between', gap: space(3) },
	itemName: { color: color.text, fontSize: type.body, flexShrink: 1 },
	itemText: { flex: 1, gap: 2 },
	itemMods: { color: color.textSoft, fontSize: 12, lineHeight: 16 },
	itemTotal: { color: color.textSoft, fontSize: type.body, fontVariant: ['tabular-nums'] },
	totalRow: {
		flexDirection: 'row',
		justifyContent: 'space-between',
		alignItems: 'baseline',
		borderTopWidth: 1,
		borderTopColor: color.hairline,
		paddingTop: space(3),
		marginTop: space(1),
	},
	totalLabel: { color: color.textSoft, fontSize: type.small },
	totalValue: { color: color.text, fontSize: type.title, fontWeight: '700', fontVariant: ['tabular-nums'] },
	address: { color: color.text, fontSize: type.body, lineHeight: 22 },
	hours: { color: color.textSoft, fontSize: type.small },
	linksRow: { flexDirection: 'row', gap: space(2), marginTop: space(1) },
	link: {
		borderWidth: 1,
		borderColor: color.hairline,
		borderRadius: radius.control,
		paddingHorizontal: space(4),
		paddingVertical: space(2.5),
		backgroundColor: color.bg,
	},
	linkText: { color: color.accentDeep, fontSize: type.small, fontWeight: '700' },
	emptyTitle: { color: color.text, fontSize: type.title, fontWeight: '600' },
	emptyText: { color: color.textSoft, fontSize: type.body, textAlign: 'center', lineHeight: 22 },
});
