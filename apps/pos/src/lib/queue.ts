/**
 * The incoming-order queue: one shared poll for the Entrantes screen, the tab
 * badge, and the order-detail modal (same query key — react-query dedupes),
 * plus the accept mutation both accept surfaces share.
 */

import { ApiError, type PosOrder, type QueueResponse } from '@haramara/api-client';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Alert } from 'react-native';

import { usePosApi } from './auth';

/** New orders should surface fast — the queue is the bakery's doorbell. */
const POLL_MS = 10_000;

export const QUEUE_KEY = ['queue'] as const;

export function useQueueQuery() {
	const api = usePosApi();
	return useQuery({
		queryKey: QUEUE_KEY,
		queryFn: () => api.queue(),
		refetchInterval: POLL_MS,
	});
}

/**
 * Slide-to-accept. On success the order leaves the queue immediately and the
 * board is refreshed (the accepted order now shows on its pickup day). A 409
 * means another device won the race — surface that and refetch.
 */
export function useAcceptOrder(onAccepted?: (order: PosOrder) => void) {
	const api = usePosApi();
	const queryClient = useQueryClient();

	return useMutation({
		mutationFn: (order: PosOrder) => api.accept(order.id),
		onSuccess: (_data, order) => {
			queryClient.setQueryData<QueueResponse>(QUEUE_KEY, (prev) =>
				prev
					? {
							orders: prev.orders.filter((o) => o.id !== order.id),
							count: Math.max(0, prev.count - 1),
						}
					: prev,
			);
			void queryClient.invalidateQueries({ queryKey: QUEUE_KEY });
			void queryClient.invalidateQueries({ queryKey: ['board'] });
			onAccepted?.(order);
		},
		onError: (e) => {
			if (e instanceof ApiError && e.status === 409) {
				Alert.alert('Pedido ya aceptado', 'Otro dispositivo ya aceptó este pedido.');
				void queryClient.invalidateQueries({ queryKey: QUEUE_KEY });
				return;
			}
			Alert.alert('No se pudo aceptar', e instanceof Error ? e.message : 'Intenta de nuevo.');
		},
	});
}

/** "hace 12 min" — relative age of an order for the queue cards. */
export function timeAgo(iso: string | null): string {
	if (!iso) return '';
	const ms = Date.now() - new Date(iso).getTime();
	const min = Math.max(0, Math.round(ms / 60_000));
	if (min < 1) return 'ahora mismo';
	if (min < 60) return `hace ${min} min`;
	const hours = Math.floor(min / 60);
	if (hours < 24) return `hace ${hours} h`;
	const days = Math.floor(hours / 24);
	return `hace ${days} d`;
}
