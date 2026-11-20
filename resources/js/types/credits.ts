/**
 * The signed-in user's balance every page receives as `credits` (CreditBalance::toArray()),
 * in integer nanodollars. Available is negative after a charge above its hold.
 */
export interface CreditBalance {
    available_nanodollars: number;
    reserved_nanodollars: number;
    running_generations: number;
}
