import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { Stack } from 'expo-router';
import { StatusBar } from 'expo-status-bar';
import React from 'react';
import { ActivityIndicator, View } from 'react-native';
import { GestureHandlerRootView } from 'react-native-gesture-handler';

import { AuthProvider, useAuth } from '../lib/auth';
import { color } from '../lib/theme';

const queryClient = new QueryClient({
	defaultOptions: {
		queries: {
			retry: 1,
			staleTime: 10_000,
		},
	},
});

function AuthedStack() {
	const { session } = useAuth();

	// SecureStore still loading — don't route yet.
	if (session === null) {
		return (
			<View style={{ flex: 1, alignItems: 'center', justifyContent: 'center', backgroundColor: color.bg }}>
				<ActivityIndicator color={color.accentDeep} size="large" />
			</View>
		);
	}

	// Guarded routes: when a guard flips, expo-router redirects to the first
	// available route on its own. (Never swap the navigator for a <Redirect> —
	// unmounting it mid-navigation loops the render cycle.)
	const signedIn = session !== false;

	return (
		<Stack screenOptions={{ headerShown: false, contentStyle: { backgroundColor: color.bg } }}>
			<Stack.Protected guard={signedIn}>
				<Stack.Screen name="(tabs)" />
				<Stack.Screen name="order/[id]" options={{ presentation: 'modal' }} />
			</Stack.Protected>
			<Stack.Protected guard={!signedIn}>
				<Stack.Screen name="login" />
			</Stack.Protected>
		</Stack>
	);
}

export default function RootLayout() {
	return (
		<GestureHandlerRootView style={{ flex: 1 }}>
			<QueryClientProvider client={queryClient}>
				<AuthProvider>
					<StatusBar style="dark" />
					<AuthedStack />
				</AuthProvider>
			</QueryClientProvider>
		</GestureHandlerRootView>
	);
}
