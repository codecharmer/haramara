/**
 * Customer-app port of the POS ModifierSheet — same skippability contract,
 * shared api-client types, customer theme tokens.
 *
 * ModifierSheet — pick a product's modifiers before it lands on the ticket.
 *
 * Self-contained Phase 4 component; nothing imports it yet (integration into
 * mostrador.tsx is a documented seam — see docs/phase4-integration.md). The
 * group shape mirrors GET /haramara/v1/pos/modifier-groups and is typed
 * locally until `ModifierGroup` lands in @haramara/api-client.
 *
 * Selection contract (mirrors Catalog\ModifierApplication::validate on the
 * server): a required group needs at least max(min, 1) options; an optional
 * group can be skipped entirely, but once one option is chosen its min/max
 * apply. `price_delta` is per UNIT — the caller multiplies by the line
 * quantity when totaling the ticket.
 */

import { useMemo, useState } from 'react';
import { Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';

import { color, money, radius, space, type } from '../lib/theme';

/** One selectable option inside a group. `price_delta` in MXN, per unit. */
import type { ModifierGroup, ModifierSelection } from '@haramara/api-client';

/** Local alias kept for prop compatibility with the POS sheet. */
export type Selection = ModifierSelection;

interface ModifierSheetProps {
	groups: ModifierGroup[];
	/** Called with only the engaged groups; `priceDelta` is the per-unit sum. */
	onConfirm: (selections: Selection[], priceDelta: number) => void;
	onCancel: () => void;
}

/** Effective minimum once engaged — a required group always needs one pick. */
function effectiveMin(group: ModifierGroup): number {
	return group.required ? Math.max(group.min, 1) : group.min;
}

function isSatisfied(group: ModifierGroup, keys: string[]): boolean {
	if (keys.length === 0) return !group.required;
	return keys.length >= group.min && (group.max === 0 || keys.length <= group.max);
}

/** "Elige 1" / "Opcional · hasta 2" — the rule, phrased for the barista. */
function ruleHint(group: ModifierGroup): string {
	if (group.max === 1) return group.required ? 'Elige 1' : 'Opcional · elige 1';
	const parts: string[] = [];
	if (!group.required) parts.push('Opcional');
	const min = effectiveMin(group);
	if (min > 0) parts.push(`al menos ${min}`);
	if (group.max > 0) parts.push(`hasta ${group.max}`);
	return parts.length > 0 ? parts.join(' · ') : 'Las que quieras';
}

/** "+$15.00" / "-$10.00" — money() already renders the sign for negatives. */
function deltaLabel(delta: number): string {
	return delta > 0 ? `+${money(delta)}` : money(delta);
}

export function ModifierSheet({ groups, onConfirm, onCancel }: ModifierSheetProps) {
	const [chosen, setChosen] = useState<Record<number, string[]>>({});

	function toggle(group: ModifierGroup, key: string) {
		setChosen((prev) => {
			const current = prev[group.id] ?? [];
			if (current.includes(key)) {
				return { ...prev, [group.id]: current.filter((k) => k !== key) };
			}
			if (group.max === 1) {
				return { ...prev, [group.id]: [key] };
			}
			if (group.max > 0 && current.length >= group.max) {
				return prev; // At the ceiling — the row renders dimmed.
			}
			return { ...prev, [group.id]: [...current, key] };
		});
	}

	const allValid = useMemo(
		() => groups.every((group) => isSatisfied(group, chosen[group.id] ?? [])),
		[groups, chosen],
	);

	const priceDelta = useMemo(() => {
		let total = 0;
		for (const group of groups) {
			const keys = chosen[group.id] ?? [];
			for (const option of group.options) {
				if (keys.includes(option.key)) total += option.price_delta;
			}
		}
		return Math.round(total * 100) / 100;
	}, [groups, chosen]);

	function confirm() {
		if (!allValid) return;
		const selections: Selection[] = [];
		for (const group of groups) {
			const keys = chosen[group.id] ?? [];
			if (keys.length === 0) continue;
			// Keys in catalog order, so the ticket reads like the menu.
			selections.push({
				group_id: group.id,
				option_keys: group.options.filter((o) => keys.includes(o.key)).map((o) => o.key),
			});
		}
		onConfirm(selections, priceDelta);
	}

	return (
		<View style={styles.sheet}>
			<ScrollView style={styles.scroll} contentContainerStyle={styles.scrollContent}>
				{groups.map((group) => {
					const keys = chosen[group.id] ?? [];
					const satisfied = isSatisfied(group, keys);
					const atMax = group.max > 1 && keys.length >= group.max;
					return (
						<View key={group.id} style={styles.group}>
							<View style={styles.groupHeader}>
								<Text style={styles.groupName}>{group.name}</Text>
								<Text style={[styles.groupHint, !satisfied && styles.groupHintUnmet]}>
									{ruleHint(group)}
								</Text>
							</View>
							{group.options.map((option) => {
								const selected = keys.includes(option.key);
								const dimmed = !selected && atMax;
								return (
									<Pressable
										key={option.key}
										accessibilityRole="checkbox"
										accessibilityState={{ checked: selected, disabled: dimmed }}
										onPress={() => toggle(group, option.key)}
										style={[
											styles.option,
											selected && styles.optionSelected,
											dimmed && styles.optionDimmed,
										]}
									>
										<View style={[styles.check, selected && styles.checkOn]}>
											{selected && <Text style={styles.checkMark}>✓</Text>}
										</View>
										<Text style={[styles.optionName, selected && styles.optionNameSelected]}>
											{option.name}
										</Text>
										{option.price_delta !== 0 && (
											<Text style={styles.optionPrice}>{deltaLabel(option.price_delta)}</Text>
										)}
									</Pressable>
								);
							})}
						</View>
					);
				})}
			</ScrollView>
			<View style={styles.footer}>
				<Pressable accessibilityRole="button" onPress={onCancel} style={styles.cancel}>
					<Text style={styles.cancelText}>Cancelar</Text>
				</Pressable>
				<Pressable
					accessibilityRole="button"
					accessibilityState={{ disabled: !allValid }}
					disabled={!allValid}
					onPress={confirm}
					style={[styles.confirm, !allValid && styles.confirmDisabled]}
				>
					<Text style={styles.confirmText}>
						{priceDelta !== 0 ? `Agregar · ${deltaLabel(priceDelta)}` : 'Agregar'}
					</Text>
				</Pressable>
			</View>
		</View>
	);
}

const styles = StyleSheet.create({
	sheet: {
		backgroundColor: color.surface,
		borderColor: color.hairline,
		borderWidth: 1,
		borderRadius: radius.card,
		maxHeight: 560,
		overflow: 'hidden',
	},
	scroll: { flexGrow: 0 },
	scrollContent: { padding: space(4), gap: space(4) },
	group: { gap: space(2) },
	groupHeader: {
		flexDirection: 'row',
		alignItems: 'baseline',
		justifyContent: 'space-between',
		borderBottomWidth: 1,
		borderBottomColor: color.hairline,
		paddingBottom: space(2),
	},
	groupName: { color: color.accentDeep, fontSize: type.body, fontWeight: '700' },
	groupHint: { color: color.textSoft, fontSize: type.tiny, fontWeight: '600' },
	groupHintUnmet: { color: color.attention },
	option: {
		flexDirection: 'row',
		alignItems: 'center',
		gap: space(3),
		paddingVertical: space(2.5),
		paddingHorizontal: space(3),
		borderRadius: radius.control,
		borderWidth: 1,
		borderColor: color.hairline,
		backgroundColor: color.bg,
	},
	optionSelected: { borderColor: color.accentDeep },
	optionDimmed: { opacity: 0.4 },
	check: {
		width: 22,
		height: 22,
		borderRadius: radius.control,
		borderWidth: 1,
		borderColor: color.hairline,
		alignItems: 'center',
		justifyContent: 'center',
		backgroundColor: color.surface,
	},
	checkOn: { backgroundColor: color.accentDeep, borderColor: color.accentDeep },
	checkMark: { color: color.surface, fontSize: type.small, fontWeight: '700' },
	optionName: { flex: 1, color: color.text, fontSize: type.small, fontWeight: '600' },
	optionNameSelected: { color: color.accentDeep },
	optionPrice: { color: color.textSoft, fontSize: type.small },
	footer: {
		flexDirection: 'row',
		gap: space(2),
		padding: space(4),
		borderTopWidth: 1,
		borderTopColor: color.hairline,
	},
	cancel: {
		borderRadius: radius.control,
		borderWidth: 1,
		borderColor: color.hairline,
		paddingVertical: space(3),
		paddingHorizontal: space(4),
		alignItems: 'center',
		justifyContent: 'center',
	},
	cancelText: { color: color.text, fontSize: type.small, fontWeight: '600' },
	confirm: {
		flex: 1,
		backgroundColor: color.accentDeep,
		borderRadius: radius.control,
		paddingVertical: space(3),
		alignItems: 'center',
		justifyContent: 'center',
	},
	confirmDisabled: { opacity: 0.4 },
	confirmText: { color: color.surface, fontSize: type.body, fontWeight: '700', letterSpacing: 0.5 },
});
