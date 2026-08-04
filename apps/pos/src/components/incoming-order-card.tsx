import type { PosOrder } from '@haramara/api-client';
import React from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';

import { timeAgo } from '../lib/queue';
import { color, money, radius, space, type } from '../lib/theme';
import { SlideToAccept } from './slide-to-accept';

interface Props {
	order: PosOrder;
	onPress: (order: PosOrder) => void;
	onAccept: (order: PosOrder) => void;
	/** Accept in flight for this order. */
	busy?: boolean;
}

/** Queue card: tap anywhere for the full detail, slide the bar to accept. */
export function IncomingOrderCard({ order, onPress, onAccept, busy = false }: Props) {
	return (
		<View style={styles.card}>
			<Pressable
				accessibilityRole="button"
				accessibilityLabel={`Ver pedido ${order.number}`}
				onPress={() => onPress(order)}
				style={({ pressed }) => [styles.body, pressed && { opacity: 0.7 }]}
			>
				<View style={styles.top}>
					<View style={styles.headline}>
						<Text style={styles.number}>#{order.number}</Text>
						<Text style={styles.customer} numberOfLines={1}>
							{order.customer}
						</Text>
					</View>
					<Text style={styles.age}>{timeAgo(order.created_at)}</Text>
				</View>

				{order.pickup.label !== '' && (
					<Text style={styles.pickup} numberOfLines={1}>
						Recoge: {order.pickup.label}
					</Text>
				)}

				<Text style={styles.items} numberOfLines={2}>
					{order.items.map((i) => `${i.quantity}× ${i.name}`).join('  ·  ')}
				</Text>

				<View style={styles.metaRow}>
					<Text style={styles.meta}>
						{money(order.total)} · {order.payment_method_title}
					</Text>
					{order.note !== '' && (
						<View style={styles.noteBadge}>
							<Text style={styles.noteBadgeText}>Nota</Text>
						</View>
					)}
				</View>
			</Pressable>

			<SlideToAccept busy={busy} onAccept={() => onAccept(order)} />
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
	body: { gap: space(3) },
	top: { flexDirection: 'row', alignItems: 'baseline', justifyContent: 'space-between', gap: space(3) },
	headline: { flexDirection: 'row', alignItems: 'baseline', gap: space(2), flexShrink: 1 },
	number: { color: color.text, fontSize: type.title, fontWeight: '700' },
	customer: { color: color.textSoft, fontSize: type.body, flexShrink: 1 },
	age: { color: color.attention, fontSize: type.small, fontWeight: '700' },
	pickup: { color: color.accentDeep, fontSize: type.small, fontWeight: '700' },
	items: { color: color.text, fontSize: type.body, lineHeight: 22 },
	metaRow: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: space(3) },
	meta: { color: color.textSoft, fontSize: type.small },
	noteBadge: {
		backgroundColor: color.attentionBg,
		borderRadius: radius.pill,
		paddingHorizontal: space(2.5),
		paddingVertical: space(1),
	},
	noteBadgeText: { color: color.attention, fontSize: type.tiny, fontWeight: '700' },
});
