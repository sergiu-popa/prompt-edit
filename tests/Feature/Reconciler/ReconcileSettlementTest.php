<?php

declare(strict_types=1);

namespace Tests\Feature\Reconciler;

use App\Domain\Generation\Enums\GenerationStatus;
use App\Domain\Generation\Models\Generation;
use App\Models\User;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CapturesLogMessages;
use Tests\Concerns\DeliversPixelforgeWebhooks;
use Tests\Concerns\InteractsWithCreditLedger;
use Tests\Concerns\InteractsWithPixelforgeApi;
use Tests\Concerns\ReadsGenerationHistory;
use Tests\Concerns\RunsTheReconciler;
use Tests\TestCase;

/**
 * The money of a poll, on PE-014's red fox: a poll settles exactly as a webhook
 * would, and exactly once however the two arrive.
 */
final class ReconcileSettlementTest extends TestCase
{
    use CapturesLogMessages;
    use DeliversPixelforgeWebhooks;
    use InteractsWithCreditLedger;
    use InteractsWithPixelforgeApi;
    use ReadsGenerationHistory;
    use RefreshDatabase;
    use RunsTheReconciler;

    /**
     * 5 × 5,000 + 280 × 40,000 + 312 × 12,500 n$.
     */
    private const int RED_FOX_PROVIDER_COST = 15_125_000;

    /**
     * ceil(15,125,000 × 125 / 100) n$.
     */
    private const int RED_FOX_CHARGE = 18_906_250;

    private const array RED_FOX_CHARGED = [
        ['hold', -self::RED_FOX_HOLD],
        ['release', self::RED_FOX_HOLD],
        ['charge', -self::RED_FOX_CHARGE],
    ];

    /**
     * AC 7: the poll's own usage and rates price it.
     */
    public function test_a_succeeded_poll_charges_the_red_fox_its_actual_cost_once(): void
    {
        $redFox = $this->givenTheStaleRedFox();
        Http::fake(['*' => self::pixelforgeRetrieved(self::redFoxSucceededObjectFor($redFox))]);

        $this->runReconciler();

        $row = $this->reloadedGeneration($redFox);
        $this->assertSame(GenerationStatus::Succeeded, $row->status);
        $this->assertSame([5, 0, 280, 312], [$row->input_text_tokens, $row->input_image_tokens, $row->output_tokens, $row->reasoning_tokens]);
        $this->assertSame(self::RED_FOX_PROVIDER_COST, $row->provider_cost_nanodollars);
        $this->assertSame(self::RED_FOX_CHARGE, $row->user_charge_nanodollars);
        $this->assertSame(self::RED_FOX_CHARGED, $this->ledgerEntriesFor($redFox));
        $this->assertSame(-self::RED_FOX_CHARGE, $this->ledgerNetFor($redFox));
    }

    /**
     * AC 8: the webhook that arrives later, twice under `[duplicate]`, is a no-op.
     */
    public function test_a_later_succeeded_webhook_delivered_twice_writes_nothing(): void
    {
        $redFox = $this->givenTheStaleRedFox();
        Http::fake(['*' => self::pixelforgeRetrieved(self::redFoxSucceededObjectFor($redFox))]);
        $this->runReconciler();
        $settled = $this->rawGenerationRow($redFox);
        $body = json_encode(self::pixelforgeEvent('generation.succeeded', self::redFoxSucceededObjectFor($redFox)), JSON_THROW_ON_ERROR);

        $this->travel(10)->seconds();
        $this->deliverPixelforgeWebhook($body)->assertOk();
        $this->deliverPixelforgeWebhook($body)->assertOk();

        $this->assertSame($settled, $this->rawGenerationRow($redFox));
        $this->assertSame(self::RED_FOX_CHARGED, $this->ledgerEntriesFor($redFox));
    }

    /**
     * AC 9: a failure is free, and History shows PE-012's copy for its code.
     */
    #[DataProvider('providerFailures')]
    public function test_a_failed_poll_releases_the_hold_and_history_explains_it(string $code, string $copy): void
    {
        $redFox = $this->givenTheStaleRedFox();
        Http::fake(['*' => self::pixelforgeRetrieved(self::generationObjectFor($redFox, GenerationStatus::Failed, [
            'error' => ['code' => $code, 'message' => 'Pixelforge says it failed.'],
        ]))]);

        $this->runReconciler();

        $row = $this->reloadedGeneration($redFox);
        $this->assertSame(GenerationStatus::Failed, $row->status);
        $this->assertSame($code, $row->error_code);
        $this->assertSame('Pixelforge says it failed.', $row->error_message);
        $this->assertSame(0, $row->user_charge_nanodollars);
        $this->assertSame([['hold', -self::RED_FOX_HOLD], ['release', self::RED_FOX_HOLD]], $this->ledgerEntriesFor($redFox));

        $item = $this->onlyHistoryItemOf(User::query()->findOrFail($redFox->user_id));
        $this->assertSame('failed', $item['status']);
        $this->assertSame($copy, $item['failure_message']);
        $this->assertNull($item['user_charge_nanodollars']);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function providerFailures(): array
    {
        return [
            'generation_failed' => ['generation_failed', 'Pixelforge couldn\'t produce an image for this prompt. Try rephrasing it.'],
            'internal_error' => ['internal_error', 'Something went wrong on Pixelforge\'s side. Please try again.'],
        ];
    }

    /**
     * AC 11: the webhook lands first; the poll that follows finds it terminal.
     */
    public function test_a_webhook_then_a_poll_charge_once(): void
    {
        $redFox = $this->givenTheStaleRedFox();
        $this->deliverRedFoxSucceeded($redFox);
        $settled = $this->rawGenerationRow($redFox);
        Http::fake(['*' => self::pixelforgeRetrieved(self::redFoxSucceededObjectFor($redFox))]);

        $this->travel(5)->minutes();
        $this->runReconciler();

        $this->assertSame([], $this->recordedRetrieveRequests(), 'A terminal row is never polled.');
        $this->assertSame($settled, $this->rawGenerationRow($redFox));
        $this->assertChargedOnce($redFox);
    }

    public function test_a_poll_then_a_webhook_charge_once(): void
    {
        $redFox = $this->givenTheStaleRedFox();
        Http::fake(['*' => self::pixelforgeRetrieved(self::redFoxSucceededObjectFor($redFox))]);

        $this->runReconciler();
        $this->deliverRedFoxSucceeded($redFox);

        $this->assertChargedOnce($redFox);
    }

    /**
     * The webhook settles the row while the poll's request is in flight, so the
     * poll's transition runs second and finds it terminal; the run still completes.
     */
    public function test_a_webhook_delivered_during_the_poll_charges_once(): void
    {
        $redFox = $this->givenTheStaleRedFox();
        Http::fake(function () use ($redFox): PromiseInterface {
            $this->deliverRedFoxSucceeded($redFox);
            $this->assertSame(GenerationStatus::Succeeded, $this->reloadedGeneration($redFox)->status);

            return self::pixelforgeRetrieved(self::redFoxSucceededObjectFor($redFox));
        });

        $this->runReconciler();

        $this->assertCount(1, $this->recordedRetrieveRequests());
        $this->assertChargedOnce($redFox);
        $this->assertSame([], array_filter($this->loggedMessages(), fn (MessageLogged $message): bool => $message->level === 'error'));
    }

    /**
     * Submitted, then three minutes without news: past the 2 min queued threshold.
     */
    private function givenTheStaleRedFox(): Generation
    {
        $redFox = $this->givenTheRedFoxGeneration();
        $this->transitionGeneration($redFox, self::snapshotFor($redFox, GenerationStatus::Queued));
        $this->travel(3)->minutes();

        return $this->reloadedGeneration($redFox);
    }

    /**
     * Usage 5 / 0 / 280 / 312 at the published rates.
     *
     * @return array<string, mixed>
     */
    private static function redFoxSucceededObjectFor(Generation $redFox): array
    {
        return self::generationObjectFor($redFox, GenerationStatus::Succeeded, [
            'usage' => [
                'input_text_tokens' => 5,
                'input_image_tokens' => 0,
                'output_tokens' => 280,
                'reasoning_tokens' => 312,
                'total_tokens' => 597,
            ],
        ]);
    }

    private function deliverRedFoxSucceeded(Generation $redFox): void
    {
        $this->deliverPixelforgeEvent(self::pixelforgeEvent('generation.succeeded', self::redFoxSucceededObjectFor($redFox)))->assertOk();
    }

    private function assertChargedOnce(Generation $redFox): void
    {
        $row = $this->reloadedGeneration($redFox);
        $this->assertSame(GenerationStatus::Succeeded, $row->status);
        $this->assertSame(self::RED_FOX_PROVIDER_COST, $row->provider_cost_nanodollars);
        $this->assertSame(self::RED_FOX_CHARGE, $row->user_charge_nanodollars);
        $this->assertSame(self::RED_FOX_CHARGED, $this->ledgerEntriesFor($redFox));
    }
}
