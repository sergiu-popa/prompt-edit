<?php

declare(strict_types=1);

/**
 * Pixelforge settings. The app boots without any `PIXELFORGE_*` variable
 * (PE-004 AC14): webhooks then answer 500 until a secret is set, and nothing
 * is submitted until a key is set (PE-015). An empty value means the default,
 * because `.env.example` lists the names empty.
 */
return [
    /**
     * The API root, without a trailing slash.
     */
    'base_url' => env('PIXELFORGE_BASE_URL') ?: 'https://pixelforge.promptedit.dev/v1',

    /**
     * The `pf_…` key, sent as a bearer token. Never logged or committed.
     */
    'api_key' => env('PIXELFORGE_API_KEY') ?: null,

    /**
     * Both stay below the submit job's timeout, so a hung request can never
     * overlap its own retry (D-33).
     */
    'connect_timeout_seconds' => (int) (env('PIXELFORGE_CONNECT_TIMEOUT') ?: 5),

    'request_timeout_seconds' => (int) (env('PIXELFORGE_REQUEST_TIMEOUT') ?: 30),

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
