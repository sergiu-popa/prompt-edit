import type { AuthProps } from '@/types/auth';
import type { CreditBalance } from '@/types/credits';
import type { NavigationLink } from '@/types/navigation';

/**
 * Props HandleInertiaRequests::share() gives every page, so `usePage().props` is typed,
 * and the flash data a redirect may carry (`Inertia::flash()`), never kept in history.
 */
declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            auth: AuthProps;
            /** The operator's nav links after History (Report); empty for everyone but operators (D-63). */
            operator_navigation: NavigationLink[];
            /** The signed-in user's balance, or null for a guest. */
            credits: CreditBalance | null;
        };
        flashDataType: {
            /** The generation CreateGenerationController just created, highlighted once on History. */
            highlighted_generation_id?: string;
            /** The server's one-off notice, such as "Your session expired. Please try again." (D-60). */
            notice?: string;
        };
    }
}
