<?php

declare(strict_types=1);

namespace Tests\Feature\Pixelforge\Live;

use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\TestCase;

/**
 * The opt-in live smoke (D-12, D-58): our HTTP driver, the pinned pricing and
 * the estimator's assumptions against the real Pixelforge, with a dev key
 * only. It never runs in `make test`, `make check` or CI; `make live-smoke`
 * runs it. Nothing it prints carries the key or a request.
 */
#[Group('live')]
final class LivePixelforgeSmokeTest extends TestCase
{
    private string $baseUrl;

    /**
     * A refusal fails the run before any client exists. Only the configured
     * base URL gets past `preventStrayRequests`, and every exchange is recorded.
     */
    protected function setUp(): void
    {
        parent::setUp();

        try {
            LiveSmokeGuard::fromRuntime($this->app)->client(config());
        } catch (RuntimeException $exception) {
            $this->fail($exception->getMessage());
        }

        $this->baseUrl = (string) config('pixelforge.base_url');

        Http::allowStrayRequests([$this->baseUrl . '/*']);
        Http::record();
    }

    /**
     * AC 3: the live document equals the pinned snapshot, with the live
     * variance fraction mapped back from the pinned integer percent.
     */
    public function test_the_live_pricing_equals_the_pinned_snapshot(): void
    {
        $response = Http::baseUrl($this->baseUrl)->acceptJson()->withoutRedirecting()->get('pricing');

        $this->assertSame(200, $response->status());

        $pricing = $response->json();
        $this->assertIsArray($pricing);

        $this->assertSame('pricing', $pricing['object'] ?? null);
        $this->assertSame('usd', $pricing['currency'] ?? null);
        $this->assertSame('per_1m_tokens', $pricing['unit'] ?? null);

        $this->assertSame(
            self::sortedByKey(['rates' => $this->pinnedRates(), 'tokens' => $this->pinnedTokens()]),
            self::sortedByKey(['rates' => $pricing['rates'] ?? null, 'tokens' => $pricing['tokens'] ?? null]),
            'Pixelforge\'s pricing drifted from config/pricing.php.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function pinnedRates(): array
    {
        $rates = config('pricing.rates');
        $this->assertIsArray($rates);

        /** @var array<string, mixed> $rates */
        return $rates;
    }

    /**
     * The pinned token tables as the endpoint publishes them.
     *
     * @return array<string, mixed>
     */
    private function pinnedTokens(): array
    {
        $tokens = config('pricing.tokens');
        $this->assertIsArray($tokens);
        $this->assertIsInt($tokens['text_token_variance_percent'] ?? null);

        $tokens['text_token_variance'] = $tokens['text_token_variance_percent'] / 100;
        unset($tokens['text_token_variance_percent']);

        /** @var array<string, mixed> $tokens */
        return $tokens;
    }

    /**
     * A copy with every level's keys sorted, so key order never matters and types always do.
     */
    private static function sortedByKey(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        ksort($value);

        return array_map(self::sortedByKey(...), $value);
    }
}
