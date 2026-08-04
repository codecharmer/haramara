import MaterialCommunityIcons from '@expo/vector-icons/MaterialCommunityIcons';
import { useQueryClient } from '@tanstack/react-query';
import { useKeepAwake } from 'expo-keep-awake';
import { Tabs, useRouter } from 'expo-router';
import React, { useCallback, useEffect } from 'react';

import { usePosApi } from '../../lib/auth';
import {
	registerForPushNotifications,
	useNewOrderAlert,
	useNotificationTaps,
} from '../../lib/notifications';
import { QUEUE_KEY, useQueueQuery } from '../../lib/queue';
import { color } from '../../lib/theme';

export default function TabsLayout() {
	const api = usePosApi();
	const router = useRouter();
	const queryClient = useQueryClient();
	const queue = useQueueQuery();
	const incoming = queue.data?.count ?? 0;

	// Counter tablet: new-order alerts ride the queue poll, so the screen must
	// never sleep while the POS is open.
	useKeepAwake();

	// Signed in with the tabs mounted → this device should get new-order pushes.
	useEffect(() => {
		void registerForPushNotifications(api);
	}, [api]);

	// Tapping a push (cold start included) lands on Entrantes with fresh data.
	useNotificationTaps(
		useCallback(() => {
			void queryClient.invalidateQueries({ queryKey: QUEUE_KEY });
			router.navigate('/');
		}, [queryClient, router]),
	);

	// Chime + vibrate whenever the poll brings in an unseen order, on any tab.
	useNewOrderAlert(queue.data?.orders);

	return (
		<Tabs
			screenOptions={{
				headerShown: false,
				tabBarActiveTintColor: color.accentDeep,
				tabBarInactiveTintColor: color.textSoft,
				tabBarStyle: {
					backgroundColor: color.surface,
					borderTopColor: color.hairline,
				},
				tabBarLabelStyle: { fontSize: 12, fontWeight: '600' },
			}}
		>
			<Tabs.Screen
				name="index"
				options={{
					title: 'Entrantes',
					tabBarBadge: incoming > 0 ? incoming : undefined,
					tabBarBadgeStyle: { backgroundColor: color.accentDeep, color: color.surface },
					tabBarIcon: ({ color: tint, size }) => (
						<MaterialCommunityIcons name="bell-ring-outline" size={size} color={tint} />
					),
				}}
			/>
			<Tabs.Screen
				name="pedidos"
				options={{
					title: 'Pedidos',
					tabBarIcon: ({ color: tint, size }) => (
						<MaterialCommunityIcons name="clipboard-text-clock-outline" size={size} color={tint} />
					),
				}}
			/>
			<Tabs.Screen
				name="mostrador"
				options={{
					title: 'Mostrador',
					tabBarIcon: ({ color: tint, size }) => (
						<MaterialCommunityIcons name="storefront-outline" size={size} color={tint} />
					),
				}}
			/>
			<Tabs.Screen
				name="lealtad"
				options={{
					title: 'Lealtad',
					tabBarIcon: ({ color: tint, size }) => (
						<MaterialCommunityIcons name="qrcode-scan" size={size} color={tint} />
					),
				}}
			/>
			<Tabs.Screen
				name="corte"
				options={{
					title: 'Corte del día',
					tabBarIcon: ({ color: tint, size }) => (
						<MaterialCommunityIcons name="receipt-text-outline" size={size} color={tint} />
					),
				}}
			/>
		</Tabs>
	);
}
