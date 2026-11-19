<?php

declare(strict_types=1);

namespace Tests\Feature\Pixelforge\Fake;

use App\Domain\Generation\Actions\CreateGenerationAction;
use App\Domain\Generation\Enums\GenerationStatus;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithCreditLedger;
use Tests\Concerns\InteractsWithFakePixelforge;
use Tests\TestCase;

/**
 * The whole local loop: a generation is created, the submit job sends it to
 * the fake, and the fake's captured webhooks, replayed byte for byte into our
 * real endpoint, pass verification and settle it. On the `sync` queue the
 * submit job and the fake's lifecycle all run inside the create.
 */
final class FakePixelforgeRoundTripTest extends TestCase
{
    use InteractsWithCreditLedger;
    use InteractsWithFakePixelforge;
    use RefreshDatabase;

    private const string FROZEN_NOW = '2026-11-19T12:00:00Z';

    /**
     * The Lighthouse's hold: its estimate's user max.
     */
    private const int LIGHTHOUSE_HOLD = 457_150_000;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse(self::FROZEN_NOW));
    }

    public function test_a_lighthouse_settles_through_the_real_webhook_endpoint(): void
    {
        $generation = $this->app->make(CreateGenerationAction::class)->execute(User::factory()->create(), self::lighthouseRequest());

        $responses = $this->replayFakeWebhooks();

        $this->assertCount(2, $responses);
        $this->assertTrue(array_all($responses, fn (TestResponse $response): bool => $response->isSuccessful()));

        $settled = $this->reloadedGeneration($generation);
        $this->assertSame(GenerationStatus::Succeeded, $settled->status);
        $this->assertNotNull($settled->provider_id);

        $charge = $this->chargeFromTheSucceededWebhook();
        $this->assertSame(
            [['hold', -self::LIGHTHOUSE_HOLD], ['release', self::LIGHTHOUSE_HOLD], ['charge', -$charge]],
            $this->ledgerEntriesFor($generation),
        );
        $this->assertSame($charge, $settled->user_charge_nanodollars);
        $this->assertGreaterThanOrEqual(347_750_000, $charge);
        $this->assertLessThanOrEqual(457_150_000, $charge);
        $this->assertSame(2, DB::table('pixelforge_webhook_events')->where('generation_id', $generation->id)->count());
    }

    /**
     * ceil(provider × 125 ÷ 100) of the replayed succeeded body's usage,
     * computed here from the docs' rates.
     */
    private function chargeFromTheSucceededWebhook(): int
    {
        $succeeded = array_values(array_filter(
            array_map(fn (Request $request): mixed => json_decode($request->body(), associative: true, flags: JSON_THROW_ON_ERROR), $this->fakeWebhookRequests()),
            fn (mixed $event): bool => is_array($event) && $event['type'] === 'generation.succeeded',
        ));
        $this->assertCount(1, $succeeded);

        /** @var array{input_text_tokens: int, input_image_tokens: int, output_tokens: int, reasoning_tokens: int} $usage */
        $usage = $succeeded[0]['data']['object']['usage'];
        $provider = $usage['input_text_tokens'] * 5_000
            + $usage['input_image_tokens'] * 10_000
            + $usage['output_tokens'] * 40_000
            + $usage['reasoning_tokens'] * 12_500;

        return intdiv($provider * 125 + 99, 100);
    }
}
