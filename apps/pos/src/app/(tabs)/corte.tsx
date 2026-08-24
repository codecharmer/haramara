/**
 * Corte del día — the drawer, not just a report.
 *
 * Top card is the turno: open it with a counted fondo inicial, record mid-shift
 * retiros, and close it against a BLIND count — the cashier declares the
 * physical drawer first and only the close response reveals expected cash and
 * the variance. While a shift is open the server also redacts everything cash
 * could be derived from in the daily summary (`cash_visible: false`) unless a
 * supervisor is signed in; this screen renders that state honestly instead of
 * pretending the numbers are missing by accident.
 */

import { newIdempotencyKey, type Shift } from '@haramara/api-client';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import React, { useState } from 'react';
import {
	ActivityIndicator,
	Pressable,
	RefreshControl,
	ScrollView,
	StyleSheet,
	Text,
	TextInput,
	View,
} from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { useAuth, usePosApi } from '../../lib/auth';
import { confirmDialog, notify } from '../../lib/dialog';
import { useOperator } from '../../lib/operator';
import { color, DESTINATION_LABELS, money, radius, space, type } from '../../lib/theme';

const CHANNEL_LABELS: Record<string, string> = {
	pickup_online: 'Pedidos en línea',
	walkin_cash: 'Mostrador · efectivo',
	walkin_card_external: 'Mostrador · tarjeta',
};

const TYPE_LABELS: Record<string, string> = {
	void: 'Cancelaciones',
	refund: 'Devoluciones',
	discount: 'Descuentos',
	comp: 'Cortesías',
	cash_drop: 'Retiros de efectivo',
	no_sale: 'Aperturas sin venta',
	reprint: 'Reimpresiones',
};

const PAYMENT_LABELS: Record<string, string> = {
	cod: 'Paga al recoger / mostrador',
	stripe: 'Tarjeta (Stripe)',
	other: 'Otros',
};

/** Parse "1,234.50" / "1234.5" into a float, NaN when unusable. */
function parseAmount(raw: string): number {
	return Number.parseFloat(raw.replace(/[^0-9.]/g, ''));
}

export default function CorteScreen() {
	const api = usePosApi();
	const queryClient = useQueryClient();
	const { session, signOut } = useAuth();
	const { operator, lockRequired, lock } = useOperator();
	const insets = useSafeAreaInsets();

	const summary = useQuery({
		queryKey: ['summary'],
		queryFn: () => api.summary(),
		refetchInterval: 60_000,
	});
	const shiftQuery = useQuery({
		queryKey: ['shift'],
		queryFn: () => api.shift(),
		refetchInterval: 60_000,
	});
	const historyQuery = useQuery({
		queryKey: ['shifts'],
		queryFn: () => api.shifts(),
		refetchInterval: 300_000,
	});

	const data = summary.data;
	const shift = shiftQuery.data ?? null;

	// One key per intent, re-minted after each success (see mostrador).
	const [actionKey, setActionKey] = useState(() => newIdempotencyKey());
	const [floatInput, setFloatInput] = useState('');
	const [declaredInput, setDeclaredInput] = useState('');
	const [closeNote, setCloseNote] = useState('');
	const [dropInput, setDropInput] = useState('');
	const [dropOpen, setDropOpen] = useState(false);
	const [closing, setClosing] = useState(false);
	const [lastClosed, setLastClosed] = useState<Shift | null>(null);

	function settled() {
		setActionKey(newIdempotencyKey());
		void queryClient.invalidateQueries({ queryKey: ['shift'] });
		void queryClient.invalidateQueries({ queryKey: ['shifts'] });
		void queryClient.invalidateQueries({ queryKey: ['summary'] });
	}

	const openShift = useMutation({
		mutationFn: (openingFloat: number) => api.openShift(openingFloat, actionKey),
		onSuccess: () => {
			setFloatInput('');
			setLastClosed(null);
			settled();
		},
		onError: (e) => notify('No se pudo abrir el turno', e instanceof Error ? e.message : 'Intenta de nuevo.'),
	});

	const closeShift = useMutation({
		mutationFn: (declared: number) => api.closeShift(declared, closeNote.trim() || undefined, actionKey),
		onSuccess: (closed) => {
			setDeclaredInput('');
			setCloseNote('');
			setClosing(false);
			setLastClosed(closed);
			settled();
		},
		onError: (e) => notify('No se pudo cerrar el turno', e instanceof Error ? e.message : 'Intenta de nuevo.'),
	});

	const cashDrop = useMutation({
		mutationFn: (amount: number) => api.cashDrop(amount, undefined, actionKey),
		onSuccess: (event) => {
			setDropInput('');
			setDropOpen(false);
			notify('Retiro registrado', money(event.amount));
			settled();
		},
		onError: (e) => notify('No se pudo registrar', e instanceof Error ? e.message : 'Intenta de nuevo.'),
	});

	function submitOpen() {
		const amount = parseAmount(floatInput);
		if (Number.isNaN(amount) || amount < 0 || openShift.isPending) return;
		confirmDialog({
			title: `Abrir turno con ${money(amount)}`,
			message: 'Cuenta el fondo inicial antes de confirmar.',
			confirmText: 'Abrir turno',
			onConfirm: () => openShift.mutate(amount),
		});
	}

	function submitClose() {
		const amount = parseAmount(declaredInput);
		if (Number.isNaN(amount) || amount < 0 || closeShift.isPending) return;
		confirmDialog({
			title: `Declarar ${money(amount)}`,
			message: 'El sistema comparará contra lo esperado DESPUÉS de declarar. No se puede corregir.',
			confirmText: 'Cerrar turno',
			destructive: true,
			onConfirm: () => closeShift.mutate(amount),
		});
	}

	function submitDrop() {
		const amount = parseAmount(dropInput);
		if (Number.isNaN(amount) || amount <= 0 || cashDrop.isPending) return;
		confirmDialog({
			title: `Retirar ${money(amount)} de la caja`,
			message: 'Queda registrado en el turno y se descuenta del corte.',
			confirmText: 'Registrar retiro',
			onConfirm: () => cashDrop.mutate(amount),
		});
	}

	function confirmSignOut() {
		confirmDialog({
			title: 'Cerrar sesión',
			message: 'La tableta pedirá la contraseña de aplicación de nuevo.',
			confirmText: 'Cerrar sesión',
			destructive: true,
			onConfirm: () => void signOut(),
		});
	}

	const cashHidden = data ? data.cash_visible === false : false;
	const history = (historyQuery.data ?? []).filter((s) => s.status === 'closed');

	return (
		<View style={[styles.screen, { paddingTop: insets.top }]}>
			<View style={styles.header}>
				<Text style={styles.title}>Corte del día</Text>
				{data && <Text style={styles.subtitle}>{data.date}</Text>}
			</View>

			{summary.isLoading || shiftQuery.isLoading ? (
				<View style={styles.center}>
					<ActivityIndicator size="large" color={color.accentDeep} />
				</View>
			) : summary.isError ? (
				<View style={styles.center}>
					<Text style={styles.emptyText}>No se pudo cargar el corte.</Text>
					<Pressable style={styles.retry} onPress={() => summary.refetch()}>
						<Text style={styles.retryText}>Reintentar</Text>
					</Pressable>
				</View>
			) : data ? (
				<ScrollView
					contentContainerStyle={styles.list}
					refreshControl={
						<RefreshControl
							refreshing={summary.isRefetching}
							onRefresh={() => {
								void summary.refetch();
								void shiftQuery.refetch();
								void historyQuery.refetch();
							}}
							tintColor={color.accentDeep}
						/>
					}
					keyboardShouldPersistTaps="handled"
				>
					{/* ---- Turno ---- */}
					<View style={styles.receipt}>
						<Text style={styles.sectionHeading}>Turno de caja</Text>

						{shift === null && (
							<>
								{lastClosed && <ClosedResult shift={lastClosed} />}
								<Text style={styles.turnoHint}>
									Sin turno abierto. Cuenta el fondo inicial y ábrelo para que el corte
									compare contra el efectivo real.
								</Text>
								<View style={styles.inline}>
									<TextInput
										style={styles.amountInput}
										value={floatInput}
										onChangeText={setFloatInput}
										keyboardType="decimal-pad"
										placeholder="Fondo inicial"
										placeholderTextColor={color.textSoft}
									/>
									<Pressable
										accessibilityRole="button"
										onPress={submitOpen}
										disabled={openShift.isPending}
										style={({ pressed }) => [styles.primary, pressed && styles.pressed]}
									>
										{openShift.isPending ? (
											<ActivityIndicator color={color.surface} />
										) : (
											<Text style={styles.primaryText}>Abrir turno</Text>
										)}
									</Pressable>
								</View>
							</>
						)}

						{shift !== null && !closing && (
							<>
								<Row label={`Abierto por ${shift.opened_by || '—'}`} value={shift.opened_at.slice(11, 16)} />
								<Row label="Fondo inicial" value={money(shift.opening_float)} />
								{shift.cash_drops > 0 && <Row label="Retiros del turno" value={`− ${money(shift.cash_drops)}`} />}

								{dropOpen ? (
									<View style={styles.inline}>
										<TextInput
											style={styles.amountInput}
											value={dropInput}
											onChangeText={setDropInput}
											keyboardType="decimal-pad"
											placeholder="Cantidad a retirar"
											placeholderTextColor={color.textSoft}
											autoFocus
										/>
										<Pressable accessibilityRole="button" onPress={submitDrop} style={({ pressed }) => [styles.primary, pressed && styles.pressed]}>
											<Text style={styles.primaryText}>Retirar</Text>
										</Pressable>
										<Pressable accessibilityRole="button" onPress={() => setDropOpen(false)}>
											<Text style={styles.link}>Cancelar</Text>
										</Pressable>
									</View>
								) : (
									<View style={styles.inline}>
										<Pressable
											accessibilityRole="button"
											onPress={() => setDropOpen(true)}
											style={({ pressed }) => [styles.secondary, pressed && styles.pressed]}
										>
											<Text style={styles.secondaryText}>Retiro de efectivo</Text>
										</Pressable>
										<Pressable
											accessibilityRole="button"
											onPress={() => setClosing(true)}
											style={({ pressed }) => [styles.secondaryDanger, pressed && styles.pressed]}
										>
											<Text style={styles.secondaryDangerText}>Cerrar turno</Text>
										</Pressable>
									</View>
								)}
							</>
						)}

						{shift !== null && closing && (
							<>
								<Text style={styles.turnoHint}>
									Cuenta TODO el efectivo del cajón y decláralo. Lo esperado se revela
									después de declarar — no antes.
								</Text>
								<TextInput
									style={[styles.amountInput, styles.blockInput]}
									value={declaredInput}
									onChangeText={setDeclaredInput}
									keyboardType="decimal-pad"
									placeholder="Efectivo contado"
									placeholderTextColor={color.textSoft}
									autoFocus
								/>
								<TextInput
									style={[styles.amountInput, styles.blockInput]}
									value={closeNote}
									onChangeText={setCloseNote}
									placeholder="Nota (opcional)"
									placeholderTextColor={color.textSoft}
								/>
								<View style={styles.inline}>
									<Pressable
										accessibilityRole="button"
										onPress={submitClose}
										disabled={closeShift.isPending}
										style={({ pressed }) => [styles.primary, pressed && styles.pressed]}
									>
										{closeShift.isPending ? (
											<ActivityIndicator color={color.surface} />
										) : (
											<Text style={styles.primaryText}>Declarar y cerrar</Text>
										)}
									</Pressable>
									<Pressable accessibilityRole="button" onPress={() => setClosing(false)}>
										<Text style={styles.link}>Volver</Text>
									</Pressable>
								</View>
							</>
						)}
					</View>

					{/* ---- Resumen ---- */}
					<View style={styles.receipt}>
						{cashHidden ? (
							<>
								<View style={styles.totalRow}>
									<Text style={styles.totalLabel}>Ingresos del día</Text>
									<Text style={styles.hiddenValue}>— oculto —</Text>
								</View>
								<Text style={styles.totalMeta}>
									{data.orders_total} {data.orders_total === 1 ? 'pedido' : 'pedidos'} · el
									efectivo se muestra al cerrar el turno
								</Text>
							</>
						) : (
							<>
								<View style={styles.totalRow}>
									<Text style={styles.totalLabel}>Ingresos del día</Text>
									<Text style={styles.totalValue}>{money(data.revenue ?? 0)}</Text>
								</View>
								<Text style={styles.totalMeta}>
									{data.orders_total} {data.orders_total === 1 ? 'pedido' : 'pedidos'}
								</Text>
							</>
						)}

						<Text style={styles.sectionHeading}>Por canal</Text>
						{Object.entries(data.by_channel).map(([key, bucket]) => (
							<Row
								key={key}
								label={`${CHANNEL_LABELS[key] ?? key} (${bucket.count})`}
								value={money(bucket.revenue)}
							/>
						))}
						{cashHidden && <Row label="Mostrador · efectivo" value="oculto" muted />}
						{Object.keys(data.by_channel).length === 0 && !cashHidden && (
							<Text style={styles.emptyText}>Sin ventas registradas hoy.</Text>
						)}

						<Text style={styles.sectionHeading}>Por forma de pago</Text>
						{Object.entries(data.by_payment_method).map(([key, bucket]) => (
							<Row
								key={key}
								label={`${PAYMENT_LABELS[key] ?? key} (${bucket.count})`}
								value={money(bucket.revenue)}
							/>
						))}
						{cashHidden && <Row label="Efectivo" value="oculto" muted />}

						{data.top_items.length > 0 && (
							<>
								<Text style={styles.sectionHeading}>Más vendidos</Text>
								{data.top_items.map((item) => (
									<Row key={item.name} label={item.name} value={`${item.quantity} pz`} />
								))}
							</>
						)}

						{data.tips && data.tips.total > 0 && (
							<>
								<Text style={styles.sectionHeading}>Propinas</Text>
								{Object.entries(data.tips.by_operator).map(([who, amount]) => (
									<Row key={who} label={who} value={money(amount)} />
								))}
								<Row
									label={`Total (efvo. ${money(data.tips.by_method.cash ?? 0)} · tarj. ${money(data.tips.by_method.card ?? 0)})`}
									value={money(data.tips.total)}
								/>
								<Text style={styles.withdrawalNote}>
									Las propinas no se cuentan como ingresos. Las de efectivo entran al
									corte de caja.
								</Text>
							</>
						)}

						{data.adjustments && Object.keys(data.adjustments.by_type).length > 0 && (
							<>
								<Text style={styles.sectionHeading}>Ajustes del día</Text>
								{Object.entries(data.adjustments.by_type).map(([t, b]) =>
									b ? (
										<Row
											key={t}
											label={`${TYPE_LABELS[t] ?? t} (${b.count})`}
											value={money(b.value)}
											valueColor={t === 'cash_drop' ? undefined : color.danger}
										/>
									) : null,
								)}
								<Text style={styles.withdrawalNote}>
									Cada ajuste queda en el registro con operador y motivo. Nunca se
									restan en silencio de los ingresos.
								</Text>
							</>
						)}

						{data.withdrawals && data.withdrawals.pieces > 0 && (
							<>
								<Text style={styles.sectionHeading}>Salidas internas</Text>
								{Object.entries(data.withdrawals.by_destination).map(([key, bucket]) => (
									<Row
										key={key}
										label={DESTINATION_LABELS[key] ?? key}
										value={`${bucket.pieces} pz · ${money(bucket.value)}`}
									/>
								))}
								<Row
									label="Total salidas"
									value={`${data.withdrawals.pieces} pz · ${money(data.withdrawals.value)}`}
								/>
								<Text style={styles.withdrawalNote}>
									Valuadas a precio de venta. No se cuentan como ingresos.
								</Text>
							</>
						)}
					</View>

					{/* ---- Historial de cortes ---- */}
					{history.length > 0 && (
						<View style={styles.receipt}>
							<Text style={styles.sectionHeading}>Cortes anteriores</Text>
							{history.map((s) => (
								<Row
									key={s.id}
									label={`${s.closed_at?.slice(0, 16).replace('T', ' ') ?? s.opened_at} · ${s.closed_by || s.opened_by || '—'}`}
									value={varianceLabel(s)}
									valueColor={varianceColor(s)}
								/>
							))}
						</View>
					)}

					{operator !== false && operator !== null && (
						<View style={styles.session}>
							<Text style={styles.sessionText}>
								En barra: <Text style={styles.operatorName}>{operator.name}</Text>
								{operator.role === 'supervisor' ? ' · Supervisor' : ''}
							</Text>
							<Pressable accessibilityRole="button" onPress={() => void lock()} style={styles.signOut}>
								<Text style={styles.lockText}>Bloquear</Text>
							</Pressable>
						</View>
					)}

					{lockRequired && operator === false && (
						<Text style={styles.sessionText}>
							Nadie ha ingresado su NIP: esta venta no quedará atribuida.
						</Text>
					)}

					<View style={styles.session}>
						<Text style={styles.sessionText}>
							Conectado a {session ? session.baseUrl : ''} como {session ? session.username : ''}
						</Text>
						<Pressable accessibilityRole="button" onPress={confirmSignOut} style={styles.signOut}>
							<Text style={styles.signOutText}>Cerrar sesión</Text>
						</Pressable>
					</View>
				</ScrollView>
			) : null}
		</View>
	);
}

function varianceLabel(s: Shift): string {
	if (typeof s.variance !== 'number') return '—';
	if (s.variance === 0) return 'exacto';
	return `${s.variance > 0 ? '+' : '−'}${money(Math.abs(s.variance))}`;
}

function varianceColor(s: Shift): string | undefined {
	if (typeof s.variance !== 'number' || s.variance === 0) return color.good;
	return s.variance < 0 ? color.danger : color.attention;
}

/** The arqueo result, shown once right after closing. */
function ClosedResult({ shift }: { shift: Shift }) {
	return (
		<View style={[styles.result, (shift.variance ?? 0) < 0 ? styles.resultBad : styles.resultGood]}>
			<Row label="Esperado" value={money(shift.expected_cash ?? 0)} />
			<Row label="Declarado" value={money(shift.declared_cash ?? 0)} />
			<Row label="Diferencia" value={varianceLabel(shift)} valueColor={varianceColor(shift)} />
		</View>
	);
}

function Row({
	label,
	value,
	valueColor,
	muted,
}: {
	label: string;
	value: string;
	valueColor?: string;
	muted?: boolean;
}) {
	return (
		<View style={styles.row}>
			<Text style={[styles.rowLabel, muted && styles.rowMuted]} numberOfLines={1}>
				{label}
			</Text>
			<View style={styles.rowRule} />
			<Text style={[styles.rowValue, valueColor ? { color: valueColor } : null, muted && styles.rowMuted]}>
				{value}
			</Text>
		</View>
	);
}

const styles = StyleSheet.create({
	screen: { flex: 1, backgroundColor: color.bg },
	header: { paddingHorizontal: space(5), paddingTop: space(4), paddingBottom: space(2) },
	title: { color: color.text, fontSize: type.display, fontWeight: '700' },
	subtitle: { color: color.textSoft, fontSize: type.body, marginTop: space(1) },
	center: { flex: 1, alignItems: 'center', justifyContent: 'center', padding: space(8), gap: space(3) },
	list: { padding: space(5), paddingBottom: space(10), gap: space(5), maxWidth: 680, width: '100%', alignSelf: 'center' },
	receipt: {
		backgroundColor: color.surface,
		borderRadius: radius.card,
		borderWidth: 1,
		borderColor: color.hairline,
		padding: space(6),
		gap: space(2),
	},
	totalRow: { flexDirection: 'row', alignItems: 'baseline', justifyContent: 'space-between', gap: space(3) },
	totalLabel: { color: color.textSoft, fontSize: type.body },
	totalValue: { color: color.text, fontSize: 34, fontWeight: '700', fontVariant: ['tabular-nums'] },
	hiddenValue: { color: color.textSoft, fontSize: type.title, fontWeight: '600' },
	totalMeta: { color: color.textSoft, fontSize: type.small },
	sectionHeading: {
		color: color.accentDeep,
		fontSize: type.small,
		fontWeight: '700',
		marginTop: space(2),
		marginBottom: space(1),
	},
	turnoHint: { color: color.textSoft, fontSize: type.small, lineHeight: 19 },
	inline: { flexDirection: 'row', alignItems: 'center', gap: space(3), marginTop: space(2), flexWrap: 'wrap' },
	amountInput: {
		flexGrow: 1,
		minWidth: 140,
		borderWidth: 1,
		borderColor: color.hairline,
		borderRadius: radius.control,
		backgroundColor: color.bg,
		paddingHorizontal: space(3),
		paddingVertical: space(2.5),
		fontSize: type.body,
		color: color.text,
		fontVariant: ['tabular-nums'],
	},
	blockInput: { marginTop: space(2), flexGrow: 0, width: '100%' },
	primary: {
		backgroundColor: color.text,
		borderRadius: radius.control,
		paddingHorizontal: space(5),
		paddingVertical: space(2.5),
		alignItems: 'center',
	},
	primaryText: { color: color.surface, fontSize: type.small, fontWeight: '700' },
	secondary: {
		borderWidth: 1,
		borderColor: color.hairline,
		borderRadius: radius.control,
		paddingHorizontal: space(4),
		paddingVertical: space(2.5),
		backgroundColor: color.bg,
	},
	secondaryText: { color: color.text, fontSize: type.small, fontWeight: '600' },
	secondaryDanger: {
		borderWidth: 1,
		borderColor: color.dangerBg,
		borderRadius: radius.control,
		paddingHorizontal: space(4),
		paddingVertical: space(2.5),
		backgroundColor: color.dangerBg,
	},
	secondaryDangerText: { color: color.danger, fontSize: type.small, fontWeight: '700' },
	result: {
		borderRadius: radius.control,
		borderWidth: 1,
		padding: space(3),
		marginBottom: space(2),
	},
	resultGood: { borderColor: color.good, backgroundColor: color.goodBg },
	resultBad: { borderColor: color.danger, backgroundColor: color.dangerBg },
	row: { flexDirection: 'row', alignItems: 'baseline', gap: space(2), paddingVertical: space(1.5) },
	rowLabel: { color: color.text, fontSize: type.body, flexShrink: 1 },
	rowMuted: { color: color.textSoft, fontStyle: 'italic' },
	rowRule: { flex: 1, borderBottomWidth: 1, borderBottomColor: color.hairline, borderStyle: 'dotted' },
	rowValue: { color: color.text, fontSize: type.body, fontWeight: '600', fontVariant: ['tabular-nums'] },
	pressed: { opacity: 0.75 },
	withdrawalNote: { color: color.textSoft, fontSize: type.tiny, marginTop: space(1) },
	emptyText: { color: color.textSoft, fontSize: type.body, lineHeight: 22 },
	retry: {
		backgroundColor: color.text,
		borderRadius: radius.control,
		paddingHorizontal: space(5),
		paddingVertical: space(2.5),
	},
	retryText: { color: color.surface, fontWeight: '700', fontSize: type.small },
	link: { color: color.accentDeep, fontSize: type.small, fontWeight: '600' },
	session: {
		flexDirection: 'row',
		alignItems: 'center',
		justifyContent: 'space-between',
		gap: space(3),
		flexWrap: 'wrap',
	},
	sessionText: { color: color.textSoft, fontSize: type.small, flexShrink: 1 },
	signOut: {
		borderWidth: 1,
		borderColor: color.hairline,
		borderRadius: radius.control,
		paddingHorizontal: space(4),
		paddingVertical: space(2),
		backgroundColor: color.surface,
	},
	signOutText: { color: color.danger, fontSize: type.small, fontWeight: '600' },
	lockText: { color: color.accentDeep, fontSize: type.small, fontWeight: '600' },
	operatorName: { color: color.text, fontWeight: '700' },
});
