<?php

declare(strict_types=1);

namespace Tests\Feature\Reconciler;

use App\Domain\Generation\Actions\PollGenerationAction;
use App\Domain\Generation\Enums\GenerationStatus;
use App\Domain\Generation\Models\Generation;
use App\Support\Pixelforge\Enums\PixelforgeErrorCode;
use App\Support\Pixelforge\Exceptions\RateBudgetExhaustedException;
use App\Support\Pixelforge\PixelforgeRequestBudget;
use Carbon\CarbonImmutable;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Cache\RateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CapturesLogMessages;
use Tests\Concerns\InteractsWithGenerations;
use Tests\Concerns\InteractsWithGenerationSnapshots;
use Tests\Concerns\InteractsWithPixelforgeApi;
use Tests\Concerns\RunsTheReconciler;
use Tests\TestCase;

/**
 * AC 17: polls share D-44's budget with submits and always leave the reserve to
 * them; Pixelforge's own 429 or a spent budget stops the run's polls.
 */
final class ReconcileBudgetTest extends TestCase
{
    use CapturesLogMessages;
    use InteractsWithGenerations;
    use InteractsWithGenerationSnapshots;
    use InteractsWithPixelforgeApi;
    use RefreshDatabase;
    use RunsTheReconciler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-20T12:00:00Z'));
    }

    /**
     * The story's example: 12 units left in the minute give only 2 polls.
     */
    public function test_with_12_units_left_only_2_polls_are_sent(): void
    {
        $generations = $this->givenStaleGenerations(5);
        $this->answerPollsUnchanged();
        $this->takeUnits(38);

        $this->runReconciler();

        $this->assertCount(2, $this->recordedRetrieveRequests());
        $this->assertSame([true, true, false, false, false], array_map(
            fn (Generation $generation): bool => $this->reloadedGeneration($generation)->reconcile_recheck_at !== null,
            $generations,
        ));
        $this->assertSame(10, $this->app->make(PixelforgeRequestBudget::class)->remaining());
    }

    public function test_a_429_from_pixelforge_stops_the_runs_polls(): void
    {
        $generations = $this->givenStaleGenerations(3);
        Http::fake(['*' => self::pixelforgeErrorFor(PixelforgeErrorCode::RateLimitExceeded, headers: ['Retry-After' => '17'])]);

        $this->runReconciler();

        $this->assertCount(1, $this->recordedRetrieveRequests());
        $this->assertNull($this->reloadedGeneration($generations[1])->reconcile_recheck_at);
        $stopped = array_values(array_filter(
            $this->loggedMessages(),
            fn (MessageLogged $message): bool => str_contains($message->message, 'stopped this run\'s polls'),
        ));
        $this->assertCount(1, $stopped);
        $this->assertSame('warning', $stopped[0]->level);
        $this->assertSame($generations[0]->id, $stopped[0]->context['generation_id']);
        $this->assertSame('rate_limit_exceeded', $stopped[0]->context['error_code']);
    }

    public function test_a_spent_budget_sends_no_poll_even_with_no_reserve(): void
    {
        config(['pixelforge.reconcile.submit_reserve' => 0]);
        $this->givenStaleGenerations(2);
        $this->answerPollsUnchanged();
        $this->takeUnits(50);

        $this->runReconciler();

        $this->assertSame([], $this->recordedRetrieveRequests());
    }

    /**
     * The poll rethrows both, so its caller stops the run rather than spending the
     * rest of the minute on refusals.
     */
    public function test_a_poll_rethrows_a_spent_budget(): void
    {
        [$generation] = $this->givenStaleGenerations(1);
        $this->takeUnits(50);

        $this->expectException(RateBudgetExhaustedException::class);

        $this->app->make(PollGenerationAction::class)->execute($generation, CarbonImmutable::now());
    }

    /**
     * Oldest first, each a minute staler than the next.
     *
     * @return list<Generation>
     */
    private function givenStaleGenerations(int $count): array
    {
        $generations = [];

        for ($row = 0; $row < $count; $row++) {
            $generations[] = Generation::factory()->processing()->create([
                'last_provider_update_at' => CarbonImmutable::now()->subMinutes(10 + $count - $row),
            ]);
        }

        return $generations;
    }

    /**
     * Units taken by other calls in the current window, in the shared store.
     */
    private function takeUnits(int $units): void
    {
        (new RateLimiter(Cache::store('database')))->increment(PixelforgeRequestBudget::BUDGET_KEY, PixelforgeRequestBudget::WINDOW_SECONDS, $units);
    }

    private function answerPollsUnchanged(): void
    {
        Http::fake(fn (Request $request): PromiseInterface => self::pixelforgeRetrieved(self::generationObjectFor(
            Generation::query()->where('provider_id', basename((string) parse_url($request->url(), PHP_URL_PATH)))->firstOrFail(),
            GenerationStatus::Processing,
        )));
    }
}
