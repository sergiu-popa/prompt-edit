import type { AuthProps } from '@/types/auth';

/**
 * Props HandleInertiaRequests::share() gives every page, so `usePage().props.auth` is typed,
 * and the flash data a redirect may carry (`Inertia::flash()`), never kept in history.
 */
declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            auth: AuthProps;
        };
        flashDataType: {
            /** The generation CreateGenerationController just created, highlighted once on History. */
            highlighted_generation_id?: string;
        };
    }
}
