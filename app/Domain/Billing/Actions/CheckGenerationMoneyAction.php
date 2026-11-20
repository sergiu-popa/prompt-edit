<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\DataTransferObjects\GenerationCostEstimate;
use App\Domain\Billing\Enums\CreditLedgerEntryType;
use App\Domain\Billing\Enums\TokenType;
use App\Domain\Billing\Exceptions\InvalidRateException;
use App\Domain\Billing\Exceptions\InvalidTokenUsageException;
use App\Domain\Billing\Exceptions\NanodollarOverflowException;
use App\Domain\Billing\Models\CreditLedgerEntry;
use App\Domain\Billing\ValueObjects\Nanodollars;
use App\Domain\Billing\ValueObjects\TokenBounds;
use App\Domain\Billing\ValueObjects\TokenRates;
use App\Domain\Billing\ValueObjects\TokenUsage;
use App\Domain\Generation\Enums\GenerationStatus;
use App\Domain\Generation\Models\Generation;

/**
 * Re-checks one generation's money from scratch, read-only, for the go-live
 * checks on real generations (PE-015 AC 22): its hold against a fresh estimate,
 * its provider cost against its own usage × its own rates, its charge against the
 * +25 % rounded up, its ledger entries, and its tokens against the estimate. A
 * failed generation must be free, with a provider cost only when Pixelforge
 * billed it after a local failure (D-09).
 */
final readonly class CheckGenerationMoneyAction
{
    public function __construct(
        private EstimateGenerationCostAction $estimateGenerationCost,
        private CalculateProviderCostAction $calculateProviderCost,
        private CalculateUserChargeAction $calculateUserCharge,
    ) {}

    /**
     * @return list<string> the problems found, empty when every check holds
     *
     * @throws NanodollarOverflowException
     */
    public function execute(Generation $generation): array
    {
        $estimate = $this->estimateGenerationCost->execute(
            $generation->prompt,
            $generation->quality,
            $generation->size,
            count($generation->reference_images),
        );
        $holdAmount = $generation->user_estimate_max_nanodollars;
        $ledger = $this->ledgerOf($generation);

        $problems = $this->holdProblems($estimate, $holdAmount, $ledger);

        if (! $generation->status->isTerminal()) {
            return [...$problems, 'not terminal yet'];
        }

        if ($generation->status === GenerationStatus::Succeeded) {
            return [...$problems, ...$this->successProblems($generation, $estimate, $holdAmount, $ledger)];
        }

        return [...$problems, ...$this->failureProblems($generation, $holdAmount, $ledger)];
    }

    /**
     * @param  list<array{string, int}>  $ledger
     * @return list<string>
     */
    private function holdProblems(GenerationCostEstimate $estimate, int $holdAmount, array $ledger): array
    {
        $problems = [];

        if ($estimate->userPrice->max->amount !== $holdAmount) {
            $problems[] = sprintf('the estimate max is %d n$ but the row holds %d n$', $estimate->userPrice->max->amount, $holdAmount);
        }

        $holds = array_values(array_filter($ledger, fn (array $entry): bool => $entry[0] === CreditLedgerEntryType::Hold->value));

        if (count($holds) !== 1) {
            return [...$problems, sprintf('%d holds, expected 1', count($holds))];
        }

        if ($holds[0][1] !== -$holdAmount) {
            $problems[] = sprintf('the hold is %d n$, expected %d n$', $holds[0][1], -$holdAmount);
        }

        return $problems;
    }

    /**
     * @param  list<array{string, int}>  $ledger
     * @return list<string>
     *
     * @throws NanodollarOverflowException
     */
    private function successProblems(Generation $generation, GenerationCostEstimate $estimate, int $holdAmount, array $ledger): array
    {
        $usage = $this->usageOf($generation);
        $providerCost = $usage === null ? null : $this->providerCostOf($generation, $usage);

        if ($usage === null || $providerCost === null) {
            return ['no usage or rates to price'];
        }

        $problems = [];
        $charge = $this->calculateUserCharge->execute($providerCost);

        if ($generation->provider_cost_nanodollars !== $providerCost->amount) {
            $problems[] = sprintf('the provider cost is %s n$, usage × rates gives %d n$', self::amount($generation->provider_cost_nanodollars), $providerCost->amount);
        }

        if ($generation->user_charge_nanodollars !== $charge->amount) {
            $problems[] = sprintf('the charge is %s n$, expected %d n$', self::amount($generation->user_charge_nanodollars), $charge->amount);
        }

        if ($charge->amount > $holdAmount) {
            $problems[] = sprintf('the charge %d n$ exceeds the hold %d n$', $charge->amount, $holdAmount);
        }

        $problems = [...$problems, ...self::ledgerProblems($ledger, [
            [CreditLedgerEntryType::Hold->value, -$holdAmount],
            [CreditLedgerEntryType::Release->value, $holdAmount],
            [CreditLedgerEntryType::Charge->value, -$charge->amount],
        ])];

        foreach ([
            TokenType::InputText->value => $usage->inputTextTokens,
            TokenType::InputImage->value => $usage->inputImageTokens,
            TokenType::Output->value => $usage->outputTokens,
            TokenType::Reasoning->value => $usage->reasoningTokens,
        ] as $type => $tokens) {
            $problems = [...$problems, ...self::tokenProblems($type, $tokens, $estimate->lines[$type]->tokens)];
        }

        return $problems;
    }

    /**
     * @param  list<array{string, int}>  $ledger
     * @return list<string>
     *
     * @throws NanodollarOverflowException
     */
    private function failureProblems(Generation $generation, int $holdAmount, array $ledger): array
    {
        $problems = self::ledgerProblems($ledger, [
            [CreditLedgerEntryType::Hold->value, -$holdAmount],
            [CreditLedgerEntryType::Release->value, $holdAmount],
        ]);

        if (! in_array($generation->user_charge_nanodollars, [null, 0], true)) {
            $problems[] = sprintf('a failed generation was charged %d n$', $generation->user_charge_nanodollars);
        }

        $usage = $this->usageOf($generation);

        if ($usage === null) {
            if (! in_array($generation->provider_cost_nanodollars, [null, 0], true)) {
                $problems[] = sprintf('a provider cost of %d n$ without usage', $generation->provider_cost_nanodollars);
            }

            return $problems;
        }

        $providerCost = $this->providerCostOf($generation, $usage);

        if ($providerCost === null) {
            return [...$problems, 'usage without rates to price'];
        }

        if ($generation->provider_cost_nanodollars !== $providerCost->amount) {
            $problems[] = sprintf('the provider cost is %s n$, usage × rates gives %d n$', self::amount($generation->provider_cost_nanodollars), $providerCost->amount);
        }

        return $problems;
    }

    /**
     * Null until Pixelforge reported usage; a row whose counts cannot form one is
     * reported by the caller as unpriceable.
     */
    private function usageOf(Generation $generation): ?TokenUsage
    {
        if ($generation->input_text_tokens === null || $generation->input_image_tokens === null || $generation->output_tokens === null || $generation->reasoning_tokens === null) {
            return null;
        }

        try {
            return new TokenUsage($generation->input_text_tokens, $generation->input_image_tokens, $generation->output_tokens, $generation->reasoning_tokens);
        } catch (InvalidTokenUsageException) {
            return null;
        }
    }

    /**
     * Σ usage × the generation's own rates, or null when its rates can't be read.
     *
     * @throws NanodollarOverflowException
     */
    private function providerCostOf(Generation $generation, TokenUsage $usage): ?Nanodollars
    {
        if ($generation->rates === null) {
            return null;
        }

        try {
            return $this->calculateProviderCost->execute($usage, TokenRates::from($generation->rates));
        } catch (InvalidRateException) {
            return null;
        }
    }

    /**
     * @return list<array{string, int}> each entry's type and signed amount, in write order
     */
    private function ledgerOf(Generation $generation): array
    {
        return array_values(CreditLedgerEntry::query()
            ->where('generation_id', $generation->id)
            ->orderBy('id')
            ->get()
            ->map(fn (CreditLedgerEntry $entry): array => [$entry->type->value, $entry->amount_nanodollars])
            ->all());
    }

    /**
     * @param  list<array{string, int}>  $ledger
     * @param  list<array{string, int}>  $expected
     * @return list<string>
     */
    private static function ledgerProblems(array $ledger, array $expected): array
    {
        return $ledger === $expected ? [] : [sprintf('the ledger is [%s], expected [%s]', self::ledgerLine($ledger), self::ledgerLine($expected))];
    }

    /**
     * @param  list<array{string, int}>  $ledger
     */
    private static function ledgerLine(array $ledger): string
    {
        return implode(', ', array_map(fn (array $entry): string => sprintf('%s %d', $entry[0], $entry[1]), $ledger));
    }

    /**
     * Exact token types must match; ranged ones must fall within the estimate.
     *
     * @return list<string>
     */
    private static function tokenProblems(string $type, int $tokens, TokenBounds $bounds): array
    {
        if ($bounds->min === $bounds->max) {
            return $tokens === $bounds->min ? [] : [sprintf('%s is %d, estimated exactly %d', $type, $tokens, $bounds->min)];
        }

        return $tokens >= $bounds->min && $tokens <= $bounds->max ? [] : [sprintf('%s is %d, outside %d–%d', $type, $tokens, $bounds->min, $bounds->max)];
    }

    private static function amount(?int $nanodollars): string
    {
        return $nanodollars === null ? 'null' : (string) $nanodollars;
    }
}
