import type { ActiveVisit, PendingVisit, VisitOptions } from '@inertiajs/core';
import { router } from '@inertiajs/core';
import { vi } from 'vitest';
import { reactive } from 'vue';

import type { AuthProps, AuthUser } from '@/types/auth';

/**
 * The page `usePage()` returns in integration tests (see setup.ts). Core's `router` is the
 * same object pages reach through `@inertiajs/vue3` and `useForm`, so spying on it here
 * sees every visit.
 */
export const page = reactive<{ props: { auth: AuthProps; errors: Record<string, string> } }>({
    props: { auth: { user: null }, errors: {} },
});

export function givenAGuest(): void {
    page.props.auth = { user: null };
}

export function givenSignedInAs(user: AuthUser): void {
    page.props.auth = { user };
}

const pendingVisit = {} as PendingVisit;
const finishedVisit = {} as ActiveVisit;

/**
 * Every POST starts and never finishes, as while the request is in flight.
 */
export function givenVisitsStayPending() {
    return vi.spyOn(router, 'post').mockImplementation((_url, _data, options: VisitOptions = {}) => {
        options.onStart?.(pendingVisit);
    });
}

/**
 * Every POST comes back with these validation errors, in the order Inertia reports them.
 */
export function givenVisitsFailWith(errors: Record<string, string>) {
    return vi.spyOn(router, 'post').mockImplementation((_url, _data, options: VisitOptions = {}) => {
        options.onStart?.(pendingVisit);
        options.onError?.(errors);
        options.onFinish?.(finishedVisit);
    });
}
