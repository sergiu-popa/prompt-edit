<?php

declare(strict_types=1);

namespace Tests\Feature\Pixelforge\Fake;

use App\Domain\Generation\Actions\CreateGenerationAction;
use App\Domain\Generation\DataTransferObjects\NewGenerationData;
use App\Domain\Generation\Enums\GenerationStatus;
use App\Domain\Shared\Enums\ImageQuality;
use App\Domain\Shared\Enums\ImageSize;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithCreditLedger;
use Tests\Concerns\InteractsWithFakePixelforge;
use Tests\Concerns\RunsTheReconciler;
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
    use RunsTheReconciler;

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
     * Both copies of each event verify and answer 2xx; the second is a
     * recorded duplicate, so the generation is charged exactly once. The
     * keyword counts as prompt characters: 57 code points, so e = 15, text
     * 12–18 and this prompt's own window.
     */
    public function test_a_duplicate_lighthouse_is_charged_once(): void
    {
        $request = new NewGenerationData(self::LIGHTHOUSE_PROMPT . ' [duplicate]', ImageSize::Landscape, ImageQuality::High, ['https://example.com/style.png']);
        $generation = $this->app->make(CreateGenerationAction::class)->execute(User::factory()->create(), $request);

        $responses = $this->replayFakeWebhooks();

        $this->assertCount(4, $responses);
        $this->assertTrue(array_all($responses, fn (TestResponse $response): bool => $response->isSuccessful()));
        $this->assertSame(GenerationStatus::Succeeded, $this->reloadedGeneration($generation)->status);

        $charge = $this->chargeFromTheSucceededWebhook();
        $this->assertSame(
            [['hold', -457_175_000], ['release', 457_175_000], ['charge', -$charge]],
            $this->ledgerEntriesFor($generation),
        );
        $this->assertGreaterThanOrEqual(347_762_500, $charge);
        $this->assertLessThanOrEqual(457_175_000, $charge);
        $this->assertSame(2, DB::table('pixelforge_webhook_events')->where('generation_id', $generation->id)->count());
    }

    /**
     * The story's literal Lighthouse, with every webhook duplicated by chaos
     * rather than by a keyword, is charged once within its own window.
     */
    public function test_a_lighthouse_duplicated_by_chaos_is_charged_once_within_its_estimate(): void
    {
        config(['pixelforge.fake.chaos.duplicate_percent' => 100]);
        $generation = $this->app->make(CreateGenerationAction::class)->execute(User::factory()->create(), self::lighthouseRequest());

        $responses = $this->replayFakeWebhooks();

        $this->assertCount(4, $responses);
        $this->assertTrue(array_all($responses, fn (TestResponse $response): bool => $response->isSuccessful()));

        $charge = $this->chargeFromTheSucceededWebhook();
        $this->assertSame(
            [['hold', -self::LIGHTHOUSE_HOLD], ['release', self::LIGHTHOUSE_HOLD], ['charge', -$charge]],
            $this->ledgerEntriesFor($generation),
        );
        $this->assertGreaterThanOrEqual(347_750_000, $charge);
        $this->assertLessThanOrEqual(457_150_000, $charge);
    }

    /**
     * AC 22's prompt, end to end in-process: the fake sends no webhook, so the row
     * stays queued until the reconciler polls it past the queued threshold, and the
     * fake's own usage then prices it.
     */
    public function test_a_no_webhooks_generation_is_settled_by_the_reconciler(): void
    {
        $request = new NewGenerationData('[succeed] [no-webhooks] a red fox', ImageSize::Square, ImageQuality::Low, []);
        $generation = $this->app->make(CreateGenerationAction::class)->execute(User::factory()->create(), $request);
        $providerId = $this->reloadedGeneration($generation)->provider_id;
        $this->assertNotNull($providerId);

        $this->assertSame([], $this->fakeWebhookRequests());
        $this->assertSame(GenerationStatus::Succeeded, $this->fakeGenerationRow($providerId)->status());
        $this->assertSame(GenerationStatus::Queued, $this->reloadedGeneration($generation)->status);

        $this->travel(121)->seconds();
        $this->runReconciler();

        $usage = $this->fakeGenerationRow($providerId)->usage;
        $provider = $usage['input_text_tokens'] * 5_000 + $usage['input_image_tokens'] * 10_000 + $usage['output_tokens'] * 40_000 + $usage['reasoning_tokens'] * 12_500;
        $charge = intdiv($provider * 125 + 99, 100);
        $settled = $this->reloadedGeneration($generation);
        $this->assertSame(GenerationStatus::Succeeded, $settled->status);
        $this->assertSame($provider, $settled->provider_cost_nanodollars);
        $this->assertSame($charge, $settled->user_charge_nanodollars);
        $this->assertSame([['hold', -21_881_250], ['release', 21_881_250], ['charge', -$charge]], $this->ledgerEntriesFor($generation));
        $this->assertSame([], $this->fakeWebhookRequests());
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
        $this->assertNotEmpty($succeeded);
        $this->assertSame(1, count(array_unique(array_column($succeeded, 'id'))));

        /** @var array{input_text_tokens: int, input_image_tokens: int, output_tokens: int, reasoning_tokens: int} $usage */
        $usage = $succeeded[0]['data']['object']['usage'];
        $provider = $usage['input_text_tokens'] * 5_000
            + $usage['input_image_tokens'] * 10_000
            + $usage['output_tokens'] * 40_000
            + $usage['reasoning_tokens'] * 12_500;

        return intdiv($provider * 125 + 99, 100);
    }
}
