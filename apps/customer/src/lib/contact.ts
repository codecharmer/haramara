/**
 * Customer contact details, persisted on the device so repeat orders don't
 * ask for name, phone and email again. Same AsyncStorage pattern as the
 * cart: hydrate once on mount, write back on every change.
 */

import AsyncStorage from '@react-native-async-storage/async-storage';
import { useCallback, useEffect, useState } from 'react';

const CONTACT_KEY = 'haramara_contact_v1';

export interface StoredContact {
	firstName: string;
	lastName: string;
	phone: string;
	email: string;
}

const EMPTY: StoredContact = { firstName: '', lastName: '', phone: '', email: '' };

export function useStoredContact(): {
	contact: StoredContact;
	setContact: (patch: Partial<StoredContact>) => void;
} {
	const [contact, setState] = useState<StoredContact>(EMPTY);
	const [hydrated, setHydrated] = useState(false);

	useEffect(() => {
		AsyncStorage.getItem(CONTACT_KEY)
			.then((raw) => {
				if (raw) setState({ ...EMPTY, ...(JSON.parse(raw) as Partial<StoredContact>) });
			})
			.catch(() => undefined)
			.finally(() => setHydrated(true));
	}, []);

	useEffect(() => {
		if (hydrated) void AsyncStorage.setItem(CONTACT_KEY, JSON.stringify(contact));
	}, [contact, hydrated]);

	const setContact = useCallback(
		(patch: Partial<StoredContact>) => setState((prev) => ({ ...prev, ...patch })),
		[],
	);

	return { contact, setContact };
}
