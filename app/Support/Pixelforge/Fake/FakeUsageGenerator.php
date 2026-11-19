<?php

declare(strict_types=1);

namespace App\Support\Pixelforge\Fake;

use App\Domain\Billing\Actions\EstimateGenerationCostAction;
use App\Domain\Billing\Enums\TokenType;
use App\Domain\Billing\Exceptions\NanodollarOverflowException;
use App\Support\Pixelforge\DataTransferObjects\CreateGenerationPayloadData;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/**
 * The usage the fake reports for a generation. Every count lies within the
 * PE-002 estimate's own bounds for the payload, so a fake generation can never
 * be charged more than its hold. The draws are seeded by the idempotency key,
 * so a payload always gets the same usage and tests can assert exact amounts.
 */
final readonly class FakeUsageGenerator
{
    public function __construct(
        private EstimateGenerationCostAction $estimateGenerationCost,
    ) {}

    /**
     * Image and output tokens are the estimate's exact counts; text and
     * reasoning are drawn within its bounds.
     *
     * @return array{input_text_tokens: int, input_image_tokens: int, output_tokens: int, reasoning_tokens: int, total_tokens: int}
     *
     * @throws NanodollarOverflowException
     */
    public function generate(CreateGenerationPayloadData $payload): array
    {
        $lines = $this->estimateGenerationCost->execute(
            $payload->prompt,
            $payload->quality,
            $payload->size,
            count($payload->referenceImages),
        )->lines;

        $randomizer = new Randomizer(new Xoshiro256StarStar(hash('sha256', $payload->idempotencyKey(), binary: true)));

        $text = $lines[TokenType::InputText->value]->tokens;
        $reasoning = $lines[TokenType::Reasoning->value]->tokens;

        $usage = [
            TokenType::InputText->value => $randomizer->getInt($text->min, $text->max),
            TokenType::InputImage->value => $lines[TokenType::InputImage->value]->tokens->min,
            TokenType::Output->value => $lines[TokenType::Output->value]->tokens->min,
            TokenType::Reasoning->value => $randomizer->getInt($reasoning->min, $reasoning->max),
        ];

        return [...$usage, 'total_tokens' => array_sum($usage)];
    }
}
