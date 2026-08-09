import MaterialCommunityIcons from '@expo/vector-icons/MaterialCommunityIcons';
import { useQuery } from '@tanstack/react-query';
import * as WebBrowser from 'expo-web-browser';
import React from 'react';
import { Alert, Platform, Pressable, RefreshControl, ScrollView, StyleSheet, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import QRCode from 'react-native-qrcode-svg';

import { appApi } from '../../lib/api';
import { useLoyaltyCard } from '../../lib/loyalty';
import { color, font, radius, space, type } from '../../lib/theme';

async function addToWallet(token: string) {
	try {
		// Safari recognizes the .pkpass response and shows the "Add Pass" sheet.
		await WebBrowser.openBrowserAsync(appApi.walletPassUrl(token));
	} catch {
		Alert.alert('No se pudo abrir', 'Inténtalo de nuevo en un momento.');
	}
}

export default function LoyaltyScreen() {
	const insets = useSafeAreaInsets();
	const { data: card, isLoading, isError, refetch, isRefetching } = useLoyaltyCard();

	const config = useQuery({ queryKey: ['config'], queryFn: () => appApi.config() });
	const walletAvailable = Platform.OS === 'ios' && config.data?.wallet_pass === true;

	return (
		<ScrollView
			style={styles.screen}
			contentContainerStyle={[styles.content, { paddingTop: insets.top + space(4) }]}
			refreshControl={
				<RefreshControl refreshing={isRefetching} onRefresh={() => void refetch()} tintColor={color.textSoft} />
			}
		>
			<Text style={styles.title}>Lealtad</Text>
			<Text style={styles.lede}>Cada visita deja huella. Muestra tu tarjeta en barra.</Text>

			{isLoading ? (
				<View style={styles.cardTile}>
					<Text style={styles.stateText}>Preparando tu tarjeta…</Text>
				</View>
			) : null}

			{isError ? (
				<View style={styles.cardTile}>
					<Text style={styles.stateText}>
						No pudimos cargar tu tarjeta. Revisa tu conexión e inténtalo de nuevo.
					</Text>
					<Pressable accessibilityRole="button" style={styles.retry} onPress={() => void refetch()}>
						<Text style={styles.retryText}>Reintentar</Text>
					</Pressable>
				</View>
			) : null}

			{card ? (
				<>
					<View style={styles.cardTile}>
						<View style={styles.qrWrap}>
							<QRCode value={card.token} size={220} color={color.bg} backgroundColor={color.text} />
						</View>
						<Text style={styles.cardHint}>Muéstralo al pagar; la barra hace el resto.</Text>
					</View>

					{walletAvailable ? (
						<Pressable
							accessibilityRole="button"
							style={({ pressed }) => [styles.wallet, pressed && { opacity: 0.85 }]}
							onPress={() => void addToWallet(card.token)}
						>
							<MaterialCommunityIcons name="wallet" size={18} color={color.noche} />
							<Text style={styles.walletText}>Agregar a Apple Wallet</Text>
						</Pressable>
					) : null}

					<View style={styles.counters}>
						<View style={styles.counter}>
							<Text style={styles.counterNumber}>{card.stamps}</Text>
							<Text style={styles.counterLabel}>Sellos</Text>
						</View>
						<View style={styles.counterRule} />
						<View style={styles.counter}>
							<Text style={styles.counterNumber}>{card.redeemed}</Text>
							<Text style={styles.counterLabel}>Canjes</Text>
						</View>
					</View>

					<Text style={styles.fine}>
						Tu tarjeta vive en este dispositivo — sin cuentas, sin fricción. Los canjes se aplican en barra.
					</Text>
				</>
			) : null}
		</ScrollView>
	);
}

const styles = StyleSheet.create({
	screen: { flex: 1, backgroundColor: color.bg },
	content: { paddingHorizontal: space(5), paddingBottom: space(10), gap: space(4) },
	title: { fontFamily: font.display, fontSize: type.hero, color: color.text },
	lede: { color: color.textSoft, fontSize: type.body, lineHeight: 22, marginTop: -space(2) },
	cardTile: {
		alignItems: 'center',
		gap: space(4),
		backgroundColor: color.surface,
		borderRadius: radius.card,
		borderWidth: 1,
		borderColor: color.hairline,
		paddingVertical: space(8),
		paddingHorizontal: space(5),
	},
	qrWrap: {
		// The scannable card: bone ground so any reader catches it in the dark bar.
		backgroundColor: color.text,
		padding: space(4),
		borderRadius: radius.card,
	},
	cardHint: { color: color.textSoft, fontSize: type.small, textAlign: 'center' },
	stateText: { color: color.textSoft, fontSize: type.body, textAlign: 'center', lineHeight: 22 },
	retry: {
		borderWidth: 1,
		borderColor: color.accent,
		borderRadius: radius.control,
		paddingHorizontal: space(6),
		paddingVertical: space(3),
	},
	retryText: { color: color.accent, fontWeight: '600', fontSize: type.body, letterSpacing: 1 },
	wallet: {
		// Apple's Wallet convention on dark UIs: the light pill. Bone fill, noche ink.
		flexDirection: 'row',
		alignItems: 'center',
		justifyContent: 'center',
		gap: space(2),
		backgroundColor: color.text,
		borderRadius: radius.control,
		paddingVertical: space(3),
	},
	walletText: { color: color.noche, fontSize: type.body, fontWeight: '600' },
	counters: {
		flexDirection: 'row',
		alignItems: 'stretch',
		justifyContent: 'center',
		gap: space(8),
		paddingVertical: space(2),
	},
	counter: { alignItems: 'center', gap: space(1) },
	counterNumber: { fontFamily: font.display, fontSize: 44, color: color.accent },
	counterLabel: {
		color: color.textSoft,
		fontSize: type.tiny,
		letterSpacing: 2,
		textTransform: 'uppercase',
	},
	counterRule: { width: 1, backgroundColor: color.hairline },
	fine: { color: color.textSoft, fontSize: type.small, textAlign: 'center', lineHeight: 20 },
});
