<?php

declare(strict_types=1);

/**
 * Pixelforge settings. The app boots without any `PIXELFORGE_*` variable
 * (PE-004 AC14): webhooks then answer 500 until a secret is set, and nothing
 * is submitted until a key is set (PE-015). An empty value means the default,
 * because `.env.example` lists the names empty.
 */
$fakeChaosPercent = filter_var(env('PIXELFORGE_FAKE_CHAOS_PERCENT'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 100, 'default' => 0]]);

/**
 * The submit job's own timeout. The request timeout stays below it (AC 19).
 */
$submitJobTimeoutSeconds = 60;

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
     * default, because Guzzle reads 0 as "wait forever"; so does a request
     * timeout that is not below the job's, which the worker would cut short.
     */
    'connect_timeout_seconds' => filter_var(env('PIXELFORGE_CONNECT_TIMEOUT'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'default' => 5]]),

    'request_timeout_seconds' => filter_var(env('PIXELFORGE_REQUEST_TIMEOUT'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => $submitJobTimeoutSeconds - 1, 'default' => 30]]),

    /**
     * The account-wide budget every call through the client takes from (D-44),
     * below Pixelforge's 60 requests a minute, which dev and production keys
     * share. Anything but a positive integer means the default.
     */
    'rate_limit_per_minute' => filter_var(env('PIXELFORGE_RATE_LIMIT_PER_MINUTE'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'default' => 50]]),

    /**
     * The cache store that counts the budget: one every process shares (D-20),
     * pinned here, whatever CACHE_STORE says.
     */
    'rate_limit_store' => 'database',

    /**
     * How long the submit job keeps trying (D-46, D-47), in seconds. Every
     * default is production's; anything but a positive integer means it.
     */
    'submit' => [
        /**
         * From the first attempt; the worker's `--tries` cannot end it sooner.
         */
        'window_seconds' => filter_var(env('PIXELFORGE_SUBMIT_WINDOW'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'default' => 1_800]]),

        /**
         * From the first `503 overloaded`; then the generation fails free.
         */
        'overloaded_deadline_seconds' => filter_var(env('PIXELFORGE_OVERLOADED_DEADLINE'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'default' => 120]]),

        /**
         * From the first `429 concurrency_limit_exceeded`; then it fails free.
         */
        'concurrency_max_wait_seconds' => filter_var(env('PIXELFORGE_CONCURRENCY_MAX_WAIT'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'default' => 600]]),

        /**
         * How many `401 invalid_api_key` answers, 20 s apart, a generation gets
         * before it fails free: enough to ride out a key rotated mid-deploy.
         */
        'invalid_api_key_attempts' => 3,

        /**
         * Above the request timeout and below the queue's `retry_after` (90),
         * so one job never runs twice at once (AC 19).
         */
        'job_timeout_seconds' => $submitJobTimeoutSeconds,
    ],

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
         * Drops the webhooks of every generation created while it is on, so the
         * reconciler is the only signal (D-55). For manual local testing only:
         * E2E uses the per-prompt `[no-webhooks]`. Off by default.
         */
        'drop_webhooks' => filter_var(env('PIXELFORGE_FAKE_DROP_WEBHOOKS'), FILTER_VALIDATE_BOOLEAN, ['options' => ['default' => false]]),

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
