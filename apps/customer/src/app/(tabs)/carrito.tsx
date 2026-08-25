import { router } from 'expo-router';
import React from 'react';
import { Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { Image } from 'expo-image';

import { useCart } from '../../lib/cart';
import { color, font, money, radius, space, type } from '../../lib/theme';

export default function CartScreen() {
	const insets = useSafeAreaInsets();
	const cart = useCart();

	return (
		<View style={[styles.screen, { paddingTop: insets.top }]}>
			<View style={styles.header}>
				<Text style={styles.title}>Tu canasta</Text>
			</View>

			{cart.lines.length === 0 ? (
				<View style={styles.empty}>
					<Text style={styles.emptyTitle}>Aún no hay nada aquí</Text>
					<Text style={styles.emptyText}>
						Explora la carta y aparta lo tuyo — lo preparamos para cuando pases por él.
					</Text>
					<Pressable
						accessibilityRole="button"
						style={styles.browse}
						onPress={() => router.navigate('/')}
					>
						<Text style={styles.browseText}>Ver la carta</Text>
					</Pressable>
				</View>
			) : (
				<>
					<ScrollView contentContainerStyle={styles.list}>
						{cart.lines.map((line) => (
							<View key={line.key} style={styles.row}>
								{line.image !== '' ? (
									<Image source={{ uri: line.image }} style={styles.thumb} contentFit="cover" />
								) : (
									<View style={[styles.thumb, styles.thumbFallback]}>
										<Text style={styles.thumbGlyph}>P</Text>
									</View>
								)}
								<View style={styles.rowBody}>
									<Text style={styles.rowName} numberOfLines={1}>
										{line.name}
									</Text>
									{line.modifierLabels.length > 0 && (
										<Text style={styles.rowMods} numberOfLines={2}>
											{line.modifierLabels.join(' · ')}
										</Text>
									)}
									<Text style={styles.rowPrice}>{money(line.price + line.priceDelta)}</Text>
								</View>
								<View style={styles.stepper}>
									<Pressable
										accessibilityLabel={`Quitar ${line.name}`}
										style={styles.step}
										onPress={() => cart.setQuantity(line.key, line.quantity - 1)}
									>
										<Text style={styles.stepText}>−</Text>
									</Pressable>
									<Text style={styles.qty}>{line.quantity}</Text>
									<Pressable
										accessibilityLabel={`Agregar ${line.name}`}
										style={styles.step}
										onPress={() => cart.setQuantity(line.key, line.quantity + 1)}
									>
										<Text style={styles.stepText}>+</Text>
									</Pressable>
								</View>
							</View>
						))}
					</ScrollView>

					<View style={[styles.footer, { paddingBottom: insets.bottom + space(3) }]}>
						<View style={styles.totalRow}>
							<Text style={styles.totalLabel}>Total</Text>
							<Text style={styles.totalValue}>{money(cart.total)}</Text>
						</View>
						<Text style={styles.footNote}>Pagas al recoger en barra.</Text>
						<Pressable
							accessibilityRole="button"
							onPress={() => router.push('/checkout')}
							style={({ pressed }) => [styles.cta, pressed && { opacity: 0.85 }]}
						>
							<Text style={styles.ctaText}>Elegir día de recolección</Text>
						</Pressable>
					</View>
				</>
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
	list: { paddingHorizontal: space(5), gap: space(3), paddingBottom: space(4) },
	row: {
		flexDirection: 'row',
		alignItems: 'center',
		gap: space(3),
		backgroundColor: color.surface,
		borderRadius: radius.card,
		borderWidth: 1,
		borderColor: color.hairline,
		padding: space(3),
	},
	thumb: { width: 56, height: 56, borderRadius: radius.control, backgroundColor: color.accentSoft },
	thumbFallback: { alignItems: 'center', justifyContent: 'center' },
	thumbGlyph: { fontFamily: font.display, fontSize: 24, color: color.accentDeep },
	rowBody: { flex: 1, gap: space(0.5) },
	rowName: { color: color.text, fontSize: type.body, fontWeight: '600' },
	rowMods: { color: color.textSoft, fontSize: 12, lineHeight: 16 },
	rowPrice: { color: color.textSoft, fontSize: type.small },
	stepper: { flexDirection: 'row', alignItems: 'center', gap: space(1.5) },
	step: {
		width: 34,
		height: 34,
		borderRadius: radius.control,
		borderWidth: 1,
		borderColor: color.hairline,
		alignItems: 'center',
		justifyContent: 'center',
		backgroundColor: color.bg,
	},
	stepText: { color: color.text, fontSize: type.body, fontWeight: '700' },
	qty: { color: color.text, fontSize: type.body, fontWeight: '700', minWidth: 24, textAlign: 'center' },
	footer: {
		backgroundColor: color.surface,
		borderTopWidth: 1,
		borderTopColor: color.hairline,
		padding: space(4),
		gap: space(2),
	},
	totalRow: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'baseline' },
	totalLabel: { color: color.textSoft, fontSize: type.body },
	totalValue: { color: color.text, fontSize: type.display, fontWeight: '700', fontVariant: ['tabular-nums'] },
	footNote: { color: color.textSoft, fontSize: type.small },
	cta: {
		marginTop: space(1),
		backgroundColor: color.accentDeep,
		borderRadius: radius.control,
		paddingVertical: space(4),
		alignItems: 'center',
	},
	ctaText: { color: color.bg, fontSize: type.body, fontWeight: '700' },
});
