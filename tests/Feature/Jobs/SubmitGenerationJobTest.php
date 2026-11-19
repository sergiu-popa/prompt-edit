<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Domain\Generation\Enums\GenerationStatus;
use App\Domain\Generation\Models\Generation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithCreditLedger;
use Tests\Concerns\InteractsWithGenerations;
use Tests\Concerns\InteractsWithGenerationSnapshots;
use Tests\Concerns\InteractsWithPixelforgeApi;
use Tests\TestCase;

/**
 * The submit job against `Http::fake`, through the transition action and the
 * ledger: what it sends for a stored row, and what Pixelforge's answer leaves.
 */
final class SubmitGenerationJobTest extends TestCase
{
    use InteractsWithCreditLedger;
    use InteractsWithGenerations;
    use InteractsWithGenerationSnapshots;
    use InteractsWithPixelforgeApi;
    use RefreshDatabase;

    private const int LIGHTHOUSE_HOLD = -457_150_000;

    public function test_the_lighthouse_row_is_sent_as_exactly_the_documented_body(): void
    {
        $lighthouse = $this->givenTheLighthouseGeneration();
        Http::fake(['*' => self::pixelforgeAccepted(self::generationObjectFor($lighthouse, GenerationStatus::Queued))]);

        $this->runSubmitJob($lighthouse);

        $requests = $this->recordedCreateRequests();
        $this->assertCount(1, $requests);
        $this->assertSame([$lighthouse->id], $requests[0]->header('Idempotency-Key'));
        $this->assertSame([
            'prompt' => 'A lighthouse on a cliff at dawn, oil painting',
            'size' => '1536x1024',
            'quality' => 'high',
            'reference_images' => ['https://example.com/style.png'],
            'metadata' => ['generation_id' => $lighthouse->id],
        ], self::decodedRequestBody($requests[0]));
    }

    public function test_a_queued_answer_leaves_the_generation_queued_with_its_hold(): void
    {
        $lighthouse = $this->givenTheLighthouseGeneration();
        Http::fake(['*' => self::pixelforgeAccepted(self::generationObjectFor($lighthouse, GenerationStatus::Queued))]);

        $this->runSubmitJob($lighthouse);

        $submitted = $this->reloadedGeneration($lighthouse);
        $this->assertSame(GenerationStatus::Queued, $submitted->status);
        $this->assertSame('gen_' . strtoupper($lighthouse->id), $submitted->provider_id);
        $this->assertSameInstant('2026-11-18T23:59:40Z', $submitted->provider_created_at);
        $this->assertNull($submitted->provider_cost_nanodollars);
        $this->assertNull($submitted->user_charge_nanodollars);
        $this->assertNull($submitted->local_failure_reason);
        $this->assertSame([['hold', self::LIGHTHOUSE_HOLD]], $this->ledgerEntriesFor($lighthouse));
        $this->assertSame(self::LIGHTHOUSE_HOLD, $this->ledgerNetFor($lighthouse));
    }

    #[DataProvider('settledRows')]
    public function test_a_generation_with_a_provider_id_or_a_terminal_status_is_not_sent(string $state): void
    {
        $factory = Generation::factory();
        $generation = match ($state) {
            'queued' => $factory->queued()->create(),
            'processing' => $factory->processing()->create(),
            'succeeded' => $factory->succeeded()->create(),
            'failed' => $factory->failed()->create(),
            'failed locally' => $factory->failedLocally()->create(),
            default => $this->fail(sprintf('Unknown state %s.', $state)),
        };
        Http::fake();
        $before = [$this->rawGenerationRow($generation), $this->ledgerEntriesFor($generation)];

        $this->runSubmitJob($generation);

        Http::assertNothingSent();
        $this->assertSame($before, [$this->rawGenerationRow($generation), $this->ledgerEntriesFor($generation)]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function settledRows(): array
    {
        return [
            'queued' => ['queued'],
            'processing' => ['processing'],
            'succeeded' => ['succeeded'],
            'failed' => ['failed'],
            'failed locally' => ['failed locally'],
        ];
    }
}
