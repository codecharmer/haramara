/**
 * The counter lock: pick your name, enter your NIP.
 *
 * Stands between the device session and every screen that can move money, so
 * that sales, salidas, and (from Phase 2) cancelaciones carry a person rather
 * than just a tablet. Deliberately a keypad rather than a keyboard — this is
 * used mid-service, one-handed, often with a queue waiting.
 */

import { ApiError } from '@haramara/api-client';
import React, { useEffect, useState } from 'react';
import { ActivityIndicator, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';

import { useAuth } from '../lib/auth';
import { useOperator } from '../lib/operator';
import { color, radius, space, type } from '../lib/theme';

const PIN_MAX = 6;
const KEYS = ['1', '2', '3', '4', '5', '6', '7', '8', '9', 'clear', '0', 'back'] as const;

export default function OperadorScreen() {
	const { roster, signIn, refreshRoster } = useOperator();
	const { signOut } = useAuth();
	const [selected, setSelected] = useState<string | null>(null);
	const [pin, setPin] = useState('');
	const [busy, setBusy] = useState(false);
	const [error, setError] = useState<string | null>(null);

	// One person on the roster is the common café case — skip the picker.
	useEffect(() => {
		if (roster.length === 1 && selected === null) setSelected(roster[0].key);
	}, [roster, selected]);

	const person = roster.find((p) => p.key === selected) ?? null;

	async function submit(candidate: string) {
		if (selected === null || busy) return;
		setBusy(true);
		setError(null);
		try {
			await signIn(selected, candidate);
			// The layout guard routes onward once the operator lands.
		} catch (e) {
			setPin('');
			if (e instanceof ApiError) {
				setError(e.message);
			} else {
				setError('No se pudo verificar el NIP.');
			}
		} finally {
			setBusy(false);
		}
	}

	function press(key: (typeof KEYS)[number]) {
		if (busy) return;
		setError(null);

		if (key === 'clear') return setPin('');
		if (key === 'back') return setPin((p) => p.slice(0, -1));

		const next = pin + key;
		if (next.length > PIN_MAX) return;
		setPin(next);
		// Four digits is the norm; auto-submit there and let longer NIPs use
		// the explicit button.
		if (next.length === 4) void submit(next);
	}

	if (selected === null) {
		return (
			<View style={styles.screen}>
				<ScrollView contentContainerStyle={styles.scroll}>
					<View style={styles.card}>
						<Text style={styles.brand}>HARAMARA</Text>
						<Text style={styles.title}>¿Quién está en barra?</Text>
						<Text style={styles.lede}>Selecciona tu nombre para continuar.</Text>

						{roster.map((p) => (
							<Pressable
								key={p.key}
								accessibilityRole="button"
								onPress={() => setSelected(p.key)}
								style={({ pressed }) => [styles.person, pressed && styles.pressed]}
							>
								<Text style={styles.personName}>{p.name}</Text>
								{p.role === 'supervisor' && <Text style={styles.roleTag}>Supervisor</Text>}
							</Pressable>
						))}

						{roster.length === 0 && (
							<Text style={styles.hint}>
								Todavía no hay NIPs configurados. Pídelo en Ajustes → Empleados.
							</Text>
						)}

						<Pressable accessibilityRole="button" onPress={() => void refreshRoster()}>
							<Text style={styles.link}>Actualizar lista</Text>
						</Pressable>
						<Pressable accessibilityRole="button" onPress={() => void signOut()}>
							<Text style={styles.link}>Cerrar sesión del dispositivo</Text>
						</Pressable>
					</View>
				</ScrollView>
			</View>
		);
	}

	return (
		<View style={styles.screen}>
			<ScrollView contentContainerStyle={styles.scroll}>
				<View style={styles.card}>
					<Text style={styles.brand}>HARAMARA</Text>
					<Text style={styles.title}>{person?.name ?? 'Operador'}</Text>
					<Text style={styles.lede}>Ingresa tu NIP para abrir el mostrador.</Text>

					<View style={styles.dots}>
						{Array.from({ length: PIN_MAX }).map((_, i) => (
							<View key={i} style={[styles.dot, i < pin.length && styles.dotFilled]} />
						))}
					</View>

					{error !== null && <Text style={styles.error}>{error}</Text>}

					<View style={styles.pad}>
						{KEYS.map((key) => (
							<Pressable
								key={key}
								accessibilityRole="button"
								accessibilityLabel={key === 'back' ? 'Borrar' : key === 'clear' ? 'Limpiar' : key}
								onPress={() => press(key)}
								style={({ pressed }) => [styles.key, pressed && styles.pressed]}
							>
								<Text style={styles.keyText}>
									{key === 'back' ? '⌫' : key === 'clear' ? 'C' : key}
								</Text>
							</Pressable>
						))}
					</View>

					<Pressable
						accessibilityRole="button"
						disabled={pin.length < 4 || busy}
						onPress={() => void submit(pin)}
						style={({ pressed }) => [
							styles.button,
							(pin.length < 4 || busy) && styles.buttonDisabled,
							pressed && styles.pressed,
						]}
					>
						{busy ? <ActivityIndicator color={color.surface} /> : <Text style={styles.buttonText}>Entrar</Text>}
					</Pressable>

					{roster.length > 1 && (
						<Pressable
							accessibilityRole="button"
							onPress={() => {
								setSelected(null);
								setPin('');
								setError(null);
							}}
						>
							<Text style={styles.link}>Cambiar de persona</Text>
						</Pressable>
					)}
				</View>
			</ScrollView>
		</View>
	);
}

const styles = StyleSheet.create({
	screen: { flex: 1, backgroundColor: color.bg },
	scroll: { flexGrow: 1, alignItems: 'center', justifyContent: 'center', padding: space(6) },
	card: {
		width: '100%',
		maxWidth: 420,
		backgroundColor: color.surface,
		borderRadius: radius.card,
		borderWidth: 1,
		borderColor: color.hairline,
		padding: space(7),
	},
	brand: {
		color: color.accentDeep,
		fontSize: type.small,
		letterSpacing: 4,
		fontWeight: '600',
		marginBottom: space(2),
	},
	title: { color: color.text, fontSize: type.display, fontWeight: '700', marginBottom: space(2) },
	lede: { color: color.textSoft, fontSize: type.body, lineHeight: 22, marginBottom: space(5) },
	person: {
		flexDirection: 'row',
		alignItems: 'center',
		justifyContent: 'space-between',
		borderWidth: 1,
		borderColor: color.hairline,
		borderRadius: radius.control,
		backgroundColor: color.bg,
		paddingHorizontal: space(4),
		paddingVertical: space(4),
		marginBottom: space(2),
	},
	personName: { color: color.text, fontSize: type.title, fontWeight: '600' },
	roleTag: {
		color: color.accent,
		backgroundColor: color.attentionBg,
		fontSize: type.tiny,
		letterSpacing: 1,
		paddingHorizontal: space(2),
		paddingVertical: space(1),
		borderRadius: radius.pill,
		overflow: 'hidden',
	},
	dots: { flexDirection: 'row', justifyContent: 'center', gap: space(3), marginBottom: space(5) },
	dot: {
		width: 14,
		height: 14,
		borderRadius: radius.pill,
		borderWidth: 1,
		borderColor: color.hairline,
		backgroundColor: color.bg,
	},
	dotFilled: { backgroundColor: color.accentDeep, borderColor: color.accentDeep },
	pad: { flexDirection: 'row', flexWrap: 'wrap', gap: space(2) },
	key: {
		width: '31.5%',
		aspectRatio: 1.6,
		alignItems: 'center',
		justifyContent: 'center',
		borderWidth: 1,
		borderColor: color.hairline,
		borderRadius: radius.control,
		backgroundColor: color.bg,
	},
	keyText: { color: color.text, fontSize: type.display, fontWeight: '600' },
	pressed: { opacity: 0.75 },
	hint: { color: color.textSoft, fontSize: type.small, marginTop: space(2), lineHeight: 18 },
	link: {
		color: color.accentDeep,
		fontSize: type.small,
		textAlign: 'center',
		marginTop: space(4),
	},
	error: {
		color: color.danger,
		backgroundColor: color.dangerBg,
		borderRadius: radius.control,
		padding: space(3),
		marginBottom: space(4),
		fontSize: type.small,
		lineHeight: 18,
		textAlign: 'center',
	},
	button: {
		marginTop: space(5),
		backgroundColor: color.text,
		borderRadius: radius.control,
		paddingVertical: space(3.5),
		alignItems: 'center',
	},
	buttonDisabled: { opacity: 0.4 },
	buttonText: { color: color.surface, fontSize: type.body, fontWeight: '600', letterSpacing: 1 },
});
