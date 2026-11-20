<?php

declare(strict_types=1);

namespace App\Domain\Generation\Actions;

use App\Domain\Billing\Exceptions\InvalidRateException;
use App\Domain\Billing\Exceptions\NanodollarOverflowException;
use App\Domain\Generation\DataTransferObjects\LocalGenerationFailureData;
use App\Domain\Generation\Enums\LocalFailureReason;
use App\Domain\Generation\Exceptions\ConcurrentGenerationTransitionException;
use App\Domain\Generation\Models\Generation;
use App\Support\Pixelforge\Contracts\PixelforgeClient;
use App\Support\Pixelforge\Contracts\ReportsPixelforgeContext;
use App\Support\Pixelforge\DataTransferObjects\CreateGenerationPayloadData;
use App\Support\Pixelforge\Enums\PixelforgeErrorCode;
use App\Support\Pixelforge\Exceptions\AmbiguousPixelforgeOutcomeException;
use App\Support\Pixelforge\Exceptions\PixelforgeApiException;
use App\Support\Pixelforge\Exceptions\PixelforgeNotConfiguredException;
use Illuminate\Support\Facades\Log;

/**
 * One submit attempt for a local generation (D-32). The row is re-read and the
 * body rebuilt from its stored columns, so every attempt sends the same bytes
 * under the same key, and Pixelforge replays instead of creating a second
 * generation. The create is sent outside any transaction or row lock, so a
 * webhook landing meanwhile is never blocked, and the answer goes through the
 * transition action, so it never undoes one that landed first (D-06).
 *
 * Only a definitive rejection, where Pixelforge created nothing (`400`, `422`
 * or `409`), fails the generation here, at no charge (D-31). Every other
 * failure is rethrown, so the job retries with the same key; a replay is free.
 */
final class SubmitGenerationAction
{
    public function __construct(
        private readonly PixelforgeClient $pixelforge,
        private readonly TransitionGenerationAction $transitionGeneration,
    ) {}

    /**
     * A generation Pixelforge has already answered for (it has a provider id)
     * or that is terminal is left alone, with no request. A definitive
     * rejection returns normally, so the job is not retried.
     *
     * @throws PixelforgeApiException
     * @throws AmbiguousPixelforgeOutcomeException
     * @throws PixelforgeNotConfiguredException
     * @throws ConcurrentGenerationTransitionException
     * @throws InvalidRateException
     * @throws NanodollarOverflowException
     */
    public function execute(string $generationId): void
    {
        $generation = Generation::query()->whereKey($generationId)->firstOrFail();

        if ($generation->provider_id !== null || $generation->status->isTerminal()) {
            return;
        }

        try {
            $created = $this->pixelforge->createGeneration(self::payloadFor($generation));
        } catch (PixelforgeApiException $exception) {
            $reason = self::definitiveRejectionReason($exception);

            if ($reason === null) {
                throw self::loggedForRetry($generation, $exception);
            }

            $this->failLocally($generation, $reason, $exception);

            return;
        } catch (ReportsPixelforgeContext $exception) {
            throw self::loggedForRetry($generation, $exception);
        }

        $outcome = $this->transitionGeneration->execute($generation, $created->snapshot);

        Log::info('Applied Pixelforge\'s answer to a generation create.', [
            'generation_id' => $generation->id,
            'provider_id' => $created->snapshot->id,
            'replayed' => $created->replayed,
            'outcome' => $outcome->value,
        ]);
    }

    /**
     * @throws ConcurrentGenerationTransitionException
     */
    private function failLocally(Generation $generation, LocalFailureReason $reason, PixelforgeApiException $rejection): void
    {
        $outcome = $this->transitionGeneration->execute($generation, new LocalGenerationFailureData(
            $reason,
            $rejection->errorCode()->value,
            $rejection->getMessage(),
            $rejection->param,
        ));

        $context = [
            'generation_id' => $generation->id,
            'error_code' => $rejection->errorCode()->value,
            'outcome' => $outcome->value,
        ];

        match ($reason) {
            LocalFailureReason::ContentPolicyViolation => Log::info('Failed a generation at no charge: Pixelforge rejected its prompt.', $context),
            LocalFailureReason::ValidationFailed => Log::error('Failed a generation at no charge: Pixelforge refused a param our validation accepted.', [
                ...$context,
                'param' => $rejection->param,
            ]),
            LocalFailureReason::IdempotencyKeyReused => Log::error('Failed a generation at no charge: Pixelforge holds its idempotency key with other params.', $context),
            LocalFailureReason::ProviderOverloaded,
            LocalFailureReason::ProviderBusy,
            LocalFailureReason::ServiceUnavailable => Log::warning('Failed a generation at no charge: Pixelforge never accepted it.', [
                ...$context,
                'reason' => $reason->value,
            ]),
        };
    }

    /**
     * The generation stays non-terminal with its hold, and the job retries
     * with the same key. PE-013 owns the policy for these codes.
     */
    private static function loggedForRetry(Generation $generation, ReportsPixelforgeContext $exception): ReportsPixelforgeContext
    {
        Log::warning('Pixelforge did not take a generation create; it will be retried with the same key.', [
            'generation_id' => $generation->id,
            ...$exception->context(),
        ]);

        return $exception;
    }

    /**
     * The local reason when Pixelforge definitely created nothing, else null.
     */
    private static function definitiveRejectionReason(PixelforgeApiException $exception): ?LocalFailureReason
    {
        return match ($exception->errorCode()) {
            PixelforgeErrorCode::ContentPolicyViolation => LocalFailureReason::ContentPolicyViolation,
            PixelforgeErrorCode::ValidationFailed => LocalFailureReason::ValidationFailed,
            PixelforgeErrorCode::IdempotencyKeyReused => LocalFailureReason::IdempotencyKeyReused,
            default => null,
        };
    }

    private static function payloadFor(Generation $generation): CreateGenerationPayloadData
    {
        return new CreateGenerationPayloadData(
            $generation->id,
            $generation->prompt,
            $generation->size,
            $generation->quality,
            $generation->reference_images,
        );
    }
}
