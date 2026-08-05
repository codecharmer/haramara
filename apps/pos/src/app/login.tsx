import { ApiError } from '@haramara/api-client';
import React, { useState } from 'react';
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

import { useAuth } from '../lib/auth';
import { color, radius, space, type } from '../lib/theme';

const DEFAULT_URL = 'https://haramara.cafe';

export default function Login() {
	const { signIn } = useAuth();
	const [baseUrl, setBaseUrl] = useState(DEFAULT_URL);
	const [username, setUsername] = useState('');
	const [appPassword, setAppPassword] = useState('');
	const [busy, setBusy] = useState(false);
	const [error, setError] = useState<string | null>(null);

	const canSubmit = baseUrl.trim() !== '' && username.trim() !== '' && appPassword.trim() !== '' && !busy;

	async function submit() {
		if (!canSubmit) return;
		setBusy(true);
		setError(null);
		try {
			await signIn({
				baseUrl: baseUrl.trim(),
				username: username.trim(),
				appPassword: appPassword.trim(),
			});
			// El Gate del layout redirige al tablero al detectar la sesión.
		} catch (e) {
			if (e instanceof ApiError && (e.status === 401 || e.status === 403)) {
				setError('Usuario o contraseña de aplicación incorrectos.');
			} else if (e instanceof ApiError) {
				setError(e.message);
			} else {
				setError('No se pudo conectar con el servidor.');
			}
		} finally {
			setBusy(false);
		}
	}

	return (
		<KeyboardAvoidingView style={styles.screen} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
			<ScrollView contentContainerStyle={styles.scroll} keyboardShouldPersistTaps="handled">
				<View style={styles.card}>
					<Text style={styles.brand}>HARAMARA</Text>
					<Text style={styles.title}>Punto de venta</Text>
					<Text style={styles.lede}>
						Inicia sesión con la cuenta del equipo y una contraseña de aplicación de WordPress.
					</Text>

					<Text style={styles.label}>Servidor</Text>
					<TextInput
						style={styles.input}
						value={baseUrl}
						onChangeText={setBaseUrl}
						autoCapitalize="none"
						autoCorrect={false}
						keyboardType="url"
						placeholder={DEFAULT_URL}
						placeholderTextColor={color.textSoft}
					/>

					<Text style={styles.label}>Usuario</Text>
					<TextInput
						style={styles.input}
						value={username}
						onChangeText={setUsername}
						autoCapitalize="none"
						autoCorrect={false}
						placeholder="pos"
						placeholderTextColor={color.textSoft}
					/>

					<Text style={styles.label}>Contraseña de aplicación</Text>
					<TextInput
						style={styles.input}
						value={appPassword}
						onChangeText={setAppPassword}
						autoCapitalize="none"
						autoCorrect={false}
						secureTextEntry
						placeholder="xxxx xxxx xxxx xxxx"
						placeholderTextColor={color.textSoft}
						onSubmitEditing={submit}
					/>
					<Text style={styles.hint}>
						Se genera en WordPress: Usuarios → Perfil → Contraseñas de aplicación.
					</Text>

					{error !== null && <Text style={styles.error}>{error}</Text>}

					<Pressable
						accessibilityRole="button"
						onPress={submit}
						disabled={!canSubmit}
						style={({ pressed }) => [
							styles.button,
							!canSubmit && styles.buttonDisabled,
							pressed && styles.buttonPressed,
						]}
					>
						{busy ? (
							<ActivityIndicator color={color.surface} />
						) : (
							<Text style={styles.buttonText}>Entrar</Text>
						)}
					</Pressable>
				</View>
			</ScrollView>
		</KeyboardAvoidingView>
	);
}

const styles = StyleSheet.create({
	screen: { flex: 1, backgroundColor: color.bg },
	scroll: { flexGrow: 1, alignItems: 'center', justifyContent: 'center', padding: space(6) },
	card: {
		width: '100%',
		maxWidth: 440,
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
	lede: { color: color.textSoft, fontSize: type.body, lineHeight: 22, marginBottom: space(6) },
	label: { color: color.text, fontSize: type.small, fontWeight: '600', marginBottom: space(1), marginTop: space(4) },
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
	hint: { color: color.textSoft, fontSize: type.small, marginTop: space(2), lineHeight: 18 },
	error: {
		color: color.danger,
		backgroundColor: color.dangerBg,
		borderRadius: radius.control,
		padding: space(3),
		marginTop: space(4),
		fontSize: type.small,
		lineHeight: 18,
	},
	button: {
		marginTop: space(6),
		backgroundColor: color.text,
		borderRadius: radius.control,
		paddingVertical: space(3.5),
		alignItems: 'center',
	},
	buttonDisabled: { opacity: 0.4 },
	buttonPressed: { opacity: 0.85 },
	buttonText: { color: color.surface, fontSize: type.body, fontWeight: '600', letterSpacing: 1 },
});
