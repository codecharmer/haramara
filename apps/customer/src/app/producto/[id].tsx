import { useQuery } from '@tanstack/react-query';
import { Image } from 'expo-image';
import { router, useLocalSearchParams } from 'expo-router';
import React from 'react';
import { ActivityIndicator, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { storeApi } from '../../lib/api';
import { productPrice, useCart } from '../../lib/cart';
import { plainText } from '../../lib/text';
import { color, font, money, radius, space, type } from '../../lib/theme';

export default function ProductScreen() {
	const { id } = useLocalSearchParams<{ id: string }>();
	const insets = useSafeAreaInsets();
	const cart = useCart();

	const products = useQuery({ queryKey: ['products'], queryFn: () => storeApi.products() });
	const product = products.data?.find((p) => p.id === Number(id));
	const inCart = cart.lines.find((l) => l.productId === Number(id))?.quantity ?? 0;

	return (
		<View style={styles.screen}>
			<ScrollView contentContainerStyle={{ paddingBottom: space(24) }}>
				{product?.images[0] ? (
					<Image source={{ uri: product.images[0].src }} style={styles.hero} contentFit="cover" transition={200} />
				) : (
					<View style={[styles.hero, styles.heroFallback]}>
						<Text style={styles.heroGlyph}>P</Text>
					</View>
				)}

				<Pressable
					accessibilityRole="button"
					accessibilityLabel="Regresar"
					onPress={() => (router.canGoBack() ? router.back() : router.replace('/'))}
					style={[styles.back, { top: insets.top + space(2) }]}
				>
					<Text style={styles.backText}>←</Text>
				</Pressable>

				{products.isLoading && (
					<View style={styles.center}>
						<ActivityIndicator size="large" color={color.accentDeep} />
					</View>
				)}

				{!products.isLoading && !product && (
					<View style={styles.center}>
						<Text style={styles.emptyText}>Este producto ya no está disponible.</Text>
					</View>
				)}

				{product && (
					<View style={styles.body}>
						<Text style={styles.name}>{product.name}</Text>
						<Text style={styles.price}>{money(productPrice(product))}</Text>
						{plainText(product.description || product.short_description) !== '' && (
							<Text style={styles.description}>
								{plainText(product.description || product.short_description)}
							</Text>
						)}
						{!product.is_in_stock && (
							<View style={styles.soldOut}>
								<Text style={styles.soldOutText}>Agotado por hoy</Text>
							</View>
						)}
						{product.low_stock_remaining !== null && product.is_in_stock && (
							<Text style={styles.lowStock}>Quedan {product.low_stock_remaining} piezas</Text>
						)}
					</View>
				)}
			</ScrollView>

			{product && product.is_in_stock && (
				<View style={[styles.footer, { paddingBottom: insets.bottom + space(3) }]}>
					<Pressable
						accessibilityRole="button"
						onPress={() => {
							cart.add(product);
							if (router.canGoBack()) {
								router.back();
							} else {
								router.replace('/');
							}
						}}
						style={({ pressed }) => [styles.cta, pressed && { opacity: 0.85 }]}
					>
						<Text style={styles.ctaText}>
							{inCart > 0 ? `Agregar otro (${inCart} en la canasta)` : 'Agregar a la canasta'}
						</Text>
					</Pressable>
				</View>
			)}
		</View>
	);
}

const styles = StyleSheet.create({
	screen: { flex: 1, backgroundColor: color.bg },
	hero: { width: '100%', height: 320, backgroundColor: color.accentSoft },
	heroFallback: { alignItems: 'center', justifyContent: 'center' },
	heroGlyph: { fontFamily: font.display, fontSize: 72, color: color.accentDeep },
	back: {
		position: 'absolute',
		left: space(4),
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
	center: { alignItems: 'center', padding: space(10) },
	body: { padding: space(5), gap: space(3) },
	name: { fontFamily: font.display, fontSize: type.hero, color: color.text, lineHeight: 40 },
	price: { color: color.accentDeep, fontSize: type.title, fontWeight: '700' },
	description: { color: color.textSoft, fontSize: type.body, lineHeight: 24 },
	lowStock: { color: color.attention, fontSize: type.small, fontWeight: '600' },
	soldOut: {
		alignSelf: 'flex-start',
		borderRadius: radius.pill,
		backgroundColor: color.dangerBg,
		paddingHorizontal: space(3),
		paddingVertical: space(1.5),
	},
	soldOutText: { color: color.danger, fontSize: type.small, fontWeight: '700' },
	emptyText: { color: color.textSoft, fontSize: type.body, textAlign: 'center' },
	footer: {
		position: 'absolute',
		left: 0,
		right: 0,
		bottom: 0,
		padding: space(4),
		backgroundColor: color.surface,
		borderTopWidth: 1,
		borderTopColor: color.hairline,
	},
	cta: {
		backgroundColor: color.accent,
		borderRadius: radius.control,
		paddingVertical: space(4),
		alignItems: 'center',
	},
	ctaText: { color: color.bg, fontSize: type.body, fontWeight: '700' },
});
