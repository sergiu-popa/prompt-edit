<?php

declare(strict_types=1);

/**
 * Generation submission settings. An empty value means the default, because
 * `.env.example` lists the names empty.
 */
return [
    /**
     * Generate submissions one user may make per minute (D-39). It also caps how fast
     * one account can spend before PE-017's balances.
     */
    'submissions_per_minute' => filter_var(env('GENERATION_SUBMISSIONS_PER_MINUTE'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'default' => 10]]),
];
