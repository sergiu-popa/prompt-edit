<?php

declare(strict_types=1);

/**
 * Pinned snapshot of Pixelforge's public `GET /v1/pricing`, checked 2026-11-18 (D-15).
 *
 * Estimates read this instead of fetching live rates, so PHP, TypeScript and the
 * shared estimate fixture stay deterministic. Settlement never reads it: a
 * generation is billed at its own `rates`. PE-015's live smoke diffs `rates` and
 * `tokens` against the endpoint to catch drift.
 *
 * Keys mirror the endpoint verbatim, with one exception: the live
 * `text_token_variance` is the JSON float 0.15, held here exactly as the
 * integer percent `text_token_variance_percent`.
 *
 * Plain data only, with no env() and no closures: unit tests require this file
 * without booting the application.
 */
return [
    'rates' => [
        'input_text_tokens' => '5.00',
        'input_image_tokens' => '10.00',
        'output_tokens' => '40.00',
        'reasoning_tokens' => '12.50',
    ],

    'tokens' => [
        'characters_per_text_token' => 4,
        'text_token_variance_percent' => 15,
        'per_reference_image' => 765,
        'output' => [
            'low' => ['1024x1024' => 280, '1536x1024' => 420, '1024x1536' => 420],
            'medium' => ['1024x1024' => 1_100, '1536x1024' => 1_650, '1024x1536' => 1_650],
            'high' => ['1024x1024' => 4_300, '1536x1024' => 6_450, '1024x1536' => 6_450],
        ],
        'reasoning' => [
            'low' => ['min' => 0, 'max' => 500],
            'medium' => ['min' => 200, 'max' => 2_000],
            'high' => ['min' => 1_000, 'max' => 8_000],
        ],
    ],
];
