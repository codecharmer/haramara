/**
 * Cross-platform dialogs. React Native web ships `Alert.alert` as a silent
 * no-op, which turns every confirmation (charging a sale, marking an order)
 * into a dead button in the browser preview. Native keeps the platform
 * alert; web falls back to the browser's own dialogs.
 */

import { Alert, Platform } from 'react-native';

interface ConfirmOptions {
	title: string;
	message?: string;
	confirmText: string;
	destructive?: boolean;
	onConfirm: () => void;
}

export function confirmDialog({ title, message, confirmText, destructive, onConfirm }: ConfirmOptions): void {
	if (Platform.OS === 'web') {
		// eslint-disable-next-line no-alert
		if (window.confirm(message ? `${title}\n\n${message}` : title)) {
			onConfirm();
		}
		return;
	}
	Alert.alert(title, message, [
		{ text: 'Cancelar', style: 'cancel' },
		{ text: confirmText, style: destructive ? 'destructive' : 'default', onPress: onConfirm },
	]);
}

export function notify(title: string, message?: string): void {
	if (Platform.OS === 'web') {
		// eslint-disable-next-line no-alert
		window.alert(message ? `${title}\n\n${message}` : title);
		return;
	}
	Alert.alert(title, message);
}
