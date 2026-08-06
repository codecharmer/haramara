/**
 * Customer order-update notifications.
 *
 * The device that places an order attaches its Expo push token to that order
 * (POST app/orders/{id}/push-token); the server's Push\OrderStatusNotifier
 * then mirrors the WhatsApp status updates as pushes. Everything is
 * best-effort: web, simulators, Expo Go and denied permission all silently
 * no-op — the order screen's 60s poll remains the fallback.
 */

import { useQueryClient } from '@tanstack/react-query';
import Constants from 'expo-constants';
import * as Device from 'expo-device';
import * as Notifications from 'expo-notifications';
import { router } from 'expo-router';
import { useEffect, useRef } from 'react';
import { Platform } from 'react-native';

import { appApi } from './api';
import type { LocalOrder } from './cart';

// expo-notifications is native-only — every entry point below must stay inert
// on web (the browser preview) or it throws UnavailabilityError at import time.
if (Platform.OS !== 'web') {
	Notifications.setNotificationHandler({
		// Unlike the POS there is no in-app chime layer, so the OS banner shows
		// even while the app is foregrounded.
		handleNotification: async () => ({
			shouldShowBanner: true,
			shouldShowList: true,
			shouldPlaySound: true,
			shouldSetBadge: false,
		}),
	});
}

/**
 * Ask for permission, fetch this device's Expo push token, and attach it to
 * the order so status changes notify this device. Silently a no-op wherever
 * push can't work (web, simulator, Expo Go, permission denied).
 */
export async function registerForOrderUpdates(orderId: number, key: string): Promise<void> {
	try {
		if (Platform.OS === 'web' || !Device.isDevice) {
			return;
		}

		if (Platform.OS === 'android') {
			// Matches the channelId the server sends (Push\ExpoPush).
			await Notifications.setNotificationChannelAsync('orders', {
				name: 'Estado de tus pedidos',
				importance: Notifications.AndroidImportance.HIGH,
				sound: 'default',
				vibrationPattern: [0, 250, 250, 250],
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
		await appApi.registerPushToken(orderId, key, token);
	} catch {
		// Push must never break checkout or the order screen.
	}
}

/**
 * Order-update notifications while the app is open: a tap (cold start
 * included) lands on the order's screen — its key resolved from the device's
 * order history, falling back to Mis pedidos — and an update arriving in the
 * foreground refreshes the order query so an open screen updates instantly.
 */
export function useOrderNotifications(orders: LocalOrder[]): void {
	const queryClient = useQueryClient();

	// The listeners must subscribe exactly once; refs keep them reading fresh
	// order history without re-subscribing (a cold-start response may only be
	// consumed once).
	const ordersRef = useRef(orders);
	useEffect(() => {
		ordersRef.current = orders;
	}, [orders]);

	useEffect(() => {
		if (Platform.OS === 'web') {
			return;
		}

		const openOrder = (data: Record<string, unknown> | undefined) => {
			if (data?.type !== 'order_status') {
				return;
			}
			const stored = ordersRef.current.find((o) => o.id === Number(data.order_id));
			if (stored) {
				router.navigate({
					pathname: '/pedido/[id]',
					params: { id: String(stored.id), key: stored.key },
				});
			} else {
				// Order history not hydrated yet, or the order was placed on
				// another device / pruned from the local list.
				router.navigate('/pedidos');
			}
		};

		let mounted = true;

		Notifications.getLastNotificationResponseAsync()
			.then((response) => {
				if (mounted) {
					openOrder(response?.notification.request.content.data);
				}
			})
			.catch(() => {});

		const tapSub = Notifications.addNotificationResponseReceivedListener((response) => {
			openOrder(response.notification.request.content.data);
		});

		const receiveSub = Notifications.addNotificationReceivedListener((notification) => {
			const data = notification.request.content.data;
			if (data?.type === 'order_status') {
				void queryClient.invalidateQueries({ queryKey: ['order', String(data.order_id)] });
			}
		});

		return () => {
			mounted = false;
			tapSub.remove();
			receiveSub.remove();
		};
	}, [queryClient]);
}
