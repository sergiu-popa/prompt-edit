<?php

declare(strict_types=1);

namespace Tests\Feature\Generation;

use App\Domain\Generation\DataTransferObjects\LocalGenerationFailureData;
use App\Domain\Generation\Enums\GenerationStatus;
use App\Domain\Generation\Enums\LocalFailureReason;
use App\Domain\Generation\Enums\SubmitRetryNote;
use App\Domain\Generation\Models\Generation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithGenerations;
use Tests\Concerns\InteractsWithGenerationSnapshots;
use Tests\Concerns\ReadsGenerationHistory;
use Tests\TestCase;

/**
 * What History's resource says about each status: the times, the actual charge,
 * the image and whether it has expired by the server's clock, why it failed, and
 * nothing of the provider's cost.
 */
final class GenerationHistoryStatusTest extends TestCase
{
    use InteractsWithGenerations;
    use InteractsWithGenerationSnapshots;
    use ReadsGenerationHistory;
    use RefreshDatabase;

    /**
     * The docs' Lighthouse at the published rates: what Pixelforge bills, never sent.
     */
    private const string LIGHTHOUSE_PROVIDER_COST = '305510000';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-19T23:59:58Z'));
    }

    public function test_a_processing_generation_has_started_but_not_completed_or_been_charged(): void
    {
        $user = User::factory()->create();
        $processing = Generation::factory()->processing()->create(['user_id' => $user->id]);

        $item = $this->onlyHistoryItemOf($user);

        $this->assertSame($processing->started_at?->utc()->format('Y-m-d\TH:i:s\Z'), $item['started_at']);
        $this->assertNull($item['completed_at']);
        $this->assertNull($item['user_charge_nanodollars']);
    }

    public function test_a_pending_generation_has_no_times_or_charge(): void
    {
        $user = User::factory()->create();
        $this->createGenerationFor($user, self::lighthouseRequest());

        $item = $this->onlyHistoryItemOf($user);

        $this->assertNull($item['started_at']);
        $this->assertNull($item['completed_at']);
        $this->assertNull($item['user_charge_nanodollars']);
    }

    public function test_the_succeeded_lighthouse_shows_its_actual_charge_and_times_and_never_the_provider_cost(): void
    {
        $user = User::factory()->create();
        $this->givenTheSucceededLighthouseFor($user);

        $history = $this->historyFor($user);
        $item = $history['data'][0];

        $this->assertSame(381_887_500, $item['user_charge_nanodollars']);
        $this->assertSame(['min' => 347_750_000, 'max' => 457_150_000], $item['user_estimate_nanodollars']);
        $this->assertSame('2026-11-18T23:59:42Z', $item['started_at']);
        $this->assertSame('2026-11-18T23:59:59Z', $item['completed_at']);
        $this->assertSame([], array_intersect(array_keys($item), [
            'provider_cost_nanodollars',
            'rates',
            'input_text_tokens',
            'input_image_tokens',
            'output_tokens',
            'reasoning_tokens',
            'margin',
        ]));
        $this->assertStringNotContainsString(self::LIGHTHOUSE_PROVIDER_COST, (string) json_encode($history));
    }

    public function test_a_failed_generation_is_sent_without_a_charge_though_it_stores_zero(): void
    {
        $user = User::factory()->create();
        $failed = Generation::factory()->failed()->create(['user_id' => $user->id]);

        $item = $this->onlyHistoryItemOf($user);

        $this->assertSame(0, $failed->user_charge_nanodollars);
        $this->assertNull($item['user_charge_nanodollars']);
        $this->assertSame($failed->completed_at?->utc()->format('Y-m-d\TH:i:s\Z'), $item['completed_at']);
    }

    public function test_one_second_before_its_expiry_the_lighthouse_image_is_sent(): void
    {
        $user = User::factory()->create();
        $lighthouse = $this->givenTheSucceededLighthouseFor($user);

        $item = $this->onlyHistoryItemOf($user);

        $this->assertSame($lighthouse->image_url, $item['image_url']);
        $this->assertStringStartsWith('https://pixelforge.promptedit.dev/images/', (string) $item['image_url']);
        $this->assertSame('2026-11-19T23:59:59Z', $item['image_url_expires_at']);
        $this->assertFalse($item['image_expired']);
    }

    public function test_exactly_at_its_expiry_the_lighthouse_image_is_expired_and_its_url_withheld(): void
    {
        $user = User::factory()->create();
        $this->givenTheSucceededLighthouseFor($user);
        $this->travelTo(CarbonImmutable::parse('2026-11-19T23:59:59Z'));

        $item = $this->onlyHistoryItemOf($user);

        $this->assertNull($item['image_url']);
        $this->assertSame('2026-11-19T23:59:59Z', $item['image_url_expires_at']);
        $this->assertTrue($item['image_expired']);
    }

    /**
     * @return array<string, array{string, ?string}>
     */
    public static function imageUrls(): array
    {
        return [
            'https' => ['https://images.example.com/a.png?signature=x', 'https://images.example.com/a.png?signature=x'],
            'root-relative, as the fake signs it' => ['/fake-pixelforge/images/01jc?expires=1&signature=x', '/fake-pixelforge/images/01jc?expires=1&signature=x'],
            'javascript' => ['javascript:alert(1)', null],
            'protocol-relative' => ['//evil.example.com/a.png', null],
            'protocol-relative with a backslash' => ['/\\evil.example.com/a.png', null],
            'plain http' => ['http://images.example.com/a.png', null],
            'relative without a slash' => ['images/a.png', null],
        ];
    }

    #[DataProvider('imageUrls')]
    public function test_only_an_https_or_root_relative_image_url_is_sent(string $stored, ?string $sent): void
    {
        $user = User::factory()->create();
        Generation::factory()->succeeded()->create([
            'user_id' => $user->id,
            'image_url' => $stored,
            'image_url_expires_at' => CarbonImmutable::parse('2026-11-20T12:00:00Z'),
        ]);

        $item = $this->onlyHistoryItemOf($user);

        $this->assertSame($sent, $item['image_url']);
        $this->assertFalse($item['image_expired']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unfinishedOrFailedStates(): array
    {
        return [
            'pending' => ['pending'],
            'queued' => ['queued'],
            'processing' => ['processing'],
            'failed' => ['failed'],
        ];
    }

    #[DataProvider('unfinishedOrFailedStates')]
    public function test_a_generation_that_has_not_succeeded_has_no_image(string $status): void
    {
        $user = User::factory()->create();
        $generation = $this->givenAGenerationIn(GenerationStatus::from($status));
        $generation->forceFill([
            'user_id' => $user->id,
            'image_url' => 'https://images.example.com/a.png',
            'image_url_expires_at' => CarbonImmutable::parse('2026-11-19T23:59:00Z'),
        ])->save();

        $item = $this->onlyHistoryItemOf($user);

        $this->assertNull($item['image_url']);
        $this->assertNull($item['image_url_expires_at']);
        $this->assertFalse($item['image_expired']);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function failures(): array
    {
        return [
            'a local content-policy rejection' => [
                ['local_failure_reason' => LocalFailureReason::ContentPolicyViolation, 'error_code' => 'content_policy_violation'],
                'Prompt rejected by Pixelforge\'s content policy. Edit the prompt and try again.',
            ],
            'a local validation failure with a param' => [
                ['local_failure_reason' => LocalFailureReason::ValidationFailed, 'error_code' => 'validation_failed', 'error_param' => 'prompt', 'error_message' => 'prompt must not be empty.'],
                'Pixelforge rejected the request: prompt: prompt must not be empty.',
            ],
            'a local validation failure without a param' => [
                ['local_failure_reason' => LocalFailureReason::ValidationFailed, 'error_code' => 'validation_failed', 'error_param' => null, 'error_message' => 'The request is invalid.'],
                'Pixelforge rejected the request: The request is invalid.',
            ],
            'a local overload past its deadline' => [
                ['local_failure_reason' => LocalFailureReason::ProviderOverloaded, 'error_code' => 'overloaded', 'error_message' => 'Pixelforge is overloaded.'],
                'Pixelforge was overloaded and didn\'t accept this request. You were not charged.',
            ],
            'a local wait that stayed busy' => [
                ['local_failure_reason' => LocalFailureReason::ProviderBusy, 'error_code' => null, 'error_message' => null],
                'Pixelforge stayed busy for too long, so we stopped waiting. You were not charged.',
            ],
            'a local failure that was never sent' => [
                ['local_failure_reason' => LocalFailureReason::ServiceUnavailable, 'error_code' => 'invalid_api_key', 'error_message' => 'Invalid API key.'],
                'Service unavailable — this generation wasn\'t sent. You were not charged.',
            ],
            'a local daily limit' => [
                ['local_failure_reason' => LocalFailureReason::DailyLimit, 'error_code' => 'daily_limit_exceeded', 'error_message' => 'Daily limit reached.'],
                'Daily capacity reached, try after 00:00 UTC. You were not charged.',
            ],
            'a provider generation_failed' => [
                ['local_failure_reason' => null, 'error_code' => 'generation_failed'],
                'Pixelforge couldn\'t produce an image for this prompt. Try rephrasing it.',
            ],
            'a provider internal_error' => [
                ['local_failure_reason' => null, 'error_code' => 'internal_error'],
                'Something went wrong on Pixelforge\'s side. Please try again.',
            ],
            'an unknown code' => [
                ['local_failure_reason' => null, 'error_code' => 'model_on_fire'],
                'This generation failed.',
            ],
            'no code' => [
                ['local_failure_reason' => null, 'error_code' => null, 'error_message' => null],
                'This generation failed.',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $failure
     */
    #[DataProvider('failures')]
    public function test_a_failed_generation_explains_itself_in_one_message(array $failure, string $message): void
    {
        $user = User::factory()->create();
        Generation::factory()->failedLocally()->create(['user_id' => $user->id, 'error_param' => null, ...$failure]);

        $item = $this->onlyHistoryItemOf($user);

        $this->assertSame('failed', $item['status']);
        $this->assertSame($message, $item['failure_message']);
        $this->assertNull($item['user_charge_nanodollars']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unfailedStates(): array
    {
        return [
            'pending' => ['pending'],
            'queued' => ['queued'],
            'processing' => ['processing'],
            'succeeded' => ['succeeded'],
        ];
    }

    #[DataProvider('unfailedStates')]
    public function test_a_generation_that_has_not_failed_has_no_failure_message(string $status): void
    {
        $user = User::factory()->create();
        $generation = $this->givenAGenerationIn(GenerationStatus::from($status));
        $generation->forceFill(['user_id' => $user->id, 'error_code' => 'generation_failed'])->save();

        $this->assertNull($this->onlyHistoryItemOf($user)['failure_message']);
    }

    /**
     * D-09: we failed it locally, then the generation Pixelforge held under our key
     * succeeded. Its provider cost is recorded for the report, but the user sees a
     * free failure, with no image and no charge.
     */
    public function test_a_local_failure_that_later_succeeded_at_pixelforge_still_reads_failed_and_free(): void
    {
        $user = User::factory()->create();
        $lighthouse = $this->createGenerationFor($user, self::lighthouseRequest());
        $this->transitionGeneration($lighthouse, new LocalGenerationFailureData(
            LocalFailureReason::IdempotencyKeyReused,
            'idempotency_key_reused',
            'This idempotency key was already used with different parameters.',
            null,
        ));
        $this->transitionGeneration($lighthouse, self::lighthouseSucceededSnapshotFor($lighthouse));
        $this->assertSame(305_510_000, $this->reloadedGeneration($lighthouse)->provider_cost_nanodollars);

        $history = $this->historyFor($user);
        $item = $history['data'][0];

        $this->assertSame('failed', $item['status']);
        $this->assertSame('Failed', $item['status_label']);
        $this->assertSame('Something went wrong submitting this generation.', $item['failure_message']);
        $this->assertNull($item['user_charge_nanodollars']);
        $this->assertNull($item['image_url']);
        $this->assertFalse($item['image_expired']);
        $this->assertStringNotContainsString(self::LIGHTHOUSE_PROVIDER_COST, (string) json_encode($history));
    }

    /**
     * @return array<string, array{?SubmitRetryNote, string}>
     */
    public static function pendingNotes(): array
    {
        return [
            'retrying' => [SubmitRetryNote::Retrying, 'Pixelforge is busy — retrying automatically'],
            'waiting for a slot' => [SubmitRetryNote::WaitingForSlot, 'Waiting for a free slot'],
        ];
    }

    /**
     * AC 3, 5: the note is stored on the row, so it survives a reload.
     */
    #[DataProvider('pendingNotes')]
    public function test_a_pending_generation_says_why_it_is_waiting(SubmitRetryNote $note, string $message): void
    {
        $user = User::factory()->create();
        Generation::factory()->create(['user_id' => $user->id, 'submit_retry_note' => $note]);

        $item = $this->onlyHistoryItemOf($user);

        $this->assertSame('Queued', $item['status_label']);
        $this->assertSame($message, $item['status_note']);
    }

    public function test_a_pending_generation_with_no_note_has_none(): void
    {
        $user = User::factory()->create();
        Generation::factory()->create(['user_id' => $user->id]);

        $this->assertNull($this->onlyHistoryItemOf($user)['status_note']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function statesPastPending(): array
    {
        return [
            'queued' => ['queued'],
            'processing' => ['processing'],
            'succeeded' => ['succeeded'],
            'failed' => ['failed'],
        ];
    }

    /**
     * AC 6: a generation that advanced or failed leaves its note behind.
     */
    #[DataProvider('statesPastPending')]
    public function test_a_generation_past_pending_has_no_note_though_one_is_stored(string $status): void
    {
        $user = User::factory()->create();
        $generation = $this->givenAGenerationIn(GenerationStatus::from($status));
        $generation->forceFill(['user_id' => $user->id, 'submit_retry_note' => SubmitRetryNote::WaitingForSlot])->save();

        $this->assertNull($this->onlyHistoryItemOf($user)['status_note']);
    }

    public function test_history_carries_the_servers_time_to_the_millisecond(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-19T23:59:58.250Z'));

        $this->assertSame('2026-11-19T23:59:58.250Z', $this->historyFor(User::factory()->create())['server_time']);
    }

    private function givenTheSucceededLighthouseFor(User $user): Generation
    {
        $lighthouse = $this->createGenerationFor($user, self::lighthouseRequest());
        $this->transitionGeneration($lighthouse, self::lighthouseSucceededSnapshotFor($lighthouse));

        return $this->reloadedGeneration($lighthouse);
    }
}
