import type { AuthProps } from '@/types/auth';
import type { CreditBalance, CreditShortfall } from '@/types/credits';
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
            /** Why the server refused a generation the balance can't hold (D-68), with the `balance` error. */
            credit_shortfall?: CreditShortfall;
            /** What the Buy credits purchase just added, in integer n$ (the first purchase's on a replay). */
            purchased_nanodollars?: number;
            /** The server's one-off notice, such as "Your session expired. Please try again." (D-60). */
            notice?: string;
        };
    }
}
