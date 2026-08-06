import { ApiError } from '@haramara/api-client';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { router } from 'expo-router';
import React, { useEffect, useState } from 'react';
import {
	ActivityIndicator,
	KeyboardAvoidingView,
	Platform,
	Pressable,
	ScrollView,
	StyleSheet,
	Text,
	TextInput,
	View,
} from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { appApi } from '../lib/api';
import { useCart } from '../lib/cart';
import { isValidEmail, isValidPhone, submitOrder } from '../lib/checkout';
import { useStoredContact } from '../lib/contact';
import { registerForOrderUpdates } from '../lib/notifications';
import { color, font, money, radius, space, type } from '../lib/theme';

export default function CheckoutScreen() {
	const insets = useSafeAreaInsets();
	const cart = useCart();
	const queryClient = useQueryClient();

	const [pickupDate, setPickupDate] = useState('');
	const [pickupSlot, setPickupSlot] = useState('');
	// Nombre, celular y correo se recuerdan en el dispositivo entre pedidos.
	const { contact, setContact } = useStoredContact();
	const { firstName, lastName, phone, email } = contact;
	const [error, setError] = useState<string | null>(null);

	const dates = useQuery({ queryKey: ['pickup-dates'], queryFn: () => appApi.pickupDates() });
	const slots = useQuery({
		queryKey: ['availability', pickupDate],
		queryFn: () => appApi.availability(pickupDate),
		enabled: pickupDate !== '',
	});

	// Preselecciona el primer día disponible.
	useEffect(() => {
		if (pickupDate === '' && dates.data && dates.data.length > 0) {
			setPickupDate(dates.data[0].date);
		}
	}, [dates.data, pickupDate]);

	// Si cambia el día, el horario elegido puede dejar de existir.
	useEffect(() => {
		if (slots.data && !slots.data.some((s) => s.slot === pickupSlot)) {
			setPickupSlot('');
		}
	}, [slots.data, pickupSlot]);

	const formValid =
		cart.lines.length > 0 &&
		pickupDate !== '' &&
		pickupSlot !== '' &&
		firstName.trim() !== '' &&
		isValidPhone(phone) &&
		isValidEmail(email);

	function missingFieldsHint(): string {
		const missing: string[] = [];
		if (pickupDate === '') missing.push('fecha');
		if (pickupSlot === '') missing.push('horario');
		if (firstName.trim() === '') missing.push('nombre');
		if (!isValidPhone(phone)) missing.push('celular');
		if (!isValidEmail(email)) missing.push('correo');
		return `Falta: ${missing.join(', ')}.`;
	}

	const order = useMutation({
		mutationFn: () =>
			submitOrder(cart.lines, {
				firstName,
				lastName,
				phone,
				email,
				pickupDate,
				pickupSlot,
			}),
		onSuccess: (result) => {
			const dateLabel = dates.data?.find((d) => d.date === pickupDate)?.label ?? pickupDate;
			cart.rememberOrder({
				id: result.order_id,
				key: result.order_key,
				number: String(result.order_id),
				total: cart.total,
				createdAt: new Date().toISOString(),
				pickupLabel: `${dateLabel} · ${pickupSlot}`,
			});
			cart.clear();
			// Fire-and-forget: the permission prompt lands right after ordering,
			// and a denial must not block the confirmation screen.
			void registerForOrderUpdates(result.order_id, result.order_key);
			void queryClient.invalidateQueries({ queryKey: ['availability'] });
			router.replace({
				pathname: '/pedido/[id]',
				params: { id: String(result.order_id), key: result.order_key, nuevo: '1' },
			});
		},
		onError: (e) => {
			setError(e instanceof ApiError ? e.message : 'No se pudo enviar el pedido. Intenta de nuevo.');
			// El cupo pudo agotarse mientras llenaban el formulario.
			void queryClient.invalidateQueries({ queryKey: ['availability'] });
			void queryClient.invalidateQueries({ queryKey: ['pickup-dates'] });
		},
	});

	const noDates = dates.isSuccess && dates.data.length === 0;

	return (
		<KeyboardAvoidingView
			style={styles.screen}
			behavior={Platform.OS === 'ios' ? 'padding' : undefined}
		>
			<View style={[styles.header, { paddingTop: insets.top + space(2) }]}>
				<Pressable
					accessibilityRole="button"
					accessibilityLabel="Regresar"
					onPress={() => (router.canGoBack() ? router.back() : router.replace('/carrito'))}
					style={styles.back}
				>
					<Text style={styles.backText}>←</Text>
				</Pressable>
				<Text style={styles.title}>Recolección</Text>
			</View>

			<ScrollView contentContainerStyle={styles.scroll} keyboardShouldPersistTaps="handled">
				{noDates ? (
					<View style={styles.card}>
						<Text style={styles.sectionTitle}>Sin fechas disponibles</Text>
						<Text style={styles.helper}>
							Por ahora no hay fechas de recolección abiertas. Escríbenos por WhatsApp para coordinar tu
							pedido.
						</Text>
					</View>
				) : (
					<View style={styles.card}>
						<Text style={styles.sectionTitle}>¿Qué día vienes por tu pedido?</Text>
						{dates.isLoading && <ActivityIndicator color={color.accentDeep} />}
						<ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={styles.chips}>
							{dates.data?.map((d) => (
								<Chip
									key={d.date}
									label={d.label}
									active={pickupDate === d.date}
									onPress={() => setPickupDate(d.date)}
								/>
							))}
						</ScrollView>

						<Text style={[styles.sectionTitle, { marginTop: space(4) }]}>¿A qué hora?</Text>
						{slots.isLoading && <ActivityIndicator color={color.accentDeep} />}
						{slots.isSuccess && slots.data.length === 0 && (
							<Text style={styles.helper}>Ese día ya no tiene horarios: elige otra fecha.</Text>
						)}
						<View style={styles.slotGrid}>
							{slots.data?.map((s) => (
								<Chip
									key={s.slot}
									label={s.slot}
									detail={s.remaining <= 2 ? `quedan ${s.remaining}` : undefined}
									active={pickupSlot === s.slot}
									onPress={() => setPickupSlot(s.slot)}
								/>
							))}
						</View>
					</View>
				)}

				<View style={styles.card}>
					<Text style={styles.sectionTitle}>Tus datos</Text>
					<Field
						label="Nombre"
						value={firstName}
						onChangeText={(v) => setContact({ firstName: v })}
						autoComplete="name"
					/>
					<Field
						label="Apellido (opcional)"
						value={lastName}
						onChangeText={(v) => setContact({ lastName: v })}
					/>
					<Field
						label="Celular (WhatsApp)"
						value={phone}
						onChangeText={(v) => setContact({ phone: v })}
						keyboardType="phone-pad"
						placeholder="777 123 4567"
						invalid={phone !== '' && !isValidPhone(phone)}
					/>
					<Text style={styles.helper}>Te avisamos por WhatsApp cuando tu pedido esté listo.</Text>
					<Field
						label="Correo"
						value={email}
						onChangeText={(v) => setContact({ email: v })}
						keyboardType="email-address"
						autoCapitalize="none"
						invalid={email !== '' && !isValidEmail(email)}
					/>
				</View>

				<View style={styles.card}>
					<Text style={styles.sectionTitle}>Pago</Text>
					<View style={styles.payRow}>
						<View style={styles.payBadge}>
							<Text style={styles.payBadgeText}>$</Text>
						</View>
						<View style={{ flex: 1 }}>
							<Text style={styles.payTitle}>Paga al recoger</Text>
							<Text style={styles.helper}>Efectivo o tarjeta al recoger en barra.</Text>
						</View>
					</View>
				</View>

			</ScrollView>

			<View style={[styles.footer, { paddingBottom: insets.bottom + space(3) }]}>
				{error !== null && <Text style={styles.error}>{error}</Text>}
				{error === null && !formValid && cart.lines.length > 0 && (
					<Text style={styles.incomplete}>{missingFieldsHint()}</Text>
				)}
				<View style={styles.totalRow}>
					<Text style={styles.totalLabel}>Total ({cart.count} pzas)</Text>
					<Text style={styles.totalValue}>{money(cart.total)}</Text>
				</View>
				<Pressable
					accessibilityRole="button"
					disabled={!formValid || order.isPending}
					onPress={() => {
						setError(null);
						order.mutate();
					}}
					style={({ pressed }) => [
						styles.cta,
						(!formValid || order.isPending) && { opacity: 0.4 },
						pressed && { opacity: 0.85 },
					]}
				>
					{order.isPending ? (
						<ActivityIndicator color={color.surface} />
					) : (
						<Text style={styles.ctaText}>Apartar mi pedido</Text>
					)}
				</Pressable>
			</View>
		</KeyboardAvoidingView>
	);
}

function Chip({
	label,
	detail,
	active,
	onPress,
}: {
	label: string;
	detail?: string;
	active: boolean;
	onPress: () => void;
}) {
	return (
		<Pressable
			accessibilityRole="button"
			accessibilityState={{ selected: active }}
			onPress={onPress}
			style={[styles.chip, active && styles.chipActive]}
		>
			<Text style={[styles.chipText, active && styles.chipTextActive]}>{label}</Text>
			{detail && <Text style={[styles.chipDetail, active && styles.chipTextActive]}>{detail}</Text>}
		</Pressable>
	);
}

function Field({
	label,
	invalid,
	...inputProps
}: { label: string; invalid?: boolean } & React.ComponentProps<typeof TextInput>) {
	return (
		<View style={{ gap: space(1) }}>
			<Text style={styles.fieldLabel}>{label}</Text>
			<TextInput
				{...inputProps}
				style={[styles.input, invalid === true && styles.inputInvalid]}
				placeholderTextColor={color.textSoft}
			/>
		</View>
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
	scroll: { padding: space(4), gap: space(4), paddingBottom: space(6) },
	card: {
		backgroundColor: color.surface,
		borderRadius: radius.card,
		borderWidth: 1,
		borderColor: color.hairline,
		padding: space(4),
		gap: space(3),
	},
	sectionTitle: { color: color.text, fontSize: type.title, fontWeight: '600' },
	chips: { gap: space(2), paddingVertical: space(1) },
	slotGrid: { flexDirection: 'row', flexWrap: 'wrap', gap: space(2) },
	chip: {
		borderRadius: radius.control,
		borderWidth: 1,
		borderColor: color.hairline,
		backgroundColor: color.bg,
		paddingHorizontal: space(3.5),
		paddingVertical: space(2.5),
		alignItems: 'center',
		gap: space(0.5),
	},
	chipActive: { backgroundColor: color.accent, borderColor: color.accent },
	chipText: { color: color.text, fontSize: type.small, fontWeight: '600' },
	chipTextActive: { color: color.bg },
	chipDetail: { color: color.attention, fontSize: type.tiny, fontWeight: '600' },
	fieldLabel: { color: color.text, fontSize: type.small, fontWeight: '600' },
	input: {
		borderWidth: 1,
		borderColor: color.hairline,
		borderRadius: radius.control,
		backgroundColor: color.bg,
		paddingHorizontal: space(3),
		paddingVertical: space(3),
		fontSize: type.body,
		color: color.text,
	},
	inputInvalid: { borderColor: color.danger },
	helper: { color: color.textSoft, fontSize: type.small, lineHeight: 18 },
	payRow: { flexDirection: 'row', alignItems: 'center', gap: space(3) },
	payBadge: {
		width: 40,
		height: 40,
		borderRadius: 20,
		backgroundColor: color.accentSoft,
		alignItems: 'center',
		justifyContent: 'center',
	},
	payBadgeText: { color: color.accentDeep, fontSize: type.title, fontWeight: '700' },
	payTitle: { color: color.text, fontSize: type.body, fontWeight: '600' },
	error: {
		color: color.danger,
		backgroundColor: color.dangerBg,
		borderRadius: radius.control,
		padding: space(3),
		fontSize: type.small,
		lineHeight: 20,
	},
	incomplete: { color: color.textSoft, fontSize: type.small, textAlign: 'center' },
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
	cta: {
		backgroundColor: color.accentDeep,
		borderRadius: radius.control,
		paddingVertical: space(4),
		alignItems: 'center',
	},
	ctaText: { color: color.bg, fontSize: type.body, fontWeight: '700' },
});
