<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use App\Domain\Generation\Actions\CreateGenerationAction;
use App\Domain\Generation\DataTransferObjects\NewGenerationData;
use App\Domain\Generation\Enums\GenerationStatus;
use App\Domain\Generation\Models\Generation;
use App\Domain\Reporting\Actions\CompareProviderUsageAction;
use App\Domain\Reporting\Enums\UsageAvailability;
use App\Domain\Reporting\ValueObjects\ReportRange;
use App\Domain\Shared\Enums\ImageQuality;
use App\Domain\Shared\Enums\ImageSize;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithFakePixelforge;
use Tests\Concerns\RunsTheReconciler;
use Tests\TestCase;

/**
 * AC 3's local parity (D-75): generations made through the app and settled by the fake's
 * webhooks or by the reconciler produce a fake `/v1/usage` exactly equal to what we
 * recorded. The fake prices in decimals and we in integers, so this checks our conversion
 * and our bucketing independently. On the `sync` queue each lifecycle runs inside its create.
 */
final class FakeUsageParityTest extends TestCase
{
    use InteractsWithFakePixelforge;
    use RefreshDatabase;
    use RunsTheReconciler;

    public function test_every_day_and_the_total_match_the_fakes_usage(): void
    {
        $user = User::factory()->create();

        $this->travelTo(CarbonImmutable::parse('2026-11-17T23:58:00Z'));
        $low = $this->create($user, '[succeed] a calm harbour', ImageSize::Square, ImageQuality::Low);
        $this->travelTo(CarbonImmutable::parse('2026-11-17T23:59:59Z'));
        $high = $this->create($user, '[succeed] a mountain lake at noon', ImageSize::Portrait, ImageQuality::High);
        $this->travelTo(CarbonImmutable::parse('2026-11-18T00:00:01Z'));
        $afterMidnight = $this->create($user, '[succeed] a lighthouse at dawn', ImageSize::Square, ImageQuality::Low);
        $failed = $this->create($user, '[fail] a broken pocket watch', ImageSize::Square, ImageQuality::Low);
        $polled = $this->create($user, '[succeed] [no-webhooks] a red fox', ImageSize::Landscape, ImageQuality::Medium);

        $this->assertTrue(array_all($this->replayFakeWebhooks(), fn (TestResponse $response): bool => $response->isSuccessful()));
        $this->travel(121)->seconds();
        $this->runReconciler();

        foreach ([$low, $high, $afterMidnight, $polled] as $generation) {
            $this->assertSame(GenerationStatus::Succeeded, $this->reloadedGeneration($generation)->status);
        }

        $this->assertSame(GenerationStatus::Failed, $this->reloadedGeneration($failed)->status);

        $comparison = $this->app->make(CompareProviderUsageAction::class)->execute(ReportRange::fromDates('2026-11-16', '2026-11-18'), CarbonImmutable::now());
        $array = $comparison->toArray();

        $this->assertSame(UsageAvailability::Available, $comparison->availability);
        $this->assertFalse($comparison->hasMismatch());
        $this->assertSame([true, true, true], array_column($array['days'], 'matches'));
        $this->assertTrue($array['total']['matches']);

        foreach ([...$array['days'], $array['total']] as $line) {
            $this->assertSame(array_diff_key($line['recorded'], ['generations' => true]), array_diff_key($line['pixelforge'], ['generations' => true]));
        }

        $this->assertSame(2, $array['days'][1]['recorded']['generations']);
        $this->assertSame(2, $array['days'][0]['recorded']['generations']);
        $this->assertSame(
            $this->reloadedGeneration($low)->provider_cost_nanodollars + $this->reloadedGeneration($high)->provider_cost_nanodollars,
            $array['days'][1]['pixelforge']['provider_cost_nanodollars'],
        );
        $this->assertGreaterThan(0, $array['total']['pixelforge']['provider_cost_nanodollars']);
    }

    private function create(User $user, string $prompt, ImageSize $size, ImageQuality $quality): Generation
    {
        return $this->app->make(CreateGenerationAction::class)->execute($user, new NewGenerationData($prompt, $size, $quality, []));
    }
}
