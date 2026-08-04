import { router } from 'expo-router';
import React from 'react';
import { Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { useCart } from '../../lib/cart';
import { color, font, money, radius, space, type } from '../../lib/theme';

export default function OrdersScreen() {
	const insets = useSafeAreaInsets();
	const { orders } = useCart();

	return (
		<View style={[styles.screen, { paddingTop: insets.top }]}>
			<View style={styles.header}>
				<Text style={styles.title}>Mis pedidos</Text>
			</View>

			{orders.length === 0 ? (
				<View style={styles.empty}>
					<Text style={styles.emptyTitle}>Todavía no has pedido</Text>
					<Text style={styles.emptyText}>
						Cuando apartes tu primer pedido, aquí podrás seguir su estado.
					</Text>
					<Pressable accessibilityRole="button" style={styles.browse} onPress={() => router.navigate('/')}>
						<Text style={styles.browseText}>Ver la carta</Text>
					</Pressable>
				</View>
			) : (
				<ScrollView contentContainerStyle={styles.list}>
					{orders.map((order) => (
						<Pressable
							key={order.id}
							accessibilityRole="button"
							onPress={() =>
								router.push({
									pathname: '/pedido/[id]',
									params: { id: String(order.id), key: order.key },
								})
							}
							style={({ pressed }) => [styles.row, pressed && { opacity: 0.9 }]}
						>
							<View style={{ flex: 1, gap: space(1) }}>
								<Text style={styles.rowTitle}>Pedido #{order.number}</Text>
								<Text style={styles.rowMeta}>{order.pickupLabel}</Text>
							</View>
							<Text style={styles.rowTotal}>{money(order.total)}</Text>
							<Text style={styles.chevron}>›</Text>
						</Pressable>
					))}
				</ScrollView>
			)}
		</View>
	);
}

const styles = StyleSheet.create({
	screen: { flex: 1, backgroundColor: color.bg },
	header: { paddingHorizontal: space(5), paddingTop: space(4), paddingBottom: space(3) },
	title: { fontFamily: font.display, fontSize: type.hero, color: color.text },
	empty: { flex: 1, alignItems: 'center', justifyContent: 'center', padding: space(8), gap: space(3) },
	emptyGlyph: { fontSize: 44 },
	emptyTitle: { color: color.text, fontSize: type.title, fontWeight: '600' },
	emptyText: { color: color.textSoft, fontSize: type.body, textAlign: 'center', lineHeight: 22, maxWidth: 300 },
	browse: {
		marginTop: space(2),
		backgroundColor: color.accent,
		borderRadius: radius.control,
		paddingHorizontal: space(6),
		paddingVertical: space(3),
	},
	browseText: { color: color.bg, fontWeight: '700', fontSize: type.body },
	list: { paddingHorizontal: space(5), gap: space(3), paddingBottom: space(8) },
	row: {
		flexDirection: 'row',
		alignItems: 'center',
		gap: space(3),
		backgroundColor: color.surface,
		borderRadius: radius.card,
		borderWidth: 1,
		borderColor: color.hairline,
		padding: space(4),
	},
	rowTitle: { color: color.text, fontSize: type.body, fontWeight: '600' },
	rowMeta: { color: color.textSoft, fontSize: type.small },
	rowTotal: { color: color.text, fontSize: type.body, fontWeight: '700', fontVariant: ['tabular-nums'] },
	chevron: { color: color.textSoft, fontSize: type.title },
});
