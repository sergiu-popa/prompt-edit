<?php

declare(strict_types=1);

use App\Support\Sentry\RedactSentryEvent;

/**
 * Sentry error monitoring (D-77): reported exceptions and error-level log lines, with nothing
 * that could carry a secret, a prompt or a person. Without a DSN nothing is sent, which is the
 * case everywhere but production. The package merges only the top-level keys of its own config
 * into this one, so every key is spelled out, and nothing here may be a closure, because
 * `config:cache` cannot store one.
 *
 * @see https://docs.sentry.io/platforms/php/guides/laravel/configuration/options/
 */
$sampleRate = static fn (string $name): ?float => filter_var(env($name), FILTER_VALIDATE_FLOAT, ['options' => ['min_range' => 0, 'max_range' => 1], 'flags' => FILTER_NULL_ON_FAILURE]);

return [
    /**
     * Filled in production's `shared/.env` only. Empty sends nothing.
     */
    'dsn' => env('SENTRY_LARAVEL_DSN') ?: null,

    /**
     * Never Spotlight: the SDK would otherwise read `SENTRY_SPOTLIGHT` from the process.
     */
    'spotlight' => false,

    /**
     * Unset means no release on the events.
     */
    'release' => env('SENTRY_RELEASE') ?: null,

    'environment' => env('APP_ENV') ?: null,

    'org_id' => null,

    /**
     * Every error event is sent.
     */
    'sample_rate' => 1.0,

    /**
     * Tracing and profiling are off unless a rate from 0 to 1 is set. Anything else means off.
     */
    'traces_sample_rate' => $sampleRate('SENTRY_TRACES_SAMPLE_RATE'),

    'profiles_sample_rate' => $sampleRate('SENTRY_PROFILES_SAMPLE_RATE'),

    'strict_trace_continuation' => false,

    /**
     * No outgoing request carries Sentry's `baggage` or `sentry-trace` header. With a DSN, the
     * package adds them to every HTTP client request, even with tracing off, and the baggage
     * names the project's public key and organisation, which Pixelforge must never get.
     */
    'trace_propagation_targets' => [],

    /**
     * Sentry's Logs and Metrics products are not used: error-level log lines reach Sentry as
     * events through the `sentry` log channel instead.
     */
    'enable_logs' => false,

    'enable_metrics' => false,

    'log_flush_threshold' => null,

    'logs_channel_level' => 'error',

    /**
     * The legacy switches, kept off and minimal in case `data_collection` is ever removed.
     */
    'send_default_pii' => false,

    'max_request_body_size' => 'never',

    /**
     * The last step before an event or a transaction is sent: secrets, credentials, email
     * addresses and failed queries' bindings are filtered from every string. A static method,
     * because `config:cache` cannot store a closure, and a callable the SDK cannot call would
     * silently mean none.
     */
    'before_send' => [RedactSentryEvent::class, 'beforeSend'],

    'before_send_transaction' => [RedactSentryEvent::class, 'beforeSend'],

    /**
     * What the SDK collects. Setting it replaces `send_default_pii`, and an invalid value falls
     * back to a default that collects more, so `SentryConfigTest` checks the resolved client.
     */
    'data_collection' => [
        /**
         * No user id, email or IP address, from the session or the request.
         */
        'user_info' => false,

        'cookies' => ['mode' => 'off'],

        /**
         * The SDK always filters names with auth, token, secret, password, key, csrf, session,
         * signature and the like; these add the headers that carry the visitor's IP.
         */
        'http_headers' => [
            'request' => ['mode' => 'denyList', 'terms' => ['forwarded', 'real-ip', 'client-ip', 'connecting-ip']],
            'response' => ['mode' => 'denyList', 'terms' => []],
        ],

        /**
         * No body of any request or response: sign-in passwords, prompts, Pixelforge's answers.
         */
        'http_bodies' => [],

        'url_query_params' => ['mode' => 'denyList', 'terms' => ['email']],

        'gen_ai' => [
            'inputs' => false,
            'outputs' => false,
        ],

        /**
         * No SQL bindings, in breadcrumbs or spans.
         */
        'database_query_data' => false,

        /**
         * No job arguments on queue spans.
         */
        'queues' => false,

        /**
         * No function arguments in stack traces.
         */
        'stack_frame_variables' => false,

        'frame_context_lines' => 5,
    ],

    'ignore_transactions' => [
        '/up',
        '/^\/(horizon|telescope|_debugbar)(\/|$)/',
    ],

    'breadcrumbs' => [
        'logs' => true,

        /**
         * Cache keys hold an email and an IP address: the sign-in throttle's `email|ip`.
         */
        'cache' => false,

        'livewire' => false,

        'sql_queries' => true,

        'sql_bindings' => false,

        'queue_info' => true,

        'command_info' => true,

        'http_client_requests' => true,

        'notifications' => true,
    ],

    'crons' => [
        'monitor_scheduled_tasks' => false,

        'exclude_scheduled_tasks' => [],
    ],

    /**
     * Only used when `SENTRY_TRACES_SAMPLE_RATE` is set.
     */
    'tracing' => [
        'queue_job_transactions' => true,

        'queue_jobs' => true,

        'sql_queries' => true,

        'sql_bindings' => false,

        'sql_origin' => true,

        'sql_origin_threshold_ms' => 100,

        'views' => true,

        'livewire' => false,

        'http_client_requests' => true,

        'http_client_requests_origin' => true,

        'http_client_requests_origin_threshold_ms' => 250,

        /**
         * The same `email|ip` keys as the cache breadcrumbs.
         */
        'cache' => false,

        'redis_commands' => false,

        'redis_origin' => false,

        'notifications' => true,

        'missing_routes' => false,

        'continue_after_response' => true,

        'gen_ai' => false,

        'gen_ai_invoke_agent' => false,

        'gen_ai_chat' => false,

        'gen_ai_execute_tool' => false,

        'gen_ai_embeddings' => false,

        'gen_ai_evaluate' => false,

        'default_integrations' => true,
    ],
];
