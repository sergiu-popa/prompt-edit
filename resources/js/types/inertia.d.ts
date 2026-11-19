import type { AuthProps } from '@/types/auth';

/**
 * Props HandleInertiaRequests::share() gives every page, so `usePage().props.auth` is typed.
 */
declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            auth: AuthProps;
        };
    }
}
