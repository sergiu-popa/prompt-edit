<?php

declare(strict_types=1);

/**
 * Credit balances (PE-017), in integer nanodollars. An empty value means the
 * default, because `.env.example` lists the names.
 */
return [
    /**
     * What every new account is granted once, whichever path creates it (D-70):
     * $1.0000 on production. Dev and E2E start accounts at $0.0100, below the
     * cheapest request's max, so the blocked flow is deterministic (D-71).
     * Anything but a positive integer means the default.
     */
    'starter_grant_nanodollars' => filter_var(env('CREDITS_STARTER_GRANT'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'default' => 1_000_000_000]]),
];
