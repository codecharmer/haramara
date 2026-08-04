import MaterialCommunityIcons from '@expo/vector-icons/MaterialCommunityIcons';
import { CameraView, useCameraPermissions } from 'expo-camera';
import * as Haptics from 'expo-haptics';
import React, { useCallback, useRef, useState } from 'react';
import {
	ActivityIndicator,
	Platform,
	Pressable,
	StyleSheet,
	Text,
	TextInput,
	View,
} from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import type { LoyaltyCard } from '@haramara/api-client';
import { ApiError } from '@haramara/api-client';
import { usePosApi } from '../../lib/auth';
import { color, radius, space, type } from '../../lib/theme';

/** Signed card tokens are `key.hmac` — reject anything else before the API. */
const TOKEN_SHAPE = /^[a-z0-9]{8,64}\.[0-9a-f]{64}$/;

type Phase =
	| { kind: 'scanning' }
	| { kind: 'loading'; token: string }
	| { kind: 'card'; token: string; card: LoyaltyCard; justDid?: 'stamp' | 'redeem' }
	| { kind: 'error'; message: string };

export default function LoyaltyScanScreen() {
	const insets = useSafeAreaInsets();
	const api = usePosApi();
	const [permission, requestPermission] = useCameraPermissions();
	const [phase, setPhase] = useState<Phase>({ kind: 'scanning' });
	const [manual, setManual] = useState('');
	const [busy, setBusy] = useState(false);
	// The camera keeps firing while the result panel is open; this gate makes
	// each physical card read exactly once until "Escanear otra".
	const locked = useRef(false);

	const lookUp = useCallback(
		async (raw: string) => {
			const token = raw.trim();
			if (!TOKEN_SHAPE.test(token)) {
				setPhase({ kind: 'error', message: 'Ese código no es una tarjeta de lealtad de Haramara.' });
				return;
			}
			setPhase({ kind: 'loading', token });
			try {
				const card = await api.loyaltyCard(token);
				void Haptics.notificationAsync(Haptics.NotificationFeedbackType.Success);
				setPhase({ kind: 'card', token, card });
			} catch (err) {
				void Haptics.notificationAsync(Haptics.NotificationFeedbackType.Error);
				setPhase({
					kind: 'error',
					message:
						err instanceof ApiError && (err.status === 403 || err.status === 404)
							? 'Tarjeta no válida o no encontrada.'
							: 'No se pudo leer la tarjeta. Revisa la conexión.',
				});
			}
		},
		[api],
	);

	const onScanned = useCallback(
		({ data }: { data: string }) => {
			if (locked.current) return;
			locked.current = true;
			void lookUp(data);
		},
		[lookUp],
	);

	const act = useCallback(
		async (what: 'stamp' | 'redeem') => {
			if (phase.kind !== 'card' || busy) return;
			setBusy(true);
			try {
				const card =
					what === 'stamp' ? await api.loyaltyStamp(phase.token) : await api.loyaltyRedeem(phase.token);
				void Haptics.notificationAsync(Haptics.NotificationFeedbackType.Success);
				setPhase({ kind: 'card', token: phase.token, card, justDid: what });
			} catch {
				void Haptics.notificationAsync(Haptics.NotificationFeedbackType.Error);
				setPhase({ kind: 'error', message: 'No se pudo registrar. Inténtalo de nuevo.' });
			} finally {
				setBusy(false);
			}
		},
		[api, phase, busy],
	);

	const reset = useCallback(() => {
		locked.current = false;
		setManual('');
		setPhase({ kind: 'scanning' });
	}, []);

	const cameraAvailable = Platform.OS !== 'web' && permission?.granted;

	return (
		<View style={[styles.screen, { paddingTop: insets.top + space(3) }]}>
			<Text style={styles.title}>Lealtad</Text>

			{phase.kind === 'scanning' && (
				<>
					{cameraAvailable ? (
						<View style={styles.cameraWrap}>
							<CameraView
								style={StyleSheet.absoluteFill}
								facing="back"
								barcodeScannerSettings={{ barcodeTypes: ['qr'] }}
								onBarcodeScanned={onScanned}
							/>
							<View pointerEvents="none" style={styles.reticle} />
							<Text style={styles.cameraHint}>Apunta al QR de la tarjeta</Text>
						</View>
					) : (
						<View style={styles.cameraFallback}>
							{Platform.OS === 'web' ? (
								<Text style={styles.fallbackText}>
									La cámara vive en la tablet. Aquí puedes ingresar el código manualmente.
								</Text>
							) : (
								<>
									<Text style={styles.fallbackText}>
										El escáner necesita permiso para usar la cámara.
									</Text>
									<Pressable accessibilityRole="button" style={styles.primary} onPress={() => void requestPermission()}>
										<Text style={styles.primaryText}>Permitir cámara</Text>
									</Pressable>
								</>
							)}
						</View>
					)}

					<View style={styles.manualRow}>
						<TextInput
							style={styles.manualInput}
							value={manual}
							onChangeText={setManual}
							placeholder="…o pega el código de la tarjeta"
							placeholderTextColor={color.textSoft}
							autoCapitalize="none"
							autoCorrect={false}
						/>
						<Pressable
							accessibilityRole="button"
							style={[styles.manualGo, manual.trim() === '' && { opacity: 0.4 }]}
							disabled={manual.trim() === ''}
							onPress={() => {
								locked.current = true;
								void lookUp(manual);
							}}
						>
							<MaterialCommunityIcons name="arrow-right" size={22} color={color.bg} />
						</Pressable>
					</View>
				</>
			)}

			{phase.kind === 'loading' && (
				<View style={styles.panel}>
					<ActivityIndicator color={color.accent} />
					<Text style={styles.panelSoft}>Leyendo tarjeta…</Text>
				</View>
			)}

			{phase.kind === 'card' && (
				<View style={styles.panel}>
					<Text style={styles.member}>Tarjeta ·{phase.card.memberKey.slice(-6)}</Text>

					<View style={styles.counters}>
						<View style={styles.counter}>
							<Text style={styles.counterNumber}>{phase.card.stamps}</Text>
							<Text style={styles.counterLabel}>Sellos</Text>
						</View>
						<View style={styles.counterRule} />
						<View style={styles.counter}>
							<Text style={styles.counterNumber}>{phase.card.redeemed}</Text>
							<Text style={styles.counterLabel}>Canjes</Text>
						</View>
					</View>

					{phase.justDid && (
						<Text style={styles.done}>
							{phase.justDid === 'stamp' ? 'Visita sellada.' : 'Canje registrado.'}
						</Text>
					)}

					<Pressable
						accessibilityRole="button"
						style={[styles.primary, busy && { opacity: 0.6 }]}
						disabled={busy}
						onPress={() => void act('stamp')}
					>
						<Text style={styles.primaryText}>Sellar visita</Text>
					</Pressable>

					<Pressable
						accessibilityRole="button"
						style={[styles.ghost, busy && { opacity: 0.6 }]}
						disabled={busy}
						onPress={() => void act('redeem')}
					>
						<Text style={styles.ghostText}>Registrar canje</Text>
					</Pressable>

					<Pressable accessibilityRole="button" style={styles.again} onPress={reset}>
						<Text style={styles.againText}>Escanear otra</Text>
					</Pressable>
				</View>
			)}

			{phase.kind === 'error' && (
				<View style={styles.panel}>
					<Text style={styles.errorText}>{phase.message}</Text>
					<Pressable accessibilityRole="button" style={styles.primary} onPress={reset}>
						<Text style={styles.primaryText}>Volver a escanear</Text>
					</Pressable>
				</View>
			)}
		</View>
	);
}

const styles = StyleSheet.create({
	screen: { flex: 1, backgroundColor: color.bg, paddingHorizontal: space(5), gap: space(4) },
	title: { fontSize: type.display, fontWeight: '700', color: color.text },
	cameraWrap: {
		height: 340,
		borderRadius: radius.card,
		overflow: 'hidden',
		backgroundColor: color.surface,
		alignItems: 'center',
		justifyContent: 'flex-end',
	},
	reticle: {
		position: 'absolute',
		top: '50%',
		left: '50%',
		width: 200,
		height: 200,
		marginLeft: -100,
		marginTop: -110,
		borderWidth: 2,
		borderColor: color.accent,
	},
	cameraHint: {
		color: color.text,
		fontSize: type.small,
		paddingVertical: space(3),
		textShadowColor: color.bg,
		textShadowRadius: 6,
	},
	cameraFallback: {
		backgroundColor: color.surface,
		borderWidth: 1,
		borderColor: color.hairline,
		borderRadius: radius.card,
		padding: space(6),
		gap: space(4),
		alignItems: 'center',
	},
	fallbackText: { color: color.textSoft, fontSize: type.body, textAlign: 'center', lineHeight: 22 },
	manualRow: { flexDirection: 'row', gap: space(2), alignItems: 'center' },
	manualInput: {
		flex: 1,
		backgroundColor: color.surface,
		borderWidth: 1,
		borderColor: color.hairline,
		borderRadius: radius.control,
		color: color.text,
		paddingHorizontal: space(4),
		paddingVertical: space(3),
		fontSize: type.small,
	},
	manualGo: {
		width: 46,
		height: 46,
		borderRadius: radius.control,
		backgroundColor: color.accent,
		alignItems: 'center',
		justifyContent: 'center',
	},
	panel: {
		backgroundColor: color.surface,
		borderWidth: 1,
		borderColor: color.hairline,
		borderRadius: radius.card,
		padding: space(6),
		gap: space(4),
		alignItems: 'center',
	},
	panelSoft: { color: color.textSoft, fontSize: type.body },
	member: { color: color.textSoft, fontSize: type.small, letterSpacing: 1 },
	counters: { flexDirection: 'row', gap: space(10), alignItems: 'center' },
	counter: { alignItems: 'center', gap: space(1) },
	counterNumber: { fontSize: 46, fontWeight: '700', color: color.accent },
	counterLabel: {
		color: color.textSoft,
		fontSize: type.tiny,
		letterSpacing: 2,
		textTransform: 'uppercase',
	},
	counterRule: { width: 1, alignSelf: 'stretch', backgroundColor: color.hairline },
	done: { color: color.accentDeep, fontSize: type.body, fontWeight: '600' },
	primary: {
		backgroundColor: color.accent,
		borderRadius: radius.control,
		paddingVertical: space(3.5),
		paddingHorizontal: space(8),
		alignItems: 'center',
		alignSelf: 'stretch',
	},
	primaryText: { color: color.bg, fontWeight: '700', fontSize: type.body },
	ghost: {
		borderWidth: 1,
		borderColor: color.accent,
		borderRadius: radius.control,
		paddingVertical: space(3.5),
		paddingHorizontal: space(8),
		alignItems: 'center',
		alignSelf: 'stretch',
	},
	ghostText: { color: color.accent, fontWeight: '600', fontSize: type.body },
	again: { paddingVertical: space(2) },
	againText: { color: color.textSoft, fontSize: type.small, letterSpacing: 1, textTransform: 'uppercase' },
	errorText: { color: color.danger, fontSize: type.body, textAlign: 'center', lineHeight: 22 },
});
