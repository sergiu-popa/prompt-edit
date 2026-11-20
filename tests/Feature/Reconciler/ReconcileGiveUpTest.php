<?php

declare(strict_types=1);

namespace Tests\Feature\Reconciler;

use App\Domain\Generation\Enums\GenerationStatus;
use App\Domain\Generation\Enums\LocalFailureReason;
use App\Domain\Generation\Enums\SubmitAttemptOutcome;
use App\Domain\Generation\Models\Generation;
use App\Domain\Generation\ValueObjects\SubmitBookkeeping;
use App\Jobs\SubmitGenerationJob;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Testing\Fakes\QueueFake;
use Tests\Concerns\CapturesLogMessages;
use Tests\Concerns\DeliversPixelforgeWebhooks;
use Tests\Concerns\InteractsWithCreditLedger;
use Tests\Concerns\InteractsWithPixelforgeApi;
use Tests\Concerns\ReadsGenerationHistory;
use Tests\Concerns\RunsTheReconciler;
use Tests\TestCase;

/**
 * AC 20, on PE-014's red fox: a generation Pixelforge never confirmed is given up a
 * day after its creation, as `lost` and free (D-49). A late success then records
 * Pixelforge's cost only (D-09).
 */
final class ReconcileGiveUpTest extends TestCase
{
    use CapturesLogMessages;
    use DeliversPixelforgeWebhooks;
    use InteractsWithCreditLedger;
    use InteractsWithPixelforgeApi;
    use ReadsGenerationHistory;
    use RefreshDatabase;
    use RunsTheReconciler;

    private const int DAY_SECONDS = 86_400;

    public function test_a_day_after_creation_a_handed_over_row_is_failed_lost_and_free(): void
    {
        $redFox = $this->givenTheHandedOverRedFox();
        $this->travel(self::DAY_SECONDS)->seconds();

        $this->runReconciler();

        $row = $this->reloadedGeneration($redFox);
        $this->assertSame(GenerationStatus::Failed, $row->status);
        $this->assertSame(LocalFailureReason::Lost, $row->local_failure_reason);
        $this->assertSame(0, $row->provider_cost_nanodollars);
        $this->assertSame(0, $row->user_charge_nanodollars);
        $this->assertSame([['hold', -self::RED_FOX_HOLD], ['release', self::RED_FOX_HOLD]], $this->ledgerEntriesFor($redFox));
        $this->assertSame([], $this->redispatchedJobs());

        $errors = array_values(array_filter($this->loggedMessages(), fn (MessageLogged $message): bool => $message->level === 'error'));
        $this->assertCount(1, $errors);
        $this->assertSame('Gave up on a generation Pixelforge never confirmed; it is failed as lost and not charged.', $errors[0]->message);
        $this->assertSame($redFox->id, $errors[0]->context['generation_id']);
        $this->assertSame('applied', $errors[0]->context['outcome']);
    }

    public function test_a_second_before_the_day_it_is_redispatched_instead(): void
    {
        $redFox = $this->givenTheHandedOverRedFox();
        $this->travel(self::DAY_SECONDS - 1)->seconds();

        $this->runReconciler();

        $this->assertSame(GenerationStatus::Pending, $this->reloadedGeneration($redFox)->status);
        $this->assertSame([$redFox->id], array_map(fn (SubmitGenerationJob $job): string => $job->generationId, $this->redispatchedJobs()));
    }

    /**
     * The webhook names our ULID, so it is matched; the row stays failed and free.
     */
    public function test_a_lost_row_that_later_succeeds_records_the_provider_cost_only(): void
    {
        $redFox = $this->givenTheHandedOverRedFox();
        $this->travel(self::DAY_SECONDS)->seconds();
        $this->runReconciler();
        $ledger = $this->ledgerEntriesFor($redFox);

        $this->deliverPixelforgeEvent(self::pixelforgeEvent('generation.succeeded', self::generationObjectFor($redFox, GenerationStatus::Succeeded, [
            'usage' => [
                'input_text_tokens' => 5,
                'input_image_tokens' => 0,
                'output_tokens' => 280,
                'reasoning_tokens' => 312,
                'total_tokens' => 597,
            ],
        ])))->assertOk();

        $row = $this->reloadedGeneration($redFox);
        $this->assertSame(GenerationStatus::Failed, $row->status);
        $this->assertSame(15_125_000, $row->provider_cost_nanodollars);
        $this->assertSame(0, $row->user_charge_nanodollars);
        $this->assertSame($ledger, $this->ledgerEntriesFor($redFox));

        $item = $this->onlyHistoryItemOf(User::query()->findOrFail($redFox->user_id));
        $this->assertSame('failed', $item['status']);
        $this->assertSame('We couldn\'t confirm this generation with Pixelforge. You were not charged.', $item['failure_message']);
        $this->assertNull($item['user_charge_nanodollars']);
        $this->assertNull($item['image_url']);
    }

    /**
     * Its one attempt timed out, and PE-013 handed it over at its window's end.
     */
    private function givenTheHandedOverRedFox(): Generation
    {
        $redFox = $this->givenTheRedFoxGeneration();
        $attemptedAt = CarbonImmutable::now();
        $redFox->recordSubmitBookkeeping((new SubmitBookkeeping)
            ->startAttempt($attemptedAt)
            ->finishAttempt(SubmitAttemptOutcome::Ambiguous, $attemptedAt)
            ->handOver($attemptedAt->addMinutes(30)));

        return $this->reloadedGeneration($redFox);
    }

    /**
     * @return list<SubmitGenerationJob>
     */
    private function redispatchedJobs(): array
    {
        $queue = Queue::getFacadeRoot();
        $this->assertInstanceOf(QueueFake::class, $queue);

        return array_values(array_filter(
            $queue->pushed(SubmitGenerationJob::class)->all(),
            fn (mixed $job): bool => $job instanceof SubmitGenerationJob && $job->redispatchedAt !== null,
        ));
    }
}
