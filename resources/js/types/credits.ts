/**
 * The signed-in user's balance every page receives as `credits` (CreditBalance::toArray()),
 * in integer nanodollars. Available is negative after a charge above its hold.
 */
export interface CreditBalance {
    available_nanodollars: number;
    reserved_nanodollars: number;
    running_generations: number;
}

/**
 * Why the server refused a generation (the `credit_shortfall` flash), in integer
 * nanodollars: its max estimate, the balance it saw, and what is missing.
 */
export interface CreditShortfall {
    required_nanodollars: number;
    available_nanodollars: number;
    shortfall_nanodollars: number;
}
