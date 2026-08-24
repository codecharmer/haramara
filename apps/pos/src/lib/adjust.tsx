/**
 * Shared UI for the adjustments ledger: the reason picker every void/discount
 * must pass through, and the supervisor step-up sheet that turns a NIP into a
 * single-action authorization.
 *
 * The server is the authority on when an authorization is required — these
 * components exist so the flows FEEL cheap while staying mandatory: reason
 * first, PIN only when the police says so (the caller retries on
 * `haramara_authorization_required`).
 */

import { ApiError, type AdjustmentReason, type Operator } from '@haramara/api-client';
import React, { useState } from 'react';
import { ActivityIndicator, Pressable, ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';

import { color, radius, space, type } from './theme';

export const REASON_LABELS: Record<AdjustmentReason, string> = {
	error_captura: 'Error de captura',
	cliente_cancelo: 'El cliente canceló',
	producto_mal_hecho: 'Producto mal hecho',
	cortesia: 'Cortesía',
	ajuste_precio: 'Ajuste de precio',
	retiro_efectivo: 'Retiro de efectivo',
	otro: 'Otro',
};

/** The reasons offered for a given flow (retiro is shift-only, never picked here). */
export function reasonsFor(flow: 'void' | 'discount'): AdjustmentReason[] {
	return flow === 'void'
		? ['error_captura', 'cliente_cancelo', 'producto_mal_hecho', 'otro']
		: ['ajuste_precio', 'cortesia', 'producto_mal_hecho', 'otro'];
}

/** True when the server said "this needs a supervisor". */
export function needsAuthorization(e: unknown): boolean {
	return e instanceof ApiError && e.code === 'haramara_authorization_required';
}

interface ReasonSheetProps {
	title: string;
	flow: 'void' | 'discount';
	busy?: boolean;
	onConfirm: (reason: AdjustmentReason, note: string) => void;
	onCancel: () => void;
}

/** Mandatory reason + note ('otro' demands the note server-side too). */
export function ReasonSheet({ title, flow, busy, onConfirm, onCancel }: ReasonSheetProps) {
	const [reason, setReason] = useState<AdjustmentReason | null>(null);
	const [note, setNote] = useState('');

	const canConfirm = reason !== null && (reason !== 'otro' || note.trim() !== '') && !busy;

	return (
		<View style={styles.overlay}>
			<View style={styles.card}>
				<Text style={styles.title}>{title}</Text>
				<Text style={styles.hint}>El motivo queda registrado y no se puede editar.</Text>

				{reasonsFor(flow).map((r) => (
					<Pressable
						key={r}
						accessibilityRole="button"
						onPress={() => setReason(r)}
						style={({ pressed }) => [styles.option, reason === r && styles.optionActive, pressed && styles.pressed]}
					>
						<Text style={[styles.optionText, reason === r && styles.optionTextActive]}>{REASON_LABELS[r]}</Text>
					</Pressable>
				))}

				<TextInput
					style={styles.input}
					value={note}
					onChangeText={setNote}
					placeholder={reason === 'otro' ? 'Detalle (obligatorio)' : 'Nota (opcional)'}
					placeholderTextColor={color.textSoft}
				/>

				<View style={styles.row}>
					<Pressable
						accessibilityRole="button"
						disabled={!canConfirm}
						onPress={() => reason !== null && onConfirm(reason, note.trim())}
						style={({ pressed }) => [styles.primary, !canConfirm && styles.disabled, pressed && styles.pressed]}
					>
						{busy ? <ActivityIndicator color={color.surface} /> : <Text style={styles.primaryText}>Confirmar</Text>}
					</Pressable>
					<Pressable accessibilityRole="button" onPress={onCancel}>
						<Text style={styles.link}>Cancelar</Text>
					</Pressable>
				</View>
			</View>
		</View>
	);
}

interface SupervisorSheetProps {
	/** Human phrasing of what is being approved ("cancelar el pedido #45"). */
	actionLabel: string;
	/** The server-side action the authorization binds to. */
	action: 'void' | 'refund' | 'discount';
	supervisors: Operator[];
	authorize: (operatorKey: string, pin: string, action: string) => Promise<{ authorization: string }>;
	onAuthorized: (authorization: string) => void;
	onCancel: () => void;
}

/** Supervisor NIP → a single-action authorization for the caller to retry with. */
export function SupervisorSheet({ actionLabel, action, supervisors, authorize, onAuthorized, onCancel }: SupervisorSheetProps) {
	const [selected, setSelected] = useState<string | null>(supervisors.length === 1 ? supervisors[0].key : null);
	const [pin, setPin] = useState('');
	const [busy, setBusy] = useState(false);
	const [error, setError] = useState<string | null>(null);

	async function submit() {
		if (selected === null || pin.length < 4 || busy) return;
		setBusy(true);
		setError(null);
		try {
			const result = await authorize(selected, pin, action);
			onAuthorized(result.authorization);
		} catch (e) {
			setPin('');
			setError(e instanceof ApiError ? e.message : 'No se pudo autorizar.');
		} finally {
			setBusy(false);
		}
	}

	return (
		<View style={styles.overlay}>
			<View style={styles.card}>
				<Text style={styles.title}>Autorización de supervisor</Text>
				<Text style={styles.hint}>Se necesita un supervisor para {actionLabel}.</Text>

				{supervisors.length === 0 && (
					<Text style={styles.error}>No hay supervisores con NIP configurado.</Text>
				)}

				<ScrollView style={{ maxHeight: 180 }}>
					{supervisors.map((s) => (
						<Pressable
							key={s.key}
							accessibilityRole="button"
							onPress={() => setSelected(s.key)}
							style={({ pressed }) => [styles.option, selected === s.key && styles.optionActive, pressed && styles.pressed]}
						>
							<Text style={[styles.optionText, selected === s.key && styles.optionTextActive]}>{s.name}</Text>
						</Pressable>
					))}
				</ScrollView>

				<TextInput
					style={styles.input}
					value={pin}
					onChangeText={(t) => setPin(t.replace(/\D/g, '').slice(0, 6))}
					keyboardType="number-pad"
					secureTextEntry
					placeholder="NIP del supervisor"
					placeholderTextColor={color.textSoft}
					onSubmitEditing={() => void submit()}
				/>

				{error !== null && <Text style={styles.error}>{error}</Text>}

				<View style={styles.row}>
					<Pressable
						accessibilityRole="button"
						disabled={selected === null || pin.length < 4 || busy}
						onPress={() => void submit()}
						style={({ pressed }) => [
							styles.primary,
							(selected === null || pin.length < 4 || busy) && styles.disabled,
							pressed && styles.pressed,
						]}
					>
						{busy ? <ActivityIndicator color={color.surface} /> : <Text style={styles.primaryText}>Autorizar</Text>}
					</Pressable>
					<Pressable accessibilityRole="button" onPress={onCancel}>
						<Text style={styles.link}>Cancelar</Text>
					</Pressable>
				</View>
			</View>
		</View>
	);
}

const styles = StyleSheet.create({
	overlay: {
		position: 'absolute',
		top: 0,
		right: 0,
		bottom: 0,
		left: 0,
		backgroundColor: 'rgba(0,0,0,0.55)',
		alignItems: 'center',
		justifyContent: 'center',
		padding: space(5),
		zIndex: 10,
	},
	card: {
		width: '100%',
		maxWidth: 440,
		backgroundColor: color.surface,
		borderRadius: radius.card,
		borderWidth: 1,
		borderColor: color.hairline,
		padding: space(5),
		gap: space(2),
	},
	title: { color: color.text, fontSize: type.title, fontWeight: '700' },
	hint: { color: color.textSoft, fontSize: type.small, lineHeight: 19, marginBottom: space(1) },
	option: {
		borderWidth: 1,
		borderColor: color.hairline,
		borderRadius: radius.control,
		backgroundColor: color.bg,
		paddingHorizontal: space(3),
		paddingVertical: space(2.5),
		marginBottom: space(1.5),
	},
	optionActive: { borderColor: color.accentDeep, backgroundColor: color.attentionBg },
	optionText: { color: color.text, fontSize: type.body },
	optionTextActive: { color: color.accentDeep, fontWeight: '700' },
	input: {
		borderWidth: 1,
		borderColor: color.hairline,
		borderRadius: radius.control,
		backgroundColor: color.bg,
		paddingHorizontal: space(3),
		paddingVertical: space(2.5),
		fontSize: type.body,
		color: color.text,
		marginTop: space(1),
	},
	row: { flexDirection: 'row', alignItems: 'center', gap: space(4), marginTop: space(3) },
	primary: {
		backgroundColor: color.text,
		borderRadius: radius.control,
		paddingHorizontal: space(5),
		paddingVertical: space(2.5),
		alignItems: 'center',
	},
	primaryText: { color: color.surface, fontSize: type.small, fontWeight: '700' },
	disabled: { opacity: 0.4 },
	pressed: { opacity: 0.8 },
	link: { color: color.accentDeep, fontSize: type.small, fontWeight: '600' },
	error: {
		color: color.danger,
		backgroundColor: color.dangerBg,
		borderRadius: radius.control,
		padding: space(2.5),
		fontSize: type.small,
	},
});
