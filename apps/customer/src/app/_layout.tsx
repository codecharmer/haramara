import { Italiana_400Regular } from '@expo-google-fonts/italiana';
import { Petrona_500Medium_Italic, useFonts } from '@expo-google-fonts/petrona';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { Stack } from 'expo-router';
import * as SplashScreen from 'expo-splash-screen';
import { StatusBar } from 'expo-status-bar';
import React, { useEffect } from 'react';

import { CartProvider } from '../lib/cart';
import { color } from '../lib/theme';

SplashScreen.preventAutoHideAsync();

const queryClient = new QueryClient({
	defaultOptions: {
		queries: {
			retry: 1,
			staleTime: 60_000,
		},
	},
});

export default function RootLayout() {
	const [fontsLoaded] = useFonts({
		Italiana_400Regular,
		Petrona_500Medium_Italic,
	});

	useEffect(() => {
		if (fontsLoaded) void SplashScreen.hideAsync();
	}, [fontsLoaded]);

	if (!fontsLoaded) return null;

	return (
		<QueryClientProvider client={queryClient}>
			<CartProvider>
				<StatusBar style="light" />
				<Stack
					screenOptions={{
						headerShown: false,
						contentStyle: { backgroundColor: color.bg },
					}}
				>
					<Stack.Screen name="(tabs)" />
					<Stack.Screen name="producto/[id]" />
					<Stack.Screen name="checkout" />
					<Stack.Screen name="pedido/[id]" />
				</Stack>
			</CartProvider>
		</QueryClientProvider>
	);
}
