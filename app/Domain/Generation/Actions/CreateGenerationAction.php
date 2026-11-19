<?php

declare(strict_types=1);

namespace App\Domain\Generation\Actions;

use App\Domain\Billing\Actions\EstimateGenerationCostAction;
use App\Domain\Billing\Exceptions\NanodollarOverflowException;
use App\Domain\Generation\DataTransferObjects\NewGenerationData;
use App\Domain\Generation\Enums\GenerationStatus;
use App\Domain\Generation\Exceptions\InvalidGenerationRequestException;
use App\Domain\Generation\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Writes the local row before anything is sent to Pixelforge (D-05), so a
 * webhook that beats our create call always finds it. The request is stored
 * exactly as given, so every retry rebuilds an identical payload.
 */
final class CreateGenerationAction
{
    public function __construct(
        private readonly EstimateGenerationCostAction $estimateGenerationCost,
    ) {}

    /**
     * @throws InvalidGenerationRequestException
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

        return DB::transaction(fn (): Generation => Generation::query()->create([
            'user_id' => $user->getKey(),
            'status' => GenerationStatus::Pending,
            'prompt' => $data->prompt,
            'size' => $data->size,
            'quality' => $data->quality,
            'reference_images' => $data->referenceImages,
            'user_estimate_min_nanodollars' => $userPrice->min->amount,
            'user_estimate_typical_nanodollars' => $userPrice->typical->amount,
            'user_estimate_max_nanodollars' => $userPrice->max->amount,
        ]));
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
