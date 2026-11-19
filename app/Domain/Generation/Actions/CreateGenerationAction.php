<?php

declare(strict_types=1);

namespace App\Domain\Generation\Actions;

use App\Domain\Billing\Actions\EstimateGenerationCostAction;
use App\Domain\Billing\Exceptions\NanodollarOverflowException;
use App\Domain\Generation\DataTransferObjects\NewGenerationData;
use App\Domain\Generation\Enums\GenerationStatus;
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
     * @throws NanodollarOverflowException
     */
    public function execute(User $user, NewGenerationData $data): Generation
    {
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
}
