import MaterialCommunityIcons from '@expo/vector-icons/MaterialCommunityIcons';
import React, { useEffect, useState } from 'react';
import { ActivityIndicator, StyleSheet, View } from 'react-native';
import { Gesture, GestureDetector } from 'react-native-gesture-handler';
import Animated, {
	interpolate,
	runOnJS,
	useAnimatedStyle,
	useSharedValue,
	withSpring,
	withTiming,
} from 'react-native-reanimated';

import { color, radius, space, type } from '../lib/theme';

const TRACK_HEIGHT = 60;
const THUMB_SIZE = 52;
const INSET = (TRACK_HEIGHT - THUMB_SIZE) / 2;
/** Fraction of the track the thumb must cross for the release to accept. */
const THRESHOLD = 0.85;

const SPRING = { damping: 18, stiffness: 180 } as const;

interface Props {
	onAccept: () => void;
	/** Accept in flight: freeze the thumb at the end with a spinner. */
	busy?: boolean;
	label?: string;
}

/**
 * iPhone-call style slider: drag the thumb across the track to accept the
 * order; release early and it springs back. Deliberate by design — a stray tap
 * can never accept an order.
 */
export function SlideToAccept({ onAccept, busy = false, label = 'Desliza para aceptar' }: Props) {
	const [trackWidth, setTrackWidth] = useState(0);
	const tx = useSharedValue(0);
	const maxDrag = Math.max(0, trackWidth - THUMB_SIZE - INSET * 2);

	// A failed accept (409, red) ends `busy` without unmounting — come home.
	useEffect(() => {
		if (!busy) {
			tx.value = withSpring(0, SPRING);
		}
	}, [busy, tx]);

	const pan = Gesture.Pan()
		.enabled(!busy && maxDrag > 0)
		.onChange((e) => {
			'worklet';
			tx.value = Math.min(maxDrag, Math.max(0, tx.value + e.changeX));
		})
		.onEnd(() => {
			'worklet';
			if (tx.value >= maxDrag * THRESHOLD) {
				tx.value = withTiming(maxDrag, { duration: 120 });
				runOnJS(onAccept)();
			} else {
				tx.value = withSpring(0, SPRING);
			}
		});

	const thumbStyle = useAnimatedStyle(() => ({
		transform: [{ translateX: tx.value }],
	}));
	const labelStyle = useAnimatedStyle(() => ({
		opacity: maxDrag > 0 ? interpolate(tx.value, [0, maxDrag * 0.6], [1, 0], 'clamp') : 1,
	}));
	const fillStyle = useAnimatedStyle(() => ({
		width: INSET + THUMB_SIZE + tx.value + INSET,
		opacity: maxDrag > 0 ? interpolate(tx.value, [0, maxDrag], [0.15, 1], 'clamp') : 0,
	}));

	return (
		<View
			style={styles.track}
			onLayout={(e) => setTrackWidth(e.nativeEvent.layout.width)}
			accessible
			accessibilityRole="button"
			accessibilityLabel={label}
			accessibilityHint="Acepta el pedido"
			accessibilityActions={[{ name: 'activate', label: 'Aceptar pedido' }]}
			onAccessibilityAction={(e) => {
				if (e.nativeEvent.actionName === 'activate' && !busy) {
					onAccept();
				}
			}}
		>
			<Animated.View pointerEvents="none" style={[styles.fill, fillStyle]} />
			<Animated.Text pointerEvents="none" style={[styles.label, labelStyle]}>
				{label}  ›
			</Animated.Text>
			<GestureDetector gesture={pan}>
				<Animated.View style={[styles.thumb, thumbStyle]}>
					{busy ? (
						<ActivityIndicator size="small" color={color.surface} />
					) : (
						<MaterialCommunityIcons name="chevron-double-right" size={26} color={color.surface} />
					)}
				</Animated.View>
			</GestureDetector>
		</View>
	);
}

const styles = StyleSheet.create({
	track: {
		height: TRACK_HEIGHT,
		borderRadius: radius.pill,
		backgroundColor: color.bg,
		borderWidth: 1,
		borderColor: color.hairline,
		justifyContent: 'center',
		overflow: 'hidden',
	},
	fill: {
		position: 'absolute',
		left: 0,
		top: 0,
		bottom: 0,
		borderRadius: radius.pill,
		backgroundColor: color.goodBg,
	},
	label: {
		alignSelf: 'center',
		color: color.textSoft,
		fontSize: type.body,
		fontWeight: '600',
		paddingHorizontal: space(10),
	},
	thumb: {
		position: 'absolute',
		left: INSET,
		width: THUMB_SIZE,
		height: THUMB_SIZE,
		borderRadius: radius.pill,
		backgroundColor: color.accentDeep,
		alignItems: 'center',
		justifyContent: 'center',
	},
});
