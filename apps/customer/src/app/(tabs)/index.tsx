import type { StoreProduct } from '@haramara/api-client';
import { useQuery } from '@tanstack/react-query';
import { Image } from 'expo-image';
import { router } from 'expo-router';
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

import { appApi, storeApi } from '../../lib/api';
import { productPrice, useCart } from '../../lib/cart';
import { plainText } from '../../lib/text';
import { color, font, money, radius, space, type } from '../../lib/theme';

export default function MenuScreen() {
	const insets = useSafeAreaInsets();
	const cart = useCart();

	const config = useQuery({ queryKey: ['config'], queryFn: () => appApi.config() });
	const products = useQuery({ queryKey: ['products'], queryFn: () => storeApi.products() });

	const groups = useMemo(() => {
		const map = new Map<string, StoreProduct[]>();
		for (const p of products.data ?? []) {
			const cat = p.categories[0]?.name ?? 'Otros';
			if (!map.has(cat)) map.set(cat, []);
			(map.get(cat) as StoreProduct[]).push(p);
		}
		// The carta reads like the bar's day: coffee first, then savory, then sweet.
		// Within Cafés, price ascending climbs the house ladder:
		// espresso → cold brew → Chill → Groove → Funky.
		const cafes = map.get('Cafés');
		if (cafes) cafes.sort((a, b) => productPrice(a) - productPrice(b));
		const order = ['Cafés', 'Salados', 'Especiales'];
		return [...map.entries()].sort(
			(a, b) =>
				(order.indexOf(a[0]) + 1 || order.length + 1) - (order.indexOf(b[0]) + 1 || order.length + 1),
		);
	}, [products.data]);

	return (
		<View style={[styles.screen, { paddingTop: insets.top }]}>
			<ScrollView
				contentContainerStyle={styles.scroll}
				refreshControl={
					<RefreshControl
						refreshing={products.isRefetching}
						onRefresh={() => {
							void products.refetch();
							void config.refetch();
						}}
						tintColor={color.accentDeep}
					/>
				}
			>
				<View style={styles.masthead}>
					<Text style={styles.wordmark}>HARAMARA</Text>
					<Text style={styles.tagline}>Creamos a través de procesos manuales.</Text>
					{config.data && (
						<Text style={styles.hours}>{config.data.business.hours_summary}</Text>
					)}
				</View>

				{products.isLoading && (
					<View style={styles.center}>
						<ActivityIndicator size="large" color={color.accentDeep} />
					</View>
				)}

				{products.isError && (
					<View style={styles.center}>
						<Text style={styles.emptyTitle}>No pudimos cargar la carta</Text>
						<Text style={styles.emptyText}>Revisa tu conexión e intenta de nuevo.</Text>
						<Pressable style={styles.retry} onPress={() => products.refetch()}>
							<Text style={styles.retryText}>Reintentar</Text>
						</Pressable>
					</View>
				)}

				{groups.map(([category, items]) => (
					<View key={category} style={styles.section}>
						<Text style={styles.sectionHeading}>{category}</Text>
						<View style={styles.rows}>
							{items.map((p) => (
								<ProductRow key={p.id} product={p} onAdd={() => cart.add(p)} />
							))}
						</View>
					</View>
				))}
			</ScrollView>
		</View>
	);
}

function ProductRow({ product, onAdd }: { product: StoreProduct; onAdd: () => void }) {
	const soldOut = !product.is_in_stock;
	const description = plainText(product.short_description || product.description).slice(0, 120);

	// Two sibling Pressables inside a plain View — nesting them renders nested
	// <button> elements on web, which is invalid HTML.
	return (
		<View style={styles.row}>
			<Pressable
				accessibilityRole="button"
				accessibilityLabel={`Ver ${product.name}`}
				onPress={() => router.push({ pathname: '/producto/[id]', params: { id: String(product.id) } })}
				style={({ pressed }) => [styles.rowMain, pressed && { opacity: 0.9 }]}
			>
				{product.images[0] ? (
					<Image
						source={{ uri: product.images[0].thumbnail || product.images[0].src }}
						style={styles.thumb}
						contentFit="cover"
						transition={150}
					/>
				) : (
					<View style={[styles.thumb, styles.thumbFallback]}>
						<Text style={styles.thumbGlyph}>H</Text>
					</View>
				)}

				<View style={styles.rowBody}>
					<Text style={styles.rowName} numberOfLines={2}>
						{product.name}
					</Text>
					{description !== '' && (
						<Text style={styles.rowDesc} numberOfLines={2}>
							{description}
						</Text>
					)}
					<Text style={styles.rowPrice}>{money(productPrice(product))}</Text>
				</View>
			</Pressable>

			{soldOut ? (
				<View style={styles.soldOut}>
					<Text style={styles.soldOutText}>Agotado</Text>
				</View>
			) : (
				<Pressable
					accessibilityLabel={`Agregar ${product.name} a la canasta`}
					accessibilityRole="button"
					onPress={onAdd}
					style={({ pressed }) => [styles.add, pressed && { opacity: 0.8 }]}
				>
					<Text style={styles.addText}>+</Text>
				</Pressable>
			)}
		</View>
	);
}

const styles = StyleSheet.create({
	screen: { flex: 1, backgroundColor: color.bg },
	scroll: { paddingBottom: space(10) },
	masthead: {
		alignItems: 'center',
		paddingTop: space(6),
		paddingBottom: space(7),
		paddingHorizontal: space(5),
		gap: space(2),
	},
	wordmark: { fontFamily: font.display, fontSize: type.hero, color: color.text, letterSpacing: 6 },
	tagline: { fontFamily: font.displayItalic, fontSize: type.body, color: color.accentDeep },
	hours: { color: color.textSoft, fontSize: type.small, marginTop: space(1) },
	center: { alignItems: 'center', justifyContent: 'center', padding: space(10), gap: space(2) },
	section: { paddingHorizontal: space(5), marginBottom: space(7) },
	sectionHeading: {
		fontFamily: font.display,
		fontSize: type.display,
		color: color.text,
		marginBottom: space(4),
	},
	rows: { gap: space(3) },
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
	rowMain: { flex: 1, flexDirection: 'row', alignItems: 'center', gap: space(3) },
	thumb: { width: 84, height: 84, borderRadius: radius.control, backgroundColor: color.accentSoft },
	thumbFallback: { alignItems: 'center', justifyContent: 'center' },
	thumbGlyph: { fontFamily: font.display, fontSize: 32, color: color.accentDeep },
	rowBody: { flex: 1, gap: space(1) },
	rowName: { color: color.text, fontSize: type.body, fontWeight: '600' },
	rowDesc: { color: color.textSoft, fontSize: type.small, lineHeight: 18 },
	rowPrice: { color: color.text, fontSize: type.small, fontWeight: '700', marginTop: space(0.5) },
	add: {
		width: 40,
		height: 40,
		borderRadius: 20,
		backgroundColor: color.accent,
		alignItems: 'center',
		justifyContent: 'center',
	},
	addText: { color: color.bg, fontSize: 22, lineHeight: 24, fontWeight: '600' },
	soldOut: {
		borderRadius: radius.pill,
		backgroundColor: color.dangerBg,
		paddingHorizontal: space(3),
		paddingVertical: space(1.5),
	},
	soldOutText: { color: color.danger, fontSize: type.tiny, fontWeight: '700' },
	emptyTitle: { color: color.text, fontSize: type.title, fontWeight: '600' },
	emptyText: { color: color.textSoft, fontSize: type.body, textAlign: 'center', lineHeight: 22 },
	retry: {
		marginTop: space(3),
		backgroundColor: color.accent,
		borderRadius: radius.control,
		paddingHorizontal: space(5),
		paddingVertical: space(2.5),
	},
	retryText: { color: color.bg, fontWeight: '700', fontSize: type.small },
});
