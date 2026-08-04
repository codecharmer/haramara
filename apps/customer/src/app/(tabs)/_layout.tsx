import MaterialCommunityIcons from '@expo/vector-icons/MaterialCommunityIcons';
import { Tabs } from 'expo-router';
import React from 'react';

import { useCart } from '../../lib/cart';
import { color } from '../../lib/theme';

export default function TabsLayout() {
	const { count } = useCart();

	return (
		<Tabs
			screenOptions={{
				headerShown: false,
				tabBarActiveTintColor: color.accent,
				tabBarInactiveTintColor: color.textSoft,
				tabBarStyle: {
					backgroundColor: color.surface,
					borderTopColor: color.hairline,
				},
				tabBarLabelStyle: { fontSize: 11, fontWeight: '600' },
			}}
		>
			<Tabs.Screen
				name="index"
				options={{
					title: 'Carta',
					tabBarIcon: ({ color: tint, size }) => (
						<MaterialCommunityIcons name="coffee" size={size} color={tint} />
					),
				}}
			/>
			<Tabs.Screen
				name="carrito"
				options={{
					title: 'Canasta',
					tabBarBadge: count > 0 ? count : undefined,
					tabBarBadgeStyle: { backgroundColor: color.accent, color: color.bg, fontSize: 11 },
					tabBarIcon: ({ color: tint, size }) => (
						<MaterialCommunityIcons name="basket-outline" size={size} color={tint} />
					),
				}}
			/>
			<Tabs.Screen
				name="lealtad"
				options={{
					title: 'Lealtad',
					tabBarIcon: ({ color: tint, size }) => (
						<MaterialCommunityIcons name="qrcode" size={size} color={tint} />
					),
				}}
			/>
			<Tabs.Screen
				name="pedidos"
				options={{
					title: 'Mis pedidos',
					tabBarIcon: ({ color: tint, size }) => (
						<MaterialCommunityIcons name="clock-check-outline" size={size} color={tint} />
					),
				}}
			/>
		</Tabs>
	);
}
