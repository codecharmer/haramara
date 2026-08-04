import type { PosOrder, StaffTransition } from '@haramara/api-client';
import React from 'react';
import { ActivityIndicator, Alert, Pressable, StyleSheet, Text, View } from 'react-native';

import { color, money, radius, space, statusStyle, TRANSITION_LABELS, type } from '../lib/theme';

interface Props {
	order: PosOrder;
	onTransition: (order: PosOrder, status: StaffTransition) => void;
	/** The transition currently in flight for this order, if any. */
	busyStatus?: StaffTransition | null;
}

export function OrderCard({ order, onTransition, busyStatus }: Props) {
	const st = statusStyle(order.status, order.status_label);

	function press(target: StaffTransition) {
		if (target === 'completed') {
			Alert.alert(
				`Entregar pedido #${order.number}`,
				`${order.customer} · ${money(order.total)}`,
				[
					{ text: 'Cancelar', style: 'cancel' },
					{ text: 'Entregado', style: 'default', onPress: () => onTransition(order, target) },
				],
			);
			return;
		}
		onTransition(order, target);
	}

	return (
		<View style={styles.card}>
			<View style={styles.top}>
				<View style={styles.headline}>
					<Text style={styles.number}>#{order.number}</Text>
					<Text style={styles.customer} numberOfLines={1}>
						{order.customer}
					</Text>
				</View>
				<View style={[styles.pill, { backgroundColor: st.bg }]}>
					<Text style={[styles.pillText, { color: st.fg }]}>{st.label}</Text>
				</View>
			</View>

			<Text style={styles.items}>
				{order.items.map((i) => `${i.quantity}× ${i.name}`).join('  ·  ')}
			</Text>

			<View style={styles.bottom}>
				<Text style={styles.meta}>
					{money(order.total)} · {order.payment_method_title}
				</Text>
				<View style={styles.actions}>
					{order.allowed_transitions.map((target) => {
						const isBusy = busyStatus === target;
						const primary = target !== 'completed';
						return (
							<Pressable
								key={target}
								accessibilityRole="button"
								disabled={busyStatus != null}
								onPress={() => press(target)}
								style={({ pressed }) => [
									styles.action,
									primary ? styles.actionPrimary : styles.actionQuiet,
									(pressed || isBusy) && { opacity: 0.7 },
									busyStatus != null && !isBusy && { opacity: 0.4 },
								]}
							>
								{isBusy ? (
									<ActivityIndicator size="small" color={primary ? color.surface : color.text} />
								) : (
									<Text style={[styles.actionText, primary ? { color: color.surface } : { color: color.text }]}>
										{TRANSITION_LABELS[target] ?? target}
									</Text>
								)}
							</Pressable>
						);
					})}
				</View>
			</View>
		</View>
	);
}

const styles = StyleSheet.create({
	card: {
		backgroundColor: color.surface,
		borderRadius: radius.card,
		borderWidth: 1,
		borderColor: color.hairline,
		padding: space(4),
		gap: space(3),
	},
	top: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: space(3) },
	headline: { flexDirection: 'row', alignItems: 'baseline', gap: space(2), flexShrink: 1 },
	number: { color: color.text, fontSize: type.title, fontWeight: '700' },
	customer: { color: color.textSoft, fontSize: type.body, flexShrink: 1 },
	pill: { borderRadius: radius.pill, paddingHorizontal: space(3), paddingVertical: space(1.5) },
	pillText: { fontSize: type.small, fontWeight: '700' },
	items: { color: color.text, fontSize: type.body, lineHeight: 22 },
	bottom: {
		flexDirection: 'row',
		alignItems: 'center',
		justifyContent: 'space-between',
		gap: space(3),
		flexWrap: 'wrap',
	},
	meta: { color: color.textSoft, fontSize: type.small },
	actions: { flexDirection: 'row', gap: space(2) },
	action: {
		borderRadius: radius.control,
		paddingHorizontal: space(4),
		paddingVertical: space(2.5),
		minWidth: 104,
		alignItems: 'center',
	},
	actionPrimary: { backgroundColor: color.text },
	actionQuiet: { backgroundColor: color.bg, borderWidth: 1, borderColor: color.hairline },
	actionText: { fontSize: type.small, fontWeight: '700' },
});
