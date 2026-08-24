import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { Stack } from 'expo-router';
import { StatusBar } from 'expo-status-bar';
import React from 'react';
import { ActivityIndicator, View } from 'react-native';
import { GestureHandlerRootView } from 'react-native-gesture-handler';

import { AuthProvider, useAuth } from '../lib/auth';
import { OperatorProvider, useOperator } from '../lib/operator';
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
	const { operator, lockRequired, touch } = useOperator();

	// SecureStore still loading — don't route yet.
	if (session === null || (session !== false && operator === null)) {
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

	// The PIN lock engages only once the server reports someone with a NIP set.
	// Before that, `lockRequired` is false and the counter works exactly as it
	// did — a deploy must never brick the till while the owner is still
	// configuring employees.
	const needsOperator = signedIn && lockRequired && operator === false;
	const atCounter = signedIn && !needsOperator;

	return (
		// Any touch defers the idle lock. This must be the CAPTURE phase:
		// `onTouchStart` never fires here because every Pressable and ScrollView
		// below claims the responder first, so a cashier ringing sales would be
		// locked out mid-transaction. Returning false observes the touch without
		// stealing it.
		<View
			style={{ flex: 1 }}
			onStartShouldSetResponderCapture={() => {
				touch();
				return false;
			}}
		>
			<Stack screenOptions={{ headerShown: false, contentStyle: { backgroundColor: color.bg } }}>
				<Stack.Protected guard={atCounter}>
					<Stack.Screen name="(tabs)" />
					<Stack.Screen name="order/[id]" options={{ presentation: 'modal' }} />
				</Stack.Protected>
				<Stack.Protected guard={needsOperator}>
					<Stack.Screen name="operador" />
				</Stack.Protected>
				<Stack.Protected guard={!signedIn}>
					<Stack.Screen name="login" />
				</Stack.Protected>
			</Stack>
		</View>
	);
}

export default function RootLayout() {
	return (
		<GestureHandlerRootView style={{ flex: 1 }}>
			<QueryClientProvider client={queryClient}>
				<AuthProvider>
					<OperatorProvider>
						<StatusBar style="dark" />
						<AuthedStack />
					</OperatorProvider>
				</AuthProvider>
			</QueryClientProvider>
		</GestureHandlerRootView>
	);
}
