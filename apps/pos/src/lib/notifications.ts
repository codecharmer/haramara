/**
 * Staff alerting for new orders.
 *
 * Two layers, both best-effort: remote Expo push (device registered with the
 * server, delivered even with the app closed — needs a dev build + EAS
 * credentials) and the in-app chime/haptic that fires when the queue poll
 * brings in an order not seen before. While the app is foregrounded the OS
 * banner/sound is suppressed so the two layers never double-alert.
 */

import type { PosApi, PosOrder } from '@haramara/api-client';
import Constants from 'expo-constants';
import * as Device from 'expo-device';
import * as Haptics from 'expo-haptics';
import * as Notifications from 'expo-notifications';
import { useAudioPlayer } from 'expo-audio';
import { useEffect, useRef } from 'react';
import { Platform } from 'react-native';

// expo-notifications is native-only — every entry point below must stay inert
// on web (the browser preview) or it throws UnavailabilityError at import time.
if (Platform.OS !== 'web') {
	Notifications.setNotificationHandler({
		handleNotification: async () => ({
			shouldShowBanner: false,
			shouldShowList: true,
			shouldPlaySound: false,
			shouldSetBadge: false,
		}),
	});
}

/**
 * Ask for permission, fetch this device's Expo push token, and register it
 * with the server. Silently a no-op wherever push can't work (web, simulator,
 * Expo Go, permission denied, EAS not configured) — the 10s queue poll remains
 * the fallback alert path.
 */
export async function registerForPushNotifications(api: PosApi): Promise<void> {
	try {
		if (Platform.OS === 'web' || !Device.isDevice) {
			return;
		}

		if (Platform.OS === 'android') {
			// Matches the channelId the server sends (Push\ExpoPush).
			await Notifications.setNotificationChannelAsync('orders', {
				name: 'Pedidos nuevos',
				importance: Notifications.AndroidImportance.MAX,
				sound: 'default',
				vibrationPattern: [0, 250, 250, 250],
				lightColor: '#8F6F63',
			});
		}

		const current = await Notifications.getPermissionsAsync();
		let granted = current.status === 'granted';
		if (!granted) {
			const requested = await Notifications.requestPermissionsAsync();
			granted = requested.status === 'granted';
		}
		if (!granted) {
			return;
		}

		const projectId =
			Constants.expoConfig?.extra?.eas?.projectId ?? Constants.easConfig?.projectId;
		if (!projectId) {
			return;
		}

		const token = (await Notifications.getExpoPushTokenAsync({ projectId })).data;
		await api.registerPushToken(token);
	} catch {
		// Push must never break the POS.
	}
}

/** Route new-order notification taps (cold start included) to the caller. */
export function useNotificationTaps(onNewOrderTap: () => void): void {
	useEffect(() => {
		if (Platform.OS === 'web') {
			return;
		}

		let mounted = true;

		Notifications.getLastNotificationResponseAsync()
			.then((response) => {
				if (mounted && response?.notification.request.content.data?.type === 'new_order') {
					onNewOrderTap();
				}
			})
			.catch(() => {});

		const sub = Notifications.addNotificationResponseReceivedListener((response) => {
			if (response.notification.request.content.data?.type === 'new_order') {
				onNewOrderTap();
			}
		});

		return () => {
			mounted = false;
			sub.remove();
		};
	}, [onNewOrderTap]);
}

const chime = require('../../assets/sounds/new-order.wav');

/**
 * Chime + vibrate when the queue gains an order we haven't seen this session.
 * Compares ID sets, not counts, so an accept crossing an arrival can't mask
 * the new order — and the initial load never chimes for the backlog.
 */
export function useNewOrderAlert(orders: PosOrder[] | undefined): void {
	const player = useAudioPlayer(chime);
	const seen = useRef<Set<number> | null>(null);

	useEffect(() => {
		if (!orders) {
			return;
		}

		const ids = new Set(orders.map((o) => o.id));
		const prev = seen.current;
		seen.current = ids;
		if (prev === null) {
			return;
		}

		let hasNew = false;
		ids.forEach((id) => {
			if (!prev.has(id)) {
				hasNew = true;
			}
		});
		if (!hasNew) {
			return;
		}

		try {
			player.seekTo(0);
			player.play();
		} catch {
			// Audio is nice-to-have; never let it break the queue.
		}
		Haptics.notificationAsync(Haptics.NotificationFeedbackType.Warning).catch(() => {});
	}, [orders, player]);
}
