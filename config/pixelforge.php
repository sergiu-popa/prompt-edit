<?php

declare(strict_types=1);

/**
 * Pixelforge settings. The app boots without any `PIXELFORGE_*` variable
 * (PE-004 AC14): webhooks then answer 500 until a secret is set, and nothing
 * is submitted until a key is set (PE-015). An empty value means the default,
 * because `.env.example` lists the names empty.
 */
$fakeChaosPercent = filter_var(env('PIXELFORGE_FAKE_CHAOS_PERCENT'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 100, 'default' => 0]]);

return [
    /**
     * Which PixelforgeClient the container builds: `http` for the real API,
     * or `fake` (local and E2E; refused in production). Any other value fails
     * when the client is resolved.
     */
    'driver' => env('PIXELFORGE_DRIVER') ?: 'http',

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
     * overlap its own retry (D-33). Anything but a positive integer means the
     * default, because Guzzle reads 0 as "wait forever".
     */
    'connect_timeout_seconds' => filter_var(env('PIXELFORGE_CONNECT_TIMEOUT'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'default' => 5]]),

    'request_timeout_seconds' => filter_var(env('PIXELFORGE_REQUEST_TIMEOUT'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'default' => 30]]),

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

    /**
     * The in-app fake (PE-010), for local dev and E2E only. Anything but a
     * valid whole number in range means the default.
     */
    'fake' => [
        /**
         * Where the worker posts the fake's webhooks: the in-network app
         * (compose service `app`, Apache on 8080), never the browser's host.
         * `/webhooks/pixelforge` is appended.
         */
        'webhook_base_url' => env('PIXELFORGE_FAKE_WEBHOOK_URL') ?: 'http://app:8080',

        /**
         * How long an accepted generation stays `queued`.
         */
        'queue_seconds' => filter_var(env('PIXELFORGE_FAKE_QUEUE_SECONDS'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'default' => 1]]),

        /**
         * How long an accepted generation stays `processing`.
         */
        'processing_seconds' => filter_var(env('PIXELFORGE_FAKE_PROCESSING_SECONDS'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'default' => 3]]),

        /**
         * How long a `[slow]` generation stays `processing`, longer than normal.
         */
        'slow_processing_seconds' => filter_var(env('PIXELFORGE_FAKE_SLOW_SECONDS'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'default' => 10]]),

        /**
         * How long a succeeded generation's image URL works: 24 h, as the docs give it.
         */
        'image_ttl_seconds' => filter_var(env('PIXELFORGE_FAKE_IMAGE_TTL_SECONDS'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'default' => 86_400]]),

        /**
         * The image URL TTL of an `[expire]` generation only, so E2E can show
         * expiry without touching the images of tests running beside it.
         */
        'expire_ttl_seconds' => filter_var(env('PIXELFORGE_FAKE_EXPIRE_TTL_SECONDS'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'default' => 5]]),

        /**
         * Unprompted chaos, as the real API has it: the percent of creates
         * answered 503, of generations that fail with `internal_error`, and
         * of generations whose webhooks all arrive twice. Off by default; one
         * setting feeds all three, and tests set them one by one.
         */
        'chaos' => [
            'overloaded_percent' => $fakeChaosPercent,
            'internal_error_percent' => $fakeChaosPercent,
            'duplicate_percent' => $fakeChaosPercent,
        ],
    ],
];
