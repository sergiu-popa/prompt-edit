<?php

declare(strict_types=1);

namespace Tests\Concerns;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The Pixelforge API as tests see it: a sentinel key that is no real key, the
 * docs' base URL and answers shaped as the docs show them. Every request is
 * faked; Tests\TestCase refuses any other.
 *
 * @phpstan-require-extends TestCase
 */
trait InteractsWithPixelforgeApi
{
    /**
     * Long enough that a trace's 15-character string preview never shows it whole.
     */
    protected const string PIXELFORGE_API_KEY = 'pf_test_sentinel_4c7e1a9b3d5f2e8a6c0b4d7f9e1a3c5b';

    protected const string PIXELFORGE_BASE_URL = 'https://pixelforge.promptedit.dev/v1';

    /**
     * The docs' Lighthouse prompt, a space and four emoji: 50 code points.
     */
    protected const string UNICODE_PROMPT = 'A lighthouse on a cliff at dawn, oil painting 🎨🎨🎨🎨';

    /**
     * Called by Laravel's test lifecycle for every test that uses the trait.
     */
    protected function setUpInteractsWithPixelforgeApi(): void
    {
        config([
            'pixelforge.base_url' => self::PIXELFORGE_BASE_URL,
            'pixelforge.api_key' => self::PIXELFORGE_API_KEY,
            'pixelforge.connect_timeout_seconds' => 5,
            'pixelforge.request_timeout_seconds' => 30,
        ]);
    }

    /**
     * A `202` carrying the generation object, with `Idempotent-Replayed` when given.
     *
     * @param  array<string, mixed>  $object
     */
    protected static function pixelforgeAccepted(array $object, ?string $replayed = null): PromiseInterface
    {
        return Http::response(
            json_encode($object, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            202,
            array_filter(['Content-Type' => 'application/json', 'Idempotent-Replayed' => $replayed], fn (?string $value): bool => $value !== null),
        );
    }

    /**
     * Every recorded `POST …/generations`, in the order it was sent.
     *
     * @return list<Request>
     */
    protected function recordedCreateRequests(): array
    {
        return array_values(Http::recorded()
            ->map(fn (array $pair): Request => $pair[0])
            ->filter(fn (Request $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/generations'))
            ->all());
    }
}
