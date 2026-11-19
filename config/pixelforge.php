<?php

declare(strict_types=1);

/**
 * Pixelforge settings. The app boots without any `PIXELFORGE_*` variable
 * (PE-004 AC14): webhooks then answer 500 until a secret is set (PE-015).
 */
return [
    /**
     * The `whsec_…` secret of the API key whose webhook URL points here. Each
     * key has its own, so local and production differ. Never logged or committed.
     */
    'webhook_secret' => env('PIXELFORGE_WEBHOOK_SECRET'),

    /**
     * How far a signature's `t` may be from now, either way, as in the docs'
     * verification example.
     */
    'webhook_tolerance_seconds' => 300,
];
