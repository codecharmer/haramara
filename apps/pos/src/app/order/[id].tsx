import { newIdempotencyKey, type AdjustmentReason } from '@haramara/api-client';
import { useQueryClient } from '@tanstack/react-query';
import { useLocalSearchParams, useRouter } from 'expo-router';
import React, { useState } from 'react';
import { Linking, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { SlideToAccept } from '../../components/slide-to-accept';
import { needsAuthorization, ReasonSheet, SupervisorSheet } from '../../lib/adjust';
import { usePosApi } from '../../lib/auth';
import { notify } from '../../lib/dialog';
import { useOperator } from '../../lib/operator';
import { timeAgo, useAcceptOrder, useQueueQuery } from '../../lib/queue';
import { color, money, radius, space, statusStyle, type } from '../../lib/theme';

/**
 * Full detail of an incoming order, resolved from the queue cache — once the
 * order is accepted (here or on another device) it leaves the queue and this
 * screen offers only the way back.
 */
export default function IncomingOrderDetail() {
	const { id } = useLocalSearchParams<{ id: string }>();
	const router = useRouter();
	const insets = useSafeAreaInsets();

	const queue = useQueueQuery();
	const accept = useAcceptOrder(() => router.back());
	const api = usePosApi();
	const queryClient = useQueryClient();
	const { roster } = useOperator();

	const [voidSheet, setVoidSheet] = useState(false);
	const [voidBusy, setVoidBusy] = useState(false);
	/** Held across the supervisor retry so the same void settles once. */
	const [voidKey] = useState(() => newIdempotencyKey());
	const [pendingReason, setPendingReason] = useState<{ reason: AdjustmentReason; note: string } | null>(null);
	const [authSheet, setAuthSheet] = useState(false);

	const order = queue.data?.orders.find((o) => o.id === Number(id));

	async function doVoid(reason: AdjustmentReason, reasonNote: string, authorization?: string) {
		if (!order) return;
		setVoidBusy(true);
		try {
			await api.voidOrder(order.id, reason, { note: reasonNote, authorization, idempotencyKey: voidKey });
			notify('Pedido cancelado', `#${order.number} quedó en el registro.`);
			setVoidSheet(false);
			setAuthSheet(false);
			void queryClient.invalidateQueries({ queryKey: ['queue'] });
			void queryClient.invalidateQueries({ queryKey: ['board'] });
			void queryClient.invalidateQueries({ queryKey: ['summary'] });
			router.back();
		} catch (e) {
			if (needsAuthorization(e)) {
				setPendingReason({ reason, note: reasonNote });
				setVoidSheet(false);
				setAuthSheet(true);
			} else {
				notify('No se pudo cancelar', e instanceof Error ? e.message : 'Intenta de nuevo.');
			}
		} finally {
			setVoidBusy(false);
		}
	}

	if (!order) {
		return (
			<View style={[styles.screen, styles.gone, { paddingTop: insets.top + space(6) }]}>
				<Text style={styles.goneTitle}>Este pedido ya no está en entrantes</Text>
				<Text style={styles.goneText}>
					Probablemente ya fue aceptado. Búscalo en la pestaña Pedidos, en su día de recolección.
				</Text>
				<Pressable style={styles.back} onPress={() => router.back()}>
					<Text style={styles.backText}>Volver</Text>
				</Pressable>
			</View>
		);
	}

	const st = statusStyle(order.status, order.status_label);

	return (
		<View style={styles.screen}>
			<ScrollView contentContainerStyle={[styles.content, { paddingTop: space(6) }]}>
				<View style={styles.top}>
					<View style={styles.headline}>
						<Text style={styles.number}>#{order.number}</Text>
						<View style={[styles.pill, { backgroundColor: st.bg }]}>
							<Text style={[styles.pillText, { color: st.fg }]}>{st.label}</Text>
						</View>
					</View>
					<Text style={styles.age}>{timeAgo(order.created_at)}</Text>
				</View>

				<View style={styles.section}>
					<Text style={styles.sectionHeading}>Cliente</Text>
					<Text style={styles.customer}>{order.customer}</Text>
					{order.phone !== '' && (
						<Pressable
							accessibilityRole="button"
							accessibilityLabel={`Llamar a ${order.customer}`}
							onPress={() => Linking.openURL(`tel:${order.phone}`)}
						>
							<Text style={styles.phone}>{order.phone}</Text>
						</Pressable>
					)}
				</View>

				<View style={styles.section}>
					<Text style={styles.sectionHeading}>Recolección</Text>
					<Text style={styles.bodyText}>
						{order.pickup.label !== ''
							? order.pickup.label
							: `${order.pickup.date}${order.pickup.slot !== '' ? ` · ${order.pickup.slot}` : ''}`}
					</Text>
				</View>

				<View style={styles.section}>
					<Text style={styles.sectionHeading}>Artículos</Text>
					{order.items.map((item, i) => (
						<View key={`${item.name}-${i}`} style={styles.itemRow}>
							<Text style={styles.itemName}>
								{item.quantity}× {item.name}
							</Text>
							<Text style={styles.itemTotal}>{money(item.total)}</Text>
						</View>
					))}
					<View style={[styles.itemRow, styles.totalRow]}>
						<Text style={styles.totalLabel}>Total · {order.payment_method_title}</Text>
						<Text style={styles.totalValue}>{money(order.total)}</Text>
					</View>
				</View>

				{order.note !== '' && (
					<View style={[styles.section, styles.noteBox]}>
						<Text style={styles.sectionHeading}>Nota del cliente</Text>
						<Text style={styles.bodyText}>{order.note}</Text>
					</View>
				)}
			</ScrollView>

			<View style={[styles.footer, { paddingBottom: Math.max(insets.bottom, space(4)) }]}>
				<SlideToAccept busy={accept.isPending} onAccept={() => accept.mutate(order)} />
				<Pressable accessibilityRole="button" onPress={() => setVoidSheet(true)} style={styles.voidLink}>
					<Text style={styles.voidLinkText}>Cancelar pedido…</Text>
				</Pressable>
			</View>

			{voidSheet && (
				<ReasonSheet
					title={`Cancelar el pedido #${order.number}`}
					flow="void"
					busy={voidBusy}
					onConfirm={(reason, reasonNote) => void doVoid(reason, reasonNote)}
					onCancel={() => setVoidSheet(false)}
				/>
			)}
			{authSheet && pendingReason && (
				<SupervisorSheet
					actionLabel={`cancelar el pedido #${order.number}`}
					action="void"
					supervisors={roster.filter((o) => o.role === 'supervisor')}
					authorize={(key, pin, action) => api.operatorAuthorize(key, pin, action)}
					onAuthorized={(auth) => void doVoid(pendingReason.reason, pendingReason.note, auth)}
					onCancel={() => setAuthSheet(false)}
				/>
			)}
		</View>
	);
}

const styles = StyleSheet.create({
	screen: { flex: 1, backgroundColor: color.bg },
	content: {
		paddingHorizontal: space(5),
		paddingBottom: space(6),
		gap: space(5),
		maxWidth: 920,
		width: '100%',
		alignSelf: 'center',
	},
	top: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: space(3) },
	headline: { flexDirection: 'row', alignItems: 'center', gap: space(3), flexShrink: 1 },
	number: { color: color.text, fontSize: type.display, fontWeight: '700' },
	pill: { borderRadius: radius.pill, paddingHorizontal: space(3), paddingVertical: space(1.5) },
	pillText: { fontSize: type.small, fontWeight: '700' },
	age: { color: color.attention, fontSize: type.small, fontWeight: '700' },
	section: {
		backgroundColor: color.surface,
		borderRadius: radius.card,
		borderWidth: 1,
		borderColor: color.hairline,
		padding: space(4),
		gap: space(2),
	},
	sectionHeading: { color: color.accentDeep, fontSize: type.small, fontWeight: '700' },
	voidLink: { alignItems: 'center', paddingVertical: space(2) },
	voidLinkText: { color: color.danger, fontSize: type.small, fontWeight: '600' },
	customer: { color: color.text, fontSize: type.title, fontWeight: '600' },
	phone: { color: color.accentDeep, fontSize: type.body, fontWeight: '700' },
	bodyText: { color: color.text, fontSize: type.body, lineHeight: 22 },
	itemRow: {
		flexDirection: 'row',
		alignItems: 'center',
		justifyContent: 'space-between',
		gap: space(3),
		paddingVertical: space(1),
	},
	itemName: { color: color.text, fontSize: type.body, flexShrink: 1 },
	itemTotal: { color: color.textSoft, fontSize: type.body },
	totalRow: {
		borderTopWidth: 1,
		borderTopColor: color.hairline,
		marginTop: space(2),
		paddingTop: space(3),
	},
	totalLabel: { color: color.textSoft, fontSize: type.body },
	totalValue: { color: color.text, fontSize: type.title, fontWeight: '700' },
	noteBox: { backgroundColor: color.attentionBg, borderColor: color.attentionBg },
	footer: {
		paddingHorizontal: space(5),
		paddingTop: space(3),
		maxWidth: 920,
		width: '100%',
		alignSelf: 'center',
	},
	gone: { alignItems: 'center', justifyContent: 'center', padding: space(8), gap: space(2) },
	goneTitle: { color: color.text, fontSize: type.title, fontWeight: '600', textAlign: 'center' },
	goneText: { color: color.textSoft, fontSize: type.body, textAlign: 'center', lineHeight: 22, maxWidth: 420 },
	back: {
		marginTop: space(3),
		backgroundColor: color.text,
		borderRadius: radius.control,
		paddingHorizontal: space(5),
		paddingVertical: space(2.5),
	},
	backText: { color: color.surface, fontWeight: '700', fontSize: type.small },
});
