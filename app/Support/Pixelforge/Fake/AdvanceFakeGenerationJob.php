<?php

declare(strict_types=1);

namespace App\Support\Pixelforge\Fake;

use App\Domain\Generation\Enums\GenerationStatus;
use App\Support\Pixelforge\Enums\WebhookEventType;
use App\Support\Pixelforge\Exceptions\WebhookSecretNotConfiguredException;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Facades\URL;

/**
 * Moves one fake generation a step along its lifecycle, queued → processing
 * → terminal, then announces the step by webhook. It is delayed by the
 * configured timings, on the normal database queue. The state is saved
 * before the send, so a failed delivery never leaves the fake wrong. One try
 * only: Pixelforge does not redeliver here, and PE-014 recovers a lost
 * terminal webhook.
 */
#[Tries(1)]
final class AdvanceFakeGenerationJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $fakeGenerationId,
    ) {}

    /**
     * @throws WebhookSecretNotConfiguredException
     */
    public function handle(FakeWebhookSender $sender): void
    {
        $generation = FakePixelforgeGeneration::query()->find($this->fakeGenerationId);

        if ($generation === null || $generation->status()->isTerminal()) {
            return;
        }

        $now = CarbonImmutable::now();

        if ($generation->status() === GenerationStatus::Queued) {
            $generation->markProcessing($now);
            $sender->deliver($generation, WebhookEventType::GenerationProcessing);

            self::dispatch($this->fakeGenerationId)->delay($generation->processing_seconds);

            return;
        }

        if ($generation->outcome !== FakeGenerationOutcome::Succeeded) {
            $generation->markFailed($now);
            $sender->deliver($generation, WebhookEventType::GenerationFailed);

            return;
        }

        $expiresAt = $now->addSeconds($generation->image_ttl_seconds);
        $generation->markSucceeded($now, self::imageUrl($generation, $expiresAt), $expiresAt);
        $sender->deliver($generation, WebhookEventType::GenerationSucceeded);
    }

    /**
     * Root-relative, so the browser loads it from whichever host it is on:
     * `prompt-edit.localhost` in dev and `127.0.0.1:8080` in E2E.
     */
    private static function imageUrl(FakePixelforgeGeneration $generation, CarbonImmutable $expiresAt): string
    {
        return URL::temporarySignedRoute('fake-pixelforge.image', $expiresAt, ['generation' => $generation->id], absolute: false);
    }
}
