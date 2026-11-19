<?php

declare(strict_types=1);

use Laravel\Fortify\Features;

/**
 * Only the options this app changes. Every other key falls back to Fortify's
 * own defaults, which the package merges in.
 */
return [
    /** Where a sign-in or sign-up lands: the `dashboard` route (config can't call route()). */
    'home' => '/dashboard',

    /** GET /login and /register render Inertia pages through FortifyServiceProvider's view callbacks. */
    'views' => true,

    /** Failures-only throttling in the login pipeline, reported inline, instead of a 429 route limiter (D-17). */
    'limiters' => [
        'login' => null,
    ],

    /** This app lowercases emails itself, at sign-in and before registration's uniqueness check (D-18). */
    'lowercase_usernames' => false,

    /** Sign-up only: password reset, email verification, profile, password updates, 2FA and passkeys stay off. */
    'features' => [
        Features::registration(),
    ],
];
