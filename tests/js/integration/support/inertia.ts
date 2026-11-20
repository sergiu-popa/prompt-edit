import type { ActiveVisit, FlashData, HttpResponse, PendingVisit, ReloadOptions, VisitOptions } from '@inertiajs/core';
import { router } from '@inertiajs/core';
import { vi } from 'vitest';
import { reactive } from 'vue';

import type { AuthProps, AuthUser } from '@/types/auth';

/**
 * The page `usePage()` returns in integration tests (see setup.ts). Core's `router` is the
 * same object pages reach through `@inertiajs/vue3` and `useForm`, so spying on it here
 * sees every visit.
 */
export const page = reactive<{ url: string; props: { auth: AuthProps; errors: Record<string, string> }; flash: FlashData }>({
    url: '/',
    props: { auth: { user: null }, errors: {} },
    flash: {},
});

/** The flash data the server sent with this page, as after a redirect. */
export function givenFlash(flash: FlashData): void {
    page.flash = flash;
}

export function givenNoFlash(): void {
    page.flash = {};
}

/** The URL Inertia reports for the current page, path and query as the server sent them. */
export function givenThePageIsAt(url: string): void {
    page.url = url;
}

export function givenAGuest(): void {
    page.props.auth = { user: null };
}

export function givenSignedInAs(user: AuthUser): void {
    page.props.auth = { user };
}

const pendingVisit = {} as PendingVisit;
const finishedVisit = {} as ActiveVisit;
const reloadedPage = {} as Parameters<NonNullable<ReloadOptions['onSuccess']>>[0];

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
 * Like Inertia, it also puts them in the page's `errors` prop, where a page reads the
 * errors that belong to no form field.
 */
export function givenVisitsFailWith(errors: Record<string, string>) {
    return vi.spyOn(router, 'post').mockImplementation((_url, _data, options: VisitOptions = {}) => {
        options.onStart?.(pendingVisit);
        page.props.errors = errors;
        options.onError?.(errors);
        options.onFinish?.(finishedVisit);
    });
}

export function givenNoErrors(): void {
    page.props.errors = {};
}

/**
 * Every partial reload succeeds, as a live update does; the test then passes the new
 * props with `setProps`, as Inertia would.
 */
export function givenReloadsSucceed() {
    return vi.spyOn(router, 'reload').mockImplementation((options: ReloadOptions = {}) => {
        options.onSuccess?.(reloadedPage);
        options.onFinish?.(finishedVisit);
    });
}

/**
 * Every partial reload fails with a 503, and the page keeps the props it had.
 */
export function givenReloadsFail() {
    return vi.spyOn(router, 'reload').mockImplementation((options: ReloadOptions = {}) => {
        options.onHttpException?.({ status: 503, headers: {}, data: '' } as HttpResponse);
        options.onFinish?.(finishedVisit);
    });
}
