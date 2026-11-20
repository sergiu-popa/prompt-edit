<?php

declare(strict_types=1);

namespace App\Domain\Generation\Actions;

use App\Domain\Billing\Actions\EstimateGenerationCostAction;
use App\Domain\Billing\Actions\HoldGenerationCreditsAction;
use App\Domain\Billing\Actions\LockCreditBalanceAction;
use App\Domain\Billing\Exceptions\InsufficientCreditsException;
use App\Domain\Billing\Exceptions\NanodollarOverflowException;
use App\Domain\Billing\ValueObjects\Nanodollars;
use App\Domain\Generation\DataTransferObjects\NewGenerationData;
use App\Domain\Generation\Enums\GenerationStatus;
use App\Domain\Generation\Exceptions\InvalidGenerationRequestException;
use App\Domain\Generation\Models\Generation;
use App\Jobs\SubmitGenerationJob;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Writes the local row before anything is sent to Pixelforge (D-05), so a
 * webhook that beats our create call always finds it. The request is stored
 * exactly as given, so every retry rebuilds an identical payload. The row and
 * the hold of its max user price commit together (D-14).
 *
 * The submit job is dispatched inside the transaction but pushed only after
 * the outermost commit (D-32): a caller's own transaction that rolls back
 * submits nothing.
 *
 * A request from the Generate form carries its submission token, and the
 * unique `(user_id, submission_token)` makes one generation per submission
 * (D-38): a double click, a resent request or a concurrent duplicate gets the
 * first generation back, with no second hold and no second job. The first
 * request wins, even when a replay carries other fields.
 *
 * The balance decides under the user-row lock, in the transaction that writes
 * the hold (D-68): the lock is taken first, a replay of a stored submission is
 * returned with no check (its hold may have spent the balance), and a max the
 * balance can't cover is refused before anything is inserted, so a refusal
 * writes no row, no hold and no job. The estimate is the server's own (PE-002).
 */
final class CreateGenerationAction
{
    public function __construct(
        private readonly EstimateGenerationCostAction $estimateGenerationCost,
        private readonly LockCreditBalanceAction $lockCreditBalance,
        private readonly HoldGenerationCreditsAction $holdGenerationCredits,
    ) {}

    /**
     * @throws InvalidGenerationRequestException
     * @throws InsufficientCreditsException when the balance cannot hold the max user price
     * @throws NanodollarOverflowException
     */
    public function execute(User $user, NewGenerationData $data): Generation
    {
        $this->ensureStorableAsGiven($data);

        $userPrice = $this->estimateGenerationCost->execute(
            $data->prompt,
            $data->quality,
            $data->size,
            count($data->referenceImages),
        )->userPrice;

        return DB::transaction(function () use ($user, $data, $userPrice): Generation {
            $balance = $this->lockCreditBalance->execute($user);

            $replayed = $data->submissionToken === null
                ? null
                : Generation::query()
                    ->where('user_id', $user->getKey())
                    ->where('submission_token', $data->submissionToken)
                    ->first();

            if ($replayed !== null) {
                return $replayed;
            }

            if ($balance->available->compareTo($userPrice->max) < 0) {
                throw InsufficientCreditsException::forHold($userPrice->max, $balance);
            }

            $attributes = [
                'status' => GenerationStatus::Pending,
                'prompt' => $data->prompt,
                'size' => $data->size,
                'quality' => $data->quality,
                'reference_images' => $data->referenceImages,
                'user_estimate_min_nanodollars' => $userPrice->min->amount,
                'user_estimate_typical_nanodollars' => $userPrice->typical->amount,
                'user_estimate_max_nanodollars' => $userPrice->max->amount,
            ];

            /*
             * The lock already serializes one user's creates, so the replay read above
             * finds every committed submission; createOrFirst stays D-38's backstop.
             * Inside this transaction it inserts under a savepoint, so a conflict rolls
             * back only the insert and the stored row is read instead.
             */
            $generation = $data->submissionToken === null
                ? Generation::query()->create(['user_id' => $user->getKey(), ...$attributes])
                : Generation::query()->createOrFirst(
                    ['user_id' => $user->getKey(), 'submission_token' => $data->submissionToken],
                    $attributes,
                );

            if (! $generation->wasRecentlyCreated) {
                return $generation;
            }

            $this->holdGenerationCredits->execute(
                $generation->user_id,
                $generation->id,
                new Nanodollars($generation->user_estimate_max_nanodollars),
            );

            SubmitGenerationJob::dispatch($generation->id);

            return $generation;
        });
    }

    /**
     * Postgres text cannot hold U+0000, and pdo_pgsql silently cuts the
     * value at it instead of failing the insert. No URL holds one either.
     *
     * @throws InvalidGenerationRequestException
     */
    private function ensureStorableAsGiven(NewGenerationData $data): void
    {
        if (str_contains($data->prompt, "\0")) {
            throw InvalidGenerationRequestException::nulByteInPrompt();
        }

        foreach ($data->referenceImages as $index => $referenceImage) {
            if (str_contains($referenceImage, "\0")) {
                throw InvalidGenerationRequestException::nulByteInReferenceImage($index + 1);
            }
        }
    }
}
