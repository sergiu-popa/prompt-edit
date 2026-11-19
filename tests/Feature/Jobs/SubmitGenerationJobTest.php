<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Domain\Generation\DataTransferObjects\NewGenerationData;
use App\Domain\Generation\Enums\GenerationStatus;
use App\Domain\Generation\Models\Generation;
use App\Domain\Shared\Enums\ImageQuality;
use App\Domain\Shared\Enums\ImageSize;
use App\Models\User;
use App\Support\Pixelforge\Enums\PixelforgeErrorCode;
use App\Support\Pixelforge\Exceptions\AmbiguousPixelforgeOutcomeException;
use App\Support\Pixelforge\Exceptions\OverloadedException;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\DeliversPixelforgeWebhooks;
use Tests\Concerns\InteractsWithCreditLedger;
use Tests\Concerns\InteractsWithPixelforgeApi;
use Tests\TestCase;

/**
 * The submit job against `Http::fake`, through the transition action and the
 * ledger: what it sends for a stored row, and what Pixelforge's answer leaves.
 */
final class SubmitGenerationJobTest extends TestCase
{
    use DeliversPixelforgeWebhooks;
    use InteractsWithCreditLedger;
    use InteractsWithPixelforgeApi;
    use RefreshDatabase;

    private const int LIGHTHOUSE_HOLD = -457_150_000;

    private const array LIGHTHOUSE_CHARGED = [['hold', -457_150_000], ['release', 457_150_000], ['charge', -381_887_500]];

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

    public function test_a_replay_that_has_moved_on_to_succeeded_is_charged_once(): void
    {
        $lighthouse = $this->givenTheLighthouseGeneration();
        $succeeded = self::lighthouseSucceededObjectFor($lighthouse);
        Http::fake(['*' => self::pixelforgeAccepted($succeeded, replayed: 'true')]);

        $this->runSubmitJob($lighthouse);

        $row = $this->reloadedGeneration($lighthouse);
        $this->assertSame(GenerationStatus::Succeeded, $row->status);
        $this->assertSame('gen_' . strtoupper($lighthouse->id), $row->provider_id);
        $this->assertSame(305_510_000, $row->provider_cost_nanodollars);
        $this->assertSame(381_887_500, $row->user_charge_nanodollars);
        $this->assertSame(self::LIGHTHOUSE_CHARGED, $this->ledgerEntriesFor($lighthouse));
        $this->assertSame(-381_887_500, $this->ledgerNetFor($lighthouse));

        $after = $this->rawGenerationRow($lighthouse);
        $this->deliverPixelforgeEvent(self::pixelforgeEvent('generation.succeeded', $succeeded))->assertOk();

        $this->assertSame($after, $this->rawGenerationRow($lighthouse));
        $this->assertSame(self::LIGHTHOUSE_CHARGED, $this->ledgerEntriesFor($lighthouse));
    }

    public function test_a_webhook_applied_while_the_create_is_in_flight_is_not_undone(): void
    {
        $lighthouse = $this->givenTheLighthouseGeneration();
        Http::fake(function () use ($lighthouse): PromiseInterface {
            $this->deliverPixelforgeEvent(self::pixelforgeEvent('generation.succeeded', self::lighthouseSucceededObjectFor($lighthouse)))->assertOk();
            $this->assertSame(GenerationStatus::Succeeded, $this->reloadedGeneration($lighthouse)->status);

            return self::pixelforgeAccepted(self::generationObjectFor($lighthouse, GenerationStatus::Queued));
        });

        $this->runSubmitJob($lighthouse);

        $row = $this->reloadedGeneration($lighthouse);
        $this->assertCount(1, $this->recordedCreateRequests());
        $this->assertSame(GenerationStatus::Succeeded, $row->status);
        $this->assertSame('gen_' . strtoupper($lighthouse->id), $row->provider_id);
        $this->assertSame(self::LIGHTHOUSE_CHARGED, $this->ledgerEntriesFor($lighthouse));
    }

    public function test_a_lost_response_is_recovered_by_the_replay_of_the_same_generation(): void
    {
        $lighthouse = $this->givenTheLighthouseGeneration();
        $accepted = self::generationObjectFor($lighthouse, GenerationStatus::Queued);
        Http::fake(['*' => Http::sequence()
            ->pushFailedConnection('cURL error 28: Operation timed out after 30001 milliseconds with 0 bytes received')
            ->pushResponse(self::pixelforgeAccepted($accepted, replayed: 'true'))]);
        $before = [$this->rawGenerationRow($lighthouse), $this->ledgerEntriesFor($lighthouse)];

        try {
            $this->runSubmitJob($lighthouse);
            $this->fail('Expected the timed-out attempt to be ambiguous.');
        } catch (AmbiguousPixelforgeOutcomeException) {
            $this->assertSame($before, [$this->rawGenerationRow($lighthouse), $this->ledgerEntriesFor($lighthouse)]);
        }

        $this->runSubmitJob($this->reloadedGeneration($lighthouse));

        $row = $this->reloadedGeneration($lighthouse);
        $this->assertSame(GenerationStatus::Queued, $row->status);
        $this->assertSame($accepted['id'], $row->provider_id);
        $this->assertSame([['hold', self::LIGHTHOUSE_HOLD]], $this->ledgerEntriesFor($lighthouse));
        $this->assertSame(1, Generation::query()->count());
    }

    public function test_two_attempts_send_byte_identical_bodies_under_the_same_key(): void
    {
        $generation = $this->createGenerationFor(User::factory()->create(), new NewGenerationData(
            self::UNICODE_PROMPT,
            ImageSize::Landscape,
            ImageQuality::High,
            ['https://example.com/style.png', 'https://example.com/palette.png?v=2&crop=1'],
        ));
        Http::fake(['*' => Http::sequence()
            ->pushResponse(self::pixelforgeErrorFor(PixelforgeErrorCode::Overloaded, headers: ['Retry-After' => '2']))
            ->pushResponse(self::pixelforgeAccepted(self::generationObjectFor($generation, GenerationStatus::Queued)))]);

        try {
            $this->runSubmitJob($this->reloadedGeneration($generation));
            $this->fail('Expected the first attempt to be overloaded.');
        } catch (OverloadedException) {
            $this->runSubmitJob($this->reloadedGeneration($generation));
        }

        [$first, $second] = $this->recordedCreateRequests();
        $stored = $this->reloadedGeneration($generation);
        $this->assertSame($first->body(), $second->body());
        $this->assertSame([$generation->id], $first->header('Idempotency-Key'));
        $this->assertSame($first->header('Idempotency-Key'), $second->header('Idempotency-Key'));
        $this->assertSame($stored->prompt, self::decodedRequestBody($second)['prompt']);
        $this->assertSame(self::UNICODE_PROMPT, $stored->prompt);
        $this->assertSame(GenerationStatus::Queued, $stored->status);
    }
}
